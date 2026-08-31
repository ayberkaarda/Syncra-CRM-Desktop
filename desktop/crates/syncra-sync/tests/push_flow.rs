//! `SYNCDESKTOP.md` §5.7 — 50 offline mutations: topological order, batching, coalescing,
//! and the `duplicate` answer to a resent `idempotency_key`.

mod common;

use common::*;
use serde_json::{json, Value};
use syncra_sync::{Entity, LocalMutation, NamedQuery, QueryParams};
use uuid::Uuid;

/// Level of an entity in the push order (`SYNCDESKTOP.md` §5.4), with the `quote_item`
/// level removed by protocol §6.2 P13.
fn level(entity: &str, op: &str) -> u8 {
    if op == "action" {
        return 5;
    }
    match entity {
        "company" | "tag" => 0,
        "contact" | "lead" => 1,
        "deal" | "conversation" => 2,
        _ => 3,
    }
}

#[tokio::test]
async fn fifty_offline_mutations_go_out_in_topological_order() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;
    mount_push_responder(&h.server, ApplyAll).await;

    // Deliberately queued child-first, so a naive FIFO order would fail the assertion.
    let mut company_ids = Vec::new();
    let mut deal_ids = Vec::new();
    for i in 0..10 {
        let deal = Uuid::now_v7();
        deal_ids.push(deal);
        h.engine
            .mutate(LocalMutation::create(
                Entity::Deal,
                deal,
                json!({ "title": format!("Deal {i}"), "amount": "100.00", "status": "open" }),
            ))
            .unwrap();

        let contact = Uuid::now_v7();
        h.engine
            .mutate(LocalMutation::create(
                Entity::Contact,
                contact,
                json!({ "first_name": "C", "last_name": format!("{i}") }),
            ))
            .unwrap();

        let company = Uuid::now_v7();
        company_ids.push(company);
        h.engine
            .mutate(LocalMutation::create(
                Entity::Company,
                company,
                json!({ "name": format!("Company {i}") }),
            ))
            .unwrap();

        let task = Uuid::now_v7();
        h.engine
            .mutate(LocalMutation::create(
                Entity::Task,
                task,
                json!({ "title": format!("Task {i}"), "status": "pending" }),
            ))
            .unwrap();
    }
    // Ten actions on the deals, which must sort after every create.
    for deal in &deal_ids {
        h.engine
            .mutate(LocalMutation::action(
                Entity::Deal,
                *deal,
                "move",
                json!({ "to_stage_id": 4, "position": "a1", "version": 1 }),
            ))
            .unwrap();
    }

    assert_eq!(h.engine.status().pending, 50);

    let report = h.engine.sync_now().await.expect("sync");
    assert_eq!(report.pushed, 50);
    assert_eq!(report.applied, 50);
    assert_eq!(h.engine.status().pending, 0);

    let requests = push_requests(&h.server).await;
    assert_eq!(requests.len(), 1, "50 mutations fit in one 200-wide batch");
    let mutations = requests[0]["mutations"].as_array().unwrap();
    assert_eq!(mutations.len(), 50);

    let levels: Vec<u8> = mutations
        .iter()
        .map(|m| {
            level(
                m["entity"].as_str().unwrap(),
                m["op"].as_str().unwrap(),
            )
        })
        .collect();
    assert!(
        levels.windows(2).all(|w| w[0] <= w[1]),
        "push order is not topological: {levels:?}"
    );
    assert_eq!(levels.first(), Some(&0), "companies must lead");
    assert_eq!(levels.last(), Some(&5), "actions must trail");

    // Every mutation carries a distinct idempotency key.
    let keys: std::collections::HashSet<&str> = mutations
        .iter()
        .map(|m| m["idempotency_key"].as_str().unwrap())
        .collect();
    assert_eq!(keys.len(), 50);
}

