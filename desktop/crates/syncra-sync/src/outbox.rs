//! The outbox: durable queue of local mutations, its coalescing rules, and the
//! topological order in which they are pushed.

use crate::error::{Result, SyncError};
use crate::protocol::WireMutation;
use crate::types::{Entity, LocalMutation, Op};
use chrono::{SecondsFormat, Utc};
use rusqlite::{Connection, Transaction};
use serde_json::Value as Json;
use uuid::Uuid;

/// One queued mutation.
#[derive(Debug, Clone)]
pub struct OutboxRow {
    /// Identifier of this record.
    pub id: Uuid,
    /// Monotonic position in the outbox; also the key of the push result.
    pub seq: i64,
    /// Makes a resend safe after a partial or lost response.
    pub idempotency_key: String,
    /// Table the row belongs to.
    pub entity: Entity,
    /// Push operation kind.
    pub op: Op,
    /// Action name, for `op = action`.
    pub action: Option<String>,
    /// `user` for the user-scoped `notification.read_all` action.
    pub scope: Option<String>,
    /// Stable local identity of the row.
    pub client_id: Option<String>,
    /// Server primary key, once the row has been accepted.
    pub server_id: Option<i64>,
    /// Server version the local edit was based on.
    pub base_sync_version: Option<i64>,
    /// Fields the mutation intends to write; nothing outside them is applied.
    pub changed_fields: Option<Vec<String>>,
    /// Field values carried by the mutation.
    pub payload: Option<Json>,
    /// When the edit happened on this device (RFC 3339, UTC).
    pub occurred_at: String,
    /// How many times sending this mutation has failed.
    pub attempts: i64,
    /// Queue state: `queued`, `inflight` or `failed`.
    pub state: String,
}

impl OutboxRow {
    /// Sort key implementing `SYNCDESKTOP.md` §5.4.
    ///
    /// Actions sit at level 5 regardless of entity, so they always follow the create of the
    /// row they act on. Within one level, `create < update < action < delete`, and `seq`
    /// breaks ties, which keeps FIFO order for equal-priority mutations.
    ///
    /// The specification's `quote_item(4)` level is gone: protocol §6.2 P13 carries quote
    /// items inside the `quote` mutation payload, so there is no separate mutation to
    /// order.
    pub fn sort_key(&self) -> (u8, u8, i64) {
        let level = if self.op == Op::Action {
            5
        } else {
            self.entity.topo_level()
        };
        (level, self.op.rank(), self.seq)
    }

    /// Render the row as a wire mutation (protocol §4.3).
    pub fn to_wire(&self) -> WireMutation {
        let is_read_all = self.entity == Entity::Notification
            && self.action.as_deref() == Some("read_all");

        // Protocol §4.3 P10: `notification.read_all` is user-scoped and carries no row
        // identity at all. Every other mutation is addressed by server_id when the row has
        // one, and by client_id while it is still local-only.
        let (client_id, server_id) = if is_read_all {
            (None, None)
        } else if self.op == Op::Create || self.server_id.is_none() {
            (self.client_id.clone(), None)
        } else {
            (None, self.server_id)
        };

        WireMutation {
            seq: self.seq,
            idempotency_key: self.idempotency_key.clone(),
            op: self.op.wire_name().to_string(),
            entity: self.entity.wire_name().to_string(),
            client_id,
            server_id,
            action: self.action.clone(),
            scope: self.scope.clone(),
            base_sync_version: match self.op {
                Op::Update | Op::Delete => self.base_sync_version,
                _ => None,
            },
            changed_fields: match self.op {
                Op::Update => self.changed_fields.clone(),
                _ => None,
            },
            // §4.4 shows `occurred_at` on create/update/action; the delete example omits it.
            occurred_at: match self.op {
                Op::Delete => None,
                _ => Some(self.occurred_at.clone()),
            },
            payload: match self.op {
                Op::Delete => None,
                _ => self.payload.clone().filter(|p| !p.is_null()),
            },
        }
    }

    /// Approximate wire size, used to respect the batch byte ceiling.
    pub fn wire_bytes(&self) -> usize {
        serde_json::to_vec(&self.to_wire()).map(|v| v.len()).unwrap_or(0)
    }
}

