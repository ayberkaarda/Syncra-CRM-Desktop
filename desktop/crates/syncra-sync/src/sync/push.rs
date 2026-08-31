//! The push half of a sync round.
//!
//! Everything here is synchronous and takes an already-locked connection; the async
//! request itself lives in [`crate::SyncEngine::sync_now`]. That split keeps the database
//! mutex from ever being held across an `await`.

use super::local;
use crate::conflicts;
use crate::error::Result;
use crate::outbox::{self, OutboxRow};
use crate::protocol::{codes, PushResponse, PushResult, PushStatus};
use crate::types::{Entity, SyncState};
use rusqlite::Connection;
use serde_json::Value as Json;
use std::collections::HashSet;
use uuid::Uuid;

/// Tally of one batch's results.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct BatchOutcome {
    /// Mutations the server accepted.
    pub applied: u32,
    /// Mutations the server had already applied.
    pub duplicates: u32,
    /// Open Conflict Inbox entries.
    pub conflicts: u32,
    /// Mutations the server refused outright.
    pub rejected: u32,
    /// Mutations the server omitted from `results` (protocol §4.3 P10b).
    pub deferred: u32,
}

/// Split the queue into batches within the server's ceilings.
pub fn prepare(conn: &Connection, max_count: u32, max_bytes: u64) -> Result<Vec<Vec<OutboxRow>>> {
    let queued = outbox::queued_in_push_order(conn)?;
    Ok(outbox::batches(queued, max_count, max_bytes))
}

/// Mark a batch as in flight just before the request goes out.
pub fn mark_inflight(conn: &Connection, batch: &[OutboxRow]) -> Result<()> {
    let tx = conn.unchecked_transaction()?;
    let ids: Vec<Uuid> = batch.iter().map(|r| r.id).collect();
    outbox::mark_inflight(&tx, &ids)?;
    tx.commit()?;
    Ok(())
}

/// Put a whole batch back after a transport or 5xx failure; this *does* count an attempt.
pub fn requeue_after_failure(conn: &Connection, batch: &[OutboxRow], error: &str) -> Result<()> {
    let tx = conn.unchecked_transaction()?;
    let ids: Vec<Uuid> = batch.iter().map(|r| r.id).collect();
    outbox::requeue_with_attempt(&tx, &ids, error)?;
    tx.commit()?;
    Ok(())
}

/// Apply a push response to the outbox and the mirror.
///
/// Protocol §4.3 P10b is implemented here and is deliberately the first thing the loop
/// checks: **a mutation whose `seq` is absent from `results` was never processed.** It goes
/// back to `queued` with `attempts` untouched and is resent next round; the
/// `idempotency_key` makes that safe. Anything else — treating it as failed, or counting an
/// attempt against it — would let a lock-contention truncation (protocol §2.4 P4a) burn
/// through the retry budget of work the server never even looked at.
pub fn apply_results(
    conn: &Connection,
    batch: &[OutboxRow],
    response: &PushResponse,
) -> Result<(BatchOutcome, Vec<Entity>, Vec<Uuid>)> {
    let mut outcome = BatchOutcome::default();
    let mut touched: HashSet<Entity> = HashSet::new();
    let mut new_conflicts: Vec<Uuid> = Vec::new();

    let tx = conn.unchecked_transaction()?;

    let mut deferred: Vec<Uuid> = Vec::new();
    let mut rejected_creates: Vec<(Entity, String)> = Vec::new();

    for row in batch {
        let Some(result) = response.results.iter().find(|r| r.seq == row.seq) else {
            deferred.push(row.id);
            outcome.deferred += 1;
            continue;
        };

        touched.insert(row.entity);

        match result.status {
            PushStatus::Applied => {
                outcome.applied += 1;
                settle(&tx, row, result)?;
            }
            PushStatus::Duplicate => {
                outcome.duplicates += 1;
                settle(&tx, row, result)?;
            }
            PushStatus::Conflict => {
                outcome.conflicts += 1;
                let code = result
                    .code
                    .clone()
                    .unwrap_or_else(|| codes::FIELD_CONFLICT.to_string());
                let id = conflicts::record(
                    &tx,
                    Some(row.id),
                    row.entity,
                    row.client_id.as_deref(),
                    &code,
                    &result.conflicting_fields,
                    &row.payload.clone().unwrap_or(Json::Null),
                    result.server_row.as_ref().unwrap_or(&Json::Null),
                )?;
                new_conflicts.push(id);
                if let Some(client_id) = row.client_id.as_deref() {
                    local::set_state(&tx, row.entity, client_id, SyncState::Conflict)?;
                }
                outbox::mark_failed(&tx, row.id, &code)?;
            }
            PushStatus::Rejected => {
                outcome.rejected += 1;
                let code = result
                    .code
                    .clone()
                    .unwrap_or_else(|| codes::INVALID_MUTATION.to_string());
                let id = conflicts::record(
                    &tx,
                    Some(row.id),
                    row.entity,
                    row.client_id.as_deref(),
                    &code,
                    &result.conflicting_fields,
                    &row.payload.clone().unwrap_or(Json::Null),
                    result.server_row.as_ref().unwrap_or(&Json::Null),
                )?;
                new_conflicts.push(id);
                if let Some(client_id) = row.client_id.as_deref() {
                    local::set_state(&tx, row.entity, client_id, SyncState::Conflict)?;
                    if row.op == crate::types::Op::Create {
                        rejected_creates.push((row.entity, client_id.to_string()));
                    }
                }
                outbox::mark_failed(&tx, row.id, &code)?;
            }
        }
    }

    outbox::requeue_untouched(&tx, &deferred)?;

    // §5.4: when a create is rejected, everything that depended on it cannot succeed
    // either. Those mutations move to the Conflict Inbox with UNRESOLVED_REFERENCE so the
    // user can retry or discard the whole group.
    for (entity, client_id) in &rejected_creates {
        let ids = cascade_failure(&tx, *entity, client_id)?;
        for id in ids {
            new_conflicts.push(id);
        }
    }

    tx.commit()?;
    let mut entities: Vec<Entity> = touched.into_iter().collect();
    entities.sort();
    Ok((outcome, entities, new_conflicts))
}

