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
    /// Tombstones applied; one this build had to skip is not among them.
    pub deletions: u64,
    /// Tables whose contents moved.
    pub tables_changed: Vec<Entity>,
    /// Tables the server says have more data behind the cursor.
    pub incomplete: Vec<Entity>,
}

/// Build the request for the tables the manifest granted.
///
/// `entities` has to be **every** granted table, not just the ones still draining: the server
/// visits its whole scope on every pull and reads a cursor missing from the body as 0, so
/// leaving a finished table out sends it again from the start (protocol §4.2).
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
                upsert::UpsertOutcome::Stale => continue,
            }
            changed.insert(spec.entity);
        }

        for deletion in &table.deletions {
            match upsert::apply_deletion(&tx, spec.entity, &deletion.row_key)? {
                upsert::TombstoneOutcome::Applied => outcome.deletions += 1,
                upsert::TombstoneOutcome::Skipped => continue,
            }
            changed.insert(spec.entity);
        }

        // Forward only: a lower cursor can only come from a server restored from a backup or
        // one reading a cursor as missing. [`SyncEngine::download_archive`] is the one
        // deliberate reset, and it goes through `db::set_cursor` itself.
        if let Some(next) = table.next_cursor {
            if next > db::get_cursor(&tx, spec.entity)? {
                db::set_cursor(&tx, spec.entity, next)?;
            }
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

/// Which of `wanted` the next request still has to serve.
///
/// A table with `has_more` is the obvious case. The other is a table missing from a response
/// that says more is coming: the byte budget ran out before the server reached it, and the only
/// flag raised is on the table it visited last, which may be one nobody here is waiting for
/// (protocol §4.2). `has_more` is read off the response rather than [`PullOutcome::incomplete`]
/// because that last table may be one this build does not mirror.
pub fn still_owed(
    wanted: &[Entity],
    response: &PullResponse,
    outcome: &PullOutcome,
) -> Vec<Entity> {
    let cut_short = response.tables.values().any(|table| table.has_more);
    wanted
        .iter()
        .copied()
        .filter(|entity| {
            outcome.incomplete.contains(entity)
                || (cut_short && !response.tables.contains_key(entity.table()))
        })
        .collect()
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