/// Current UTC timestamp in the format the wire contract uses.
pub fn now_iso() -> String {
    Utc::now().to_rfc3339_opts(SecondsFormat::Millis, true)
}

/// Next monotonic sequence number.
fn next_seq(tx: &Transaction<'_>) -> Result<i64> {
    let seq: i64 = tx.query_row(
        "SELECT COALESCE(MAX(seq), 0) + 1 FROM outbox",
        [],
        |r| r.get(0),
    )?;
    Ok(seq)
}

/// The newest queued row for `(entity, client_id)`, if any.
fn latest_queued(
    tx: &Transaction<'_>,
    entity: Entity,
    client_id: &str,
) -> Result<Option<OutboxRow>> {
    let mut stmt = tx.prepare(
        "SELECT id, seq, idempotency_key, entity, op, action, scope, client_id, server_id,
                base_sync_version, changed_fields, payload, occurred_at, attempts, state
           FROM outbox
          WHERE entity = ?1 AND client_id = ?2 AND state = 'queued'
          ORDER BY seq DESC LIMIT 1",
    )?;
    let mut rows = stmt.query(rusqlite::params![entity.wire_name(), client_id])?;
    match rows.next()? {
        Some(row) => Ok(Some(row_from_sql(row)?)),
        None => Ok(None),
    }
}

fn row_from_sql(row: &rusqlite::Row<'_>) -> Result<OutboxRow> {
    let id: String = row.get(0)?;
    let entity: String = row.get(3)?;
    let op: String = row.get(4)?;
    let changed: Option<String> = row.get(10)?;
    let payload: Option<String> = row.get(11)?;
    Ok(OutboxRow {
        id: Uuid::parse_str(&id).map_err(|e| SyncError::Db(rusqlite::Error::ToSqlConversionFailure(Box::new(e))))?,
        seq: row.get(1)?,
        idempotency_key: row.get(2)?,
        entity: Entity::from_wire_name(&entity)
            .ok_or_else(|| SyncError::Protocol(format!("unknown entity {entity:?} in outbox")))?,
        op: Op::from_wire_name(&op)
            .ok_or_else(|| SyncError::Protocol(format!("unknown op {op:?} in outbox")))?,
        action: row.get(5)?,
        scope: row.get(6)?,
        client_id: row.get(7)?,
        server_id: row.get(8)?,
        base_sync_version: row.get(9)?,
        changed_fields: changed
            .as_deref()
            .map(serde_json::from_str)
            .transpose()
            .map_err(|e| SyncError::Protocol(format!("outbox changed_fields: {e}")))?,
        payload: payload
            .as_deref()
            .map(serde_json::from_str)
            .transpose()
            .map_err(|e| SyncError::Protocol(format!("outbox payload: {e}")))?,
        occurred_at: row.get(12)?,
        attempts: row.get(13)?,
        state: row.get(14)?,
    })
}

/// What [`enqueue`] did.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EnqueueOutcome {
    /// A new outbox row was written.
    Queued(Uuid),
    /// The mutation was folded into an existing queued row (`SYNCDESKTOP.md` §5.4).
    Coalesced(Uuid),
    /// A queued create was cancelled by a delete; nothing will be sent.
    Annihilated,
}

impl EnqueueOutcome {
    /// The outbox row this mutation now lives in, if it lives at all.
    pub fn outbox_id(self) -> Option<Uuid> {
        match self {
            EnqueueOutcome::Queued(id) | EnqueueOutcome::Coalesced(id) => Some(id),
            EnqueueOutcome::Annihilated => None,
        }
    }
}

