//! Retention and the disk ceiling (`SYNCDESKTOP.md` §5.6, K8).

use crate::config::SyncConfig;
use crate::db::schema;
use crate::error::{Result, SyncError};
use crate::types::{StorageStats, WriteBlockReason};
use chrono::{Duration, Utc};
use rusqlite::Connection;
use std::path::Path;

/// LRU ceiling for `cached_files`, in bytes (§5.6/3).
pub const CACHED_FILES_MAX_BYTES: u64 = 100 * 1024 * 1024;

/// Namespace for the deterministic id of a `cached_files` row.
///
/// A fixed, arbitrary v4 UUID, used exactly like [`crate::db::schema::CLIENT_ID_NAMESPACE`]:
/// it must never change, or every already-cached file would get a second identity the next
/// time it is recorded and the table would grow a duplicate row per file.
pub const CACHED_FILE_NAMESPACE: uuid::Uuid = uuid::uuid!("c1f6a2d8-9b3e-4a7c-8f21-5d0e6b4a9c33");

/// Deterministic identity of a cached file: `uuid5(namespace, "<kind>:<ref>")`.
///
/// The identity is the **logical reference**, not the path, because that is the only thing
/// the caller reliably knows on a cache *hit*: `commands::files::cache_quote_pdf` is handed a
/// quote id and a revision and has to be able to name the same row again later without
/// re-deriving a path spelling. Deriving it here rather than letting the caller invent an id
/// is what makes the second `record_cached_file` for the same file an update instead of a
/// second row.
pub fn cached_file_id(kind: &str, reference: &str) -> uuid::Uuid {
    let name = format!("{kind}:{reference}");
    uuid::Uuid::new_v5(&CACHED_FILE_NAMESPACE, name.as_bytes())
}

/// Insert (or refresh) the `cached_files` row for a file that is already on disk.
///
/// The caller writes the blob, then records it; this function never touches the file system
/// on the way in. `bytes` is the caller's measurement of that blob -- it is what
/// [`storage_stats`] sums and what [`trim_cached_files`] spends, so a caller that lies about
/// it moves the ceiling, not the file.
///
/// `path` must be absolute and valid UTF-8: [`trim_cached_files`] deletes it later with
/// `fs::remove_file`, and a relative path would resolve against whatever the process working
/// directory happens to be at eviction time -- which is not a location this crate has any
/// business deleting from.
///
/// Recording the same `(kind, reference)` twice updates the existing row in place (new path,
/// size and fetch time) instead of adding a second one; see [`cached_file_id`].
///
/// # The ceiling is enforced here, not only in the daily sweep (defter O69)
///
/// The last thing this does is run [`trim_cached_files`], so the invariant §5.6/3 asks for is
/// *"the ledger is never above the ceiling after a record"* rather than *"the ledger is under
/// the ceiling once a day"*. Before, the only caller of the trim was
/// [`crate::SyncEngine::run_retention`], which the engine schedules at most daily: a user who
/// downloaded forty quote PDFs in one afternoon sat above 100 MB until the next morning. The
/// trim is a cheap no-op below the ceiling (one `SUM(bytes)` and an early return), so paying
/// for it on every record costs a single aggregate over a table that holds a few hundred rows
/// at most.
///
/// A record that itself crosses the ceiling can evict *itself* — but only after everything
/// colder, because the row this just wrote carries `fetched_at = now` and the trim walks
/// `fetched_at ASC`. A single file larger than the whole ceiling is therefore recorded and
/// then immediately evicted, which is the correct answer: the ceiling is the promise, not the
/// file.
pub fn record_cached_file(
    conn: &Connection,
    kind: &str,
    reference: &str,
    path: &Path,
    bytes: u64,
) -> Result<uuid::Uuid> {
    if kind.trim().is_empty() {
        return Err(SyncError::Validation("cached file kind is empty".into()));
    }
    if reference.trim().is_empty() {
        return Err(SyncError::Validation("cached file reference is empty".into()));
    }
    if !path.is_absolute() {
        return Err(SyncError::Validation(format!(
            "cached file path must be absolute: {}",
            path.display()
        )));
    }
    let path_text = path.to_str().ok_or_else(|| {
        SyncError::Validation(format!("cached file path is not valid UTF-8: {}", path.display()))
    })?;

    let id = cached_file_id(kind, reference);
    conn.execute(
        "INSERT INTO cached_files(id, kind, ref, path, bytes, fetched_at)
         VALUES (?1, ?2, ?3, ?4, ?5, ?6)
         ON CONFLICT(id) DO UPDATE SET
           kind       = excluded.kind,
           ref        = excluded.ref,
           path       = excluded.path,
           bytes      = excluded.bytes,
           fetched_at = excluded.fetched_at",
        rusqlite::params![
            id.to_string(),
            kind,
            reference,
            path_text,
            bytes.min(i64::MAX as u64) as i64,
            Utc::now().to_rfc3339(),
        ],
    )?;
    // The ceiling is enforced on the spot, not only by the daily sweep (defter O69).
    trim_cached_files(conn)?;
    Ok(id)
}

