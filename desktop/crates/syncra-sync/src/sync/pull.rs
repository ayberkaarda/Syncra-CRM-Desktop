//! The pull half of a sync round.
//!
//! Like [`super::push`], everything here is synchronous and takes a locked connection; the
//! HTTP call itself lives in the engine.

use crate::db::{self, schema, upsert};
use crate::error::Result;
use crate::protocol::{PullRequest, PullResponse};
use crate::types::Entity;
use rusqlite::Connection;
use std::collections::{BTreeMap, HashSet};

/// What one pull response wrote.
#[derive(Debug, Clone, Default)]
pub struct PullOutcome {
    /// Full rows at or beyond the cursor.
    pub rows: u64,
    /// Server rows parked because the local row still had unpushed edits.
    pub shadowed: u64,
    /// Tombstones applied.
    pub deletions: u64,
    /// Tables whose contents moved.
    pub tables_changed: Vec<Entity>,
    /// Tables the server says have more data behind the cursor.
    pub incomplete: Vec<Entity>,
}

/// Build the request for the tables the manifest granted.
///
/// `window_days` is only meaningful while a cursor is 0: `SYNCDESKTOP.md` §4.4 applies the
/// retention window to bootstrap and no filter at all to deltas, and K12 is what keeps a
/// first sync from dragging the entire history onto the disk.
///
/// `None` therefore means "this is a delta" and the field is left out of the body entirely —
/// the server rejects `window_days: 0` with a 422 (`min:1`), which is what made every delta
/// pull fail against the real backend (AUTH-1 U3).
pub fn build_request(
    conn: &Connection,
    entities: &[Entity],
    limit: u32,
    window_days: Option<u32>,
) -> Result<PullRequest> {
    let mut cursors = BTreeMap::new();
    for entity in entities {
        cursors.insert(entity.table().to_string(), db::get_cursor(conn, *entity)?);
    }
    Ok(PullRequest {
        cursors,
        limit,
        window_days,
    })
}

/// Write a pull response into the mirror.
pub fn apply(conn: &Connection, response: &PullResponse) -> Result<PullOutcome> {
    let mut outcome = PullOutcome::default();
    let mut changed: HashSet<Entity> = HashSet::new();
    let mut column_cache = upsert::ColumnCache::new();

    // Resolve the column lists before the transaction so `PRAGMA table_info` never runs
    // inside it.
    let mut columns: BTreeMap<Entity, Vec<String>> = BTreeMap::new();
    for table_name in response.tables.keys() {
        if let Some(entity) = Entity::from_table(table_name) {
            columns.insert(entity, column_cache.get(conn, entity)?.to_vec());
        }
    }

    let tx = conn.unchecked_transaction()?;
    let mut id_map = upsert::IdMap::new();

    // Parents before children, so a child's foreign key finds its target already stored.
    for spec in schema::TABLES {
        let Some(table) = response.tables.get(spec.entity.table()) else {
            continue;
        };
        let Some(cols) = columns.get(&spec.entity) else {
            continue;
        };

        for row in &table.rows {
            match upsert::upsert_row(&tx, spec, row, &mut id_map, cols)? {
                upsert::UpsertOutcome::Written => outcome.rows += 1,
                upsert::UpsertOutcome::Shadowed => outcome.shadowed += 1,
            }
            changed.insert(spec.entity);
        }

        for deletion in &table.deletions {
            upsert::apply_deletion(&tx, spec.entity, &deletion.row_key)?;
            outcome.deletions += 1;
            changed.insert(spec.entity);
        }

        if let Some(next) = table.next_cursor {
            db::set_cursor(&tx, spec.entity, next)?;
        }
        if table.has_more {
            outcome.incomplete.push(spec.entity);
        }
    }

    tx.commit()?;

    let mut tables: Vec<Entity> = changed.into_iter().collect();
    tables.sort();
    outcome.tables_changed = tables;
    Ok(outcome)
}

/// Tables the manifest granted, in pull order.
///
/// A table the user has no `.view` permission for is simply absent from the manifest
/// (§4.1), so it is never asked for.
pub fn granted_entities(manifest: &crate::protocol::Manifest) -> Vec<Entity> {
    schema::TABLES
        .iter()
        .map(|s| s.entity)
        .filter(|e| manifest.tables.contains_key(e.table()))
        .collect()
}