/// Queue one mutation, applying the coalescing rules of `SYNCDESKTOP.md` §5.4.
///
/// * consecutive `update`s on the same row merge into one (`changed_fields` unioned, last
///   payload wins);
/// * an `update` after a `create` folds into the create;
/// * a `delete` after a `create` removes both — the row never existed for the server.
///
/// `base_sync_version` is captured from the mirror row at enqueue time and preserved
/// through coalescing, because it describes the server state the *first* edit was based on.
pub fn enqueue(
    tx: &Transaction<'_>,
    mutation: &LocalMutation,
    server_id: Option<i64>,
    base_sync_version: Option<i64>,
) -> Result<EnqueueOutcome> {
    let entity = mutation.entity;
    let client_id = mutation.client_id.map(|u| u.to_string());
    let occurred_at = now_iso();

    let is_read_all =
        entity == Entity::Notification && mutation.action.as_deref() == Some("read_all");
    if client_id.is_none() && !is_read_all {
        return Err(SyncError::Validation(
            "only notification.read_all may omit client_id (protocol §4.3 P10)".into(),
        ));
    }

    if let (Some(cid), false) = (client_id.as_deref(), mutation.op == Op::Action) {
        if let Some(existing) = latest_queued(tx, entity, cid)? {
            match (existing.op, mutation.op) {
                // create + delete -> both vanish
                (Op::Create, Op::Delete) => {
                    tx.execute(
                        "DELETE FROM outbox WHERE id = ?1",
                        [existing.id.to_string()],
                    )?;
                    return Ok(EnqueueOutcome::Annihilated);
                }
                // create + update -> folded into the create
                (Op::Create, Op::Update) => {
                    let merged = merge_payload(existing.payload.clone(), &mutation.payload);
                    tx.execute(
                        "UPDATE outbox SET payload = ?1, occurred_at = ?2 WHERE id = ?3",
                        rusqlite::params![
                            merged.to_string(),
                            occurred_at,
                            existing.id.to_string()
                        ],
                    )?;
                    return Ok(EnqueueOutcome::Coalesced(existing.id));
                }
                // update + update -> one update
                (Op::Update, Op::Update) => {
                    let merged = merge_payload(existing.payload.clone(), &mutation.payload);
                    let fields = union_fields(
                        existing.changed_fields.as_deref(),
                        mutation.changed_fields.as_deref(),
                    );
                    tx.execute(
                        "UPDATE outbox SET payload = ?1, changed_fields = ?2, occurred_at = ?3
                          WHERE id = ?4",
                        rusqlite::params![
                            merged.to_string(),
                            serde_json::to_string(&fields)?,
                            occurred_at,
                            existing.id.to_string()
                        ],
                    )?;
                    return Ok(EnqueueOutcome::Coalesced(existing.id));
                }
                _ => {}
            }
        }
    }

    let id = Uuid::now_v7();
    let seq = next_seq(tx)?;
    let idempotency_key = Uuid::now_v7().to_string();

    tx.execute(
        "INSERT INTO outbox(id, seq, idempotency_key, entity, op, action, scope, client_id,
                            server_id, base_sync_version, changed_fields, payload, occurred_at,
                            attempts, state)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, 0, 'queued')",
        rusqlite::params![
            id.to_string(),
            seq,
            idempotency_key,
            entity.wire_name(),
            mutation.op.wire_name(),
            mutation.action.as_deref(),
            if is_read_all { Some("user") } else { None },
            client_id.as_deref(),
            server_id,
            base_sync_version,
            mutation
                .changed_fields
                .as_ref()
                .map(serde_json::to_string)
                .transpose()?,
            if mutation.payload.is_null() {
                None
            } else {
                Some(mutation.payload.to_string())
            },
            occurred_at,
        ],
    )?;

    Ok(EnqueueOutcome::Queued(id))
}

fn merge_payload(base: Option<Json>, overlay: &Json) -> Json {
    let mut merged = match base {
        Some(Json::Object(map)) => map,
        _ => serde_json::Map::new(),
    };
    if let Json::Object(over) = overlay {
        for (k, v) in over {
            merged.insert(k.clone(), v.clone());
        }
    }
    Json::Object(merged)
}

fn union_fields(a: Option<&[String]>, b: Option<&[String]>) -> Vec<String> {
    let mut out: Vec<String> = a.unwrap_or(&[]).to_vec();
    for field in b.unwrap_or(&[]) {
        if !out.iter().any(|f| f == field) {
            out.push(field.clone());
        }
    }
    out
}

/// Read every queued mutation, already in push order.
pub fn queued_in_push_order(conn: &Connection) -> Result<Vec<OutboxRow>> {
    let mut stmt = conn.prepare(
        "SELECT id, seq, idempotency_key, entity, op, action, scope, client_id, server_id,
                base_sync_version, changed_fields, payload, occurred_at, attempts, state
           FROM outbox WHERE state = 'queued'",
    )?;
    let mut rows = stmt.query([])?;
    let mut out = Vec::new();
    while let Some(row) = rows.next()? {
        out.push(row_from_sql(row)?);
    }
    out.sort_by_key(|r| r.sort_key());
    Ok(out)
}

