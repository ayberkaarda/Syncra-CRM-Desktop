//! `SYNCDESKTOP.md` K3 / §9 — the local database is really encrypted.
//!
//! The F6 security checklist asks for this as a gate; proving it here means a regression in
//! the SQLCipher feature wiring (a build that silently falls back to plain SQLite, say)
//! fails the crate's own suite rather than surviving to the security phase.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::{Entity, LocalMutation};
use uuid::Uuid;

/// A plain SQLite file starts with the 16 bytes `SQLite format 3\0`. An encrypted one must
/// not.
#[tokio::test]
async fn the_database_file_has_no_plaintext_sqlite_header() {
    let h = Harness::start().await;
    h.login().await;
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Secret Corp" }),
        ))
        .unwrap();

    // Force everything to disk before reading the raw bytes.
    {
        let conn = h.raw_conn();
        conn.pragma_update(None, "wal_checkpoint", "TRUNCATE").ok();
    }
    drop(h.engine);

    let bytes = std::fs::read(&h.db_path).expect("read database file");
    assert!(bytes.len() > 16, "database file is suspiciously small");
    assert_ne!(
        &bytes[..16],
        b"SQLite format 3\0",
        "the database must not carry a plaintext SQLite header"
    );

    // The row we just wrote must not be readable in the clear either.
    let haystack = String::from_utf8_lossy(&bytes);
    assert!(
        !haystack.contains("Secret Corp"),
        "row data leaked into the file in plaintext"
    );
}

/// Opening with the wrong key fails rather than silently returning an empty database.
#[tokio::test]
async fn a_wrong_key_cannot_open_the_database() {
    let h = Harness::start().await;
    h.login().await;
    drop(h.engine);

    let conn = rusqlite::Connection::open(&h.db_path).expect("open");
    conn.pragma_update(None, "key", "f".repeat(64)).expect("key");
    let result: Result<i64, _> =
        conn.query_row("SELECT count(*) FROM sqlite_master", [], |r| r.get(0));
    assert!(result.is_err(), "a wrong key must not open the database");
}
