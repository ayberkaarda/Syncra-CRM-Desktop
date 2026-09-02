//! Local SQLCipher database: opening, migrating, and JSON <-> SQL marshalling.

pub mod query;
pub mod schema;
pub mod upsert;

use crate::error::{Result, SyncError};
use crate::types::Entity;
use rusqlite::functions::FunctionFlags;
use rusqlite::{Connection, OpenFlags};
use serde_json::Value as Json;
use std::path::Path;

const MIGRATION_0001: &str = include_str!("../../migrations/0001_init.sql");
/// Adds the four server-computed SLA columns to `tickets` (defter O2/O35, KARAR A26) — see
/// the file for the full contract.
const MIGRATION_0002: &str = include_str!("../../migrations/0002_ticket_sla_fields.sql");
/// Folds the mirror's two timestamp dialects into one (defter O83) — see the file for why
/// 86 `*_at` columns are converted and the date-only columns are not.
const MIGRATION_0003: &str = include_str!("../../migrations/0003_normalize_timestamps.sql");
/// Adds the four flattened attachment-metadata columns to `messages` (KARAR A29, defter
/// O90) — see the file for the full contract.
const MIGRATION_0004: &str = include_str!("../../migrations/0004_message_attachment_fields.sql");

/// Schema version written to `PRAGMA user_version` once every migration has run.
const SCHEMA_VERSION: i32 = 4;

/// Every migration, in order, paired with the `user_version` it brings the database to.
///
/// `migrate()` walks this list and applies every entry whose version is still ahead of the
/// database's current `user_version` — not just the newest one. A database that was created
/// before this list grew past `(1, MIGRATION_0001)` sits at `user_version = 1` forever unless
/// something applies `MIGRATION_0002` to it specifically; re-running `MIGRATION_0001` on it
/// would fail (the tables already exist), and jumping straight to `SCHEMA_VERSION` without
/// applying the intermediate SQL would silently leave its columns out. `user_version` is
/// advanced after each individual migration (not once at the end), so a crash mid-chain
/// resumes at the next unapplied entry instead of re-running one that already succeeded.
const MIGRATIONS: &[(i32, &str)] = &[
    (1, MIGRATION_0001),
    (2, MIGRATION_0002),
    (3, MIGRATION_0003),
    (4, MIGRATION_0004),
];

/// Open (or create) the encrypted database and bring it to the current schema version.
///
/// `key` is 64 hex characters from the OS keychain (K9). It is applied with
/// `PRAGMA key` as the very first statement, before any other access; SQLCipher requires
/// this, and it is what keeps the file from having a readable `SQLite format 3` header.
pub fn open(path: &Path, key: &str) -> Result<Connection> {
    if let Some(parent) = path.parent() {
        if !parent.as_os_str().is_empty() {
            std::fs::create_dir_all(parent)
                .map_err(|e| SyncError::Validation(format!("cannot create db directory: {e}")))?;
        }
    }

    let conn = Connection::open_with_flags(
        path,
        OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_CREATE,
    )?;

    conn.pragma_update(None, "key", key)?;
    // Fails loudly if the key is wrong, instead of at some later random statement.
    conn.query_row("SELECT count(*) FROM sqlite_master", [], |r| {
        r.get::<_, i64>(0)
    })?;

    configure(&conn)?;
    register_functions(&conn)?;
    migrate(&conn)?;
    Ok(conn)
}

/// Connection-level pragmas (`SYNCDESKTOP.md` K3, §5.6).
fn configure(conn: &Connection) -> Result<()> {
    // `auto_vacuum` must be set on an empty database and *before* the journal mode
    // switch, otherwise SQLite silently keeps NONE and `PRAGMA incremental_vacuum`
    // becomes a no-op for the life of the file.
    conn.pragma_update(None, "auto_vacuum", "INCREMENTAL")?;
    conn.pragma_update(None, "journal_mode", "WAL")?;
    conn.pragma_update(None, "synchronous", "NORMAL")?;
    conn.pragma_update(None, "foreign_keys", "OFF")?;
    conn.busy_timeout(std::time::Duration::from_secs(5))?;
    Ok(())
}

/// Register `syncra_fold`, the case-folding function the FTS triggers use.
///
/// `SYNCDESKTOP.md` §5.3 asks for `remove_diacritics 2` in the tokenizer *plus*
/// application-side `to_lowercase` normalisation. Doing the lowercasing in a SQL function
/// rather than in Rust before the insert is what makes the two sides symmetric: the
/// triggers fold what they index, and [`crate::SyncEngine::search`] folds the query with
/// the same code path. Turkish dotted capital `I` (U+0130) lowercases to `i` plus a
/// combining dot, which `remove_diacritics 2` then strips, so `İSTANBUL` and `istanbul`
/// land on the same token.
fn register_functions(conn: &Connection) -> Result<()> {
    conn.create_scalar_function(
        "syncra_fold",
        1,
        FunctionFlags::SQLITE_UTF8 | FunctionFlags::SQLITE_DETERMINISTIC,
        |ctx| {
            let raw: Option<String> = ctx.get(0)?;
            Ok(raw.map(|s| fold(&s)))
        },
    )?;
    Ok(())
}

/// The normalisation applied to both indexed text and search queries.
pub fn fold(input: &str) -> String {
    input.to_lowercase()
}

fn migrate(conn: &Connection) -> Result<()> {
    // `SCHEMA_VERSION` is not read directly by the loop below (each entry carries its own
    // target version) — it exists as the single named "latest" other code and tests assert
    // against, so this check is what keeps it from silently drifting out of sync with
    // `MIGRATIONS` if a future migration is appended without bumping it.
    debug_assert_eq!(
        MIGRATIONS.last().map(|(version, _)| *version),
        Some(SCHEMA_VERSION),
        "SCHEMA_VERSION must equal the version of the last entry in MIGRATIONS"
    );

    migrate_with(conn, MIGRATIONS)
}

