//! `SYNCDESKTOP.md` §5.5 — the sync loop's triggers, and the remaining engine entry points
//! (`restore_session`, `handle_realtime`, `download_archive`).

mod common;

use common::*;
use serde_json::json;
use syncra_sync::{
    DesktopSettings, Entity, LocalMutation, NamedQuery, QueryParams, RealtimeEvent, SyncError,
};
use uuid::Uuid;

/// Coming back online is a trigger: the background loop wakes without waiting out the
/// 60 second timer.
///
/// The manifest gate is what keeps this test about the trigger. Since O46 B2 the loop probes
/// for connectivity on its own, so against a reachable server a round proves nothing about
/// `set_online` — the probe would have found the network anyway. Here the server stays
/// unreachable for the whole test: the probe cannot succeed, the round that follows
/// `set_online(true)` runs off the manifest cached before the gate closed, and the wake
/// trigger is therefore the only possible explanation for it.
#[tokio::test]
async fn coming_back_online_wakes_the_background_loop() {
    let h = Harness::start_bare().await;
    let gate = ManifestGate::new(manifest_body(GRANTED, 1), true);
    mount_manifest_gate(&h.server, gate.clone()).await;
    h.login().await;
    mount_empty_pull(&h.server).await;
    mount_push_responder(&h.server, ApplyAll).await;

    // Fill the ten minute manifest cache (§5.5) while the server is still reachable, so the
    // round below needs no network of its own, then take the server away.
    h.engine.restore_session().await.expect("restore");
    gate.close();

    h.engine.set_online(false);
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Queued while offline" }),
        ))
        .unwrap();

    let scheduler = h.engine.start_background_sync();

    // Nothing may go out while the engine believes it is offline and cannot prove otherwise.
    tokio::time::sleep(std::time::Duration::from_millis(200)).await;
    assert!(
        push_requests(&h.server).await.is_empty(),
        "an offline engine must not push"
    );
    assert_eq!(h.engine.status().pending, 1);

    h.engine.set_online(true);

    // The wake-up must land well inside the 60 second timer.
    let deadline = std::time::Instant::now() + std::time::Duration::from_secs(5);
    while h.engine.status().pending > 0 && std::time::Instant::now() < deadline {
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;
    }

    assert_eq!(
        h.engine.status().pending,
        0,
        "set_online(true) must trigger a round"
    );
    assert_eq!(push_requests(&h.server).await.len(), 1);
    scheduler.stop();
}

// ---------------------------------------------------------------------------
// O46 B2 — the loop discovers connectivity by itself
// ---------------------------------------------------------------------------

/// Poll `condition` on the millisecond scale until it holds or `timeout` runs out.
///
/// The probe ramp is measured in seconds and the assertions below only ever need to observe
/// the *first*, immediate probe, so no test in this file has to wait one out. This exists so
/// they do not have to guess how long a wiremock round trip takes either.
async fn within<F: FnMut() -> bool>(timeout: std::time::Duration, mut condition: F) -> bool {
    let deadline = std::time::Instant::now() + timeout;
    while std::time::Instant::now() < deadline {
        if condition() {
            return true;
        }
        tokio::time::sleep(std::time::Duration::from_millis(10)).await;
    }
    condition()
}

/// O46 B2: nobody tells the engine the network came back — it finds out.
///
/// This is the F4 acceptance failure in miniature: the app was offline with a full outbox,
/// the network returned, and nothing in the process was capable of noticing. There is no
/// `set_online(true)` anywhere in this test on purpose.
#[tokio::test]
async fn the_offline_probe_discovers_the_network_and_drains_the_outbox() {
    let h = Harness::start_bare().await;
    // Closed: the network is down before the loop even starts, exactly as it was when the
    // F4 acceptance run left the app stuck on `pending = 19`.
    let gate = ManifestGate::new(manifest_body(GRANTED, 1), false);
    mount_manifest_gate(&h.server, gate.clone()).await;
    h.login().await;
    mount_empty_pull(&h.server).await;
    mount_push_responder(&h.server, ApplyAll).await;

    h.engine.set_online(false);
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Queued while the network was down" }),
        ))
        .unwrap();
    assert_eq!(h.engine.status().pending, 1);

    let scheduler = h.engine.start_background_sync();

    // The first probe fires immediately and finds nothing.
    tokio::time::sleep(std::time::Duration::from_millis(200)).await;
    assert!(!h.engine.status().online, "the gate is still closed");
    assert!(push_requests(&h.server).await.is_empty());

    // The network comes back. Nobody tells the engine.
    gate.open();

    let engine = h.engine.clone();
    let recovered = within(std::time::Duration::from_secs(5), || {
        engine.status().online && engine.status().pending == 0
    })
    .await;

    assert!(
        recovered,
        "the probe must flip `online` and run a round without any outside trigger \
         (online={}, pending={})",
        h.engine.status().online,
        h.engine.status().pending
    );
    assert_eq!(
        push_requests(&h.server).await.len(),
        1,
        "the round the probe triggered must be a real one"
    );
    scheduler.stop();
}