#[tokio::test]
async fn the_batch_ceiling_splits_the_queue() {
    // A manifest that only allows 20 mutations per request.
    let server = wiremock::MockServer::start().await;
    let dir = tempfile::tempdir().unwrap();
    let cfg = syncra_sync::SyncConfig::new(
        url::Url::parse(&format!("{}/api/", server.uri())).unwrap(),
        dir.path().join("syncra.db"),
    );
    let engine = syncra_sync::SyncEngine::open_with_keystore(
        cfg,
        std::sync::Arc::new(syncra_sync::MemoryKeyStore::new()),
    )
    .await
    .unwrap();

    let mut manifest = manifest_body(GRANTED, 1);
    manifest["policy"]["push_batch_max"] = json!(20);
    mount_manifest(&server, manifest).await;
    mount_device_auth(&server, 1, "a@b.c").await;
    engine.login("a@b.c", "secret", device_info()).await.unwrap();
    mount_empty_pull(&server).await;
    mount_push_responder(&server, ApplyAll).await;

    for i in 0..45 {
        engine
            .mutate(LocalMutation::create(
                Entity::Company,
                Uuid::now_v7(),
                json!({ "name": format!("C{i}") }),
            ))
            .unwrap();
    }

    engine.sync_now().await.expect("sync");

    let requests = push_requests(&server).await;
    let sizes: Vec<usize> = requests
        .iter()
        .map(|r| r["mutations"].as_array().unwrap().len())
        .collect();
    assert_eq!(sizes, vec![20, 20, 5]);
    assert_eq!(engine.status().pending, 0);
}

/// §5.4 coalescing: consecutive updates merge, and an update after a create folds into it.
#[tokio::test]
async fn coalescing_collapses_a_create_and_three_updates_into_one_mutation() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;
    mount_push_responder(&h.server, ApplyAll).await;

    let deal = Uuid::now_v7();
    h.engine
        .mutate(LocalMutation::create(
            Entity::Deal,
            deal,
            json!({ "title": "First", "amount": "100.00", "status": "open" }),
        ))
        .unwrap();
    for (i, amount) in ["200.00", "300.00", "400.00"].iter().enumerate() {
        h.engine
            .mutate(LocalMutation::update(
                Entity::Deal,
                deal,
                &["amount", "title"],
                json!({ "amount": amount, "title": format!("Title {i}") }),
            ))
            .unwrap();
    }

    assert_eq!(
        h.engine.status().pending,
        1,
        "four edits to one row must coalesce into a single pending mutation"
    );

    h.engine.sync_now().await.expect("sync");
    let requests = push_requests(&h.server).await;
    let mutations = requests[0]["mutations"].as_array().unwrap();
    assert_eq!(mutations.len(), 1);
    assert_eq!(mutations[0]["op"], "create");
    assert_eq!(
        mutations[0]["payload"]["amount"], "400.00",
        "the last write must win"
    );
    assert_eq!(mutations[0]["payload"]["title"], "Title 2");
}

/// A create followed by a delete never reaches the server at all (§5.4).
#[tokio::test]
async fn a_delete_after_a_create_annihilates_both() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;
    mount_push_responder(&h.server, ApplyAll).await;

    let company = Uuid::now_v7();
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            company,
            json!({ "name": "Ephemeral" }),
        ))
        .unwrap();
    h.engine
        .mutate(LocalMutation::delete(Entity::Company, company))
        .unwrap();

    assert_eq!(h.engine.status().pending, 0);
    h.engine.sync_now().await.expect("sync");
    assert!(
        push_requests(&h.server).await.is_empty(),
        "nothing should have been sent"
    );
}

/// A resend after a transport failure carries the same `idempotency_key`, and the server's
/// `duplicate` answer settles the row (§5.5).
#[tokio::test]
async fn a_resend_keeps_the_idempotency_key_and_duplicate_settles_it() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;

    // First attempt fails with a 5xx, second answers `duplicate`.
    wiremock::Mock::given(wiremock::matchers::method("POST"))
        .and(wiremock::matchers::path("/api/sync/push"))
        .respond_with(wiremock::ResponseTemplate::new(500))
        .up_to_n_times(1)
        .mount(&h.server)
        .await;
    mount_push_responder(&h.server, DuplicateAll).await;

    let company = Uuid::now_v7();
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            company,
            json!({ "name": "Retried" }),
        ))
        .unwrap();

    let first = h.engine.sync_now().await;
    assert!(first.is_err(), "a 500 must not be reported as success");
    assert_eq!(
        h.engine.status().pending,
        1,
        "the mutation stays queued after a transport failure"
    );

    h.engine.set_online(true);
    let report = h.engine.sync_now().await.expect("second attempt");
    assert_eq!(report.duplicates, 1);
    assert_eq!(h.engine.status().pending, 0);

    let requests = push_requests(&h.server).await;
    assert_eq!(requests.len(), 2);
    let key_of = |r: &Value| r["mutations"][0]["idempotency_key"].as_str().unwrap().to_string();
    assert_eq!(
        key_of(&requests[0]),
        key_of(&requests[1]),
        "the resend must reuse the idempotency key"
    );

    let rows = h
        .engine
        .query(NamedQuery::companies(), QueryParams::default())
        .unwrap();
    assert_eq!(rows[0].get_str("sync_state"), Some("synced"));
    assert_eq!(rows[0].get_i64("server_id"), Some(4242));
}

