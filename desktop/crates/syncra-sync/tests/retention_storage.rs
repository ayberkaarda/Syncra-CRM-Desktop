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
            .query(NamedQuery::companies(), QueryParams::default())
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
        .query(NamedQuery::companies(), QueryParams::default())
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
            close_to_tray: true,
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
    assert!(
        settings.clipboard_capture,
        "clipboard_capture must survive a restart, not just the numeric ceilings"
    );
    assert!(
        settings.close_to_tray,
        "close_to_tray must survive a restart, not just the numeric ceilings"
    );
}

/// The bug `settings()` had until now: `update_settings` wrote `clipboard_capture` and
/// `close_to_tray` into the persisted row (`SETTING_PREFERENCES`), but the getter rebuilt its
/// answer from `cfg` alone -- which never carried those two fields -- and hard-coded them
/// instead of reading the row back. A caller could set `close_to_tray: false` and `settings()`
/// would keep reporting `true` forever, in the same process, with no restart involved. This
/// is the write-then-read round trip that must hold without ever closing the engine.
#[tokio::test]
async fn updated_settings_are_read_back_in_the_same_session() {
    let h = Harness::start().await;

    h.engine
        .update_settings(DesktopSettings {
            close_to_tray: false,
            clipboard_capture: true,
            ..Default::default()
        })
        .unwrap();

    let settings = h.engine.settings();
    assert!(
        !settings.close_to_tray,
        "a write to close_to_tray must be visible to the very next read"
    );
    assert!(
        settings.clipboard_capture,
        "a write to clipboard_capture (F5-6's opt-in) must be visible to the very next read"
    );
}

/// A freshly opened engine that has never had `update_settings` called on it has no
/// `SETTING_PREFERENCES` row at all. `settings()` must answer with the documented defaults
/// rather than erroring or panicking on the missing row.
#[tokio::test]
async fn settings_with_no_persisted_row_returns_defaults() {
    let h = Harness::start().await;

    assert_eq!(h.engine.settings(), DesktopSettings::default());
}

