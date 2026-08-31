//! Writing pulled server rows into the local mirror.
//!
//! Two rules from the contract shape everything here:
//!
//! * `SYNCDESKTOP.md` §5.3 — a server row without a `client_id` (i.e. one created on the
//!   web) gets the deterministic `uuid5(namespace, "entity:server_id")` identity, and
//!   foreign keys arriving as server ids are resolved to local `client_id`s. Protocol
//!   §6.1 P12 exempts `notifications`, whose id already *is* a UUID.
//! * `SYNCDESKTOP.md` §5.5 — a pull must never overwrite fields that are still waiting in
//!   the outbox. When the local row is `pending`, the server row is parked in
//!   `pending_shadows` as "theirs" instead.

use super::schema::{self, TableSpec};
use super::{columns, json_to_sql};
use crate::error::{Result, SyncError};
use crate::types::{Entity, SyncState};
use rusqlite::{Connection, Transaction};
use serde_json::Value as Json;
use std::collections::HashMap;
use uuid::Uuid;

/// Outcome of upserting one pulled row.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum UpsertOutcome {
    /// The row was written.
    Written,
    /// The local row has unpushed edits; the server row went to `pending_shadows`.
    Shadowed,
}

/// Local identity of a pulled server row.
///
/// `notifications` is the documented exception (protocol §6.1 P12): its server id *is* the
/// client id, so there is nothing to derive and no `server_id` column to fill.
pub fn client_id_for(entity: Entity, row: &Json) -> Result<String> {
    if entity == Entity::Notification {
        return row
            .get("id")
            .and_then(|v| v.as_str())
            .map(|s| s.to_string())
            .ok_or_else(|| SyncError::Protocol("notification row without id".into()));
    }

    if let Some(cid) = row.get("client_id").and_then(|v| v.as_str()) {
        if !cid.is_empty() {
            return Ok(cid.to_string());
        }
    }

    let server_id = row
        .get("id")
        .and_then(|v| v.as_i64())
        .ok_or_else(|| SyncError::Protocol(format!("{entity} row without id")))?;
    Ok(schema::derive_client_id(entity, server_id).to_string())
}

/// Cache of `server_id -> client_id` lookups, so a pull batch does not re-query per row.
#[derive(Debug, Default)]
pub struct IdMap {
    entries: HashMap<(Entity, i64), String>,
}

impl IdMap {
    /// New.
    pub fn new() -> Self {
        Self::default()
    }

    /// Resolve a foreign key's server id to a local `client_id`.
    ///
    /// A row created on this device keeps its random `client_id`, so the local table is
    /// consulted first; only when the target is unknown locally does the deterministic
    /// derivation apply. Both branches produce the same answer for web-created rows.
    pub fn resolve(&mut self, tx: &Transaction<'_>, target: Entity, server_id: i64) -> Result<String> {
        if let Some(found) = self.entries.get(&(target, server_id)) {
            return Ok(found.clone());
        }
        let resolved = lookup_client_id(tx, target, server_id)?
            .unwrap_or_else(|| schema::derive_client_id(target, server_id).to_string());
        self.entries.insert((target, server_id), resolved.clone());
        Ok(resolved)
    }
}

/// One-shot version of [`IdMap::resolve`], for callers that have no batch to amortise over.
pub fn client_id_for_fk(tx: &Transaction<'_>, target: Entity, server_id: i64) -> Result<String> {
    Ok(lookup_client_id(tx, target, server_id)?
        .unwrap_or_else(|| schema::derive_client_id(target, server_id).to_string()))
}

fn lookup_client_id(tx: &Transaction<'_>, target: Entity, server_id: i64) -> Result<Option<String>> {
    if !schema::spec_for(target).has_server_id {
        return Ok(None);
    }
    let sql = format!(
        "SELECT client_id FROM {} WHERE server_id = ?1",
        target.table()
    );
    let mut stmt = tx.prepare_cached(&sql)?;
    let mut rows = stmt.query([server_id])?;
    match rows.next()? {
        Some(row) => Ok(Some(row.get(0)?)),
        None => Ok(None),
    }
}

