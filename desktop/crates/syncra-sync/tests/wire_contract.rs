//! Regression suite for the AUTH-1 wire audit (`docs/DESKTOP-OPEN-ITEMS.md` §5).
//!
//! Every test here locks a mismatch that the *existing* suite was green on: wiremock and
//! PHPUnit agreed with each other, not with the real wire. So each one is written against the
//! shape the live backend actually produces — the nested `{"errors": {...}}` envelope, the
//! `min:1` rule on `window_days`, the `DELETE /api/me/devices/{id}` call a logout owes the
//! server — rather than against whatever was convenient to mock.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::{Entity, LocalMutation, LogoutOutcome, SyncError};
use uuid::Uuid;

// ---------------------------------------------------------------------------
// U3 — window_days
// ---------------------------------------------------------------------------

/// A bootstrap pull carries the retention window; every later pull carries no window key at
/// all. The server validates `sometimes|nullable|integer|min:1|max:365`, so the `0` this used
/// to send made the pull half of **every** sync round answer 422.
#[tokio::test]
async fn only_a_bootstrap_pull_carries_a_window() {
    let h = Harness::start().await;
    h.login().await;
    mount_pull(&h.server, pull_body(vec![])).await;

    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    h.engine.sync_now().await.expect("sync");

    let requests = pull_requests(&h.server).await;
    assert!(requests.len() >= 2, "expected a bootstrap and a delta pull");

    assert_eq!(
        requests[0]["window_days"], 30,
        "the bootstrap applies the retention window (K12)"
    );
    for (index, request) in requests.iter().enumerate().skip(1) {
        assert!(
            request.as_object().unwrap().get("window_days").is_none(),
            "delta pull #{index} must omit window_days entirely: {request}"
        );
    }
}

/// `download_archive` is bootstrap-shaped — it resets the cursors — so it does send a window,
/// and the value stays inside the server's `1..=365` rule.
#[tokio::test]
async fn download_archive_sends_a_window_inside_the_servers_range() {
    let h = Harness::start().await;
    h.login().await;
    mount_pull(&h.server, pull_body(vec![])).await;

    h.engine.download_archive(10_000).await.expect("archive");

    let request = pull_requests(&h.server).await.pop().expect("a pull");
    let window = request["window_days"].as_u64().expect("window_days");
    assert!(
        (1..=365).contains(&window),
        "window_days must satisfy the server's min:1|max:365; got {window}"
    );
}

// ---------------------------------------------------------------------------
// U11 / U12 — the server's own code and retry_after survive
// ---------------------------------------------------------------------------

/// `401 INVALID_CREDENTIALS` reaches the caller as that code, not as a `VALIDATION_ERROR`
/// whose message happens to contain the word.
#[tokio::test]
async fn a_rejected_password_keeps_the_servers_code() {
    let h = Harness::start_with_granted(&[]).await;
    mount_error(
        &h.server,
        "POST",
        "/api/auth/device",
        401,
        "INVALID_CREDENTIALS",
        None,
    )
    .await;

    let err = h
        .engine
        .login("a@b.c", "wrong", device_info())
        .await
        .unwrap_err();

    assert_eq!(err.code(), "INVALID_CREDENTIALS", "got {err:?}");
    assert_eq!(err.retry_after(), None);
}

/// `423 LOCKED_OUT` carries `retry_after`, which is what `LoginPage`'s countdown reads. It
/// used to be dropped entirely, so the countdown could not work on the desktop at all.
#[tokio::test]
async fn a_lockout_carries_retry_after() {
    let h = Harness::start_with_granted(&[]).await;
    mount_error(
        &h.server,
        "POST",
        "/api/auth/device",
        423,
        "LOCKED_OUT",
        Some(137),
    )
    .await;

    let err = h
        .engine
        .login("a@b.c", "secret", device_info())
        .await
        .unwrap_err();

    assert_eq!(err.code(), "LOCKED_OUT", "got {err:?}");
    assert_eq!(
        err.retry_after(),
        Some(137),
        "the lockout countdown needs the server's retry_after"
    );
    assert_eq!(err.server().map(|e| e.status), Some(423));
}

/// A `422` — the status U1 produced on every single login — is a validation failure with the
/// offending field in the message, not an opaque `Protocol` error.
#[tokio::test]
async fn a_422_is_reported_as_a_validation_failure() {
    let h = Harness::start_with_granted(&[]).await;
    wiremock::Mock::given(wiremock::matchers::method("POST"))
        .and(wiremock::matchers::path("/api/auth/device"))
        .respond_with(wiremock::ResponseTemplate::new(422).set_body_json(json!({
            "message": "The device fingerprint field must be 64 characters.",
            "errors": {
                "device_fingerprint": ["The device fingerprint field must be 64 characters."]
            }
        })))
        .mount(&h.server)
        .await;

    let err = h
        .engine
        .login("a@b.c", "secret", device_info())
        .await
        .unwrap_err();

    assert_eq!(err.code(), "VALIDATION_ERROR", "got {err:?}");
    assert!(
        err.to_string().contains("422"),
        "the status belongs in the message: {err}"
    );
}

// ---------------------------------------------------------------------------
// U16 / O1 — KARAR A25
// ---------------------------------------------------------------------------

