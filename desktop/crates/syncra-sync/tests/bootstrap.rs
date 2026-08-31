//! `SYNCDESKTOP.md` §5.7 — bootstrap fills the tables and sets the cursors.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::Entity;

#[tokio::test]
async fn bootstrap_fills_tables_and_sets_cursors() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull(
        &h.server,
        pull_body(vec![
            (
                "users",
                vec![json!({
                    "id": 1, "name": "Ayberk", "email": "ayberk@example.com",
                    "avatar_url": null, "is_active": true, "department": "Sales",
                    "sync_version": 100
                })],
                vec![],
                100,
                false,
            ),
            (
                "companies",
                vec![
                    json!({ "id": 44, "name": "Acme", "email": "hi@acme.test", "sync_version": 120 }),
                    json!({ "id": 45, "name": "Globex", "email": null, "sync_version": 121 }),
                ],
                vec![],
                121,
                false,
            ),
            (
                "deals",
                vec![json!({
                    "id": 18342, "title": "Big deal", "amount": "1500.00", "status": "open",
                    "company_id": 44, "pipeline_stage_id": 4, "position": "a0",
                    "version": 8, "sync_version": 184320
                })],
                vec![],
                184320,
                false,
            ),
        ]),
    )
    .await;

    let seen = std::sync::Mutex::new(Vec::new());
    h.engine
        .bootstrap(|progress| seen.lock().unwrap().push(progress.rows_loaded))
        .await
        .expect("bootstrap");
    let seen = seen.into_inner().unwrap();

    assert_eq!(h.row_count(Entity::Company), 2);
    assert_eq!(h.row_count(Entity::Deal), 1);
    assert_eq!(h.row_count(Entity::User), 1);
    assert!(!seen.is_empty(), "progress callback was never invoked");

    // The next pull must resume from the cursors the server handed back.
    let requests = pull_requests(&h.server).await;
    let first = &requests[0]["cursors"];
    assert_eq!(first["companies"], 0, "bootstrap starts at cursor 0");
    assert_eq!(
        requests[0]["window_days"], 30,
        "bootstrap applies the retention window (K12)"
    );

    // Trigger one more pull and check the cursors moved.
    h.engine.sync_now().await.expect("sync");
    let requests = pull_requests(&h.server).await;
    let last = requests.last().expect("a second pull");
    assert_eq!(last["cursors"]["companies"], 121);
    assert_eq!(last["cursors"]["deals"], 184320);
    assert_eq!(
        last["window_days"], 0,
        "a delta pull carries no retention window (§4.4)"
    );
}

#[tokio::test]
async fn bootstrap_follows_has_more_until_the_table_is_drained() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({ "id": 1, "name": "One", "sync_version": 10 })],
            vec![],
            10,
            true,
        )]),
    )
    .await;
    mount_pull(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({ "id": 2, "name": "Two", "sync_version": 20 })],
            vec![],
            20,
            false,
        )]),
    )
    .await;

    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    assert_eq!(h.row_count(Entity::Company), 2);
    assert!(
        pull_requests(&h.server).await.len() >= 2,
        "has_more must drive a second pull"
    );
}
