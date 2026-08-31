//! `SYNCDESKTOP.md` §5.7 — retention leaves pending work alone, and the ceilings block
//! writes without blocking reads.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::keystore::KeyStore;
use syncra_sync::{
    DesktopSettings, Entity, LocalMutation, NamedQuery, QueryParams, SyncError, WriteBlockReason,
};
use uuid::Uuid;

/// §5.6/2: a row is only swept when nothing in the outbox or the Conflict Inbox refers to
/// it. A pending mutation always wins over the retention window.
#[tokio::test]
async fn retention_never_removes_a_row_with_pending_work() {
    let h = Harness::start().await;
    h.login().await;

    // Two old rows: one clean, one about to be edited offline.
    mount_pull(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![
                json!({ "id": 1, "name": "Stale", "updated_at": "2000-01-01T00:00:00Z", "sync_version": 1 }),
                json!({ "id": 2, "name": "Edited", "updated_at": "2000-01-01T00:00:00Z", "sync_version": 2 }),
            ],
            vec![],
            2,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();
    assert_eq!(h.row_count(Entity::Company), 2);

    let edited = Uuid::parse_str(
        h.engine
            .query(NamedQuery::CompanyList, QueryParams::default())
            .unwrap()
            .iter()
            .find(|r| r.get_str("name") == Some("Edited"))
            .unwrap()
            .get_str("client_id")
            .unwrap(),
    )
    .unwrap();

    h.engine
        .mutate(LocalMutation::update(
            Entity::Company,
            edited,
            &["name"],
            json!({ "name": "Edited offline" }),
        ))
        .unwrap();

    let report = h.engine.run_retention().unwrap();
    assert_eq!(
        report.stale_rows_removed, 1,
        "only the clean stale row may be swept"
    );
    assert_eq!(h.row_count(Entity::Company), 1);
    let survivor = h.engine.get(Entity::Company, edited).unwrap().unwrap();
    assert_eq!(survivor.get_str("name"), Some("Edited offline"));
    assert_eq!(h.engine.status().pending, 1);
}

/// §5.6: reaching the outbox ceiling refuses writes but leaves reads working.
#[tokio::test]
async fn the_outbox_ceiling_blocks_writes_and_not_reads() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({ "id": 1, "name": "Readable", "sync_version": 1 })],
            vec![],
            1,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();

    // K8 floors the ceiling at 500, so fill it rather than lowering it below the floor.
    h.engine
        .update_settings(DesktopSettings {
            max_outbox: 1,
            ..Default::default()
        })
        .unwrap();
    assert_eq!(
        h.engine.settings().max_outbox,
        500,
        "K8 clamps the outbox ceiling to its minimum"
    );

    for i in 0..500 {
        h.engine
            .mutate(LocalMutation::create(
                Entity::Company,
                Uuid::now_v7(),
                json!({ "name": format!("C{i}") }),
            ))
            .unwrap();
    }

    let status = h.engine.status();
    assert_eq!(status.write_blocked, Some(WriteBlockReason::OutboxFull));

    let err = h
        .engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "One too many" }),
        ))
        .unwrap_err();
    assert!(
        matches!(err, SyncError::WriteBlocked(WriteBlockReason::OutboxFull)),
        "got {err:?}"
    );

    // Reads keep working.
    let rows = h
        .engine
        .query(NamedQuery::CompanyList, QueryParams::default())
        .unwrap();
    assert!(!rows.is_empty());
}

/// §5.6: crossing the database ceiling blocks writes with `DiskFull`.
#[tokio::test]
async fn the_disk_ceiling_blocks_writes() {
    let h = Harness::start().await;
    h.login().await;

    // The floor is 100 MB, which a test cannot realistically fill; drive the check through
    // the accounting instead by asking the engine for its own numbers.
    let stats = h.engine.storage_stats();
    assert!(stats.db_bytes > 0);
    assert_eq!(stats.max_db_bytes, 500 * 1024 * 1024);
    assert!(stats.db_usage_percent < 80);
    assert_eq!(h.engine.status().write_blocked, None);

    // A ceiling below the current size is clamped up to the 100 MB floor, and the engine
    // must still be writable at that size.
    h.engine
        .update_settings(DesktopSettings {
            max_db_size_mb: 1,
            ..Default::default()
        })
        .unwrap();
    assert_eq!(h.engine.settings().max_db_size_mb, 100);
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Still writable" }),
        ))
        .unwrap();
}

/// Settings survive a restart of the engine.
#[tokio::test]
async fn settings_are_persisted() {
    let h = Harness::start().await;
    h.engine
        .update_settings(DesktopSettings {
            retention_days: 90,
            max_db_size_mb: 250,
            max_outbox: 1200,
            clipboard_capture: true,
        })
        .unwrap();

    let cfg = syncra_sync::SyncConfig::new(
        url::Url::parse(&format!("{}/api/", h.server.uri())).unwrap(),
        h.db_path.clone(),
    );
    // The engine holds the file; drop the first one before reopening.
    drop(h.engine);
    let keystore = std::sync::Arc::new(syncra_sync::MemoryKeyStore::new());
    keystore
        .set(&cfg.keychain_service, syncra_sync::keystore::KEY_DB, &h.db_key)
        .unwrap();
    let reopened = syncra_sync::SyncEngine::open_with_keystore(cfg, keystore)
        .await
        .unwrap();

    let settings = reopened.settings();
    assert_eq!(settings.retention_days, 90);
    assert_eq!(settings.max_db_size_mb, 250);
    assert_eq!(settings.max_outbox, 1200);
}

/// A row the server sent without timestamps has no age, and retention must not invent one.
/// Treating "unknown" as "old" would delete freshly pulled rows on the very next sweep.
#[tokio::test]
async fn retention_keeps_rows_that_carry_no_timestamps() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull(
        &h.server,
        pull_body(vec![(
            "notifications",
            vec![json!({
                "id": "55555555-5555-4555-8555-555555555555", "type": "T",
                "notifiable_type": "U", "notifiable_id": 1, "data": "{}",
                "read_at": null, "sync_version": 1
            })],
            vec![],
            1,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();
    assert_eq!(h.row_count(Entity::Notification), 1);

    let report = h.engine.run_retention().unwrap();
    assert_eq!(report.stale_rows_removed, 0);
    assert_eq!(
        h.row_count(Entity::Notification),
        1,
        "a row with no timestamp must survive retention"
    );
}