/// Split queued mutations into batches within the server's batch and byte ceilings.
pub fn batches(rows: Vec<OutboxRow>, max_count: u32, max_bytes: u64) -> Vec<Vec<OutboxRow>> {
    let mut batches = Vec::new();
    let mut current: Vec<OutboxRow> = Vec::new();
    let mut bytes: u64 = 0;
    for row in rows {
        let size = row.wire_bytes() as u64;
        let would_overflow = !current.is_empty()
            && (current.len() as u32 >= max_count || bytes + size > max_bytes);
        if would_overflow {
            batches.push(std::mem::take(&mut current));
            bytes = 0;
        }
        bytes += size;
        current.push(row);
    }
    if !current.is_empty() {
        batches.push(current);
    }
    batches
}

/// Mark rows as in flight.
pub fn mark_inflight(tx: &Transaction<'_>, ids: &[Uuid]) -> Result<()> {
    for id in ids {
        tx.execute(
            "UPDATE outbox SET state = 'inflight' WHERE id = ?1",
            [id.to_string()],
        )?;
    }
    Ok(())
}

/// Return rows to the queue **without** touching `attempts`.
///
/// This is the protocol §4.3 P10b path: a mutation whose `seq` did not appear in the
/// server's `results` array was never processed, so it must not be penalised.
pub fn requeue_untouched(tx: &Transaction<'_>, ids: &[Uuid]) -> Result<()> {
    for id in ids {
        tx.execute(
            "UPDATE outbox SET state = 'queued' WHERE id = ?1",
            [id.to_string()],
        )?;
    }
    Ok(())
}

/// Return rows to the queue and count the failed attempt (transport/5xx failures).
pub fn requeue_with_attempt(tx: &Transaction<'_>, ids: &[Uuid], error: &str) -> Result<()> {
    for id in ids {
        tx.execute(
            "UPDATE outbox SET state = 'queued', attempts = attempts + 1, last_error = ?2
              WHERE id = ?1",
            rusqlite::params![id.to_string(), error],
        )?;
    }
    Ok(())
}

/// Mark a mutation as terminally failed; it now lives in the Conflict Inbox.
pub fn mark_failed(tx: &Transaction<'_>, id: Uuid, error: &str) -> Result<()> {
    tx.execute(
        "UPDATE outbox SET state = 'failed', last_error = ?2, attempts = attempts + 1
          WHERE id = ?1",
        rusqlite::params![id.to_string(), error],
    )?;
    Ok(())
}

/// Drop a mutation that has been accepted (or superseded).
pub fn remove(tx: &Transaction<'_>, id: Uuid) -> Result<()> {
    tx.execute("DELETE FROM outbox WHERE id = ?1", [id.to_string()])?;
    Ok(())
}

/// Number of mutations still waiting to reach the server.
pub fn pending_count(conn: &Connection) -> Result<u32> {
    let count: i64 = conn.query_row(
        "SELECT count(*) FROM outbox WHERE state IN ('queued', 'inflight')",
        [],
        |r| r.get(0),
    )?;
    Ok(count as u32)
}

/// Total outbox size, including failures parked in the Conflict Inbox.
pub fn total_count(conn: &Connection) -> Result<u32> {
    let count: i64 = conn.query_row("SELECT count(*) FROM outbox", [], |r| r.get(0))?;
    Ok(count as u32)
}

/// Look up one outbox row by id.
pub fn find(conn: &Connection, id: Uuid) -> Result<Option<OutboxRow>> {
    let mut stmt = conn.prepare(
        "SELECT id, seq, idempotency_key, entity, op, action, scope, client_id, server_id,
                base_sync_version, changed_fields, payload, occurred_at, attempts, state
           FROM outbox WHERE id = ?1",
    )?;
    let mut rows = stmt.query([id.to_string()])?;
    match rows.next()? {
        Some(row) => Ok(Some(row_from_sql(row)?)),
        None => Ok(None),
    }
}

