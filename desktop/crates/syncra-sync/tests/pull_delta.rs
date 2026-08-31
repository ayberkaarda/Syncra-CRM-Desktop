//! `SYNCDESKTOP.md` §5.7 — delta pull, tombstones, and `server_id` -> `client_id` mapping.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::db::schema::derive_client_id;
use syncra_sync::{Entity, NamedQuery, QueryParams};

/// A server row created on the web has no `client_id`; §5.3 gives it the deterministic
/// `uuid5(namespace, "entity:server_id")` identity, and foreign keys resolve to it.
#[tokio::test]
async fn web_rows_get_the_deterministic_uuid5_identity() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull(
        &h.server,
        pull_body(vec![
            (
                "companies",
                vec![json!({ "id": 44, "name": "Acme", "sync_version": 10 })],
                vec![],
                10,
                false,
            ),
            (
                "contacts",
                vec![json!({
                    "id": 7, "first_name": "Ada", "last_name": "Lovelace",
                    "company_id": 44, "sync_version": 11
                })],
                vec![],
                11,
                false,
            ),
        ]),
    )
    .await;

    h.engine.bootstrap(|_| {}).await.expect("bootstrap");

    let expected_company = derive_client_id(Entity::Company, 44).to_string();
    let expected_contact = derive_client_id(Entity::Contact, 7).to_string();

    let companies = h.engine.query(NamedQuery::companies(), QueryParams::default()).unwrap();
    assert_eq!(companies[0].get_str("client_id"), Some(expected_company.as_str()));

    let contacts = h
        .engine
        .query(
            NamedQuery::contacts(),
            QueryParams::default(),
        )
        .unwrap();
    assert_eq!(contacts[0].get_str("client_id"), Some(expected_contact.as_str()));
    assert_eq!(
        contacts[0].get_str("company_client_id"),
        Some(expected_company.as_str()),
        "the foreign key must resolve to the parent's local identity"
    );

    // Filtering by the resolved parent works.
    let scoped = h
        .engine
        .query(
            NamedQuery::ContactList {
                q: None,
                company_id: Some(44),
                owner_id: None,
                is_primary: None,
                city: None,
                tag_id: None,
                from: None,
                to: None,
            },
            QueryParams::default(),
        )
        .unwrap();
    assert_eq!(scoped.len(), 1);
}

/// Protocol §6.1 P12: `notifications.id` is already a UUID, so it *is* the `client_id` and
/// no uuid5 derivation happens.
#[tokio::test]
async fn notification_client_id_is_the_server_uuid() {
    let h = Harness::start().await;
    h.login().await;

    let id = "0f0f0f0f-1111-4222-8333-444444444444";
    mount_pull(
        &h.server,
        pull_body(vec![(
            "notifications",
            vec![json!({
                "id": id, "type": "App\\Notifications\\TaskDue",
                "notifiable_type": "App\\Models\\User", "notifiable_id": 1,
                "data": "{}", "read_at": null, "sync_version": 900
            })],
            vec![],
            900,
            false,
        )]),
    )
    .await;

    h.engine.bootstrap(|_| {}).await.expect("bootstrap");

    let rows = h
        .engine
        .query(
            NamedQuery::notifications(Some(syncra_sync::ReadFilter::Unread)),
            QueryParams::default(),
        )
        .unwrap();
    assert_eq!(rows.len(), 1);
    assert_eq!(rows[0].get_str("client_id"), Some(id));
    assert_ne!(
        rows[0].get_str("client_id"),
        Some(derive_client_id(Entity::Notification, 0).to_string().as_str())
    );
}

/// Protocol §2.7 narrows `sync_deletions` to `tags`, `notifications` and
/// `conversation_user`, and the pivot is addressed by `conversation_id:user_id`.
#[tokio::test]
async fn tombstones_arrive_for_the_three_tables_that_have_them() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![
            (
                "tags",
                vec![json!({ "id": 5, "name": "vip", "slug": "vip", "sync_version": 1 })],
                vec![],
                1,
                false,
            ),
            (
                "notifications",
                vec![json!({
                    "id": "aaaaaaaa-1111-4222-8333-444444444444",
                    "type": "T", "notifiable_type": "U", "notifiable_id": 1,
                    "data": "{}", "read_at": null, "sync_version": 2
                })],
                vec![],
                2,
                false,
            ),
            (
                "conversation_user",
                vec![json!({
                    "id": 91, "conversation_id": 7, "user_id": 1,
                    "unread_count": 3, "is_muted": false, "sync_version": 3
                })],
                vec![],
                3,
                false,
            ),
        ]),
    )
    .await;

    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    assert_eq!(h.row_count(Entity::Notification), 1);

    // Second round: everything is deleted.
    mount_pull(
        &h.server,
        pull_body(vec![
            (
                "tags",
                vec![],
                vec![json!({ "row_key": "5", "sync_version": 20 })],
                20,
                false,
            ),
            (
                "notifications",
                vec![],
                vec![json!({
                    "row_key": "aaaaaaaa-1111-4222-8333-444444444444",
                    "sync_version": 21
                })],
                21,
                false,
            ),
            (
                "conversation_user",
                vec![],
                // The logical key, not the surrogate id 91 (protocol §2.7).
                vec![json!({ "row_key": "7:1", "sync_version": 22 })],
                22,
                false,
            ),
        ]),
    )
    .await;

    let report = h.engine.sync_now().await.expect("sync");
    assert_eq!(report.deletions, 3);
    assert_eq!(h.row_count(Entity::Notification), 0);
}

/// A soft-deleted row arrives as a normal row with `deleted_at` set; it becomes a
/// tombstone locally and drops out of the default query.
#[tokio::test]
async fn soft_deleted_rows_become_local_tombstones() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({ "id": 44, "name": "Acme", "deleted_at": null, "sync_version": 10 })],
            vec![],
            10,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    assert_eq!(
        h.engine
            .query(NamedQuery::companies(), QueryParams::default())
            .unwrap()
            .len(),
        1
    );

    mount_pull(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({
                "id": 44, "name": "Acme", "deleted_at": "2026-08-30T10:00:00Z",
                "sync_version": 30
            })],
            vec![],
            30,
            false,
        )]),
    )
    .await;
    h.engine.sync_now().await.expect("sync");

    assert_eq!(
        h.engine
            .query(NamedQuery::companies(), QueryParams::default())
            .unwrap()
            .len(),
        0,
        "a tombstone must not show up in the default listing"
    );
    assert_eq!(
        h.row_count(Entity::Company),
        1,
        "the tombstone row itself is still there until retention removes it"
    );
}

/// `settings.group` is a reserved word; the local mirror spells it `group_name` and the
/// alias table keeps the value from being silently dropped.
#[tokio::test]
async fn the_reserved_settings_group_column_survives_the_pull() {
    let h = Harness::start_with_granted(&[Entity::Setting]).await;
    h.login().await;

    mount_pull(
        &h.server,
        pull_body(vec![(
            "settings",
            vec![json!({
                "id": 3, "key": "company.name", "value": "Syncra",
                "type": "string", "group": "company", "is_public": true,
                "sync_version": 7
            })],
            vec![],
            7,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();

    let row = h
        .engine
        .get(
            Entity::Setting,
            syncra_sync::db::schema::derive_client_id(Entity::Setting, 3),
        )
        .unwrap()
        .expect("the setting must be mirrored");
    assert_eq!(row.get_str("key"), Some("company.name"));
    assert_eq!(row.get_str("group_name"), Some("company"));
}