/// The actual migration walk, parameterised over the migration list so tests can exercise a
/// deliberately broken entry without touching the real [`MIGRATIONS`] table (defter O39).
///
/// Each migration's SQL *and* the `user_version` bump that records it landing are applied in
/// one transaction — `conn.unchecked_transaction()`, the same pattern [`wipe`] and every sync
/// writer in this crate use. SQLite DDL (`ALTER TABLE` included) participates in transactions
/// like any other statement, so if a migration's `execute_batch` fails partway through — the
/// third of five `ALTER TABLE`s, say — the whole transaction (earlier statements in that same
/// batch included) rolls back on drop, because it is never committed. `PRAGMA user_version` is
/// written through the same transaction handle, so a failed migration leaves the database
/// exactly as it was before the attempt: none of its DDL applied, and the version unmoved. The
/// next `open()` retries that same migration from scratch instead of either re-running SQL that
/// already landed or getting stuck re-applying SQL that partially landed.
fn migrate_with(conn: &Connection, migrations: &[(i32, &str)]) -> Result<()> {
    let current: i32 = conn.query_row("PRAGMA user_version", [], |r| r.get(0))?;
    for (version, sql) in migrations {
        if *version <= current {
            continue;
        }
        let tx = conn.unchecked_transaction()?;
        tx.execute_batch(sql)?;
        tx.pragma_update(None, "user_version", *version)?;
        tx.commit()?;
    }
    Ok(())
}

/// Drop every row from every mirror and engine table, keeping the schema.
///
/// Used when a *different* user logs in (`SYNCDESKTOP.md` §5.5) and by
/// `clear_local`. The outbox goes too — it belongs to the previous identity.
pub fn wipe(conn: &Connection) -> Result<()> {
    let tx = conn.unchecked_transaction()?;
    for spec in schema::TABLES {
        tx.execute(&format!("DELETE FROM {}", spec.entity.table()), [])?;
    }
    for table in [
        "outbox",
        "conflicts",
        "cursors",
        "cached_files",
        "pending_shadows",
        "fts_records",
    ] {
        tx.execute(&format!("DELETE FROM {table}"), [])?;
    }
    tx.commit()?;
    Ok(())
}

/// Column names of `table`, in declaration order.
pub fn columns(conn: &Connection, table: &str) -> Result<Vec<String>> {
    let mut stmt = conn.prepare(&format!("PRAGMA table_info({table})"))?;
    let rows = stmt.query_map([], |r| r.get::<_, String>(1))?;
    let mut out = Vec::new();
    for row in rows {
        out.push(row?);
    }
    Ok(out)
}

/// Convert a JSON value into something rusqlite can bind.
///
/// Objects and arrays are stored as their JSON text; that is how the embedded `tags`,
/// `custom_fields` and `items` documents live in a single column.
pub fn json_to_sql(value: &Json) -> rusqlite::types::Value {
    use rusqlite::types::Value as V;
    match value {
        Json::Null => V::Null,
        Json::Bool(b) => V::Integer(i64::from(*b)),
        Json::Number(n) => {
            if let Some(i) = n.as_i64() {
                V::Integer(i)
            } else if let Some(f) = n.as_f64() {
                V::Real(f)
            } else {
                V::Text(n.to_string())
            }
        }
        Json::String(s) => V::Text(s.clone()),
        other => V::Text(other.to_string()),
    }
}

/// Whether `column` holds a timestamp that must be normalised on the way in.
///
/// Decided by name rather than by a hand-kept list. Every datetime column in
/// `migrations/0001_init.sql` ends in `_at` — 111 of them across 25 tables (`created_at`,
/// `updated_at`, `deleted_at`, `due_at`, `reminder_at`, `completed_at`, `occurred_at`,
/// `converted_at`, `closed_at`, `sent_at`, `accepted_at`, `rejected_at`, `last_message_at`,
/// `edited_at`, `joined_at`, `read_at`, `fetched_at`, `first_response_at`, `resolved_at`,
/// `sla_due_at`, `sla_paused_at`, `sla_warning_notified_at`, `sla_breach_notified_at`,
/// `local_updated_at`) — and, the half that actually matters, every **date-only** column does
/// not: `expected_close_date`, `valid_until`, `rate_date` and `exchange_rate_date` are MySQL
/// `date()` columns whose `2026-09-05` values are already correct and would be turned into a
/// fabricated midnight by any normalisation. The suffix is exactly the line between the two
/// sets, which is why it is the rule.
///
/// [`normalize_timestamp`] is a second, independent guard: it rewrites only values that
/// really are a naive date plus time, so even a future `_at` column holding something else
/// passes through untouched.
pub fn is_timestamp_column(column: &str) -> bool {
    column.ends_with("_at")
}

/// Rewrite a naive `YYYY-MM-DD[ T]HH:MM:SS[.fraction]` stamp into `YYYY-MM-DDTHH:MM:SS.mmmZ`.
///
/// # Why (defter O83)
///
/// Locally created rows have always used that shape: `outbox::now_iso()` is
/// `to_rfc3339_opts(SecondsFormat::Millis, true)`. Server rows did **not**.
/// `SyncPullService::fetchRows()` reads the mirror tables with `DB::table()` — no Eloquent,
/// so no datetime cast — and a MySQL `DATETIME` therefore reaches the wire as
/// `2026-09-01 23:59:00`, with a **space** (`0x20`) at offset 10 where the local dialect has
/// `T` (`0x54`).
///
/// Both dialects land in the same `TEXT` column, and every ordering, keyset and range
/// comparison in [`query`] is a plain string comparison over that column. Because
/// `'T' > ' '`, on any given date *every* offline row sorted above *every* server row
/// regardless of the actual clock, the `messages` keyset cursor cut the wrong page, and
/// `due_at < strftime('%Y-%m-%dT%H:%M:%SZ','now')` marked every server task due later *today*
/// as overdue — its space-form stamp is below the `T`-form "now" for the whole day.
///
/// Normalising at the write boundary is the fix, rather than normalising inside the queries:
/// SQL-side normalisation would have to be repeated in every predicate and would make
/// `idx_messages_conv` and the other timestamp indexes unusable, while rewriting the *local*
/// rows into the space form instead would strip the zone marker off every row and re-open the
/// "a naive stamp is assumed to be local time" class of bug.
///
/// The trailing `.000` is not decoration: `now_iso()` emits milliseconds, so a bare `...:27Z`
/// and a local `...:27.000Z` are not string-equal at the same instant (`'Z' > '.'`) and the
/// two dialects would simply diverge again one character further along.
///
/// # What is left alone
///
/// `None` means *leave the value exactly as it is*:
///
/// * anything already carrying a zone (`...Z`, `...+03:00`) — it is unambiguous already, and
///   rewriting it would either be a no-op or a lie about the offset;
/// * date-only values (`2026-09-05`), which no `_at` column should hold but which must
///   survive untouched if one ever does;
/// * anything that is not a timestamp at all.
///
/// A sub-second fraction is truncated (not rounded) to three digits, which is exactly what
/// `SecondsFormat::Millis` does on the local side, so both writers agree to the character.
/// MySQL `DATETIME` carries no fractional part today; the branch exists so that a future
/// `DATETIME(6)` column cannot re-introduce a second dialect.
pub fn normalize_timestamp(raw: &str) -> Option<String> {
    let bytes = raw.as_bytes();
    if bytes.len() < 19 {
        return None;
    }
    for (index, byte) in bytes[..19].iter().enumerate() {
        let ok = match index {
            4 | 7 => *byte == b'-',
            10 => *byte == b' ' || *byte == b'T',
            13 | 16 => *byte == b':',
            _ => byte.is_ascii_digit(),
        };
        if !ok {
            return None;
        }
    }

    let millis = if bytes.len() == 19 {
        String::from("000")
    } else {
        // Anything other than a fraction at offset 19 is a zone marker (`Z`, `+`, `-`);
        // those are already unambiguous and must not be touched.
        if bytes[19] != b'.' {
            return None;
        }
        let fraction = &raw[20..];
        if fraction.is_empty() || !fraction.bytes().all(|b| b.is_ascii_digit()) {
            return None;
        }
        let mut millis: String = fraction.chars().take(3).collect();
        while millis.len() < 3 {
            millis.push('0');
        }
        millis
    };

    Some(format!("{}T{}.{millis}Z", &raw[..10], &raw[11..19]))
}