/// Insert or update one pulled row.
pub fn upsert_row(
    tx: &Transaction<'_>,
    spec: &TableSpec,
    row: &Json,
    id_map: &mut IdMap,
    table_columns: &[String],
) -> Result<UpsertOutcome> {
    let entity = spec.entity;
    let obj = row
        .as_object()
        .ok_or_else(|| SyncError::Protocol(format!("{entity} row is not an object")))?;

    let client_id = client_id_for(entity, row)?;
    let server_version = obj
        .get("sync_version")
        .and_then(|v| v.as_i64())
        .unwrap_or_default();

    // §5.5: never clobber unpushed local edits.
    if let Some(state) = current_state(tx, entity, &client_id)? {
        if state == "pending" || state == "conflict" {
            tx.execute(
                "INSERT INTO pending_shadows(entity, client_id, row_json, server_sync_version)
                 VALUES (?1, ?2, ?3, ?4)
                 ON CONFLICT(entity, client_id) DO UPDATE SET
                   row_json = excluded.row_json,
                   server_sync_version = excluded.server_sync_version",
                rusqlite::params![
                    entity.wire_name(),
                    &client_id,
                    row.to_string(),
                    server_version
                ],
            )?;
            return Ok(UpsertOutcome::Shadowed);
        }
    }

    let mut cols: Vec<String> = vec!["client_id".to_string()];
    let mut vals: Vec<rusqlite::types::Value> =
        vec![rusqlite::types::Value::Text(client_id.clone())];

    if spec.has_server_id {
        if let Some(server_id) = obj.get("id").and_then(|v| v.as_i64()) {
            cols.push("server_id".to_string());
            vals.push(rusqlite::types::Value::Integer(server_id));
        }
    }

    for (key, value) in obj {
        // `id`, `client_id` and `sync_version` are envelope fields, handled above.
        if key == "id" || key == "client_id" || key == "sync_version" {
            continue;
        }
        let column = spec
            .aliases
            .iter()
            .find(|(server, _)| server == key)
            .map(|(_, local)| (*local).to_string())
            .unwrap_or_else(|| key.clone());
        if !table_columns.contains(&column) {
            // The server is free to send columns this build does not mirror
            // (users is a fixed projection, and additive server columns are expected).
            continue;
        }
        cols.push(column);
        vals.push(json_to_sql(value));
    }

    // Resolve foreign keys to local references.
    for fk in spec.fks {
        let server_id = obj.get(fk.server_col).and_then(|v| v.as_i64());
        cols.push(fk.client_col.to_string());
        match server_id {
            Some(id) => {
                let resolved = id_map.resolve(tx, fk.target, id)?;
                vals.push(rusqlite::types::Value::Text(resolved));
            }
            None => vals.push(rusqlite::types::Value::Null),
        }
    }

    let deleted = obj
        .get("deleted_at")
        .map(|v| !v.is_null())
        .unwrap_or(false);
    cols.push("sync_state".to_string());
    vals.push(rusqlite::types::Value::Text(
        if deleted {
            SyncState::Tombstone
        } else {
            SyncState::Synced
        }
        .as_str()
        .to_string(),
    ));

    cols.push("server_sync_version".to_string());
    vals.push(rusqlite::types::Value::Integer(server_version));

    let placeholders: Vec<String> = (1..=cols.len()).map(|i| format!("?{i}")).collect();
    let assignments: Vec<String> = cols
        .iter()
        .filter(|c| c.as_str() != "client_id")
        .map(|c| format!("{c} = excluded.{c}"))
        .collect();

    let sql = format!(
        "INSERT INTO {table}({cols}) VALUES ({placeholders})
         ON CONFLICT(client_id) DO UPDATE SET {assignments}",
        table = entity.table(),
        cols = cols.join(", "),
        placeholders = placeholders.join(", "),
        assignments = assignments.join(", "),
    );

    let params: Vec<&dyn rusqlite::ToSql> =
        vals.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
    tx.execute(&sql, params.as_slice())?;

    Ok(UpsertOutcome::Written)
}