/// KARAR A25: `403 USER_DEACTIVATED` wipes the local database **and** the keychain token.
///
/// This is `docs/DESKTOP-OPEN-ITEMS.md` O1, the item whose decision was recorded and whose
/// code never existed. Before the fix, a deactivated user saw an unactionable "protocol error"
/// and kept a full local mirror.
#[tokio::test]
async fn a_deactivated_account_wipes_the_local_database() {
    let h = Harness::start().await;
    h.login().await;

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
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Unsent" }),
        ))
        .expect("mutate");
    assert_eq!(h.row_count(Entity::Company), 2);
    assert_eq!(h.engine.status().pending, 1);

    // From here on the account is gone. `EnsureUserIsActive` sits in front of every sync
    // route, so all three answer the same way — and the bootstrap above warmed the manifest
    // cache, which means the push is what actually goes out first.
    h.server.reset().await;
    for (verb, endpoint) in [
        ("GET", "/api/sync/manifest"),
        ("POST", "/api/sync/pull"),
        ("POST", "/api/sync/push"),
    ] {
        mount_error(&h.server, verb, endpoint, 403, "USER_DEACTIVATED", None).await;
    }

    let mut events = h.engine.subscribe();
    let err = h.engine.sync_now().await.unwrap_err();
    assert_eq!(err.code(), "USER_DEACTIVATED", "got {err:?}");

    assert_eq!(
        h.row_count(Entity::Company),
        0,
        "A25: a deactivated account takes the local mirror with it"
    );
    assert_eq!(
        h.engine.status().pending,
        0,
        "A25: the outbox goes with the wipe (unlike a bare 401)"
    );
    assert!(h.engine.session().is_none());

    let mut saw_auth_lost = false;
    while let Ok(event) = events.try_recv() {
        if matches!(event, syncra_sync::EngineEvent::AuthLost) {
            saw_auth_lost = true;
        }
    }
    assert!(saw_auth_lost, "the UI still has to be told to sign out");
}

/// The other half of A25, and the reason the two signals cannot be merged: a `403` that is
/// **not** `USER_DEACTIVATED` — `ABILITY_REQUIRED` from `EnsureDeviceToken`, say — must not
/// destroy anything.
#[tokio::test]
async fn a_plain_403_does_not_wipe_anything() {
    let h = Harness::start().await;
    h.login().await;

    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Unsent" }),
        ))
        .expect("mutate");

    h.server.reset().await;
    mount_error(
        &h.server,
        "GET",
        "/api/sync/manifest",
        403,
        "ABILITY_REQUIRED",
        None,
    )
    .await;

    let err = h.engine.sync_now().await.unwrap_err();
    assert_eq!(err.code(), "ABILITY_REQUIRED", "got {err:?}");
    assert_eq!(
        h.engine.status().pending,
        1,
        "only USER_DEACTIVATED wipes; a 403 about the token type does not"
    );
    assert_eq!(h.row_count(Entity::Company), 1);
}

/// And the 401 side stays exactly as §5.5 wrote it: session dropped, outbox kept.
#[tokio::test]
async fn a_bare_401_still_keeps_the_outbox() {
    let h = Harness::start().await;
    h.login().await;

    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Unsent" }),
        ))
        .expect("mutate");

    h.server.reset().await;
    mount_error(
        &h.server,
        "GET",
        "/api/sync/manifest",
        401,
        "UNAUTHENTICATED",
        None,
    )
    .await;

    let err = h.engine.sync_now().await.unwrap_err();
    assert!(matches!(err, SyncError::Auth), "got {err:?}");
    assert_eq!(h.engine.status().pending, 1);
    assert_eq!(h.row_count(Entity::Company), 1);
}

// ---------------------------------------------------------------------------
// U6 — logout revokes the token on the server
// ---------------------------------------------------------------------------

/// A logout must kill the token where it lives. Deleting the keychain entry only makes the
/// client forget it; the personal access token stayed usable on the server.
#[tokio::test]
async fn logout_revokes_the_device_token_on_the_server() {
    let h = Harness::start().await;
    h.login_as(7, "seven@example.com").await;
    let token_id = h.engine.session().expect("session").token_id;

    assert_eq!(h.engine.logout(false).await.unwrap(), LogoutOutcome::Wiped);

    assert_eq!(
        revoke_requests(&h.server).await,
        vec![format!("/api/me/devices/{token_id}")],
        "logout must call DELETE /api/me/devices/{{token_id}} exactly once"
    );
}

/// Offline logout: the server call fails, the local wipe still happens, and the failure is
/// reported rather than swallowed.
#[tokio::test]
async fn an_unreachable_server_still_lets_the_user_log_out_locally() {
    let h = Harness::start().await;
    h.login().await;
    // Drop every mock, then answer the revoke with a 500 the transport cannot mistake for
    // "already gone".
    h.server.reset().await;
    wiremock::Mock::given(wiremock::matchers::method("DELETE"))
        .and(wiremock::matchers::path_regex(r"^/api/me/devices/\d+$"))
        .respond_with(wiremock::ResponseTemplate::new(500))
        .mount(&h.server)
        .await;

    let outcome = h.engine.logout(false).await.unwrap();
    assert!(
        matches!(outcome, LogoutOutcome::WipedLocalOnly(_)),
        "an unrevoked token must be reported, not swallowed; got {outcome:?}"
    );
    assert!(h.engine.session().is_none(), "the local wipe still happens");
    assert_eq!(h.engine.status().pending, 0);
}

/// Refusing a logout over pending work must not touch the server either.
#[tokio::test]
async fn a_refused_logout_revokes_nothing() {
    let h = Harness::start().await;
    h.login().await;
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Unsent" }),
        ))
        .expect("mutate");

    assert_eq!(
        h.engine.logout(false).await.unwrap(),
        LogoutOutcome::PendingMutations(1)
    );
    assert!(
        revoke_requests(&h.server).await.is_empty(),
        "a refused logout must leave the device usable"
    );
}