/// [`json_to_sql`], plus the timestamp normalisation the mirror's `TEXT` columns depend on.
///
/// This is the single write boundary for server-sourced values. Both places a server row
/// reaches a mirror table go through it — [`upsert::upsert_row`] on the pull path (which is
/// what `bootstrap`, every delta and `download_archive` all funnel into via
/// `pull_until_drained`), and `sync::apply_server_row` on the conflict-resolution path, which
/// is also where a `pending_shadows.row_json` parked by §5.5 is replayed. Wiring only the
/// first would let a `TakeServer` resolution write the old dialect back over a row a pull had
/// already normalised.
pub fn json_to_sql_for_column(column: &str, value: &Json) -> rusqlite::types::Value {
    let sql = json_to_sql(value);
    if !is_timestamp_column(column) {
        return sql;
    }
    match &sql {
        rusqlite::types::Value::Text(text) => match normalize_timestamp(text) {
            Some(normalized) => rusqlite::types::Value::Text(normalized),
            None => sql,
        },
        _ => sql,
    }
}

/// Convert a SQL value back into JSON.
pub fn sql_to_json(value: rusqlite::types::ValueRef<'_>) -> Json {
    use rusqlite::types::ValueRef as V;
    match value {
        V::Null => Json::Null,
        V::Integer(i) => Json::Number(i.into()),
        V::Real(f) => serde_json::Number::from_f64(f)
            .map(Json::Number)
            .unwrap_or(Json::Null),
        V::Text(t) => Json::String(String::from_utf8_lossy(t).into_owned()),
        V::Blob(_) => Json::Null,
    }
}

/// Read one statement's current row into a JSON object, re-parsing the embedded document
/// columns of `entity` so callers see arrays and objects rather than JSON text.
pub fn row_to_json(row: &rusqlite::Row<'_>, entity: Option<Entity>) -> Result<crate::types::Row> {
    let embedded: &[&str] = entity.map(|e| schema::spec_for(e).embedded).unwrap_or(&[]);
    let stmt = row.as_ref();
    let mut map = serde_json::Map::new();
    for idx in 0..stmt.column_count() {
        let name = stmt.column_name(idx)?.to_string();
        let mut value = sql_to_json(row.get_ref(idx)?);
        if embedded.contains(&name.as_str()) {
            if let Json::String(ref text) = value {
                value = serde_json::from_str(text).unwrap_or(Json::Null);
            }
        }
        map.insert(name, value);
    }
    Ok(crate::types::Row(map))
}

/// Read a `desktop_settings` entry.
pub fn get_setting(conn: &Connection, key: &str) -> Result<Option<String>> {
    let mut stmt = conn.prepare("SELECT value FROM desktop_settings WHERE key = ?1")?;
    let mut rows = stmt.query([key])?;
    match rows.next()? {
        Some(row) => Ok(Some(row.get(0)?)),
        None => Ok(None),
    }
}

/// Write a `desktop_settings` entry.
pub fn put_setting(conn: &Connection, key: &str, value: &str) -> Result<()> {
    conn.execute(
        "INSERT INTO desktop_settings(key, value) VALUES (?1, ?2)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        rusqlite::params![key, value],
    )?;
    Ok(())
}

/// Current pull cursor for a table (protocol §2.5 K-C: one scalar per table).
pub fn get_cursor(conn: &Connection, entity: Entity) -> Result<i64> {
    let mut stmt = conn.prepare("SELECT sync_version FROM cursors WHERE table_name = ?1")?;
    let mut rows = stmt.query([entity.table()])?;
    match rows.next()? {
        Some(row) => Ok(row.get(0)?),
        None => Ok(0),
    }
}