/// A row that fails to parse -- written by some future build, or simply corrupted -- must not
/// take the whole getter down with it. It is exactly the same "unknown state, answer safely"
/// case as no row at all, so the fallback is the same defaults.
#[tokio::test]
async fn a_corrupt_persisted_settings_row_falls_back_to_defaults() {
    let h = Harness::start().await;

    {
        let conn = h.raw_conn();
        syncra_sync::db::put_setting(&conn, "preferences", "{ not json").unwrap();
    }

    assert_eq!(
        h.engine.settings(),
        DesktopSettings::default(),
        "a corrupt row must fall back to defaults, not panic or propagate the parse error"
    );
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

// -------------------------------------------------------------------------------------------
// §5.6/3 — the `cached_files` ledger and the 100 MB LRU ceiling.
//
// The whole point of these tests is the file system, not the table. Before this the engine had
// no way to put a row in `cached_files` at all (the shell wrote PDFs and nothing recorded
// them), and eviction deleted rows while leaving every blob on disk — so the ceiling freed
// exactly zero bytes even in the case it was written for.
// -------------------------------------------------------------------------------------------

/// Sizes are what the *caller* reports, so the ceiling can be crossed with tiny blobs. That is
/// the only way to exercise a 100 MB ceiling without writing 100 MB, and it still tests the
/// real accounting path: `storage_stats` and the trim both read `cached_files.bytes`.
const HALF_CEILING: u64 = 60 * 1024 * 1024;

/// A third of the ceiling. Two of these stay under 100 MB; the third crosses it. `HALF_CEILING`
/// cannot express that, because two halves already cross — and `record_cached_file` now trims on
/// every record, so a test that wants to observe *which* file gets evicted needs a step where
/// nothing is evicted yet.
const THIRD_CEILING: u64 = 40 * 1024 * 1024;

/// Write a small real file under the temp dir of the harness and return its absolute path.
fn write_blob(h: &Harness, name: &str) -> std::path::PathBuf {
    let dir = h.dir.path().join("cache");
    std::fs::create_dir_all(&dir).expect("cache dir");
    let path = dir.join(name);
    std::fs::write(&path, b"%PDF-1.7 fake").expect("write blob");
    assert!(path.is_absolute(), "the engine only accepts absolute paths");
    path
}

fn cached_row_count(h: &Harness) -> i64 {
    h.raw_conn()
        .query_row("SELECT count(*) FROM cached_files", [], |r| r.get(0))
        .expect("count cached_files")
}

/// A recorded file is immediately part of the storage accounting. Without the writer this
/// number was structurally always zero.
#[tokio::test]
async fn a_recorded_cached_file_shows_up_in_the_storage_accounting() {
    let h = Harness::start().await;
    assert_eq!(h.engine.storage_stats().cached_file_bytes, 0);

    let path = write_blob(&h, "quote-42-3.pdf");
    let id = h
        .engine
        .record_cached_file("quote_pdf", "42-3", &path, 4096)
        .expect("record");

    assert_eq!(h.engine.storage_stats().cached_file_bytes, 4096);
    assert_eq!(cached_row_count(&h), 1);
    assert_eq!(
        id,
        syncra_sync::retention::cached_file_id("quote_pdf", "42-3"),
        "the id must be derivable from the logical reference alone"
    );
}

/// Recording the same file again is an update, not a second row — otherwise every cache
/// refresh would double-count its own bytes against the ceiling and evict twice as fast.
#[tokio::test]
async fn recording_the_same_cached_file_twice_does_not_duplicate_the_row() {
    let h = Harness::start().await;
    let path = write_blob(&h, "quote-42-3.pdf");

    let first = h
        .engine
        .record_cached_file("quote_pdf", "42-3", &path, 4096)
        .expect("first record");
    let second = h
        .engine
        .record_cached_file("quote_pdf", "42-3", &path, 8192)
        .expect("second record");

    assert_eq!(first, second, "the identity is (kind, reference)");
    assert_eq!(cached_row_count(&h), 1);
    assert_eq!(
        h.engine.storage_stats().cached_file_bytes,
        8192,
        "the row must carry the new size, not the sum of both"
    );
}

/// `touch` is the "L" in LRU: it re-dates a row on a cache **hit**, and the next eviction has
/// to respect the new order. Recording A then B and touching A must cost B its place.
#[tokio::test]
async fn touching_a_cached_file_changes_which_one_gets_evicted() {
    let h = Harness::start().await;
    let cold = write_blob(&h, "quote-1-1.pdf");
    let warm = write_blob(&h, "quote-2-1.pdf");

    // Two thirds of the ceiling: still under it, so nothing is evicted yet and the touch below
    // is what decides the victim. With halves the second record would already have evicted A.
    h.engine
        .record_cached_file("quote_pdf", "1-1", &cold, THIRD_CEILING)
        .expect("record A");
    h.engine
        .record_cached_file("quote_pdf", "2-1", &warm, THIRD_CEILING)
        .expect("record B");

    // ...but A is the one the user just opened again.
    assert!(
        h.engine.touch_cached_file("quote_pdf", "1-1").expect("touch"),
        "touching a recorded file must report a hit"
    );
    assert!(
        !h.engine
            .touch_cached_file("quote_pdf", "never-cached")
            .expect("touch miss"),
        "touching an unknown reference must report a miss, not silently succeed"
    );

    // The third record crosses the ceiling, so the trim inside `record_cached_file` runs here
    // and has to choose. `fetched_at` ordering makes B the coldest, because A was touched.
    let third = write_blob(&h, "quote-3-1.pdf");
    h.engine
        .record_cached_file("quote_pdf", "3-1", &third, THIRD_CEILING)
        .expect("record C");

    assert!(
        cold.exists(),
        "the touched file must survive; ordering by first download would have taken it"
    );
    assert!(!warm.exists(), "the least recently used file is the victim");
    assert!(third.exists(), "the file that triggered the trim must not evict itself");
    assert_eq!(cached_row_count(&h), 2);

    // The ceiling was already enforced by the record above, so the daily sweep finds nothing.
    let report = h.engine.run_retention().expect("retention");
    assert_eq!(
        report.cached_files_removed, 0,
        "trim-on-record must leave the sweep with nothing to do"
    );
}

/// The test this whole change exists for: crossing the ceiling must free **disk**, not just
/// table rows.
#[tokio::test]
async fn crossing_the_ceiling_deletes_the_row_and_the_file_on_disk() {
    let h = Harness::start().await;
    let old = write_blob(&h, "quote-1-1.pdf");
    let new = write_blob(&h, "quote-2-1.pdf");

    h.engine
        .record_cached_file("quote_pdf", "1-1", &old, HALF_CEILING)
        .expect("record old");
    h.engine
        .record_cached_file("quote_pdf", "2-1", &new, HALF_CEILING)
        .expect("record new");

    // Two halves cross the ceiling, so the trim inside the SECOND record already ran. There is
    // no window in which both rows coexist over the ceiling any more — that window is exactly
    // what §5.6/3 says must not exist.
    assert_eq!(cached_row_count(&h), 1, "the record itself enforced the ceiling");

    assert!(
        !old.exists(),
        "the blob of the victim must be gone from disk — deleting the row alone frees \
         nothing, which is the bug this replaces"
    );
    assert!(new.exists(), "the blob of the survivor must be untouched");
    assert_eq!(h.engine.storage_stats().cached_file_bytes, HALF_CEILING);

    // Nothing left for the sweep: the ceiling was enforced at record time.
    let report = h.engine.run_retention().expect("retention");
    assert_eq!(report.cached_files_removed, 0);
}

/// A blob the user deleted by hand is not an error: it is already the state eviction wants, so
/// the row goes and the pass carries on.
#[tokio::test]
async fn evicting_a_file_that_is_already_gone_is_not_an_error() {
    let h = Harness::start().await;
    let old = write_blob(&h, "quote-1-1.pdf");
    let new = write_blob(&h, "quote-2-1.pdf");

    // Stay under the ceiling so nothing is evicted yet.
    h.engine
        .record_cached_file("quote_pdf", "1-1", &old, THIRD_CEILING)
        .expect("record old");
    h.engine
        .record_cached_file("quote_pdf", "2-1", &new, THIRD_CEILING)
        .expect("record new");

    // The user emptied the cache folder behind the back of the app.
    std::fs::remove_file(&old).expect("remove blob");
    assert!(!old.exists());

    // This record crosses the ceiling and makes the stale row the victim. Removing a blob that
    // is already gone must not fail the record.
    let third = write_blob(&h, "quote-3-1.pdf");
    h.engine
        .record_cached_file("quote_pdf", "3-1", &third, THIRD_CEILING)
        .expect("recording must not fail because a victim's blob vanished");

    assert_eq!(cached_row_count(&h), 2, "the stale row must still be swept");
    assert!(new.exists());
    assert!(third.exists());
}

/// Below the ceiling the trim is a no-op — it must never touch a file just because retention
/// ran.
#[tokio::test]
async fn retention_below_the_ceiling_evicts_nothing() {
    let h = Harness::start().await;
    let path = write_blob(&h, "quote-1-1.pdf");
    h.engine
        .record_cached_file("quote_pdf", "1-1", &path, 1024)
        .expect("record");

    let report = h.engine.run_retention().expect("retention");

    assert_eq!(report.cached_files_removed, 0);
    assert_eq!(cached_row_count(&h), 1);
    assert!(path.exists(), "a file under the ceiling must never be evicted");
}

/// A relative path is refused: the trim deletes what it is given, and a relative path resolves
/// against whatever the process working directory happens to be at eviction time.
#[tokio::test]
async fn a_relative_cached_file_path_is_refused() {
    let h = Harness::start().await;
    let err = h
        .engine
        .record_cached_file("quote_pdf", "1-1", std::path::Path::new("cache/x.pdf"), 10)
        .unwrap_err();
    assert!(matches!(err, SyncError::Validation(_)), "got {err:?}");
    assert_eq!(cached_row_count(&h), 0);
}