/// The probe ramp climbs and then stops at 30 seconds — the ceiling F4's "back in sync
/// shortly after the network returns" depends on, and the reason it is not the 300s the
/// failed-round backoff uses.
#[test]
fn the_offline_probe_ramp_climbs_and_stops_at_thirty_seconds() {
    use syncra_sync::sync::offline_probe_delay;

    let secs = |attempt: u32| offline_probe_delay(attempt).as_secs();

    assert_eq!(
        [secs(0), secs(1), secs(2), secs(3), secs(4)],
        [1, 2, 4, 8, 16],
        "the ramp must double"
    );
    for attempt in 5..64u32 {
        assert_eq!(secs(attempt), 30, "attempt {attempt} must sit on the ceiling");
    }
    assert_eq!(secs(u32::MAX), 30, "the shift must not wrap past the ceiling");
    assert!(
        secs(3) > secs(0),
        "a fixed 1s poll would burn the 30/min `sync` throttle bucket"
    );
}

/// Negative control: a probe that does not get a manifest back changes nothing.
///
/// A 5xx is the sharpest version of the false positive to rule out — the socket connected and
/// the server answered, which is exactly the shape a naive "did the request go anywhere?"
/// probe would call "online".
#[tokio::test]
async fn a_failing_probe_leaves_the_engine_offline_and_the_outbox_alone() {
    let h = Harness::start_bare().await;
    mount_error(&h.server, "GET", "/api/sync/manifest", 503, "SERVER_ERROR", None).await;
    h.login().await;
    mount_empty_pull(&h.server).await;
    mount_push_responder(&h.server, ApplyAll).await;

    h.engine.set_online(false);
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Must stay in the outbox" }),
        ))
        .unwrap();

    let scheduler = h.engine.start_background_sync();

    // Long enough for the immediate probe plus the first 1s rung of the ramp.
    tokio::time::sleep(std::time::Duration::from_millis(1_200)).await;

    assert!(
        !h.engine.status().online,
        "a 503 is not connectivity: `online` must stay false"
    );
    assert_eq!(
        h.engine.status().pending,
        1,
        "a probe must not touch the outbox"
    );
    assert!(
        push_requests(&h.server).await.is_empty(),
        "an offline engine must not push"
    );
    assert!(
        pull_requests(&h.server).await.is_empty(),
        "an offline engine must not pull"
    );

    // The ramp, observed from the outside: probes land at t = 0s and t = 1s. A loop that
    // ignored the ramp and spun would have made hundreds of requests in the same window.
    let probes = manifest_request_count(&h.server).await;
    assert!(
        (1..=3).contains(&probes),
        "expected the first one or two rungs of the ramp, got {probes} probes in 1.2s"
    );
    scheduler.stop();
}

/// A halted engine does not probe: reaching the server changes nothing until the app itself
/// is updated, so the `halted` branch still wins over the new offline branch.
#[tokio::test]
async fn a_halted_engine_does_not_probe() {
    let h = Harness::start_bare().await;
    mount_manifest(&h.server, manifest_body(GRANTED, 2)).await;
    h.login().await;

    let err = h.engine.sync_now().await.unwrap_err();
    assert!(
        matches!(err, syncra_sync::SyncError::Protocol(_)),
        "got {err:?}"
    );

    h.engine.set_online(false);
    let before = manifest_request_count(&h.server).await;

    let scheduler = h.engine.start_background_sync();
    tokio::time::sleep(std::time::Duration::from_millis(1_200)).await;

    assert_eq!(
        manifest_request_count(&h.server).await,
        before,
        "a halted engine must not spend the `sync` throttle bucket on probes"
    );
    assert!(!h.engine.status().online);
    scheduler.stop();
}

