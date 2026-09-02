//! O25 — a `5xx` is a server error, not "offline".
//!
//! Before this fix, `transport.rs` folded every `5xx` into `SyncError::Offline`, so a real
//! `500 SQLSTATE[42S22]` on the backend showed the user "no internet connection" instead of a
//! server failure. The transport still treats a `5xx` as transient (the sync loop backs off
//! and retries exactly as it always did for `Offline`) — only the *shape* of the error
//! changes, from `Offline` to `Server{status, code, ..}`.
//!
//! Three things have to hold at once, which is why this file exists instead of one assertion
//! bolted onto `wire_contract.rs`:
//! 1. a `5xx` becomes `Server`, carries the status, and is not `Offline`;
//! 2. a request that never reaches a server at all — no mock, no listener — is still
//!    `Offline`/`Http`, i.e. separating `5xx` from `Offline` did not touch the real offline
//!    path;
//! 3. a `4xx` is `Server` too, but does not flip the engine's online flag the way `Offline`
//!    and a `5xx` do — only the two "the network or the server is broken" shapes should.

mod common;

use common::*;
use syncra_sync::transport::Transport;
use syncra_sync::SyncError;
use url::Url;

// ---------------------------------------------------------------------------
// 1. Positive: a 5xx is `Server`, not `Offline`.
// ---------------------------------------------------------------------------

/// A `500` on `sync/manifest` reaches `sync_now`'s caller as `SyncError::Server` carrying
/// `status: 500`, and it is explicitly not `SyncError::Offline`.
#[tokio::test]
async fn a_500_is_reported_as_a_server_error_not_offline() {
    let h = Harness::start().await;
    h.login().await;

    // `login` does not warm the manifest cache (only `restore_session`/`bootstrap` do), so
    // the next round's `load_manifest` is a real request and lands on this mock.
    h.server.reset().await;
    mount_error(&h.server, "GET", "/api/sync/manifest", 500, "HTTP_500", None).await;

    let err = h.engine.sync_now().await.unwrap_err();

    assert!(
        !matches!(err, SyncError::Offline),
        "a 5xx must not be reported as offline: {err:?}"
    );
    let server = err.server().expect("a 500 must carry a ServerError");
    assert_eq!(server.status, 500);
    assert_eq!(err.code(), "HTTP_500");
}

/// The same holds for a `503` with no body at all (`mount_status`'s shape), which is closer
/// to what a real crash or a proxy in front of a dead app server sends.
#[tokio::test]
async fn a_503_with_no_body_is_still_a_server_error() {
    let h = Harness::start().await;
    h.login().await;

    h.server.reset().await;
    mount_status(&h.server, "GET", "/api/sync/manifest", 503).await;

    let err = h.engine.sync_now().await.unwrap_err();

    assert!(!matches!(err, SyncError::Offline), "got {err:?}");
    let server = err.server().expect("a 503 must carry a ServerError");
    assert_eq!(server.status, 503);
}

/// A round that fails on a `5xx` still marks the engine offline (`sync/mod.rs`'s
/// `set_online_flag`), the same signal a real `Offline` produces — the UI's connectivity
/// indicator must not stay green while the server is down.
#[tokio::test]
async fn a_5xx_round_flips_the_engines_online_flag_off() {
    let h = Harness::start().await;
    h.login().await;
    assert!(h.engine.status().online, "a fresh login starts online");

    h.server.reset().await;
    mount_error(&h.server, "GET", "/api/sync/manifest", 500, "HTTP_500", None).await;

    let _ = h.engine.sync_now().await.unwrap_err();
    assert!(
        !h.engine.status().online,
        "a 5xx round must flip online off, same as a real Offline round"
    );
}

// ---------------------------------------------------------------------------
// 2. Negative control: a genuinely unreachable server is still Offline/Http.
// ---------------------------------------------------------------------------

/// A request that never finds a server at all — nothing is listening on the port — still
/// classifies as `Offline` (or `Http`, transport's other network-failure arm). Splitting
/// `5xx` out of `Offline` in `transport.rs::decode` must not have disturbed
/// `transport.rs::classify`, which is what actually handles connection failures.
#[tokio::test]
async fn a_truly_unreachable_server_is_still_offline() {
    // Bind to an ephemeral port, then drop the listener immediately: the OS gives the port
    // back, but nothing answers on it, so the connection is refused right away instead of
    // timing out (this must stay fast; `Transport`'s request timeout is 60s).
    let listener = std::net::TcpListener::bind("127.0.0.1:0").expect("bind ephemeral port");
    let port = listener.local_addr().expect("local_addr").port();
    drop(listener);

    let transport = Transport::new(Url::parse(&format!("http://127.0.0.1:{port}/api/")).unwrap())
        .expect("build transport");

    let err = transport.manifest("does-not-matter").await.unwrap_err();

    assert!(
        matches!(err, SyncError::Offline | SyncError::Http(_)),
        "an unreachable server must not be reported as a server error: {err:?}"
    );
    assert!(
        err.server().is_none(),
        "a connection failure carries no ServerError"
    );
}

// ---------------------------------------------------------------------------
// 3. Negative control: a 4xx is `Server`, but does not enter the offline/backoff path.
// ---------------------------------------------------------------------------

/// A `422` is a permanent refusal, not a transient one: it must still surface as `Server`
/// (this was already true before O25 — `decode`'s `!status.is_success()` branch), but unlike
/// a `5xx` it must not flip the engine's online flag. That flag is what the background loop
/// reads before deciding whether to fast-retry (`sync/mod.rs::is_server_5xx`); a `4xx` fixing
/// itself on a fast retry makes no sense, so it must not look like a connectivity problem.
#[tokio::test]
async fn a_422_is_a_server_error_but_does_not_flip_the_online_flag() {
    let h = Harness::start().await;
    h.login().await;
    assert!(h.engine.status().online);

    h.server.reset().await;
    mount_error(
        &h.server,
        "GET",
        "/api/sync/manifest",
        422,
        "VALIDATION_ERROR",
        None,
    )
    .await;

    let err = h.engine.sync_now().await.unwrap_err();

    let server = err.server().expect("a 422 must carry a ServerError");
    assert_eq!(server.status, 422);
    assert!(
        h.engine.status().online,
        "a 4xx must not be treated like Offline/5xx connectivity trouble"
    );
}

/// The same 401/403 behaviour `wire_contract.rs` already locks stays true here too: a bare
/// 401 is `Auth`, not `Server`, and does not flip the online flag either — O25 only touches
/// the `5xx` branch of `decode`.
#[tokio::test]
async fn a_401_still_does_not_flip_the_online_flag() {
    let h = Harness::start().await;
    h.login().await;

    h.server.reset().await;
    mount_error(&h.server, "GET", "/api/sync/manifest", 401, "UNAUTHENTICATED", None).await;

    let err = h.engine.sync_now().await.unwrap_err();
    assert!(matches!(err, SyncError::Auth), "got {err:?}");
    assert!(
        h.engine.status().online,
        "a 401 is an auth problem, not a connectivity one"
    );
}
