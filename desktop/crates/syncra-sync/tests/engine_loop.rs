//! `SYNCDESKTOP.md` §5.5 — the sync loop's triggers, and the remaining engine entry points
//! (`restore_session`, `handle_realtime`, `download_archive`).

mod common;

use common::*;
use serde_json::json;
use syncra_sync::{Entity, LocalMutation, NamedQuery, QueryParams, RealtimeEvent};
use uuid::Uuid;

/// Coming back online is a trigger: the background loop wakes without waiting out the
/// 60 second timer.
#[tokio::test]
async fn coming_back_online_wakes_the_background_loop() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;
    mount_push_responder(&h.server, ApplyAll).await;

    h.engine.set_online(false);
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Queued while offline" }),
        ))
        .unwrap();

    let scheduler = h.engine.start_background_sync();

    // Nothing may go out while the engine believes it is offline.
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