/// Move a cached file to the head of the LRU queue.
///
/// Returns `false` when nothing is recorded under `(kind, reference)`, so a caller that
/// served a cache hit off a blob the table never learned about can tell the difference and
/// record it instead of silently keeping an unaccounted file on disk.
///
/// Without this, `fetched_at` only ever means "when it was first downloaded" and
/// [`trim_cached_files`] evicts the *oldest download* rather than the *least recently used*
/// file -- the ordering §5.6/3 asks for.
pub fn touch_cached_file(conn: &Connection, kind: &str, reference: &str) -> Result<bool> {
    let id = cached_file_id(kind, reference).to_string();
    let updated = conn.execute(
        "UPDATE cached_files SET fetched_at = ?2 WHERE id = ?1",
        rusqlite::params![id, Utc::now().to_rfc3339()],
    )?;
    Ok(updated > 0)
}

/// Percentage of the database ceiling that raises `StorageWarning` (§5.6).
pub const WARN_PERCENT: u32 = 80;

/// What one maintenance pass removed.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct RetentionReport {
    /// Tombstones deleted.
    pub tombstones_removed: u64,
    /// Synced rows that fell out of the retention window.
    pub stale_rows_removed: u64,
    /// Cached files evicted.
    pub cached_files_removed: u64,
}

/// Current storage accounting from `page_count * page_size` (§5.6).
pub fn storage_stats(conn: &Connection, cfg: &SyncConfig) -> Result<StorageStats> {
    let page_count = pragma_i64(conn, "page_count")?;
    let page_size = pragma_i64(conn, "page_size")?;
    let db_bytes = (page_count.max(0) as u64) * (page_size.max(0) as u64);

    let cached: i64 = conn.query_row(
        "SELECT COALESCE(SUM(bytes), 0) FROM cached_files",
        [],
        |r| r.get(0),
    )?;
    let outbox: i64 = conn.query_row("SELECT count(*) FROM outbox", [], |r| r.get(0))?;

    let max_db_bytes = cfg.max_db_bytes();
    let db_usage_percent = db_bytes
        .saturating_mul(100)
        .checked_div(max_db_bytes)
        .unwrap_or(0) as u32;

    Ok(StorageStats {
        db_bytes,
        max_db_bytes,
        cached_file_bytes: cached.max(0) as u64,
        outbox_count: outbox.max(0) as u32,
        max_outbox: cfg.max_outbox,
        db_usage_percent,
    })
}

/// Read a numeric pragma.
///
/// SQLCipher intercepts some SQLite pragmas and answers with a differently named, *text*
/// column -- `PRAGMA page_size` comes back as `cipher_page_size` on an encrypted
/// connection. Reading the raw value and coercing keeps this working on both plain SQLite
/// and SQLCipher builds.
fn pragma_i64(conn: &Connection, name: &str) -> Result<i64> {
    let value: rusqlite::types::Value =
        conn.query_row(&format!("PRAGMA {name}"), [], |r| r.get(0))?;
    Ok(match value {
        rusqlite::types::Value::Integer(i) => i,
        rusqlite::types::Value::Real(f) => f as i64,
        rusqlite::types::Value::Text(t) => t.trim().parse().unwrap_or(0),
        _ => 0,
    })
}