fn current_state(tx: &Transaction<'_>, entity: Entity, client_id: &str) -> Result<Option<String>> {
    let sql = format!(
        "SELECT sync_state FROM {} WHERE client_id = ?1",
        entity.table()
    );
    let mut stmt = tx.prepare_cached(&sql)?;
    let mut rows = stmt.query([client_id])?;
    match rows.next()? {
        Some(row) => Ok(Some(row.get(0)?)),
        None => Ok(None),
    }
}

/// Apply one `sync_deletions` tombstone.
///
/// Protocol §2.7 narrows the tombstone surface to exactly three tables. `tags` and
/// `notifications` are addressed by their primary key; `conversation_user` is addressed by
/// the logical key `conversation_id:user_id`, because a member who leaves and rejoins gets
/// a fresh surrogate id.
pub fn apply_deletion(tx: &Transaction<'_>, entity: Entity, row_key: &str) -> Result<bool> {
    let affected = match entity {
        Entity::ConversationUser => {
            let (conversation_id, user_id) = row_key.split_once(':').ok_or_else(|| {
                SyncError::Protocol(format!(
                    "conversation_user row_key must be conversation_id:user_id, got {row_key:?}"
                ))
            })?;
            let conversation_id: i64 = conversation_id.parse().map_err(|_| {
                SyncError::Protocol(format!("bad conversation_id in row_key {row_key:?}"))
            })?;
            let user_id: i64 = user_id
                .parse()
                .map_err(|_| SyncError::Protocol(format!("bad user_id in row_key {row_key:?}")))?;
            tx.execute(
                "DELETE FROM conversation_user WHERE conversation_id = ?1 AND user_id = ?2",
                rusqlite::params![conversation_id, user_id],
            )?
        }
        Entity::Notification => tx.execute(
            "DELETE FROM notifications WHERE client_id = ?1",
            [row_key],
        )?,
        Entity::Tag => {
            let server_id: i64 = row_key
                .parse()
                .map_err(|_| SyncError::Protocol(format!("bad tag row_key {row_key:?}")))?;
            tx.execute("DELETE FROM tags WHERE server_id = ?1", [server_id])?
        }
        other => {
            return Err(SyncError::Protocol(format!(
                "sync_deletions carries no tombstones for {other} (protocol §2.7)"
            )))
        }
    };
    Ok(affected > 0)
}

/// Tables that may appear in `sync_deletions` (protocol §2.7).
pub const TOMBSTONE_ENTITIES: &[Entity] =
    &[Entity::Tag, Entity::Notification, Entity::ConversationUser];

/// Cached column list per table, so an upsert loop does not re-run `PRAGMA table_info`.
#[derive(Debug, Default)]
pub struct ColumnCache {
    cache: HashMap<Entity, Vec<String>>,
}

impl ColumnCache {
    /// New.
    pub fn new() -> Self {
        Self::default()
    }

    /// Column names of `entity`, fetched once and reused.
    pub fn get(&mut self, conn: &Connection, entity: Entity) -> Result<&[String]> {
        if let std::collections::hash_map::Entry::Vacant(slot) = self.cache.entry(entity) {
            slot.insert(columns(conn, entity.table())?);
        }
        Ok(self.cache.get(&entity).expect("just inserted"))
    }
}

