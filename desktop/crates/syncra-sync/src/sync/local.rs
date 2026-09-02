//! Applying a [`LocalMutation`] to the local mirror.
//!
//! Every mutation is written to the mirror *and* the outbox in one transaction, so the UI
//! sees its own edit immediately (offline-first) and the engine never loses an edit it has
//! already shown.

use crate::db::{columns, json_to_sql};
use crate::error::{Result, SyncError};
use crate::outbox;
use crate::protocol::is_whitelisted_action;
use crate::types::{Entity, LocalMutation, Op, SyncState};
use rusqlite::Transaction;
use serde_json::Value as Json;

/// The mirror row's server identity and version, needed to build the wire mutation.
#[derive(Debug, Clone, Copy, Default)]
pub struct RowAnchor {
    /// Server primary key, once the row has been accepted.
    pub server_id: Option<i64>,
    /// Server version the local edit was based on.
    pub base_sync_version: Option<i64>,
}

/// Read the server anchor of a mirror row.
pub fn anchor(tx: &Transaction<'_>, entity: Entity, client_id: &str) -> Result<RowAnchor> {
    let spec = crate::db::schema::spec_for(entity);
    let sql = if spec.has_server_id {
        format!(
            "SELECT server_id, server_sync_version FROM {} WHERE client_id = ?1",
            entity.table()
        )
    } else {
        format!(
            "SELECT NULL, server_sync_version FROM {} WHERE client_id = ?1",
            entity.table()
        )
    };
    let mut stmt = tx.prepare(&sql)?;
    let mut rows = stmt.query([client_id])?;
    match rows.next()? {
        Some(row) => Ok(RowAnchor {
            server_id: row.get(0)?,
            base_sync_version: row.get(1)?,
        }),
        None => Ok(RowAnchor::default()),
    }
}

/// Validate a mutation against the contract before anything is written.
pub fn validate(mutation: &LocalMutation) -> Result<()> {
    if mutation.entity.default_mode() == crate::types::TableMode::Ro {
        return Err(SyncError::Validation(format!(
            "{} is read-only",
            mutation.entity
        )));
    }
    match mutation.op {
        Op::Update => {
            let fields = mutation.changed_fields.as_deref().unwrap_or(&[]);
            if fields.is_empty() {
                return Err(SyncError::Validation(
                    "update requires changed_fields".into(),
                ));
            }
            // §4.4: `changed_fields ⊆ payload keys`, and nothing outside them is written.
            let payload = mutation
                .payload
                .as_object()
                .ok_or_else(|| SyncError::Validation("update payload must be an object".into()))?;
            for field in fields {
                if !payload.contains_key(field) {
                    return Err(SyncError::Validation(format!(
                        "changed_fields lists {field:?} but the payload has no such key"
                    )));
                }
            }
        }
        Op::Action => {
            let action = mutation.action.as_deref().ok_or_else(|| {
                SyncError::Validation("action mutations need an action name".into())
            })?;
            if !is_whitelisted_action(mutation.entity.wire_name(), action) {
                return Err(SyncError::Validation(format!(
                    "{}.{action} is not in the action whitelist (ONLINE_ONLY)",
                    mutation.entity
                )));
            }
            let read_all = mutation.entity == Entity::Notification && action == "read_all";
            if !read_all && mutation.client_id.is_none() {
                return Err(SyncError::Validation(
                    "only notification.read_all may omit client_id".into(),
                ));
            }
        }
        Op::Create | Op::Delete => {
            if mutation.client_id.is_none() {
                return Err(SyncError::Validation("client_id is required".into()));
            }
        }
    }
    Ok(())
}

/// Apply the mutation to the mirror table.
pub fn apply(tx: &Transaction<'_>, mutation: &LocalMutation) -> Result<()> {
    let entity = mutation.entity;
    let now = outbox::now_iso();
    let cols = columns(tx, entity.table())?;

    match mutation.op {
        Op::Create => {
            let client_id = mutation.client_id.expect("validated").to_string();
            let mut names: Vec<String> = vec!["client_id".into()];
            let mut values: Vec<rusqlite::types::Value> =
                vec![rusqlite::types::Value::Text(client_id)];
            collect_payload(&mutation.payload, &cols, None, &mut names, &mut values);

            for (col, value) in [
                ("created_at", now.clone()),
                ("updated_at", now.clone()),
                ("local_updated_at", now.clone()),
            ] {
                if cols.iter().any(|c| c == col) && !names.iter().any(|n| n == col) {
                    names.push(col.to_string());
                    values.push(rusqlite::types::Value::Text(value));
                }
            }
            names.push("sync_state".into());
            values.push(rusqlite::types::Value::Text(
                SyncState::Pending.as_str().into(),
            ));

            let placeholders: Vec<String> = (1..=names.len()).map(|i| format!("?{i}")).collect();
            let assignments: Vec<String> = names
                .iter()
                .filter(|n| n.as_str() != "client_id")
                .map(|n| format!("{n} = excluded.{n}"))
                .collect();
            let sql = format!(
                "INSERT INTO {}({}) VALUES ({}) ON CONFLICT(client_id) DO UPDATE SET {}",
                entity.table(),
                names.join(", "),
                placeholders.join(", "),
                assignments.join(", ")
            );
            let params: Vec<&dyn rusqlite::ToSql> =
                values.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
            tx.execute(&sql, params.as_slice())?;
        }

        Op::Update => {
            let client_id = mutation.client_id.expect("validated").to_string();
            let allowed = mutation.changed_fields.clone().unwrap_or_default();
            let mut names = Vec::new();
            let mut values = Vec::new();
            collect_payload(
                &mutation.payload,
                &cols,
                Some(&allowed),
                &mut names,
                &mut values,
            );
            write_row(tx, entity, &client_id, names, values, &now, SyncState::Pending)?;
        }

        Op::Delete => {
            let client_id = mutation.client_id.expect("validated").to_string();
            tx.execute(
                &format!(
                    "UPDATE {} SET sync_state = 'tombstone', deleted_at = ?2, local_updated_at = ?2
                      WHERE client_id = ?1",
                    entity.table()
                ),
                rusqlite::params![client_id, now],
            )?;
        }

        Op::Action => {
            let action = mutation.action.as_deref().unwrap_or_default();
            if entity == Entity::Notification && action == "read_all" {
                tx.execute(
                    "UPDATE notifications SET read_at = ?1, sync_state = 'pending',
                            local_updated_at = ?1
                      WHERE read_at IS NULL",
                    [&now],
                )?;
                return Ok(());
            }

            let client_id = mutation.client_id.expect("validated").to_string();
            let mut names = Vec::new();
            let mut values = Vec::new();
            collect_payload(&mutation.payload, &cols, None, &mut names, &mut values);

            // Actions whose local effect is not simply "write the payload".
            match (entity, action) {
                (Entity::Task, "complete") => {
                    push(&mut names, &mut values, "status", Json::String("completed".into()));
                    push(&mut names, &mut values, "completed_at", Json::String(now.clone()));
                }
                (Entity::Notification, "read") => {
                    push(&mut names, &mut values, "read_at", Json::String(now.clone()));
                }
                (Entity::Conversation, "read") => {
                    // The per-member unread counter lives on conversation_user and is
                    // recomputed by the server (protocol §2.2); nothing to mirror here.
                }
                _ => {}
            }

            write_row(tx, entity, &client_id, names, values, &now, SyncState::Pending)?;
        }
    }

    Ok(())
}

