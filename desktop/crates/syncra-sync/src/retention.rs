//! Retention and the disk ceiling (`SYNCDESKTOP.md` §5.6, K8).

use crate::config::SyncConfig;
use crate::db::schema;
use crate::error::Result;
use crate::types::{StorageStats, WriteBlockReason};
use chrono::{Duration, Utc};
use rusqlite::Connection;

/// LRU ceiling for `cached_files`, in bytes (§5.6/3).
pub const CACHED_FILES_MAX_BYTES: u64 = 100 * 1024 * 1024;

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
/// 3. `cached_files` is trimmed to 100 MB, oldest fetch first;
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

    report.cached_files_removed += trim_cached_files(&tx)? as u64;
    tx.commit()?;

    conn.pragma_update(None, "incremental_vacuum", 0)?;
    Ok(report)
}

/// Evict least-recently-fetched cached files until the store fits in 100 MB.
fn trim_cached_files(tx: &rusqlite::Transaction<'_>) -> Result<usize> {
    let total: i64 = tx.query_row(
        "SELECT COALESCE(SUM(bytes), 0) FROM cached_files",
        [],
        |r| r.get(0),
    )?;
    let mut total = total.max(0) as u64;
    if total <= CACHED_FILES_MAX_BYTES {
        return Ok(0);
    }

    let mut victims: Vec<(String, u64)> = Vec::new();
    {
        let mut stmt = tx.prepare(
            "SELECT id, COALESCE(bytes, 0) FROM cached_files ORDER BY fetched_at ASC, id ASC",
        )?;
        let mut rows = stmt.query([])?;
        while let Some(row) = rows.next()? {
            if total <= CACHED_FILES_MAX_BYTES {
                break;
            }
            let id: String = row.get(0)?;
            let bytes: i64 = row.get(1)?;
            total = total.saturating_sub(bytes.max(0) as u64);
            victims.push((id, bytes.max(0) as u64));
        }
    }

    for (id, _) in &victims {
        tx.execute("DELETE FROM cached_files WHERE id = ?1", [id])?;
    }
    Ok(victims.len())
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
