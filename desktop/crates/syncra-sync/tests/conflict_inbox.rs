//! `SYNCDESKTOP.md` §5.7 — conflicts land in the inbox, and resolving them behaves.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::{Entity, LocalMutation, NamedQuery, QueryParams, Resolution};
use uuid::Uuid;

/// Seed one server-side deal and return its local id.
async fn seeded_deal(h: &Harness) -> Uuid {
    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "deals",
            vec![json!({
                "id": 18342, "title": "Server title", "amount": "1000.00",
                "status": "open", "position": "a0", "version": 8, "sync_version": 184000
            })],
            vec![],
            184000,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    mount_empty_pull(&h.server).await;

    let rows = h
        .engine
        .query(
            NamedQuery::DealsList {
                status: None,
                owner_client_id: None,
            },
            QueryParams::default(),
        )
        .unwrap();
    Uuid::parse_str(rows[0].get_str("client_id").unwrap()).unwrap()
}

#[tokio::test]
async fn a_field_conflict_lands_in_the_inbox_and_marks_the_row() {
    let h = Harness::start().await;
    h.login().await;
    let deal = seeded_deal(&h).await;

    mount_push(
        &h.server,
        push_body(vec![conflict(
            1,
            &["amount"],
            json!({
                "id": 18342, "title": "Server title", "amount": "9999.00",
                "status": "open", "sync_version": 184990
            }),
            184990,
        )]),
    )
    .await;

    h.engine
        .mutate(LocalMutation::update(
            Entity::Deal,
            deal,
            &["amount"],
            json!({ "amount": "1500.00" }),
        ))
        .unwrap();

    let report = h.engine.sync_now().await.expect("sync");
    assert_eq!(report.conflicts, 1);

    let conflicts = h.engine.conflicts().unwrap();
    assert_eq!(conflicts.len(), 1);
    assert_eq!(conflicts[0].code, "FIELD_CONFLICT");
    assert_eq!(conflicts[0].conflicting_fields, vec!["amount".to_string()]);
    assert_eq!(conflicts[0].entity, Entity::Deal);
    assert_eq!(conflicts[0].client_id, Some(deal));
    assert_eq!(conflicts[0].mine["amount"], "1500.00");
    assert_eq!(conflicts[0].theirs["amount"], "9999.00");

    let row = h.engine.get(Entity::Deal, deal).unwrap().unwrap();
    assert_eq!(row.get_str("sync_state"), Some("conflict"));
    assert_eq!(h.engine.status().conflicts, 1);
}

/// `KeepMine` must produce a *new* mutation carrying the server's current
/// `base_sync_version`; resending against the stale base would loop forever.
#[tokio::test]
async fn keep_mine_requeues_against_the_fresh_base_sync_version() {
    let h = Harness::start().await;
    h.login().await;
    let deal = seeded_deal(&h).await;

    mount_push_once(
        &h.server,
        push_body(vec![conflict(
            1,
            &["amount"],
            json!({
                "id": 18342, "title": "Server title", "amount": "9999.00",
                "status": "open", "sync_version": 184990
            }),
            184990,
        )]),
    )
    .await;
    mount_push_responder(&h.server, ApplyAll).await;

    h.engine
        .mutate(LocalMutation::update(
            Entity::Deal,
            deal,
            &["amount"],
            json!({ "amount": "1500.00" }),
        ))
        .unwrap();
    h.engine.sync_now().await.expect("first round");

    let first_push = &push_requests(&h.server).await[0]["mutations"][0];
    assert_eq!(first_push["base_sync_version"], 184000);

    let conflict_id = h.engine.conflicts().unwrap()[0].id;
    h.engine
        .resolve_conflict(conflict_id, Resolution::KeepMine)
        .unwrap();

    assert!(h.engine.conflicts().unwrap().is_empty());
    assert_eq!(
        h.engine.status().pending,
        1,
        "KeepMine must produce a new pending mutation"
    );
    let row = h.engine.get(Entity::Deal, deal).unwrap().unwrap();
    assert_eq!(row.get_str("sync_state"), Some("pending"));
    assert_eq!(row.get_str("amount"), Some("1500.00"), "mine wins locally");

    h.engine.sync_now().await.expect("second round");
    let second_push = &push_requests(&h.server).await[1]["mutations"][0];
    assert_eq!(
        second_push["base_sync_version"], 184990,
        "the resend must be based on the version the server reported"
    );
    assert_eq!(second_push["payload"]["amount"], "1500.00");
    assert_eq!(second_push["changed_fields"], json!(["amount"]));
}

#[tokio::test]
async fn take_server_drops_the_local_change() {
    let h = Harness::start().await;
    h.login().await;
    let deal = seeded_deal(&h).await;

    mount_push(
        &h.server,
        push_body(vec![conflict(
            1,
            &["amount"],
            json!({
                "id": 18342, "title": "Server title", "amount": "9999.00",
                "status": "open", "sync_version": 184990
            }),
            184990,
        )]),
    )
    .await;

    h.engine
        .mutate(LocalMutation::update(
            Entity::Deal,
            deal,
            &["amount"],
            json!({ "amount": "1500.00" }),
        ))
        .unwrap();
    h.engine.sync_now().await.expect("sync");

    let conflict_id = h.engine.conflicts().unwrap()[0].id;
    h.engine
        .resolve_conflict(conflict_id, Resolution::TakeServer)
        .unwrap();

    assert!(h.engine.conflicts().unwrap().is_empty());
    assert_eq!(h.engine.status().pending, 0);
    let row = h.engine.get(Entity::Deal, deal).unwrap().unwrap();
    assert_eq!(row.get_str("amount"), Some("9999.00"));
    assert_eq!(row.get_str("sync_state"), Some("synced"));
}

/// A terminal `rejected` result is inbox material too, and §5.4 cascades the failure onto
/// whatever depended on the rejected create.
#[tokio::test]
async fn a_rejected_create_cascades_unresolved_reference_onto_its_dependents() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;

    let company = Uuid::now_v7();
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            company,
            json!({ "name": "Rejected Co" }),
        ))
        .unwrap();
    h.engine
        .mutate(LocalMutation::create(
            Entity::Contact,
            Uuid::now_v7(),
            json!({
                "first_name": "Dep", "last_name": "Endant",
                "company_client_id": company.to_string()
            }),
        ))
        .unwrap();

    // The server rejects the company and never gets to the contact (a short `results`
    // array, protocol §4.3 P10b). The contact returns to `queued` and is then failed by the
    // cascade, because its parent can never exist.
    mount_push(&h.server, push_body(vec![rejected(1, "INVALID_MUTATION")])).await;

    h.engine.sync_now().await.expect("sync");

    let codes: Vec<String> = h
        .engine
        .conflicts()
        .unwrap()
        .into_iter()
        .map(|c| c.code)
        .collect();
    assert!(codes.contains(&"INVALID_MUTATION".to_string()));
    assert!(
        codes.contains(&"UNRESOLVED_REFERENCE".to_string()),
        "a dependent of a rejected create must fall into the inbox: {codes:?}"
    );
}