/// A realtime hint pulls only the tables it names.
#[tokio::test]
async fn handle_realtime_pulls_just_the_named_tables() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull(
        &h.server,
        pull_body(vec![(
            "deals",
            vec![json!({
                "id": 1, "title": "Realtime deal", "status": "open",
                "amount": "1.00", "position": "a0", "sync_version": 50
            })],
            vec![],
            50,
            false,
        )]),
    )
    .await;

    h.engine
        .handle_realtime(RealtimeEvent {
            entities: vec![Entity::Deal],
        })
        .await;

    let requests = pull_requests(&h.server).await;
    assert_eq!(requests.len(), 1);
    let cursors = requests[0]["cursors"].as_object().unwrap();
    assert_eq!(
        cursors.keys().collect::<Vec<_>>(),
        vec!["deals"],
        "a realtime pull must not drag every table along"
    );
    assert_eq!(h.row_count(Entity::Deal), 1);
}

/// `restore_session` proves the stored token still works and refreshes the user document.
#[tokio::test]
async fn restore_session_revalidates_the_stored_token() {
    let h = Harness::start().await;
    h.login().await;

    let restored = h
        .engine
        .restore_session()
        .await
        .expect("restore")
        .expect("a session must be restored");
    assert_eq!(restored.user_id, 1);
    assert!(restored.abilities.contains(&"desktop".to_string()));
    assert!(h.engine.status().online);
}

/// With no token there is nothing to restore, and that is not an error.
#[tokio::test]
async fn restore_session_without_a_token_returns_none() {
    let h = Harness::start().await;
    assert!(h.engine.restore_session().await.unwrap().is_none());
}

/// K12: `download_archive` widens the window and re-pulls from cursor 0.
#[tokio::test]
async fn download_archive_rewinds_the_cursors_and_widens_the_window() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({ "id": 1, "name": "Recent", "sync_version": 100 })],
            vec![],
            100,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();

    mount_pull(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![
                json!({ "id": 1, "name": "Recent", "sync_version": 100 }),
                json!({ "id": 2, "name": "Archived", "sync_version": 5 }),
            ],
            vec![],
            100,
            false,
        )]),
    )
    .await;

    h.engine.download_archive(90).await.expect("archive");

    let requests = pull_requests(&h.server).await;
    let archive = requests.last().unwrap();
    assert_eq!(
        archive["cursors"]["companies"], 0,
        "the archive pull must restart from cursor 0"
    );
    assert_eq!(
        archive["window_days"], 120,
        "retention_days (30) + extra_days (90)"
    );

    let names: Vec<String> = h
        .engine
        .query(NamedQuery::companies(), QueryParams::default())
        .unwrap()
        .iter()
        .map(|r| r.get_str("name").unwrap_or_default().to_string())
        .collect();
    assert!(names.contains(&"Archived".to_string()));
}

/// The window is capped by the server's own `retention_days_max`.
#[tokio::test]
async fn download_archive_respects_the_server_ceiling() {
    let h = Harness::start().await;
    h.login().await;
    mount_pull(&h.server, pull_body(vec![])).await;

    h.engine.download_archive(10_000).await.expect("archive");

    let archive = pull_requests(&h.server).await.pop().unwrap();
    assert_eq!(archive["window_days"], 365);
}

/// Two concurrent `sync_now` calls do not queue up behind each other (§5.5).
#[tokio::test]
async fn concurrent_sync_rounds_coalesce() {
    let h = Harness::start().await;
    h.login().await;
    mount_pull(
        &h.server,
        pull_body(vec![("companies", vec![], vec![], 1, false)]),
    )
    .await;

    let a = h.engine.clone();
    let b = h.engine.clone();
    let (ra, rb) = tokio::join!(a.sync_now(), b.sync_now());
    assert!(ra.is_ok() && rb.is_ok());

    assert!(
        pull_requests(&h.server).await.len() <= 2,
        "a coalesced trigger must not multiply round-trips"
    );
}