/// Whether local writes must be refused (§5.6).
pub fn write_block_reason(stats: &StorageStats) -> Option<WriteBlockReason> {
    if stats.db_usage_percent >= 100 {
        Some(WriteBlockReason::DiskFull)
    } else if stats.outbox_count >= stats.max_outbox {
        Some(WriteBlockReason::OutboxFull)
    } else {
        None
    }
}

/// One maintenance pass (`SYNCDESKTOP.md` §5.6).
///
/// 1. tombstones older than the retention window are deleted;
/// 2. `synced` rows whose `updated_at` fell out of the window are deleted **unless** an
///    outbox or conflict entry still refers to them — a pending mutation always wins;
/// 3. `cached_files` is trimmed to 100 MB, least recently fetched first — row **and**
///    blob (see [`trim_cached_files`]);
/// 4. freed pages are handed back with `PRAGMA incremental_vacuum`.
///
/// Child tables are visited before parents so a delete never orphans a row mid-pass.
///
/// A row whose timestamps are all NULL is **kept**: the window cannot be applied to a row
/// with no date, and treating "unknown" as "old" would silently delete freshly pulled rows
/// the server sent without timestamps.
pub fn run(conn: &Connection, cfg: &SyncConfig) -> Result<RetentionReport> {
    let cutoff = (Utc::now() - Duration::days(i64::from(cfg.retention_days))).to_rfc3339();
    let mut report = RetentionReport::default();

    let tx = conn.unchecked_transaction()?;

    // Reverse of the pull order: children first.
    for spec in schema::TABLES.iter().rev() {
        let table = spec.entity.table();
        let entity = spec.entity.wire_name();

        let removed = tx.execute(
            &format!(
                "DELETE FROM {table}
                  WHERE sync_state = 'tombstone'
                    AND COALESCE(deleted_at, updated_at, created_at) IS NOT NULL
                    AND COALESCE(deleted_at, updated_at, created_at) < ?1
                    AND client_id NOT IN (SELECT client_id FROM outbox WHERE client_id IS NOT NULL)
                    AND client_id NOT IN (SELECT client_id FROM conflicts WHERE client_id IS NOT NULL)"
            ),
            [&cutoff],
        )?;
        report.tombstones_removed += removed as u64;

        let removed = tx.execute(
            &format!(
                "DELETE FROM {table}
                  WHERE sync_state = 'synced'
                    AND COALESCE(updated_at, created_at) IS NOT NULL
                    AND COALESCE(updated_at, created_at) < ?1
                    AND client_id NOT IN (SELECT client_id FROM outbox WHERE client_id IS NOT NULL)
                    AND client_id NOT IN (SELECT client_id FROM conflicts WHERE client_id IS NOT NULL)
                    AND client_id NOT IN (SELECT client_id FROM pending_shadows WHERE entity = ?2)"
            ),
            rusqlite::params![&cutoff, entity],
        )?;
        report.stale_rows_removed += removed as u64;
    }

    tx.commit()?;

    // Deliberately *outside* the sweep transaction above. Evicting a cached file deletes a
    // blob from disk, which no transaction can roll back; running it inside `tx` would mean a
    // failed commit leaves the files gone and their rows alive — the exact table/disk
    // divergence this pass exists to avoid. `trim_cached_files` owns its own transaction.
    report.cached_files_removed += trim_cached_files(conn)? as u64;

    conn.pragma_update(None, "incremental_vacuum", 0)?;
    Ok(report)
}