fn push(
    names: &mut Vec<String>,
    values: &mut Vec<rusqlite::types::Value>,
    column: &str,
    value: Json,
) {
    if let Some(idx) = names.iter().position(|n| n == column) {
        values[idx] = json_to_sql(&value);
    } else {
        names.push(column.to_string());
        values.push(json_to_sql(&value));
    }
}

/// Copy payload keys that are real columns into an update/insert list.
///
/// `allowed` implements the `changed_fields` rule of §4.4: when it is `Some`, a payload key
/// outside it is silently *not* written, exactly as the server would refuse it.
fn collect_payload(
    payload: &Json,
    cols: &[String],
    allowed: Option<&[String]>,
    names: &mut Vec<String>,
    values: &mut Vec<rusqlite::types::Value>,
) {
    let Some(obj) = payload.as_object() else {
        return;
    };
    for (key, value) in obj {
        if !cols.iter().any(|c| c == key) {
            continue;
        }
        if matches!(
            key.as_str(),
            "client_id" | "server_id" | "sync_state" | "server_sync_version"
        ) {
            continue;
        }
        if let Some(allowed) = allowed {
            if !allowed.iter().any(|f| f == key) {
                continue;
            }
        }
        names.push(key.clone());
        values.push(json_to_sql(value));
    }
}

fn write_row(
    tx: &Transaction<'_>,
    entity: Entity,
    client_id: &str,
    mut names: Vec<String>,
    mut values: Vec<rusqlite::types::Value>,
    now: &str,
    state: SyncState,
) -> Result<()> {
    names.push("local_updated_at".into());
    values.push(rusqlite::types::Value::Text(now.to_string()));
    names.push("updated_at".into());
    values.push(rusqlite::types::Value::Text(now.to_string()));
    names.push("sync_state".into());
    values.push(rusqlite::types::Value::Text(state.as_str().to_string()));

    let assignments: Vec<String> = names
        .iter()
        .enumerate()
        .map(|(i, n)| format!("{n} = ?{}", i + 1))
        .collect();
    values.push(rusqlite::types::Value::Text(client_id.to_string()));

    let sql = format!(
        "UPDATE {} SET {} WHERE client_id = ?{}",
        entity.table(),
        assignments.join(", "),
        values.len()
    );
    let params: Vec<&dyn rusqlite::ToSql> =
        values.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
    tx.execute(&sql, params.as_slice())?;
    Ok(())
}

/// Move a mirror row to a new sync state.
pub fn set_state(
    tx: &Transaction<'_>,
    entity: Entity,
    client_id: &str,
    state: SyncState,
) -> Result<()> {
    tx.execute(
        &format!(
            "UPDATE {} SET sync_state = ?2 WHERE client_id = ?1",
            entity.table()
        ),
        rusqlite::params![client_id, state.as_str()],
    )?;
    Ok(())
}

/// Record the server identity a create was assigned.
pub fn set_server_identity(
    tx: &Transaction<'_>,
    entity: Entity,
    client_id: &str,
    server_id: Option<i64>,
    sync_version: Option<i64>,
) -> Result<()> {
    let spec = crate::db::schema::spec_for(entity);
    if spec.has_server_id {
        if let Some(server_id) = server_id {
            tx.execute(
                &format!(
                    "UPDATE {} SET server_id = ?2 WHERE client_id = ?1",
                    entity.table()
                ),
                rusqlite::params![client_id, server_id],
            )?;
        }
    }
    if let Some(version) = sync_version {
        tx.execute(
            &format!(
                "UPDATE {} SET server_sync_version = ?2 WHERE client_id = ?1",
                entity.table()
            ),
            rusqlite::params![client_id, version],
        )?;
    }
    Ok(())
}