/// Restore every in-flight row to `queued`.
///
/// Called on open: a process that died mid-push left rows marked `inflight` with no request
/// in the air. `idempotency_key` makes the resend safe.
pub fn recover_inflight(conn: &Connection) -> Result<usize> {
    let n = conn.execute("UPDATE outbox SET state = 'queued' WHERE state = 'inflight'", [])?;
    Ok(n)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(entity: Entity, op: Op, seq: i64) -> OutboxRow {
        OutboxRow {
            id: Uuid::now_v7(),
            seq,
            idempotency_key: seq.to_string(),
            entity,
            op,
            action: if op == Op::Action {
                Some("move".into())
            } else {
                None
            },
            scope: None,
            client_id: Some(Uuid::now_v7().to_string()),
            server_id: Some(1),
            base_sync_version: Some(1),
            changed_fields: None,
            payload: Some(serde_json::json!({"a": 1})),
            occurred_at: now_iso(),
            attempts: 0,
            state: "queued".into(),
        }
    }

    #[test]
    fn companies_sort_before_deals_and_actions_come_last() {
        let mut rows = [
            row(Entity::Deal, Op::Action, 1),
            row(Entity::Deal, Op::Create, 2),
            row(Entity::Company, Op::Create, 3),
            row(Entity::Contact, Op::Create, 4),
        ];
        rows.sort_by_key(|r| r.sort_key());
        let order: Vec<_> = rows.iter().map(|r| (r.entity, r.op)).collect();
        assert_eq!(
            order,
            [
                (Entity::Company, Op::Create),
                (Entity::Contact, Op::Create),
                (Entity::Deal, Op::Create),
                (Entity::Deal, Op::Action),
            ]
        );
    }

    #[test]
    fn ops_of_one_entity_order_create_update_action_delete() {
        let mut rows = [
            row(Entity::Deal, Op::Delete, 1),
            row(Entity::Deal, Op::Action, 2),
            row(Entity::Deal, Op::Update, 3),
            row(Entity::Deal, Op::Create, 4),
        ];
        rows.sort_by_key(|r| r.sort_key());
        let order: Vec<_> = rows.iter().map(|r| r.op).collect();
        assert_eq!(order, [Op::Create, Op::Update, Op::Delete, Op::Action]);
    }

    #[test]
    fn batches_respect_both_ceilings() {
        let rows: Vec<OutboxRow> = (0..7).map(|i| row(Entity::Deal, Op::Create, i)).collect();
        let by_count = batches(rows.clone(), 3, u64::MAX);
        assert_eq!(by_count.iter().map(|b| b.len()).collect::<Vec<_>>(), vec![3, 3, 1]);

        let one = rows[0].wire_bytes() as u64;
        let by_bytes = batches(rows, 200, one * 2);
        assert!(by_bytes.iter().all(|b| b.len() <= 2));
    }

    #[test]
    fn delete_wire_shape_omits_payload_and_occurred_at() {
        let mut r = row(Entity::Task, Op::Delete, 1);
        r.server_id = Some(991);
        let wire = r.to_wire();
        assert_eq!(wire.op, "delete");
        assert_eq!(wire.server_id, Some(991));
        assert_eq!(wire.base_sync_version, Some(1));
        assert!(wire.payload.is_none());
        assert!(wire.occurred_at.is_none());
    }

    #[test]
    fn local_only_rows_are_addressed_by_client_id() {
        let mut r = row(Entity::Deal, Op::Update, 1);
        r.server_id = None;
        let wire = r.to_wire();
        assert!(wire.client_id.is_some());
        assert!(wire.server_id.is_none());
    }

    #[test]
    fn merge_payload_lets_the_later_write_win() {
        let merged = merge_payload(
            Some(serde_json::json!({"a": 1, "b": 2})),
            &serde_json::json!({"b": 3, "c": 4}),
        );
        assert_eq!(merged, serde_json::json!({"a": 1, "b": 3, "c": 4}));
    }

    #[test]
    fn union_fields_deduplicates() {
        let out = union_fields(
            Some(&["title".to_string(), "amount".to_string()]),
            Some(&["amount".to_string(), "status".to_string()]),
        );
        assert_eq!(out, vec!["title", "amount", "status"]);
    }
}
