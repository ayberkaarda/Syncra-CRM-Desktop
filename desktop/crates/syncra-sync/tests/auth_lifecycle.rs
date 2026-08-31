//! `SYNCDESKTOP.md` §5.7 — 401 keeps the outbox, a different user wipes the database, and a
//! protocol mismatch stops the engine.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::{EngineEvent, Entity, LocalMutation, LogoutOutcome, SyncError};
use uuid::Uuid;

/// §5.5: a 401 raises `AuthLost` and drops the session, but the outbox survives so the same
/// user can log back in and finish what they started.
#[tokio::test]
async fn a_401_raises_auth_lost_and_preserves_the_outbox() {
    let h = Harness::start_with_granted(&[]).await;
    h.login().await;

    let mut events = h.engine.subscribe();

    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Unsent" }),
        ))
        .unwrap();
    assert_eq!(h.engine.status().pending, 1);

    // From here on, the server rejects the token. `reset` drops the harness mocks so the
    // 401 is the only thing left to match.
    h.server.reset().await;
    mount_status(&h.server, "GET", "/api/sync/manifest", 401).await;

    let err = h.engine.sync_now().await.unwrap_err();
    assert!(matches!(err, SyncError::Auth), "expected Auth, got {err:?}");

    assert_eq!(
        h.engine.status().pending,
        1,
        "the outbox must survive a 401 (§5.5)"
    );
    assert!(h.engine.session().is_none());

    let mut saw_auth_lost = false;
    while let Ok(event) = events.try_recv() {
        if matches!(event, EngineEvent::AuthLost) {
            saw_auth_lost = true;
        }
    }
    assert!(saw_auth_lost, "AuthLost must be emitted");
}

/// §5.5: a login by a *different* user wipes the local database.
///
/// O67: the wipe is a privacy boundary, not just a row count reset — user A's cached quote
/// PDFs must not still be readable on disk once user B has logged in on the same machine.
#[tokio::test]
async fn a_different_user_login_wipes_the_local_database() {
    let h = Harness::start().await;
    h.login_as(1, "first@example.com").await;

    mount_pull(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({ "id": 44, "name": "Acme", "sync_version": 10 })],
            vec![],
            10,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Local" }),
        ))
        .unwrap();
    assert_eq!(h.row_count(Entity::Company), 2);
    assert_eq!(h.engine.status().pending, 1);

    // A's cached quote PDF, recorded in the ledger like a real cache write would.
    let blob = write_blob(&h, "quote-44-1.pdf");
    h.engine
        .record_cached_file("quote_pdf", "44-1", &blob, 4096)
        .expect("record cached file");
    assert!(blob.exists(), "the fixture must actually write the file");

    h.login_as(2, "second@example.com").await;

    assert_eq!(
        h.row_count(Entity::Company),
        0,
        "a different user must not inherit the previous user's rows"
    );
    assert_eq!(
        h.engine.status().pending,
        0,
        "the previous user's outbox goes with the wipe"
    );
    assert!(
        !blob.exists(),
        "O67: a different-user login must remove the previous user's cached blobs, not just \
         the ledger rows that named them"
    );
}

/// The same user logging back in keeps everything, including the waiting outbox.
#[tokio::test]
async fn the_same_user_logging_back_in_keeps_the_outbox() {
    let h = Harness::start().await;
    h.login_as(1, "first@example.com").await;

    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Local" }),
        ))
        .unwrap();
    assert_eq!(h.engine.status().pending, 1);

    h.login_as(1, "first@example.com").await;

    assert_eq!(h.engine.status().pending, 1);
    assert_eq!(h.row_count(Entity::Company), 1);
}

/// `logout` refuses to throw away unpushed work unless it is forced.
///
/// O67: a forced logout is one of the three wipe paths (`sync/mod.rs:397`), so it must clear
/// the cached blobs on disk along with the rows that name them.
#[tokio::test]
async fn logout_refuses_while_mutations_are_pending() {
    let h = Harness::start().await;
    h.login().await;

    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Local" }),
        ))
        .unwrap();

    assert_eq!(
        h.engine.logout(false).await.unwrap(),
        LogoutOutcome::PendingMutations(1)
    );
    assert_eq!(h.engine.status().pending, 1);

    let blob = write_blob(&h, "quote-1-1.pdf");
    h.engine
        .record_cached_file("quote_pdf", "1-1", &blob, 4096)
        .expect("record cached file");
    assert!(blob.exists(), "the fixture must actually write the file");

    assert_eq!(h.engine.logout(true).await.unwrap(), LogoutOutcome::Wiped);
    assert_eq!(h.row_count(Entity::Company), 0);
    assert!(h.engine.session().is_none());
    assert!(
        !blob.exists(),
        "O67: a forced logout must remove cached blobs, not just the ledger rows that named \
         them"
    );
}

/// §4.4: a protocol version the client does not implement stops the engine.
#[tokio::test]
async fn a_protocol_mismatch_halts_the_engine() {
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

    mount_manifest(&server, manifest_body(GRANTED, 2)).await;
    mount_device_auth(&server, 1, "a@b.c").await;
    engine.login("a@b.c", "secret", device_info()).await.unwrap();

    let mut events = engine.subscribe();

    let err = engine.sync_now().await.unwrap_err();
    assert!(matches!(err, SyncError::Protocol(_)), "got {err:?}");

    let mut mismatch = None;
    while let Ok(event) = events.try_recv() {
        if let EngineEvent::ProtocolMismatch { server } = event {
            mismatch = Some(server);
        }
    }
    assert_eq!(mismatch, Some(2), "ProtocolMismatch must be emitted");

    // The engine stays halted: a second call fails without touching the network.
    let err = engine.sync_now().await.unwrap_err();
    assert!(matches!(err, SyncError::Protocol(_)));
    assert!(
        pull_requests(&server).await.is_empty(),
        "a halted engine must not pull"
    );
}