/// Evict least-recently-fetched cached files until the store fits in 100 MB (§5.6/3).
///
/// Eviction is **row and blob together**. Deleting only the row (what this did before) frees
/// nothing at all: the 100 MB the ceiling is about lives in the file system, not in the four
/// numbers the table holds about it.
///
/// The two halves are ordered so that the failure mode is the harmless one:
///
/// * the blob is removed **first**, and the row is dropped only if that succeeded. A file the
///   OS refuses to delete — open in a PDF viewer on Windows, most plausibly — keeps its row,
///   so the next sweep sees it again and retries, instead of leaving an unreferenced blob on
///   disk forever. That one victim is skipped rather than aborting the pass; one locked file
///   must not stop the other evictions, and the store simply stays a little over the ceiling
///   until the next sweep.
/// * a blob that is **already gone** counts as success and the row goes. The user deleting a
///   cached PDF by hand is not an error, it is the state the eviction was trying to reach.
///
/// A blob is only unlinked once nothing else points at it: two rows may legitimately name the
/// same path (the same document cached under two logical references), and removing the file
/// out from under the surviving row would leave the table promising a file that is not there.
///
/// The whole loop commits as one transaction. If that commit were to fail, the blobs are gone
/// and their rows survive — which self-heals, because the next sweep's `remove_file` hits
/// "already gone" and drops the rows then.
fn trim_cached_files(conn: &Connection) -> Result<usize> {
    let total: i64 = conn.query_row(
        "SELECT COALESCE(SUM(bytes), 0) FROM cached_files",
        [],
        |r| r.get(0),
    )?;
    let mut total = total.max(0) as u64;
    if total <= CACHED_FILES_MAX_BYTES {
        return Ok(0);
    }

    let tx = conn.unchecked_transaction()?;

    let mut victims: Vec<(String, Option<String>)> = Vec::new();
    {
        let mut stmt = tx.prepare(
            "SELECT id, path, COALESCE(bytes, 0) FROM cached_files
              ORDER BY fetched_at ASC, id ASC",
        )?;
        let mut rows = stmt.query([])?;
        while let Some(row) = rows.next()? {
            if total <= CACHED_FILES_MAX_BYTES {
                break;
            }
            let id: String = row.get(0)?;
            let path: Option<String> = row.get(1)?;
            let bytes: i64 = row.get(2)?;
            total = total.saturating_sub(bytes.max(0) as u64);
            victims.push((id, path));
        }
    }

    let mut removed = 0usize;
    for (id, path) in &victims {
        if let Some(path) = path {
            // Rows are deleted as the loop goes, so this counts the survivors *and* the
            // victims not yet processed: a blob shared by several rows is unlinked by the
            // last of them, never by the first.
            let still_referenced: i64 = tx.query_row(
                "SELECT count(*) FROM cached_files WHERE path = ?1 AND id <> ?2",
                rusqlite::params![path, id],
                |r| r.get(0),
            )?;
            if still_referenced == 0 && !remove_cached_blob(Path::new(path)) {
                continue;
            }
        }
        tx.execute("DELETE FROM cached_files WHERE id = ?1", [id])?;
        removed += 1;
    }

    tx.commit()?;
    Ok(removed)
}

/// Unlink one cached blob. `true` means the file is no longer on disk — including the case
/// where it never was.
///
/// `pub(crate)` because eviction is no longer the only thing that has to reach the file
/// system: `SyncEngine`'s wipe path (defter O67) removes every recorded blob before dropping
/// the ledger rows that name them, and it must use exactly this "already gone counts as
/// success" definition rather than a second, subtly different one.
pub(crate) fn remove_cached_blob(path: &Path) -> bool {
    match std::fs::remove_file(path) {
        Ok(()) => true,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => true,
        Err(_) => false,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::types::WriteBlockReason;

    fn stats(percent: u32, outbox: u32, max_outbox: u32) -> StorageStats {
        StorageStats {
            db_bytes: 0,
            max_db_bytes: 100,
            cached_file_bytes: 0,
            outbox_count: outbox,
            max_outbox,
            db_usage_percent: percent,
        }
    }

    #[test]
    fn disk_full_wins_over_outbox_full() {
        assert_eq!(
            write_block_reason(&stats(100, 9999, 10)),
            Some(WriteBlockReason::DiskFull)
        );
    }

    #[test]
    fn outbox_ceiling_blocks_writes() {
        assert_eq!(
            write_block_reason(&stats(10, 5000, 5000)),
            Some(WriteBlockReason::OutboxFull)
        );
    }

    #[test]
    fn healthy_storage_does_not_block() {
        assert_eq!(write_block_reason(&stats(79, 10, 5000)), None);
    }
}