/// Parse a `client_id` column value into a UUID.
pub fn parse_uuid(value: &str) -> Result<Uuid> {
    Uuid::parse_str(value).map_err(|e| SyncError::Validation(format!("bad uuid {value:?}: {e}")))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::open;
    use serde_json::json;

    fn temp_conn() -> (tempfile::TempDir, Connection) {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("test.db");
        let conn = open(&path, &"a".repeat(64)).unwrap();
        (dir, conn)
    }

    /// The transport test (defter O2/O35, KARAR A26). Before migration
    /// `0002_ticket_sla_fields.sql` these four keys had no matching column and were silently
    /// dropped by the per-key loop above ("the server is free to send columns this build does
    /// not mirror") — the mapper could read the field correctly and it would still show
    /// null/0, because the value never survived the trip into SQLite. This shapes a pull row
    /// exactly like `SyncPullService::attachTicketSla()` does and proves it now round-trips
    /// through `upsert_row` into a plain `SELECT`.
    #[test]
    fn ticket_sla_fields_survive_the_upsert_round_trip() {
        let (_dir, conn) = temp_conn();
        let spec = schema::spec_for(Entity::Ticket);
        let table_columns = columns(&conn, "tickets").unwrap();

        let row = json!({
            "id": 501,
            "ticket_number": "T-0501",
            "subject": "Cannot log in",
            "priority": "high",
            "status": "open",
            "sla_due_at": "2026-09-01T00:00:00Z",
            "sla_paused_seconds": 0,
            "sla_remaining_seconds": 3600,
            "sla_total_seconds": 72000,
            "sla_target_hours": 20.0,
            "sla_breached": false,
            "sync_version": 1,
        });

        let tx = conn.unchecked_transaction().unwrap();
        let mut id_map = IdMap::new();
        upsert_row(&tx, spec, &row, &mut id_map, &table_columns).unwrap();
        tx.commit().unwrap();

        let mut stmt = conn
            .prepare(
                "SELECT sla_remaining_seconds, sla_total_seconds, sla_target_hours, sla_breached
                 FROM tickets WHERE server_id = 501",
            )
            .unwrap();
        let (remaining, total, hours, breached): (Option<i64>, Option<i64>, Option<f64>, Option<i64>) =
            stmt.query_row([], |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?)))
                .unwrap();

        assert_eq!(remaining, Some(3600));
        assert_eq!(total, Some(72000));
        assert_eq!(hours, Some(20.0));
        // false -> 0, same convention every other boolean column already uses (e.g.
        // contacts.is_primary) — see json_to_sql().
        assert_eq!(breached, Some(0));
    }

    /// The `null` half of the same round trip: a resolved ticket's `sla_remaining_seconds` is
    /// `null` on the wire (`SlaService::remainingSeconds()`), and that must survive as SQL
    /// `NULL`, not get coerced into `0` by `json_to_sql()` or dropped as "absent".
    #[test]
    fn a_null_sla_remaining_seconds_survives_as_sql_null_not_zero() {
        let (_dir, conn) = temp_conn();
        let spec = schema::spec_for(Entity::Ticket);
        let table_columns = columns(&conn, "tickets").unwrap();

        let row = json!({
            "id": 502,
            "ticket_number": "T-0502",
            "subject": "Resolved already",
            "priority": "urgent",
            "status": "resolved",
            "sla_due_at": "2026-08-25T00:00:00Z",
            "resolved_at": "2026-08-26T00:00:00Z",
            "sla_paused_seconds": 0,
            "sla_remaining_seconds": null,
            "sla_total_seconds": 14400,
            "sla_target_hours": 4.0,
            "sla_breached": true,
            "sync_version": 2,
        });

        let tx = conn.unchecked_transaction().unwrap();
        let mut id_map = IdMap::new();
        upsert_row(&tx, spec, &row, &mut id_map, &table_columns).unwrap();
        tx.commit().unwrap();

        let remaining: Option<i64> = conn
            .query_row(
                "SELECT sla_remaining_seconds FROM tickets WHERE server_id = 502",
                [],
                |r| r.get(0),
            )
            .unwrap();

        assert_eq!(remaining, None, "null must stay SQL NULL, never become 0");
    }

    /// The same transport test, for `pipeline_stages.name_key` (defter O7 follow-up). Same
    /// mechanism as the ticket SLA fields: `SyncPullService`'s `pipeline_stages` pull query is
    /// `SELECT *`, so `name_key` was always on the wire — it just had no column to land in
    /// until this migration.
    #[test]
    fn pipeline_stage_name_key_survives_the_upsert_round_trip() {
        let (_dir, conn) = temp_conn();
        let spec = schema::spec_for(Entity::PipelineStage);
        let table_columns = columns(&conn, "pipeline_stages").unwrap();

        let row = json!({
            "id": 9,
            "name": "Qualified",
            "name_key": "qualified",
            "slug": "qualified",
            "position": 2,
            "sync_version": 1,
        });

        let tx = conn.unchecked_transaction().unwrap();
        let mut id_map = IdMap::new();
        upsert_row(&tx, spec, &row, &mut id_map, &table_columns).unwrap();
        tx.commit().unwrap();

        let name_key: Option<String> = conn
            .query_row(
                "SELECT name_key FROM pipeline_stages WHERE server_id = 9",
                [],
                |r| r.get(0),
            )
            .unwrap();

        assert_eq!(name_key, Some("qualified".to_string()));
    }

    /// The `null` half: a custom, user-created stage has no taxonomy key on the server
    /// either — `null` must survive as SQL `NULL`, the same discipline as the SLA fields.
    #[test]
    fn a_null_pipeline_stage_name_key_survives_as_sql_null() {
        let (_dir, conn) = temp_conn();
        let spec = schema::spec_for(Entity::PipelineStage);
        let table_columns = columns(&conn, "pipeline_stages").unwrap();

        let row = json!({
            "id": 10,
            "name": "Vendor Review",
            "name_key": null,
            "slug": "vendor-review",
            "position": 3,
            "sync_version": 1,
        });

        let tx = conn.unchecked_transaction().unwrap();
        let mut id_map = IdMap::new();
        upsert_row(&tx, spec, &row, &mut id_map, &table_columns).unwrap();
        tx.commit().unwrap();

        let name_key: Option<String> = conn
            .query_row(
                "SELECT name_key FROM pipeline_stages WHERE server_id = 10",
                [],
                |r| r.get(0),
            )
            .unwrap();

        assert_eq!(name_key, None);
    }

    /// NEGATIVE CONTROL: the four SLA columns were added ON TOP of the "unmirrored key is
    /// dropped, not rejected" behaviour, not instead of it. A pull row carrying a field this
    /// build still has no column for (the server is free to add columns ahead of the client,
    /// per the comment on the loop above) must upsert cleanly and simply not carry that field
    /// locally — proving migration 0002 did not accidentally widen the mirror into an
    /// "accept anything" table or break the drop path for every OTHER unmirrored column.
    #[test]
    fn an_unmirrored_key_is_still_silently_dropped() {
        let (_dir, conn) = temp_conn();
        let spec = schema::spec_for(Entity::Ticket);
        let table_columns = columns(&conn, "tickets").unwrap();
        assert!(
            !table_columns.iter().any(|c| c == "totally_unmirrored_future_field"),
            "fixture bug: this column must not exist for the test to mean anything"
        );

        let row = json!({
            "id": 503,
            "ticket_number": "T-0503",
            "subject": "x",
            "priority": "high",
            "status": "open",
            "totally_unmirrored_future_field": "server can add this anytime",
            "sync_version": 1,
        });

        let tx = conn.unchecked_transaction().unwrap();
        let mut id_map = IdMap::new();
        // Must not error: an unrecognised column is dropped, never rejected.
        let outcome = upsert_row(&tx, spec, &row, &mut id_map, &table_columns).unwrap();
        tx.commit().unwrap();

        assert_eq!(outcome, UpsertOutcome::Written);

        let ticket_number: String = conn
            .query_row(
                "SELECT ticket_number FROM tickets WHERE server_id = 503",
                [],
                |r| r.get(0),
            )
            .unwrap();
        assert_eq!(ticket_number, "T-0503", "the rest of the row must still write normally");
    }
}