/// An accepted mutation: adopt the server identity, mark the row synced, drop the queue entry.
fn settle(tx: &rusqlite::Transaction<'_>, row: &OutboxRow, result: &PushResult) -> Result<()> {
    // `notification.read_all` is the one mutation with no row identity (protocol §4.3 P10),
    // yet it marked every unread notification `pending` locally. Nothing else would ever
    // clear that, and a `pending` row is shielded from pull overwrites (§5.5) — so it would
    // stay stuck. Settle those rows here.
    if row.client_id.is_none()
        && row.entity == Entity::Notification
        && row.action.as_deref() == Some("read_all")
    {
        tx.execute(
            "UPDATE notifications SET sync_state = 'synced' WHERE sync_state = 'pending'",
            [],
        )?;
        outbox::remove(tx, row.id)?;
        return Ok(());
    }

    if let Some(client_id) = row.client_id.as_deref() {
        local::set_server_identity(
            tx,
            row.entity,
            client_id,
            result.server_id,
            result.sync_version,
        )?;
        // A delete that the server accepted stays a tombstone locally until retention
        // sweeps it; everything else becomes clean.
        let state = if row.op == crate::types::Op::Delete {
            SyncState::Tombstone
        } else {
            SyncState::Synced
        };
        local::set_state(tx, row.entity, client_id, state)?;
    }
    outbox::remove(tx, row.id)?;
    Ok(())
}

/// Fail every queued mutation that depends on a create the server rejected.
fn cascade_failure(
    tx: &rusqlite::Transaction<'_>,
    entity: Entity,
    client_id: &str,
) -> Result<Vec<Uuid>> {
    let mut dependents: Vec<(Uuid, Entity, Option<String>, Option<String>)> = Vec::new();
    {
        let mut stmt = tx.prepare(
            "SELECT id, entity, client_id, payload FROM outbox
              WHERE state IN ('queued', 'inflight')
                AND (client_id = ?1 OR payload LIKE '%' || ?1 || '%')",
        )?;
        let mut rows = stmt.query([client_id])?;
        while let Some(row) = rows.next()? {
            let id: String = row.get(0)?;
            let ent: String = row.get(1)?;
            let cid: Option<String> = row.get(2)?;
            let payload: Option<String> = row.get(3)?;
            let Ok(id) = Uuid::parse_str(&id) else {
                continue;
            };
            let Some(ent) = Entity::from_wire_name(&ent) else {
                continue;
            };
            dependents.push((id, ent, cid, payload));
        }
    }

    let mut created = Vec::new();
    for (id, dep_entity, dep_client_id, payload) in dependents {
        let mine = payload
            .as_deref()
            .and_then(|p| serde_json::from_str::<Json>(p).ok())
            .unwrap_or(Json::Null);
        let conflict_id = conflicts::record(
            tx,
            Some(id),
            dep_entity,
            dep_client_id.as_deref(),
            codes::UNRESOLVED_REFERENCE,
            &[],
            &mine,
            &serde_json::json!({ "unresolved": { "entity": entity.wire_name(), "client_id": client_id } }),
        )?;
        created.push(conflict_id);
        outbox::mark_failed(tx, id, codes::UNRESOLVED_REFERENCE)?;
        if let Some(cid) = dep_client_id.as_deref() {
            local::set_state(tx, dep_entity, cid, SyncState::Conflict)?;
        }
    }
    Ok(created)
}
