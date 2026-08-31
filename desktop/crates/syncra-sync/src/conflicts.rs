//! The Conflict Inbox.
//!
//! `SYNCDESKTOP.md` K6 forbids silent overwrites: anything the server did not accept
//! outright lands here with both sides of the story, and the user decides. That covers
//! `conflict` results (field-level, or record-level for the entities protocol §5.1 leaves
//! out of `activity_log`) and terminal `rejected` results such as `ONLINE_ONLY` or
//! `UNRESOLVED_REFERENCE`.

use crate::error::{Result, SyncError};
use crate::types::{Conflict, Entity};
use chrono::{DateTime, Utc};
use rusqlite::{Connection, Transaction};
use serde_json::Value as Json;
use uuid::Uuid;

/// Store one conflict and return its id.
#[allow(clippy::too_many_arguments)]
pub fn record(
    tx: &Transaction<'_>,
    outbox_id: Option<Uuid>,
    entity: Entity,
    client_id: Option<&str>,
    code: &str,
    conflicting_fields: &[String],
    mine: &Json,
    theirs: &Json,
) -> Result<Uuid> {
    let id = Uuid::now_v7();
    tx.execute(
        "INSERT INTO conflicts(id, outbox_id, entity, client_id, code, conflicting_fields,
                               mine, theirs, created_at)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9)",
        rusqlite::params![
            id.to_string(),
            outbox_id.map(|u| u.to_string()),
            entity.wire_name(),
            client_id,
            code,
            serde_json::to_string(conflicting_fields)?,
            mine.to_string(),
            theirs.to_string(),
            Utc::now().to_rfc3339(),
        ],
    )?;
    Ok(id)
}

/// Every unresolved conflict, oldest first.
pub fn list(conn: &Connection) -> Result<Vec<Conflict>> {
    let mut stmt = conn.prepare(
        "SELECT id, outbox_id, entity, client_id, code, conflicting_fields, mine, theirs, created_at
           FROM conflicts ORDER BY created_at ASC, id ASC",
    )?;
    let mut rows = stmt.query([])?;
    let mut out = Vec::new();
    while let Some(row) = rows.next()? {
        out.push(from_sql(row)?);
    }
    Ok(out)
}

/// One conflict by id.
pub fn find(conn: &Connection, id: Uuid) -> Result<Option<Conflict>> {
    let mut stmt = conn.prepare(
        "SELECT id, outbox_id, entity, client_id, code, conflicting_fields, mine, theirs, created_at
           FROM conflicts WHERE id = ?1",
    )?;
    let mut rows = stmt.query([id.to_string()])?;
    match rows.next()? {
        Some(row) => Ok(Some(from_sql(row)?)),
        None => Ok(None),
    }
}

/// Remove a settled conflict.
pub fn remove(tx: &Transaction<'_>, id: Uuid) -> Result<()> {
    tx.execute("DELETE FROM conflicts WHERE id = ?1", [id.to_string()])?;
    Ok(())
}

/// How many conflicts are open.
pub fn count(conn: &Connection) -> Result<u32> {
    let n: i64 = conn.query_row("SELECT count(*) FROM conflicts", [], |r| r.get(0))?;
    Ok(n as u32)
}

fn from_sql(row: &rusqlite::Row<'_>) -> Result<Conflict> {
    let id: String = row.get(0)?;
    let outbox_id: Option<String> = row.get(1)?;
    let entity: String = row.get(2)?;
    let client_id: Option<String> = row.get(3)?;
    let fields: String = row.get(5)?;
    let mine: String = row.get(6)?;
    let theirs: String = row.get(7)?;
    let created_at: String = row.get(8)?;

    Ok(Conflict {
        id: parse_uuid(&id)?,
        outbox_id: outbox_id.as_deref().map(parse_uuid).transpose()?,
        entity: Entity::from_wire_name(&entity)
            .ok_or_else(|| SyncError::Protocol(format!("unknown entity {entity:?}")))?,
        client_id: client_id.as_deref().map(parse_uuid).transpose()?,
        code: row.get(4)?,
        conflicting_fields: serde_json::from_str(&fields).unwrap_or_default(),
        mine: serde_json::from_str(&mine).unwrap_or(Json::Null),
        theirs: serde_json::from_str(&theirs).unwrap_or(Json::Null),
        created_at: created_at
            .parse::<DateTime<Utc>>()
            .unwrap_or_else(|_| Utc::now()),
    })
}

fn parse_uuid(value: &str) -> Result<Uuid> {
    Uuid::parse_str(value).map_err(|e| SyncError::Validation(format!("bad uuid {value:?}: {e}")))
}

/// The server row that a pull parked while local edits were still pending
/// (`SYNCDESKTOP.md` §5.5).
pub fn take_shadow(
    tx: &Transaction<'_>,
    entity: Entity,
    client_id: &str,
) -> Result<Option<(Json, i64)>> {
    let mut stmt = tx.prepare(
        "SELECT row_json, server_sync_version FROM pending_shadows
          WHERE entity = ?1 AND client_id = ?2",
    )?;
    let mut rows = stmt.query(rusqlite::params![entity.wire_name(), client_id])?;
    let found = match rows.next()? {
        Some(row) => {
            let json: String = row.get(0)?;
            let version: i64 = row.get(1)?;
            Some((serde_json::from_str(&json).unwrap_or(Json::Null), version))
        }
        None => None,
    };
    drop(rows);
    drop(stmt);
    if found.is_some() {
        tx.execute(
            "DELETE FROM pending_shadows WHERE entity = ?1 AND client_id = ?2",
            rusqlite::params![entity.wire_name(), client_id],
        )?;
    }
    Ok(found)
}