/// Protocol §4.3 P10 — `notification.read_all` is the one mutation with no row identity.
#[tokio::test]
async fn notification_read_all_is_user_scoped_and_carries_no_row_id() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "notifications",
            vec![
                json!({ "id": "11111111-1111-4111-8111-111111111111", "type": "T",
                        "notifiable_type": "U", "notifiable_id": 1, "data": "{}",
                        "read_at": null, "sync_version": 1 }),
                json!({ "id": "22222222-2222-4222-8222-222222222222", "type": "T",
                        "notifiable_type": "U", "notifiable_id": 1, "data": "{}",
                        "read_at": null, "sync_version": 2 }),
            ],
            vec![],
            2,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();
    mount_empty_pull(&h.server).await;
    mount_push(
        &h.server,
        push_body(vec![json!({ "seq": 1, "status": "applied", "affected": 2 })]),
    )
    .await;

    h.engine
        .mutate(LocalMutation::notification_read_all())
        .unwrap();

    // Locally every unread notification is already marked read.
    let unread = h
        .engine
        .query(
            NamedQuery::notifications(Some(syncra_sync::ReadFilter::Unread)),
            QueryParams::default(),
        )
        .unwrap();
    assert!(unread.is_empty(), "read_all must apply locally too");

    h.engine.sync_now().await.expect("sync");

    let requests = push_requests(&h.server).await;
    let mutation = &requests[0]["mutations"][0];
    assert_eq!(mutation["op"], "action");
    assert_eq!(mutation["entity"], "notification");
    assert_eq!(mutation["action"], "read_all");
    assert_eq!(mutation["scope"], "user");
    assert!(
        mutation.get("server_id").is_none(),
        "read_all must not carry server_id"
    );
    assert!(
        mutation.get("client_id").is_none(),
        "read_all must not carry client_id"
    );
}

/// The action whitelist is enforced before anything is written (`SYNCDESKTOP.md` §4.4/§8).
#[tokio::test]
async fn online_only_actions_are_refused_locally() {
    let h = Harness::start().await;
    h.login().await;

    let err = h
        .engine
        .mutate(LocalMutation::action(
            Entity::Lead,
            Uuid::now_v7(),
            "convert",
            json!({}),
        ))
        .unwrap_err();
    assert!(matches!(err, syncra_sync::SyncError::Validation(_)));
    assert_eq!(h.engine.status().pending, 0);
}

/// After the server accepts `read_all`, the rows it touched must not be left `pending`.
///
/// A `pending` row is shielded from pull overwrites (§5.5), so a stuck one would never
/// receive server updates again.
#[tokio::test]
async fn read_all_settles_the_rows_it_marked() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "notifications",
            vec![json!({ "id": "33333333-3333-4333-8333-333333333333", "type": "T",
                         "notifiable_type": "U", "notifiable_id": 1, "data": "{}",
                         "read_at": null, "sync_version": 1 })],
            vec![],
            1,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();
    mount_empty_pull(&h.server).await;
    mount_push(
        &h.server,
        push_body(vec![json!({ "seq": 1, "status": "applied", "affected": 1 })]),
    )
    .await;

    h.engine
        .mutate(LocalMutation::notification_read_all())
        .unwrap();
    let rows = h
        .engine
        .query(
            NamedQuery::notifications(None),
            QueryParams::default(),
        )
        .unwrap();
    assert_eq!(rows[0].get_str("sync_state"), Some("pending"));

    h.engine.sync_now().await.expect("sync");

    let rows = h
        .engine
        .query(
            NamedQuery::notifications(None),
            QueryParams::default(),
        )
        .unwrap();
    assert_eq!(
        rows[0].get_str("sync_state"),
        Some("synced"),
        "read_all must settle the rows it marked"
    );
    assert_eq!(h.engine.status().pending, 0);
}