// -------------------------------------------------------------------------------------------
// Teardown (defter C1) — `SyncEngine::shutdown`.
// -------------------------------------------------------------------------------------------

/// The engine runs in WAL mode (K3), so an unclean exit leaves `-wal` and `-shm` next to the
/// database with the tail of the last transactions still in them. `shutdown` checkpoints with
/// `TRUNCATE` and closes the connection, which is what lets SQLite delete both siblings.
///
/// The second call must be a no-op rather than a panic or an error: the shell will end up
/// calling this from a tray Quit *and* from the window teardown path, and a teardown that only
/// tolerates being run once is a teardown that crashes on exit.
#[tokio::test]
async fn shutdown_checkpoints_the_wal_and_is_idempotent() {
    let h = Harness::start().await;

    // Give the WAL something to hold: settings are a plain local write, no server needed.
    h.engine
        .update_settings(DesktopSettings {
            retention_days: 45,
            ..Default::default()
        })
        .unwrap();

    let wal = h.db_path.with_extension("db-wal");
    let shm = h.db_path.with_extension("db-shm");
    let wal_before = std::fs::metadata(&wal).map(|m| m.len()).unwrap_or(0);
    assert!(
        wal_before > 0,
        "the fixture must leave a non-empty -wal at {}, or this test proves nothing",
        wal.display()
    );

    h.engine.shutdown().expect("first shutdown");

    let wal_after = std::fs::metadata(&wal).map(|m| m.len()).unwrap_or(0);
    assert!(
        wal_after == 0,
        "-wal must be truncated or removed by shutdown; it is still {wal_after} bytes"
    );
    assert!(
        !shm.exists(),
        "closing the last connection must take the -shm sibling with it"
    );

    // Idempotent.
    h.engine.shutdown().expect("second shutdown must be a no-op");

    // And the connection really is closed: further database work fails cleanly instead of
    // running against a half-torn-down handle.
    let err = h.engine.run_retention().unwrap_err();
    assert!(matches!(err, SyncError::Validation(_)), "got {err:?}");
}

/// `shutdown` is the half of teardown that `SyncScheduler::stop` is not: it makes the
/// background loop return by itself, without the shell having to hold on to the scheduler
/// handle. The loop is finished when its task completes.
#[tokio::test]
async fn shutdown_ends_the_background_loop_without_the_scheduler_handle() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;
    mount_push_responder(&h.server, ApplyAll).await;

    let scheduler = h.engine.start_background_sync();

    h.engine.shutdown().expect("shutdown");

    // The loop leaves at its next check point, which is at most one round away.
    let finished = tokio::time::timeout(std::time::Duration::from_secs(10), async {
        while !scheduler.is_finished() {
            tokio::time::sleep(std::time::Duration::from_millis(20)).await;
        }
    })
    .await;
    assert!(
        finished.is_ok(),
        "the background loop must return once shutdown raises the stop flag"
    );
}

