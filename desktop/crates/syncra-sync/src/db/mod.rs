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

/// The one and only migration; applied on open when `user_version` is 0.
const MIGRATION_0001: &str = include_str!("../../migrations/0001_init.sql");

/// Schema version written to `PRAGMA user_version` once the migration has run.
const SCHEMA_VERSION: i32 = 1;

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
    let current: i32 = conn.query_row("PRAGMA user_version", [], |r| r.get(0))?;
    if current >= SCHEMA_VERSION {
        return Ok(());
    }
    conn.execute_batch(MIGRATION_0001)?;
    conn.pragma_update(None, "user_version", SCHEMA_VERSION)?;
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
}