/// Advance a pull cursor.
pub fn set_cursor(conn: &Connection, entity: Entity, version: i64) -> Result<()> {
    conn.execute(
        "INSERT INTO cursors(table_name, sync_version) VALUES (?1, ?2)
         ON CONFLICT(table_name) DO UPDATE SET sync_version = excluded.sync_version",
        rusqlite::params![entity.table(), version],
    )?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn temp_db() -> (tempfile::TempDir, Connection) {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("test.db");
        let conn = open(&path, &"a".repeat(64)).unwrap();
        (dir, conn)
    }

    #[test]
    fn migration_creates_every_mirror_table() {
        let (_dir, conn) = temp_db();
        for spec in schema::TABLES {
            let cols = columns(&conn, spec.entity.table()).unwrap();
            assert!(
                cols.contains(&"client_id".to_string()),
                "{} has no client_id",
                spec.entity.table()
            );
            assert_eq!(
                cols.contains(&"server_id".to_string()),
                spec.has_server_id,
                "{} server_id column mismatch",
                spec.entity.table()
            );
        }
    }

    #[test]
    fn embedded_tables_are_absent_from_the_schema() {
        let (_dir, conn) = temp_db();
        for absent in ["taggables", "quote_items", "custom_field_values"] {
            let count: i64 = conn
                .query_row(
                    "SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = ?1",
                    [absent],
                    |r| r.get(0),
                )
                .unwrap();
            assert_eq!(count, 0, "{absent} must not be a local mirror table");
        }
    }

    #[test]
    fn wal_and_incremental_vacuum_are_on() {
        let (_dir, conn) = temp_db();
        let mode: String = conn
            .query_row("PRAGMA journal_mode", [], |r| r.get(0))
            .unwrap();
        assert_eq!(mode.to_lowercase(), "wal");
        let vacuum: i64 = conn
            .query_row("PRAGMA auto_vacuum", [], |r| r.get(0))
            .unwrap();
        assert_eq!(vacuum, 2, "auto_vacuum must be INCREMENTAL");
    }

    #[test]
    fn migration_is_idempotent() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("test.db");
        let key = "b".repeat(64);
        {
            let _conn = open(&path, &key).unwrap();
        }
        let conn = open(&path, &key).unwrap();
        let version: i32 = conn
            .query_row("PRAGMA user_version", [], |r| r.get(0))
            .unwrap();
        assert_eq!(version, SCHEMA_VERSION);
    }

    /// The migration-chain test (defter O2/O35). A database created before `MIGRATION_0002`
    /// existed is stuck at `user_version = 1` forever unless `migrate()` walks the *whole*
    /// pending range on the next `open()`, not just the newest entry. Without this behaviour
    /// the ticket SLA columns fix would be correct in a fresh install and silently absent on
    /// every real user's existing mirror — the same "works nowhere it needs to" failure the
    /// coordinator caught in the mapper-only version of this fix.
    #[test]
    fn existing_v1_database_gets_migration_0002_applied_on_next_open() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("test.db");
        let key = "c".repeat(64);

        // Fixture: replicate exactly what `migrate()` used to do end-to-end, before
        // MIGRATION_0002 existed — apply only MIGRATION_0001 and stamp user_version = 1.
        {
            let conn = Connection::open_with_flags(
                &path,
                OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_CREATE,
            )
            .unwrap();
            conn.pragma_update(None, "key", &key).unwrap();
            conn.query_row("SELECT count(*) FROM sqlite_master", [], |r| r.get::<_, i64>(0))
                .unwrap();
            conn.execute_batch(MIGRATION_0001).unwrap();
            conn.pragma_update(None, "user_version", 1).unwrap();
        }

        // Sanity: the fixture really is a pre-0002 database (v1, no SLA columns).
        {
            let conn =
                Connection::open_with_flags(&path, OpenFlags::SQLITE_OPEN_READ_WRITE).unwrap();
            conn.pragma_update(None, "key", &key).unwrap();
            let version: i32 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
            assert_eq!(version, 1, "fixture must start at v1, or this test proves nothing");
            let cols = columns(&conn, "tickets").unwrap();
            assert!(!cols.contains(&"sla_remaining_seconds".to_string()));
            let stage_cols = columns(&conn, "pipeline_stages").unwrap();
            assert!(!stage_cols.contains(&"name_key".to_string()));
        }

        // The real entry point every app launch goes through: open() on the SAME file/key
        // must bring an existing v1 database the rest of the way to SCHEMA_VERSION.
        let conn = open(&path, &key).unwrap();

        let version: i32 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
        assert_eq!(version, SCHEMA_VERSION);

        let cols = columns(&conn, "tickets").unwrap();
        for col in [
            "sla_remaining_seconds",
            "sla_total_seconds",
            "sla_target_hours",
            "sla_breached",
        ] {
            assert!(
                cols.contains(&col.to_string()),
                "tickets.{col} missing after migrating an existing v1 database"
            );
        }

        let stage_cols = columns(&conn, "pipeline_stages").unwrap();
        assert!(
            stage_cols.contains(&"name_key".to_string()),
            "pipeline_stages.name_key missing after migrating an existing v1 database"
        );
    }

    /// The same migration-chain proof as the v1 test above, narrowed to migration 0004
    /// (KARAR A29, defter O90): a database that already has the four SLA columns and the
    /// normalised timestamp dialect (v3) must still gain the four `messages.attachment_*`
    /// columns on the next `open()`, an existing `messages` row must survive untouched with
    /// NULL in all four (K5: the server re-versions old attached rows so the next delta
    /// re-attaches real metadata; nothing here invents a value), and a second `open()` must
    /// be a no-op.
    #[test]
    fn existing_v3_database_gets_migration_0004_applied_on_next_open() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("test.db");
        let key = "d".repeat(64);

        // Fixture: a v3 database — MIGRATION_0001..0003 applied, user_version stamped at 3 —
        // with one pre-existing `messages` row, exactly what a real user's mirror looks like
        // the moment before this migration ships.
        {
            let conn = Connection::open_with_flags(
                &path,
                OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_CREATE,
            )
            .unwrap();
            conn.pragma_update(None, "key", &key).unwrap();
            conn.query_row("SELECT count(*) FROM sqlite_master", [], |r| r.get::<_, i64>(0))
                .unwrap();
            conn.execute_batch(MIGRATION_0001).unwrap();
            conn.execute_batch(MIGRATION_0002).unwrap();
            register_functions(&conn).unwrap();
            conn.execute_batch(MIGRATION_0003).unwrap();
            conn.pragma_update(None, "user_version", 3).unwrap();
            conn.execute(
                "INSERT INTO messages(client_id, server_id, conversation_id, user_id, body, \
                 attachment_id, type, created_at, updated_at) \
                 VALUES ('msg-v3-probe', 9001, 1, 1, 'pre-existing row', 1, 'file', \
                 '2026-08-01T00:00:00.000Z', '2026-08-01T00:00:00.000Z')",
                [],
            )
            .unwrap();
        }

        // Sanity: the fixture really is a pre-0004 database (v3, no attachment_* columns).
        {
            let conn =
                Connection::open_with_flags(&path, OpenFlags::SQLITE_OPEN_READ_WRITE).unwrap();
            conn.pragma_update(None, "key", &key).unwrap();
            let version: i32 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
            assert_eq!(version, 3, "fixture must start at v3, or this test proves nothing");
            let cols = columns(&conn, "messages").unwrap();
            for col in [
                "attachment_name",
                "attachment_mime",
                "attachment_size",
                "attachment_is_image",
            ] {
                assert!(!cols.contains(&col.to_string()));
            }
        }

        // The real entry point every app launch goes through.
        let conn = open(&path, &key).unwrap();

        let version: i32 = conn.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
        assert_eq!(version, SCHEMA_VERSION);

        let cols = columns(&conn, "messages").unwrap();
        for col in [
            "attachment_name",
            "attachment_mime",
            "attachment_size",
            "attachment_is_image",
        ] {
            assert!(
                cols.contains(&col.to_string()),
                "messages.{col} missing after migrating an existing v3 database"
            );
        }

        // The pre-existing row survived, its old columns unchanged, and the four new columns
        // are NULL rather than some invented value.
        let (body, attachment_name, attachment_is_image): (String, Option<String>, Option<i64>) =
            conn.query_row(
                "SELECT body, attachment_name, attachment_is_image FROM messages \
                 WHERE client_id = 'msg-v3-probe'",
                [],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)),
            )
            .unwrap();
        assert_eq!(body, "pre-existing row");
        assert_eq!(attachment_name, None);
        assert_eq!(attachment_is_image, None);

        // Idempotent: opening again must not fail or double-apply.
        drop(conn);
        let conn2 = open(&path, &key).unwrap();
        let version2: i32 = conn2.query_row("PRAGMA user_version", [], |r| r.get(0)).unwrap();
        assert_eq!(version2, SCHEMA_VERSION);
    }

    /// A migration whose `execute_batch` fails partway through must leave the database exactly
    /// as it was before the attempt: the earlier statement(s) in that same batch rolled back
    /// and `user_version` unmoved — never a state where the SQL landed but the version didn't
    /// (or vice versa). Proven against a synthetic migration list, not the real [`MIGRATIONS`]
    /// (defter O39): the fix is `migrate_with`'s transaction wrapping, not anything about
    /// migration 0002's actual SQL.
    #[test]
    fn a_failing_migration_rolls_back_and_can_be_retried_after_the_fix() {
        let (_dir, conn) = temp_db();
        let starting_version: i32 = conn
            .query_row("PRAGMA user_version", [], |r| r.get(0))
            .unwrap();
        assert_eq!(starting_version, SCHEMA_VERSION);

        // The first statement is valid and, taken alone, would succeed; the second targets a
        // table that does not exist and must fail. If the two statements were not wrapped in a
        // single transaction, the first `ALTER TABLE` would survive the second one's error.
        const BROKEN: &[(i32, &str)] = &[(
            SCHEMA_VERSION + 1,
            "ALTER TABLE tickets ADD COLUMN o39_probe TEXT;\n\
             ALTER TABLE table_that_does_not_exist ADD COLUMN x TEXT;",
        )];

        let err = migrate_with(&conn, BROKEN);
        assert!(err.is_err(), "a migration with a failing statement must return Err");

        // 1) The first statement's effect is NOT present — it was rolled back.
        let cols = columns(&conn, "tickets").unwrap();
        assert!(
            !cols.contains(&"o39_probe".to_string()),
            "the successful first ALTER TABLE must have been rolled back with the failed second one"
        );

        // 2) user_version has NOT advanced past the last good migration.
        let version: i32 = conn
            .query_row("PRAGMA user_version", [], |r| r.get(0))
            .unwrap();
        assert_eq!(
            version, SCHEMA_VERSION,
            "user_version must not advance when the migration that would have earned it failed"
        );

        // 3) Once the migration is fixed, the same connection (matching a real re-open after
        // the bug is patched) migrates cleanly from where it left off — no "duplicate column"
        // wall, because the broken attempt left nothing behind to collide with.
        const FIXED: &[(i32, &str)] = &[(
            SCHEMA_VERSION + 1,
            "ALTER TABLE tickets ADD COLUMN o39_probe TEXT;",
        )];
        migrate_with(&conn, FIXED).expect("retry with corrected SQL must succeed");

        let cols = columns(&conn, "tickets").unwrap();
        assert!(cols.contains(&"o39_probe".to_string()));
        let version: i32 = conn
            .query_row("PRAGMA user_version", [], |r| r.get(0))
            .unwrap();
        assert_eq!(version, SCHEMA_VERSION + 1);
    }

    #[test]
    fn fold_lowercases_turkish_dotted_i() {
        assert_eq!(fold("İSTANBUL"), "i\u{307}stanbul");
        assert_eq!(fold("Şirket"), "şirket");
    }

    /// Executes every whitelisted query against a real (empty) database.
    ///
    /// `build()` only proves the SQL is *shaped* right. This proves SQLite accepts it — which
    /// is the only way to catch a mistyped column, a `json_each` build without JSON1, or an
    /// aggregate whose `GROUP BY` does not line up. An empty result set is fine; a prepare
    /// error is not.
    #[test]
    fn every_named_query_is_valid_sql() {
        use crate::db::query::{CountScope, NamedQuery, QueryParams, ReadFilter};

        let (_dir, conn) = temp_db();
        let queries = vec![
            NamedQuery::RowsByServerIds {
                entity: Entity::Deal,
                ids: vec![1, -2],
            },
            NamedQuery::RowsByClientIds {
                entity: Entity::Company,
                client_ids: vec!["x".into()],
            },
            NamedQuery::DealsBoard {
                stage_client_ids: vec!["s".into()],
            },
            NamedQuery::DealsList {
                q: Some("a".into()),
                status: Some("open".into()),
                stage_id: Some(1),
                owner_id: Some(2),
                company_id: Some(3),
                contact_id: Some(4),
                tag_id: Some(5),
                amount_min: Some(1.0),
                amount_max: Some(9.0),
                from: Some("2026-01-01".into()),
                to: Some("2026-12-31".into()),
            },
            NamedQuery::CompanyList {
                q: Some("a".into()),
                industry: Some("it".into()),
                owner_id: Some(1),
                city: Some("Ankara".into()),
                country: Some("TR".into()),
                tag_id: Some(2),
                from: Some("2026-01-01".into()),
                to: Some("2026-12-31".into()),
            },
            NamedQuery::ContactList {
                q: Some("a".into()),
                company_id: Some(1),
                owner_id: Some(2),
                is_primary: Some(true),
                city: Some("Izmir".into()),
                tag_id: Some(3),
                from: None,
                to: None,
            },
            NamedQuery::LeadList {
                q: Some("a".into()),
                status: Some("new".into()),
                source: Some("website".into()),
                owner_id: Some(1),
                score_min: Some(10),
                score_max: Some(90),
                tag_id: Some(2),
                from: None,
                to: None,
            },
            NamedQuery::TaskList {
                q: Some("a".into()),
                status: Some("pending".into()),
                priority: Some("high".into()),
                assigned_to: Some(1),
                created_by: Some(2),
                taskable_type: Some("deal".into()),
                taskable_id: Some(3),
                overdue: Some(true),
                from: None,
                to: None,
            },
            NamedQuery::ActivityList {
                q: Some("a".into()),
                kind: Some("call".into()),
                user_id: Some(1),
                activityable_type: Some("deal".into()),
                activityable_id: Some(2),
                from: None,
                to: None,
            },
            NamedQuery::TicketList {
                q: Some("a".into()),
                status: Some("open".into()),
                priority: Some("urgent".into()),
                assigned_to: Some(1),
                company_id: Some(2),
                contact_id: Some(3),
                category: Some("bug".into()),
                tag_id: Some(4),
                sla_breached: Some(true),
                from: None,
                to: None,
            },
            NamedQuery::TicketStats,
            NamedQuery::QuoteList {
                q: Some("a".into()),
                status: Some("draft".into()),
                deal_id: Some(1),
                company_id: Some(2),
                contact_id: Some(3),
                expired: Some(true),
                from: None,
                to: None,
            },
            NamedQuery::QuoteRevisionFamily {
                root_number: "TKF-2026-0001".into(),
            },
            NamedQuery::ConversationList {
                kind: Some("dm".into()),
                q: Some("a".into()),
            },
            NamedQuery::ConversationMessages {
                conversation_id: 1,
                before_server_id: Some(2),
            },
            NamedQuery::ConversationMembership {
                user_id: Some(1),
                conversation_id: Some(2),
            },
            NamedQuery::NotificationList {
                read: Some(ReadFilter::Read),
            },
            NamedQuery::PipelineStages,
            NamedQuery::ProductList {
                q: Some("a".into()),
                category: Some("hw".into()),
                is_active: Some(true),
                tag_id: Some(1),
                price_min: Some(1.0),
                price_max: Some(2.0),
                in_stock: Some(true),
            },
            NamedQuery::ProductCategories,
            NamedQuery::PriceListList {
                q: Some("a".into()),
                is_active: Some(true),
                is_default: Some(false),
            },
            NamedQuery::PriceListItemList {
                price_list_id: Some(1),
                product_id: Some(2),
            },
            NamedQuery::ExchangeRateList,
            NamedQuery::SavedViewList {
                module: Some("deals".into()),
            },
            NamedQuery::SettingList,
            NamedQuery::TagList { q: Some("a".into()) },
            NamedQuery::CustomFieldList {
                entity_type: Some("deals".into()),
            },
            NamedQuery::UserList {
                q: Some("a".into()),
                is_active: Some(true),
            },
            NamedQuery::RelatedCounts {
                scope: CountScope::CompanyDeals,
                parent_ids: vec![1, 2],
            },
            NamedQuery::PendingRows {
                entity: Entity::Task,
            },
        ];

        for query in &queries {
            for count_only in [false, true] {
                let query_params = QueryParams {
                    count_only,
                    ..Default::default()
                };
                let (sql, binds) = query.build(&query_params).expect("build");
                let mut stmt = conn
                    .prepare(&sql)
                    .unwrap_or_else(|e| panic!("{query:?} did not prepare: {e}\n{sql}"));
                let bound: Vec<&dyn rusqlite::ToSql> =
                    binds.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
                let mut rows = stmt
                    .query(bound.as_slice())
                    .unwrap_or_else(|e| panic!("{query:?} did not run: {e}\n{sql}"));
                while rows.next().expect("row").is_some() {}
            }
        }
    }

    /// The embedded `tags` document is filterable without a `taggables` table (protocol §1.4).
    #[test]
    fn the_tag_filter_matches_the_embedded_document() {
        use crate::db::query::{NamedQuery, QueryParams};

        let (_dir, conn) = temp_db();
        for (client_id, name, tags) in [
            ("11111111-1111-1111-1111-111111111111", "Tagged", "[7,9]"),
            ("22222222-2222-2222-2222-222222222222", "Untagged", "[3]"),
        ] {
            conn.execute(
                "INSERT INTO companies(client_id, name, tags) VALUES (?1, ?2, ?3)",
                rusqlite::params![client_id, name, tags],
            )
            .unwrap();
        }

        let query = NamedQuery::CompanyList {
            q: None,
            industry: None,
            owner_id: None,
            city: None,
            country: None,
            tag_id: Some(9),
            from: None,
            to: None,
        };
        let (sql, binds) = query.build(&QueryParams::default()).unwrap();
        let mut stmt = conn.prepare(&sql).unwrap();
        let bound: Vec<&dyn rusqlite::ToSql> =
            binds.iter().map(|v| v as &dyn rusqlite::ToSql).collect();
        let names: Vec<String> = stmt
            .query_map(bound.as_slice(), |r| r.get::<_, String>("name"))
            .unwrap()
            .map(|r| r.unwrap())
            .collect();
        assert_eq!(names, vec!["Tagged".to_string()]);
    }

    #[test]
    fn json_round_trips_through_sql() {
        let (_dir, conn) = temp_db();
        conn.execute(
            "INSERT INTO companies(client_id, name, tags) VALUES (?1, ?2, ?3)",
            rusqlite::params![
                "11111111-1111-1111-1111-111111111111",
                "Acme",
                serde_json::json!([1, 2, 3]).to_string()
            ],
        )
        .unwrap();
        let mut stmt = conn.prepare("SELECT * FROM companies").unwrap();
        let mut rows = stmt.query([]).unwrap();
        let row = rows.next().unwrap().unwrap();
        let json = row_to_json(row, Some(Entity::Company)).unwrap();
        assert_eq!(json.get_str("name"), Some("Acme"));
        assert_eq!(json.get("tags"), Some(&serde_json::json!([1, 2, 3])));
    }

    // ---------------------------------------------------------------------------------
    // defter O83 — one timestamp dialect
    // ---------------------------------------------------------------------------------

    /// Every input shape the mirror can be handed, and what must come back.
    ///
    /// The `None` half is the load-bearing one: a value that already carries a zone, a
    /// date-only value, and anything that is not a timestamp must be returned untouched. A
    /// normaliser that is merely eager here would corrupt `expected_close_date` and lie about
    /// the offset of `+03:00` stamps.
    #[test]
    fn normalize_timestamp_converts_only_naive_date_times() {
        // The server dialect: MySQL DATETIME text, space separator, no zone.
        assert_eq!(
            normalize_timestamp("2026-09-01 23:59:00").as_deref(),
            Some("2026-09-01T23:59:00.000Z")
        );
        // A naive stamp that already uses `T` still needs the `.000Z` tail, or it would sort
        // apart from `now_iso()`'s output one character later (`Z` is 0x5A, `.` is 0x2E).
        assert_eq!(
            normalize_timestamp("2026-09-01T23:59:00").as_deref(),
            Some("2026-09-01T23:59:00.000Z")
        );
        // A fraction is truncated to milliseconds, exactly like `SecondsFormat::Millis`.
        assert_eq!(
            normalize_timestamp("2026-09-01 23:59:00.123456").as_deref(),
            Some("2026-09-01T23:59:00.123Z")
        );
        // A short fraction is padded, not left ragged: `.5` means 500ms, and an unpadded
        // `.5Z` would sort above `.499Z` but also above `.6Z`.
        assert_eq!(
            normalize_timestamp("2026-09-01 23:59:00.5").as_deref(),
            Some("2026-09-01T23:59:00.500Z")
        );

        for already_fine in [
            "2026-09-01T23:59:00Z",
            "2026-09-01T23:59:00.000Z",
            "2026-09-01T23:59:00.123Z",
            "2026-09-01T23:59:00+03:00",
            "2026-09-01T23:59:00.123+03:00",
            "2026-09-01 23:59:00Z",
        ] {
            assert_eq!(
                normalize_timestamp(already_fine),
                None,
                "{already_fine:?} already carries a zone and must be left exactly as it is"
            );
        }

        for not_a_timestamp in [
            "2026-09-05", // date-only: expected_close_date, valid_until, rate_date
            "",
            "not a date",
            "2026-09-01 23:59",     // 16 chars, no seconds
            "2026/09/01 23:59:00",  // wrong separators
            "2026-09-01x23:59:00",  // wrong date/time separator
            "2026-09-01 23:59:00.", // a dot with nothing behind it
            "2026-09-01 23:59:00.12x",
        ] {
            assert_eq!(
                normalize_timestamp(not_a_timestamp),
                None,
                "{not_a_timestamp:?} is not a naive date-time and must pass through"
            );
        }
    }

    /// The `*_at` rule is only safe because the schema actually splits that way. This asserts
    /// it against the database as opened, not against a comment: every date-only column must
    /// fall outside the rule, and every column the read layer compares must fall inside it.
    #[test]
    fn the_timestamp_column_rule_matches_the_schema() {
        for date_only in [
            "expected_close_date",
            "valid_until",
            "rate_date",
            "exchange_rate_date",
        ] {
            assert!(
                !is_timestamp_column(date_only),
                "{date_only} is a date() column — normalising it would invent a midnight"
            );
        }
        for timestamp in [
            "created_at",
            "updated_at",
            "deleted_at",
            "due_at",
            "occurred_at",
            "last_message_at",
            "sla_due_at",
            "read_at",
            "local_updated_at",
        ] {
            assert!(
                is_timestamp_column(timestamp),
                "{timestamp} is a datetime column"
            );
        }

        // And the schema itself: no column ending in `_at` may be anything but TEXT, or the
        // name-based rule would be pointing at the wrong kind of value.
        let (_dir, conn) = temp_db();
        let names: Vec<String> = {
            let mut stmt = conn
                .prepare("SELECT name FROM sqlite_master WHERE type = 'table'")
                .unwrap();
            let found = stmt
                .query_map([], |r| r.get::<_, String>(0))
                .unwrap()
                .map(|r| r.unwrap())
                .collect();
            found
        };
        let mut checked = 0;
        for table in names {
            let mut stmt = conn.prepare(&format!("PRAGMA table_info({table})")).unwrap();
            let cols: Vec<(String, String)> = stmt
                .query_map([], |r| Ok((r.get::<_, String>(1)?, r.get::<_, String>(2)?)))
                .unwrap()
                .map(|r| r.unwrap())
                .collect();
            for (name, kind) in cols {
                if is_timestamp_column(&name) {
                    assert_eq!(kind, "TEXT", "{table}.{name} is {kind}, not TEXT");
                    checked += 1;
                }
            }
        }
        assert!(
            checked >= 100,
            "expected ~111 `*_at` columns in the schema, found {checked}"
        );
    }

    /// The write boundary itself: the column name decides whether a value is a candidate, and
    /// non-text values are never touched.
    #[test]
    fn json_to_sql_for_column_normalises_at_the_boundary() {
        use rusqlite::types::Value as V;
        use serde_json::json;

        assert_eq!(
            json_to_sql_for_column("created_at", &json!("2026-09-01 23:59:00")),
            V::Text("2026-09-01T23:59:00.000Z".into())
        );
        assert_eq!(
            json_to_sql_for_column("created_at", &json!("2026-09-01T23:59:00.000Z")),
            V::Text("2026-09-01T23:59:00.000Z".into()),
            "an already-canonical value round-trips unchanged"
        );
        assert_eq!(
            json_to_sql_for_column("expected_close_date", &json!("2026-09-05")),
            V::Text("2026-09-05".into())
        );
        assert_eq!(
            json_to_sql_for_column("created_at", &json!(null)),
            V::Null,
            "NULL stays NULL"
        );
        assert_eq!(
            json_to_sql_for_column("sla_target_hours", &json!(4.25)),
            V::Real(4.25),
            "a non-text value is untouched by the timestamp path"
        );
        // A column that merely looks like it holds a stamp is not a candidate: the rule is
        // the column name, and `title` is free text.
        assert_eq!(
            json_to_sql_for_column("title", &json!("2026-09-01 23:59:00")),
            V::Text("2026-09-01 23:59:00".into())
        );
    }

    /// The upgrade half (defter O83). Normalising at the write boundary only fixes rows the
    /// server sends from now on; a mirror already on disk is full of the old dialect, and
    /// nothing re-pulls a row whose `sync_version` has not moved. Migration 0003 converts what
    /// is already there — and, just as importantly, leaves alone what is already right.
    ///
    /// Fixture pattern follows `existing_v1_database_gets_migration_0002_applied_on_next_open`:
    /// build a database exactly as the previous release left it (v2, old-dialect rows), then
    /// go through the real `open()` that every app launch uses.
    #[test]
    fn migration_0003_converts_an_existing_mirror_and_spares_the_rest() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("test.db");
        let key = "d".repeat(64);

        {
            let conn = Connection::open_with_flags(
                &path,
                OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_CREATE,
            )
            .unwrap();
            conn.pragma_update(None, "key", &key).unwrap();
            conn.query_row("SELECT count(*) FROM sqlite_master", [], |r| {
                r.get::<_, i64>(0)
            })
            .unwrap();
            // The FTS triggers call `syncra_fold`, so a bare connection cannot write a mirror
            // row at all. `open()` registers it before `migrate()` runs, which is also what
            // lets migration 0003's UPDATEs re-index the rows they touch.
            register_functions(&conn).unwrap();
            conn.execute_batch(MIGRATION_0001).unwrap();
            conn.execute_batch(MIGRATION_0002).unwrap();
            conn.pragma_update(None, "user_version", 2).unwrap();

            // A server row as the old build stored it, beside a date() column that is already
            // correct.
            conn.execute(
                "INSERT INTO deals(client_id, server_id, title, created_at, updated_at,
                                   expected_close_date, closed_at)
                 VALUES ('11111111-1111-1111-1111-111111111111', 1, 'Server row',
                         '2026-09-01 23:59:00', '2026-08-31 07:05:09', '2026-09-05', NULL)",
                [],
            )
            .unwrap();
            // A row created offline by that same old build: already canonical, must not move.
            conn.execute(
                "INSERT INTO deals(client_id, title, created_at, updated_at, local_updated_at)
                 VALUES ('22222222-2222-2222-2222-222222222222', 'Offline row',
                         '2026-09-01T08:00:00.000Z', '2026-09-01T08:00:00.000Z',
                         '2026-09-01T08:00:00.000Z')",
                [],
            )
            .unwrap();
            // A second table and a domain column, to prove the migration is not just
            // `deals.created_at`.
            conn.execute(
                "INSERT INTO tasks(client_id, server_id, title, due_at, reminder_at, created_at)
                 VALUES ('33333333-3333-3333-3333-333333333333', 5, 'Server task',
                         '2026-09-01 17:00:00', NULL, '2026-09-01 09:00:00')",
                [],
            )
            .unwrap();
            // A quote, for `valid_until` — the other date() column.
            conn.execute(
                "INSERT INTO quotes(client_id, server_id, quote_number, valid_until, sent_at)
                 VALUES ('44444444-4444-4444-4444-444444444444', 3, 'Q-3', '2026-09-30',
                         '2026-09-01 12:30:00')",
                [],
            )
            .unwrap();
        }

        // Sanity: the fixture really is a pre-0003 database, or this test proves nothing.
        {
            let conn =
                Connection::open_with_flags(&path, OpenFlags::SQLITE_OPEN_READ_WRITE).unwrap();
            conn.pragma_update(None, "key", &key).unwrap();
            let version: i32 = conn
                .query_row("PRAGMA user_version", [], |r| r.get(0))
                .unwrap();
            assert_eq!(version, 2, "fixture must start at v2");
            let created: String = conn
                .query_row("SELECT created_at FROM deals WHERE server_id = 1", [], |r| {
                    r.get(0)
                })
                .unwrap();
            assert_eq!(
                created, "2026-09-01 23:59:00",
                "fixture must carry the old dialect"
            );
        }

        let conn = open(&path, &key).unwrap();
        let version: i32 = conn
            .query_row("PRAGMA user_version", [], |r| r.get(0))
            .unwrap();
        assert_eq!(version, SCHEMA_VERSION);

        let get = |sql: &str| -> Option<String> { conn.query_row(sql, [], |r| r.get(0)).unwrap() };

        // Converted.
        assert_eq!(
            get("SELECT created_at FROM deals WHERE server_id = 1").as_deref(),
            Some("2026-09-01T23:59:00.000Z")
        );
        assert_eq!(
            get("SELECT updated_at FROM deals WHERE server_id = 1").as_deref(),
            Some("2026-08-31T07:05:09.000Z")
        );
        assert_eq!(
            get("SELECT due_at FROM tasks WHERE server_id = 5").as_deref(),
            Some("2026-09-01T17:00:00.000Z")
        );
        assert_eq!(
            get("SELECT created_at FROM tasks WHERE server_id = 5").as_deref(),
            Some("2026-09-01T09:00:00.000Z")
        );
        assert_eq!(
            get("SELECT sent_at FROM quotes WHERE server_id = 3").as_deref(),
            Some("2026-09-01T12:30:00.000Z")
        );

        // Untouched.
        assert_eq!(
            get("SELECT expected_close_date FROM deals WHERE server_id = 1").as_deref(),
            Some("2026-09-05"),
            "a date() column must survive the migration exactly as it was"
        );
        assert_eq!(
            get("SELECT valid_until FROM quotes WHERE server_id = 3").as_deref(),
            Some("2026-09-30")
        );
        assert_eq!(
            get("SELECT created_at FROM deals
                  WHERE client_id = '22222222-2222-2222-2222-222222222222'")
            .as_deref(),
            Some("2026-09-01T08:00:00.000Z"),
            "an already-canonical value must not be rewritten"
        );
        assert_eq!(
            get("SELECT local_updated_at FROM deals
                  WHERE client_id = '22222222-2222-2222-2222-222222222222'")
            .as_deref(),
            Some("2026-09-01T08:00:00.000Z")
        );
        assert_eq!(
            get("SELECT reminder_at FROM tasks WHERE server_id = 5"),
            None,
            "NULL stays NULL"
        );

        // And the point of the whole exercise: the two rows now sort by the clock.
        let order: Vec<String> = conn
            .prepare("SELECT title FROM deals ORDER BY created_at DESC")
            .unwrap()
            .query_map([], |r| r.get::<_, String>(0))
            .unwrap()
            .map(|r| r.unwrap())
            .collect();
        assert_eq!(
            order,
            vec!["Server row".to_string(), "Offline row".to_string()]
        );

        // Re-running is a no-op: a second open() must not append another `.000Z`.
        drop(conn);
        let conn = open(&path, &key).unwrap();
        let created: String = conn
            .query_row("SELECT created_at FROM deals WHERE server_id = 1", [], |r| {
                r.get(0)
            })
            .unwrap();
        assert_eq!(created, "2026-09-01T23:59:00.000Z");
    }
}