/// `SYNCDESKTOP.md` §10 F8/1 in miniature: "engine closed, WAL checkpoint, copy the
/// `syncra.db` family and `cache/`, verify, **delete the old directory**".
///
/// The last step is the one that needs proving. On Windows `remove_dir_all` fails with a
/// sharing violation while *anything* still holds a handle on a file inside the directory, so
/// "the engine is closed" has to mean "every handle is released", not merely "nobody intends to
/// use it again". That distinction is not academic: it is exactly what leaked 245 mirror
/// directories under `%LOCALAPPDATA%\Temp` (defter O104), where `TempDir::drop` hit the same
/// failure and swallowed it. A migration cannot swallow it — it would leave the user's old,
/// still-encrypted database sitting in the abandoned location.
///
/// So this does on purpose what F8 will do for real, and it doubles as the measurement of the
/// file list F8 has to copy: after a clean close the `-wal` and `-shm` siblings are gone and the
/// family is the single `syncra.db`. On non-Windows platforms an open handle would not block the
/// unlink, so the test simply passes there without asserting anything false.
#[tokio::test]
async fn shutdown_frees_the_data_directory_for_the_f8_migration() {
    // The engine's directory sits *inside* a temp dir so the test can delete it outright, the
    // way F8 does, and still leave nothing behind for the developer to sweep up.
    let tmp = tempfile::tempdir().expect("tempdir");
    let data_dir = tmp.path().join("syncra");
    let db_path = data_dir.join("syncra.db");

    let engine = syncra_sync::SyncEngine::open_ephemeral(syncra_sync::SyncConfig::new(
        url::Url::parse("http://127.0.0.1/api/").expect("url"),
        db_path.clone(),
    ))
    .await
    .expect("open engine");

    // Real work, so the WAL carries something and the checkpoint below is not vacuous.
    engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "F8 handle test" }),
        ))
        .expect("mutate");

    // The other half of what F8 moves: the blob cache next to the database.
    let cache = data_dir.join("cache");
    std::fs::create_dir_all(&cache).expect("cache dir");
    std::fs::write(cache.join("quote.pdf"), b"%PDF-1.7 fake").expect("blob");

    let wal = data_dir.join("syncra.db-wal");
    let shm = data_dir.join("syncra.db-shm");
    assert!(
        wal.exists(),
        "WAL mode (K3) must leave a -wal sibling while the engine is open, or the checkpoint \
         assertion below proves nothing"
    );

    engine.shutdown().expect("shutdown");

    // The F8 copy list, measured rather than assumed.
    assert!(db_path.exists(), "the main database must survive shutdown");
    assert!(
        !wal.exists(),
        "a checkpointed, cleanly closed connection takes -wal with it; it is still there"
    );
    assert!(
        !shm.exists(),
        "a cleanly closed last connection takes -shm with it; it is still there"
    );

    // Every remaining file individually first, so a failure names the file that is still open
    // instead of only saying the directory is busy.
    for entry in std::fs::read_dir(&data_dir).expect("read data dir") {
        let path = entry.expect("dir entry").path();
        if path.is_file() {
            std::fs::remove_file(&path).unwrap_or_else(|err| {
                panic!(
                    "{} is still held open after shutdown: {err}",
                    path.display()
                )
            });
        }
    }

    std::fs::remove_dir_all(&data_dir).expect(
        "F8/1 ends by deleting the old data directory; a leaked file handle makes this fail on \
         Windows",
    );
    assert!(!data_dir.exists(), "the old data directory must be gone");
}

/// The same guarantee for the path F8 does *not* take, so a regression cannot hide there:
/// dropping the last `SyncEngine` without calling `shutdown` must also release the files.
///
/// This is the case the leak was really about — the four background-loop tests never called
/// `shutdown`, they just let the harness fall out of scope while a tokio task still held an
/// engine clone. With no clone outstanding the connection closes in `Inner`'s drop glue, and
/// SQLite's own close path checkpoints and removes the siblings; asserting it here pins that
/// behaviour so `Harness`'s `Drop` stays a belt-and-braces measure rather than the only thing
/// standing between the suite and a leaking temp directory.
#[tokio::test]
async fn dropping_the_last_engine_clone_also_releases_the_database_files() {
    let tmp = tempfile::tempdir().expect("tempdir");
    let data_dir = tmp.path().join("syncra");
    let db_path = data_dir.join("syncra.db");

    {
        let engine = syncra_sync::SyncEngine::open_ephemeral(syncra_sync::SyncConfig::new(
            url::Url::parse("http://127.0.0.1/api/").expect("url"),
            db_path.clone(),
        ))
        .await
        .expect("open engine");
        engine
            .mutate(LocalMutation::create(
                Entity::Company,
                Uuid::now_v7(),
                json!({ "name": "drop test" }),
            ))
            .expect("mutate");
        // A clone that also goes out of scope here: the connection must close when the *last*
        // one does, not when the first does.
        let _clone = engine.clone();
    }

    std::fs::remove_dir_all(&data_dir)
        .expect("dropping the last engine clone must release every handle on the database");
    assert!(!data_dir.exists());
}
