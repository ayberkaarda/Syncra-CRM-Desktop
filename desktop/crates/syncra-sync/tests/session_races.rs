//! A response belongs to the data it was requested against.
//!
//! Every wipe of the local database (a login by another user, a logout, `clear_local`, a
//! `403 USER_DEACTIVATED`) ends that data. A pull or a push that was already on the wire when
//! it happened carries an answer about data that no longer exists, and none of it may be
//! written into what comes after: not the rows, not the cursors, not a conflict. A login by the
//! *same* user wipes nothing, and a response across it is applied as usual.
//!
//! The order of events is not left to timing. The engine talks to the mock server through
//! [`gate::Proxy`], which forwards a held request to the server at once (so the server has
//! seen and answered it) but keeps the answer from the engine until the test releases it. A
//! test therefore knows the request reached the server before the wipe, and that the wipe
//! finished before the answer arrived; it asserts both.

mod common;

use common::*;
use serde_json::{json, Value};
use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;
use syncra_sync::keystore::{KeyStore, KEY_DB};
use syncra_sync::{
    Entity, LocalMutation, MemoryKeyStore, RealtimeEvent, SyncConfig, SyncEngine, SyncReport,
};
use uuid::Uuid;
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, Request, Respond, ResponseTemplate};

// ---------------------------------------------------------------------------
// A proxy that can hold an answer
// ---------------------------------------------------------------------------

mod gate {
    //! Plain blocking HTTP/1.1 forwarding, one thread per connection. wiremock serves on a
    //! single-threaded runtime, so a responder cannot block without stalling every other
    //! request; holding the answer here, outside the server, does not.

    use std::io::{Read, Write};
    use std::net::{SocketAddr, TcpListener, TcpStream};
    use std::sync::atomic::{AtomicBool, Ordering};
    use std::sync::{Arc, Condvar, Mutex};
    use std::time::Duration;

    #[derive(Default)]
    struct HoldState {
        arrived: AtomicBool,
        released: Mutex<bool>,
        wake: Condvar,
    }

    /// One held answer: the next request to a path.
    pub struct Held(Arc<HoldState>);

    impl Held {
        /// Wait until the request has reached the server and the server has answered it.
        pub async fn arrived(&self) {
            for _ in 0..2000 {
                if self.0.arrived.load(Ordering::SeqCst) {
                    return;
                }
                tokio::time::sleep(Duration::from_millis(5)).await;
            }
            panic!("the held request never reached the server");
        }

        /// Let the server's answer through to the engine.
        pub fn release(&self) {
            *self.0.released.lock().unwrap() = true;
            self.0.wake.notify_all();
        }
    }

    type Holds = Arc<Mutex<Vec<(String, Arc<HoldState>)>>>;

    pub struct Proxy {
        pub addr: SocketAddr,
        holds: Holds,
    }

    impl Proxy {
        pub fn start(upstream: SocketAddr) -> Proxy {
            let listener = TcpListener::bind("127.0.0.1:0").expect("bind proxy");
            let addr = listener.local_addr().expect("proxy addr");
            let holds: Holds = Arc::default();
            let shared = holds.clone();
            std::thread::spawn(move || {
                for client in listener.incoming() {
                    let Ok(client) = client else { return };
                    let holds = shared.clone();
                    std::thread::spawn(move || serve(client, upstream, holds));
                }
            });
            Proxy { addr, holds }
        }

        /// Hold the answer to the next request whose path is `path`.
        pub fn hold(&self, path: &str) -> Held {
            let state = Arc::new(HoldState::default());
            self.holds.lock().unwrap().push((path.to_string(), state.clone()));
            Held(state)
        }
    }

    fn serve(mut client: TcpStream, upstream: SocketAddr, holds: Holds) {
        while let Some((raw, path)) = read_message(&mut client, true) {
            let mut server = TcpStream::connect(upstream).expect("connect upstream");
            server.write_all(&raw).expect("forward request");
            let Some((answer, _)) = read_message(&mut server, false) else { return };

            let hold = {
                let mut holds = holds.lock().unwrap();
                holds
                    .iter()
                    .position(|(p, _)| *p == path)
                    .map(|i| holds.remove(i).1)
            };
            if let Some(hold) = hold {
                hold.arrived.store(true, Ordering::SeqCst);
                let mut released = hold.released.lock().unwrap();
                while !*released {
                    let (guard, timeout) = hold
                        .wake
                        .wait_timeout(released, Duration::from_secs(30))
                        .unwrap();
                    released = guard;
                    if timeout.timed_out() {
                        break;
                    }
                }
            }
            if client.write_all(&answer).is_err() {
                return;
            }
        }
    }

    /// One whole HTTP/1.1 message off `stream`, and the request path when it is a request.
    fn read_message(stream: &mut TcpStream, request: bool) -> Option<(Vec<u8>, String)> {
        let mut raw = Vec::new();
        let mut buf = [0u8; 8192];
        let head_end = loop {
            if let Some(i) = raw.windows(4).position(|w| w == b"\r\n\r\n") {
                break i + 4;
            }
            let n = stream.read(&mut buf).ok()?;
            if n == 0 {
                return None;
            }
            raw.extend_from_slice(&buf[..n]);
        };
        let head = String::from_utf8_lossy(&raw[..head_end]).to_string();
        let mut lines = head.split("\r\n");
        let first = lines.next().unwrap_or_default().to_string();
        let mut length = 0usize;
        for line in lines {
            let lower = line.to_ascii_lowercase();
            if let Some(value) = lower.strip_prefix("content-length:") {
                length = value.trim().parse().unwrap_or(0);
            }
            assert!(
                !lower.starts_with("transfer-encoding:"),
                "the proxy only handles content-length bodies"
            );
        }
        while raw.len() < head_end + length {
            let n = stream.read(&mut buf).ok()?;
            if n == 0 {
                return None;
            }
            raw.extend_from_slice(&buf[..n]);
        }
        let path = if request {
            first.split(' ').nth(1).unwrap_or_default().split('?').next().unwrap_or_default().to_string()
        } else {
            String::new()
        };
        Some((raw, path))
    }
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

/// The user a request was authenticated as, read off its bearer token.
fn user_of(req: &Request) -> i64 {
    req.headers
        .get("authorization")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer token-for-"))
        .and_then(|id| id.parse().ok())
        .unwrap_or(0)
}

/// Three companies per user, at versions only that user's rows use.
struct PerUserPull;

impl Respond for PerUserPull {
    fn respond(&self, req: &Request) -> ResponseTemplate {
        let user = user_of(req);
        let rows: Vec<Value> = (1..=3)
            .map(|n| {
                json!({
                    "id": user * 100 + n,
                    "name": format!("user-{user}-row-{n}"),
                    "sync_version": user * 100 + n,
                })
            })
            .collect();
        ResponseTemplate::new(200)
            .set_body_json(pull_body(vec![("companies", rows, vec![], user * 100 + 3, false)]))
    }
}

/// Answers every mutation of a push with one verdict.
struct Verdict(&'static str);

impl Respond for Verdict {
    fn respond(&self, req: &Request) -> ResponseTemplate {
        let body: Value = serde_json::from_slice(&req.body).expect("push body");
        let results: Vec<Value> = body["mutations"]
            .as_array()
            .expect("mutations")
            .iter()
            .map(|m| {
                let seq = m["seq"].as_i64().expect("seq");
                match self.0 {
                    "conflict" => conflict(seq, &["name"], json!({"id": 9, "name": "Theirs", "sync_version": 7}), 7),
                    "rejected" => rejected(seq, "INVALID_MUTATION"),
                    _ => applied(seq, 900 + seq, 50 + seq),
                }
            })
            .collect();
        ResponseTemplate::new(200).set_body_json(push_body(results))
    }
}

/// What the next push request gets, after the server has dealt with it.
#[derive(Clone, Copy, PartialEq)]
enum Answer {
    /// Apply it and say so.
    Normal,
    /// Apply it, then answer 500: the result is stored, the device never hears it.
    LostAfterApply,
    /// Process nothing and leave every `seq` out of `results` (protocol §4.3 P10b).
    Deferred,
}

#[derive(Default)]
struct ServerState {
    /// `idempotency_key` → stored result, replayed for a known key whatever the payload.
    results: HashMap<String, Value>,
    /// `client_id` → (`name`, deleted).
    rows: HashMap<String, (String, bool)>,
    by_server_id: HashMap<i64, String>,
    version: i64,
    answers: Vec<Answer>,
    /// Every mutation that changed something, in order.
    applied: Vec<Value>,
}

/// A push endpoint with the server's idempotency ledger: a key it has seen gets the stored
/// result back, and its payload is ignored.
#[derive(Clone, Default)]
struct LedgerServer(Arc<Mutex<ServerState>>);

impl LedgerServer {
    fn answer_next(&self, answer: Answer) {
        self.0.lock().unwrap().answers.push(answer);
    }

    fn row(&self, client_id: Uuid) -> Option<(String, bool)> {
        self.0.lock().unwrap().rows.get(&client_id.to_string()).cloned()
    }

    fn applied(&self) -> Vec<Value> {
        self.0.lock().unwrap().applied.clone()
    }
}

impl Respond for LedgerServer {
    fn respond(&self, req: &Request) -> ResponseTemplate {
        let body: Value = serde_json::from_slice(&req.body).expect("push body");
        let mut state = self.0.lock().unwrap();
        let answer = if state.answers.is_empty() { Answer::Normal } else { state.answers.remove(0) };
        let mut results = Vec::new();
        for m in body["mutations"].as_array().expect("mutations") {
            let seq = m["seq"].as_i64().expect("seq");
            let key = m["idempotency_key"].as_str().expect("key").to_string();
            if let Some(stored) = state.results.get(&key) {
                let mut replay = stored.clone();
                replay["seq"] = json!(seq);
                replay["status"] = json!("duplicate");
                results.push(replay);
                continue;
            }
            if answer == Answer::Deferred {
                continue;
            }
            let client_id = m["client_id"]
                .as_str()
                .map(str::to_string)
                .or_else(|| m["server_id"].as_i64().and_then(|id| state.by_server_id.get(&id).cloned()))
                .expect("a mutation the server can place");
            state.version += 1;
            let version = state.version;
            match m["op"].as_str().expect("op") {
                "create" => {
                    let server_id = 500 + state.by_server_id.len() as i64;
                    state.by_server_id.insert(server_id, client_id.clone());
                    let name = m["payload"]["name"].as_str().unwrap_or_default().to_string();
                    state.rows.insert(client_id.clone(), (name, false));
                }
                "update" => {
                    let row = state.rows.get_mut(&client_id).expect("update of a known row");
                    if let Some(name) = m["payload"]["name"].as_str() {
                        row.0 = name.to_string();
                    }
                }
                "delete" => {
                    state.rows.get_mut(&client_id).expect("delete of a known row").1 = true;
                }
                other => panic!("unexpected op {other}"),
            }
            let server_id = state
                .by_server_id
                .iter()
                .find(|(_, c)| **c == client_id)
                .map(|(id, _)| *id)
                .expect("server id");
            let result = json!({ "seq": seq, "status": "applied", "server_id": server_id, "sync_version": version });
            state.results.insert(key, result.clone());
            state.applied.push(m.clone());
            results.push(result);
        }
        if answer == Answer::LostAfterApply {
            return ResponseTemplate::new(500).set_body_json(json!({ "errors": { "code": "SERVER_ERROR" } }));
        }
        ResponseTemplate::new(200).set_body_json(push_body(results))
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

struct Setup {
    h: Harness,
    proxy: gate::Proxy,
}

/// An engine that talks to `server` through a [`gate::Proxy`].
async fn engine_behind_proxy(server: MockServer) -> (Harness, gate::Proxy) {
    let proxy = gate::Proxy::start(*server.address());
    let dir = tempfile::tempdir().expect("tempdir");
    let cfg = SyncConfig::new(
        url::Url::parse(&format!("http://{}/api/", proxy.addr)).expect("url"),
        dir.path().join("syncra.db"),
    );
    let db_path = cfg.db_path.clone();
    let service = cfg.keychain_service.clone();
    let keystore = Arc::new(MemoryKeyStore::new());
    let engine = SyncEngine::open_with_keystore(cfg, keystore.clone())
        .await
        .expect("open engine");
    let db_key = keystore.get(&service, KEY_DB).expect("keystore").expect("db key");
    (Harness { server, engine, dir, db_key, db_path }, proxy)
}

/// Logged in as user 1; every user's pull answers with that user's own rows. `manifest_ok`
/// limits how many manifests are served before `403 USER_DEACTIVATED`; `None` serves them
/// for the whole test.
async fn setup(manifest_ok: Option<u64>) -> Setup {
    let server = MockServer::start().await;
    let manifest = Mock::given(method("GET"))
        .and(path("/api/sync/manifest"))
        .respond_with(ResponseTemplate::new(200).set_body_json(manifest_body(&[Entity::Company], 1)));
    match manifest_ok {
        Some(n) => {
            manifest.up_to_n_times(n).mount(&server).await;
            mount_error(&server, "GET", "/api/sync/manifest", 403, "USER_DEACTIVATED", None).await;
        }
        None => manifest.mount(&server).await,
    }
    Mock::given(method("POST"))
        .and(path("/api/sync/pull"))
        .respond_with(PerUserPull)
        .mount(&server)
        .await;
    let (h, proxy) = engine_behind_proxy(server).await;
    h.login_as(1, "a@example.com").await;
    Setup { h, proxy }
}

async fn mount_verdict(h: &Harness, verdict: &'static str) {
    Mock::given(method("POST"))
        .and(path("/api/sync/push"))
        .respond_with(Verdict(verdict))
        .up_to_n_times(1)
        .mount(&h.server)
        .await;
}

async fn mount_ledger(h: &Harness) -> LedgerServer {
    let ledger = LedgerServer::default();
    mount_push_responder(&h.server, ledger.clone()).await;
    ledger
}

fn create_company(h: &Harness, name: &str) -> Uuid {
    let id = Uuid::now_v7();
    h.engine
        .mutate(LocalMutation::create(Entity::Company, id, json!({ "name": name })))
        .expect("mutate");
    id
}

fn rename(h: &Harness, id: Uuid, name: &str) {
    h.engine
        .mutate(LocalMutation::update(Entity::Company, id, &["name"], json!({ "name": name })))
        .expect("rename");
}

fn delete(h: &Harness, id: Uuid) {
    h.engine.mutate(LocalMutation::delete(Entity::Company, id)).expect("delete");
}

fn count(h: &Harness, sql: &str) -> i64 {
    h.raw_conn().query_row(sql, [], |r| r.get(0)).expect("count")
}

fn rows_of(h: &Harness, user: i64) -> i64 {
    count(h, &format!("SELECT count(*) FROM companies WHERE name LIKE 'user-{user}-row-%'"))
}

fn companies_cursor(h: &Harness) -> i64 {
    h.raw_conn()
        .query_row("SELECT sync_version FROM cursors WHERE table_name = 'companies'", [], |r| r.get(0))
        .unwrap_or(-1)
}

/// `(idempotency_key, attempts, state)` of every outbox row, in `seq` order.
fn outbox(h: &Harness) -> Vec<(String, i64, String)> {
    let conn = h.raw_conn();
    let mut stmt = conn
        .prepare("SELECT idempotency_key, attempts, state FROM outbox ORDER BY seq")
        .expect("prepare");
    let rows = stmt
        .query_map([], |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?)))
        .expect("query");
    rows.map(|r| r.expect("row")).collect()
}

fn realtime_companies() -> RealtimeEvent {
    RealtimeEvent { entities: vec![Entity::Company] }
}

/// Run `call`, and record when it has returned.
async fn tracked<F: std::future::Future>(done: &AtomicBool, call: F) -> F::Output {
    let out = call.await;
    done.store(true, Ordering::SeqCst);
    out
}

fn assert_empty_report(report: &SyncReport) {
    assert_eq!(
        (report.pushed, report.pulled_rows, report.conflicts),
        (0, 0, 0),
        "a round whose data was wiped under it reports nothing"
    );
}

// ---------------------------------------------------------------------------
// 1. A login by another user while a pull is on the wire
// ---------------------------------------------------------------------------

/// User A's pull reaches the server, user B logs in and the mirror is wiped for B, then A's
/// answer arrives. It must not land: B would see A's rows, and A's cursors would carry B's
/// first delta past everything B is allowed to see.
#[tokio::test(flavor = "multi_thread")]
async fn a_pull_started_for_one_user_never_lands_in_the_next_users_mirror() {
    let s = setup(None).await;
    let held = s.proxy.hold("/api/sync/pull");
    let done = AtomicBool::new(false);

    let login_b = async {
        held.arrived().await;
        assert_eq!(pull_requests(&s.h.server).await.len(), 1, "A's pull reached the server");
        s.h.login_as(2, "b@example.com").await;
        assert!(!done.load(Ordering::SeqCst), "A's pull finished before B logged in");
        held.release();
    };
    tokio::join!(tracked(&done, s.h.engine.handle_realtime(realtime_companies())), login_b);

    assert_eq!(rows_of(&s.h, 1), 0, "user B's mirror holds user A's rows");
    assert_eq!(companies_cursor(&s.h), -1, "user A's cursor survived into user B's session");

    // B's own first pull starts from nothing and brings B's rows only.
    s.h.engine.sync_now().await.expect("user B's round");
    assert_eq!((rows_of(&s.h, 1), rows_of(&s.h, 2)), (0, 3));
    let last = pull_requests(&s.h.server).await.pop().expect("a pull");
    assert_eq!(last["cursors"]["companies"], json!(0), "B's pull did not start from zero");
}

/// Two logins in quick succession while A's pull is on the wire.
#[tokio::test(flavor = "multi_thread")]
async fn two_quick_logins_do_not_let_the_first_users_pull_through() {
    let s = setup(None).await;
    let held = s.proxy.hold("/api/sync/pull");
    let done = AtomicBool::new(false);

    let logins = async {
        held.arrived().await;
        s.h.login_as(2, "b@example.com").await;
        s.h.login_as(3, "c@example.com").await;
        assert!(!done.load(Ordering::SeqCst), "A's pull finished before the logins");
        held.release();
    };
    tokio::join!(tracked(&done, s.h.engine.handle_realtime(realtime_companies())), logins);

    assert_eq!(s.h.engine.session().map(|s| s.user_id), Some(3));
    assert_eq!(rows_of(&s.h, 1), 0, "user C's mirror holds user A's rows");
    assert_eq!(companies_cursor(&s.h), -1);
}

/// A logout followed by another user's login, with A's pull on the wire. Logout waits for the
/// pull lock, so here A's answer lands first and the logout wipes it.
#[tokio::test(flavor = "multi_thread")]
async fn logout_then_login_does_not_let_the_first_users_pull_through() {
    let s = setup(None).await;
    let held = s.proxy.hold("/api/sync/pull");

    let switch = async {
        held.arrived().await;
        let logout = async {
            s.h.engine.logout(true).await.expect("logout");
            s.h.login_as(2, "b@example.com").await;
        };
        // The logout is polled first, so it is already queued on the pull lock when the
        // answer is let through.
        tokio::join!(logout, async { held.release() });
    };
    tokio::join!(s.h.engine.handle_realtime(realtime_companies()), switch);

    assert_eq!(rows_of(&s.h, 1), 0, "user B's mirror holds user A's rows");
    assert_eq!(companies_cursor(&s.h), -1);
}

/// A bootstrap of user A's whose pull is overtaken by user B's login does not fail: it starts
/// again for B and loads B's rows, which is what B's login asked the shell for.
#[tokio::test(flavor = "multi_thread")]
async fn a_bootstrap_overtaken_by_another_login_finishes_for_the_new_user() {
    let s = setup(None).await;
    let held = s.proxy.hold("/api/sync/pull");
    let done = AtomicBool::new(false);

    let login_b = async {
        held.arrived().await;
        s.h.login_as(2, "b@example.com").await;
        assert!(!done.load(Ordering::SeqCst), "A's bootstrap finished before B logged in");
        held.release();
    };
    let (boot, ()) = tokio::join!(tracked(&done, s.h.engine.bootstrap(|_| {})), login_b);

    boot.expect("a superseded bootstrap is not an error");
    assert_eq!((rows_of(&s.h, 1), rows_of(&s.h, 2)), (0, 3));
}

// ---------------------------------------------------------------------------
// 2. USER_DEACTIVATED while a pull is on the wire
// ---------------------------------------------------------------------------

fn assert_wiped(h: &Harness) {
    assert_eq!(count(h, "SELECT count(*) FROM companies"), 0, "rows from before the wipe came back");
    assert_eq!(companies_cursor(h), -1, "a cursor from before the wipe came back");
    assert_eq!(count(h, "SELECT count(*) FROM outbox"), 0, "outbox rows survived the wipe");
    assert_eq!(count(h, "SELECT count(*) FROM conflicts"), 0, "a conflict from before the wipe was written");
    assert!(h.engine.session().is_none(), "the session survived the wipe");
}

/// A push answers `403 USER_DEACTIVATED` and the mirror is wiped while a realtime pull that
/// was authorised before it is still on the wire.
#[tokio::test(flavor = "multi_thread")]
async fn a_deactivation_answered_to_a_push_is_not_undone_by_an_earlier_pull() {
    let s = setup(None).await;
    mount_error(&s.h.server, "POST", "/api/sync/push", 403, "USER_DEACTIVATED", None).await;
    create_company(&s.h, "Unsent");
    let held = s.proxy.hold("/api/sync/pull");
    let done = AtomicBool::new(false);

    let round = async {
        held.arrived().await;
        let round = s.h.engine.sync_now().await;
        assert!(!done.load(Ordering::SeqCst), "the pull finished before the wipe");
        held.release();
        round
    };
    let (_, round) = tokio::join!(tracked(&done, s.h.engine.handle_realtime(realtime_companies())), round);

    assert_eq!(round.unwrap_err().code(), "USER_DEACTIVATED");
    assert_wiped(&s.h);
}

/// The same, with the manifest call being the one that hears it.
#[tokio::test(flavor = "multi_thread")]
async fn a_deactivation_answered_to_a_manifest_is_not_undone_by_an_earlier_pull() {
    // One manifest for the warm-up below, `403 USER_DEACTIVATED` afterwards.
    let s = setup(Some(1)).await;
    s.h.engine.restore_session().await.expect("warm the manifest cache");
    let held = s.proxy.hold("/api/sync/pull");
    let done = AtomicBool::new(false);

    let restore = async {
        held.arrived().await;
        let restored = s.h.engine.restore_session().await;
        assert!(!done.load(Ordering::SeqCst), "the pull finished before the wipe");
        held.release();
        restored
    };
    let (_, restored) = tokio::join!(tracked(&done, s.h.engine.handle_realtime(realtime_companies())), restore);

    assert_eq!(restored.unwrap_err().code(), "USER_DEACTIVATED");
    assert_wiped(&s.h);
}

/// A push and a pull both on the wire when a third call hears `USER_DEACTIVATED`: neither the
/// pull's rows nor the push's conflict may be written afterwards.
#[tokio::test(flavor = "multi_thread")]
async fn a_deactivation_with_a_push_and_a_pull_both_on_the_wire_keeps_both_out() {
    let s = setup(Some(1)).await;
    s.h.engine.restore_session().await.expect("warm the manifest cache");
    mount_verdict(&s.h, "conflict").await;
    create_company(&s.h, "Mine");
    let held_pull = s.proxy.hold("/api/sync/pull");
    let held_push = s.proxy.hold("/api/sync/push");
    let (pull_done, push_done) = (AtomicBool::new(false), AtomicBool::new(false));

    let restore = async {
        held_pull.arrived().await;
        held_push.arrived().await;
        let restored = s.h.engine.restore_session().await;
        assert!(!pull_done.load(Ordering::SeqCst) && !push_done.load(Ordering::SeqCst));
        held_pull.release();
        held_push.release();
        restored
    };
    let (_, round, restored) = tokio::join!(
        tracked(&pull_done, s.h.engine.handle_realtime(realtime_companies())),
        tracked(&push_done, s.h.engine.sync_now()),
        restore,
    );

    assert_eq!(restored.unwrap_err().code(), "USER_DEACTIVATED");
    assert_empty_report(&round.expect("the overtaken round is not an error"));
    assert_wiped(&s.h);
}

/// The error handler runs while the pull that failed still holds the pull lock. The wipe must
/// not wait for that lock, or the engine would deadlock on itself.
#[tokio::test(flavor = "multi_thread")]
async fn a_deactivation_answered_to_the_pull_itself_does_not_deadlock() {
    let h = Harness::start().await;
    mount_error(&h.server, "POST", "/api/sync/pull", 403, "USER_DEACTIVATED", None).await;
    h.login().await;
    create_company(&h, "Unsent");
    // The push half has to succeed so the round reaches the pull.
    mount_push(&h.server, push_body(vec![])).await;

    let round = tokio::time::timeout(Duration::from_secs(5), h.engine.sync_now())
        .await
        .expect("the wipe waited for the lock its own caller holds");
    assert_eq!(round.unwrap_err().code(), "USER_DEACTIVATED");

    // `clear_local` takes the pull lock, so it only returns if the failed pull let go of it.
    tokio::time::timeout(Duration::from_secs(5), h.engine.clear_local())
        .await
        .expect("the pull lock was never released")
        .expect("clear");
    assert_eq!(count(&h, "SELECT count(*) FROM companies"), 0);
    assert!(h.engine.session().is_none());
}

/// A deactivation the server answered to user A's push arrives after user B has logged in.
/// It is about A's token, and must neither wipe B's data nor drop B's token.
#[tokio::test(flavor = "multi_thread")]
async fn a_deactivation_meant_for_the_previous_session_leaves_the_next_one_alone() {
    let s = setup(None).await;
    mount_error(&s.h.server, "POST", "/api/sync/push", 403, "USER_DEACTIVATED", None).await;
    create_company(&s.h, "A's");
    let held = s.proxy.hold("/api/sync/push");

    let login_b = async {
        held.arrived().await;
        s.h.login_as(2, "b@example.com").await;
        create_company(&s.h, "B's");
        held.release();
    };
    let (_round, ()) = tokio::join!(s.h.engine.sync_now(), login_b);

    assert_eq!(s.h.engine.session().map(|s| s.user_id), Some(2), "B's session was dropped");
    assert_eq!(s.h.engine.status().pending, 1, "B's unsent work was wiped");
    assert_eq!(count(&s.h, "SELECT count(*) FROM companies WHERE name = 'B''s'"), 1);
}

/// A manifest for user A that says "incompatible protocol" arrives after user B has logged
/// in. It must not halt B's engine.
#[tokio::test(flavor = "multi_thread")]
async fn an_incompatible_manifest_for_the_previous_user_does_not_halt_the_next_one() {
    struct PerUserManifest;
    impl Respond for PerUserManifest {
        fn respond(&self, req: &Request) -> ResponseTemplate {
            let version = if user_of(req) == 1 { 2 } else { 1 };
            ResponseTemplate::new(200).set_body_json(manifest_body(&[Entity::Company], version))
        }
    }
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path("/api/sync/manifest"))
        .respond_with(PerUserManifest)
        .mount(&server)
        .await;
    Mock::given(method("POST")).and(path("/api/sync/pull")).respond_with(PerUserPull).mount(&server).await;
    let (h, proxy) = engine_behind_proxy(server).await;
    h.login_as(1, "a@example.com").await;
    let held = proxy.hold("/api/sync/manifest");
    let done = AtomicBool::new(false);

    let login_b = async {
        held.arrived().await;
        h.login_as(2, "b@example.com").await;
        assert!(!done.load(Ordering::SeqCst), "A's manifest arrived before B logged in");
        held.release();
    };
    let (restored, ()) = tokio::join!(tracked(&done, h.engine.restore_session()), login_b);

    assert_eq!(
        restored.expect("an overtaken restore is not an error").map(|s| s.user_id),
        Some(2)
    );
    h.engine.sync_now().await.expect("B's engine was halted by A's manifest");
    assert_eq!(rows_of(&h, 2), 3);
}

// ---------------------------------------------------------------------------
// 3. A push verdict for a batch sent before a clear
// ---------------------------------------------------------------------------

/// The push reaches the server, local data is cleared, then the server's `conflict` arrives.
/// The batch's outbox rows are gone, and the conflict must not be written from the copy kept
/// in memory. The round itself ends quietly: nothing the user can act on happened.
#[tokio::test(flavor = "multi_thread")]
async fn a_conflict_for_a_batch_sent_before_a_clear_is_not_written_back() {
    let s = setup(None).await;
    mount_verdict(&s.h, "conflict").await;
    create_company(&s.h, "Mine");
    let held = s.proxy.hold("/api/sync/push");
    let done = AtomicBool::new(false);

    let clear = async {
        held.arrived().await;
        s.h.engine.clear_local().await.expect("clear");
        assert!(!done.load(Ordering::SeqCst), "the push finished before the clear");
        held.release();
    };
    let (round, ()) = tokio::join!(tracked(&done, s.h.engine.sync_now()), clear);

    assert_empty_report(&round.expect("an overtaken round is not an error"));
    assert_eq!(count(&s.h, "SELECT count(*) FROM conflicts"), 0, "a pre-clear conflict was written");
    assert!(s.h.engine.conflicts().expect("conflicts").is_empty());
    assert_eq!(s.h.engine.status().conflicts, 0);
    assert_eq!(count(&s.h, "SELECT count(*) FROM outbox"), 0);
}

/// Same, with a `rejected` create (which also walks its dependents).
#[tokio::test(flavor = "multi_thread")]
async fn a_rejection_for_a_batch_sent_before_a_clear_is_not_written_back() {
    let s = setup(None).await;
    mount_verdict(&s.h, "rejected").await;
    create_company(&s.h, "Mine");
    let held = s.proxy.hold("/api/sync/push");
    let done = AtomicBool::new(false);

    let clear = async {
        held.arrived().await;
        s.h.engine.clear_local().await.expect("clear");
        assert!(!done.load(Ordering::SeqCst), "the push finished before the clear");
        held.release();
    };
    let (_round, ()) = tokio::join!(tracked(&done, s.h.engine.sync_now()), clear);

    assert_eq!(count(&s.h, "SELECT count(*) FROM conflicts"), 0, "a pre-clear rejection was written");
    assert_eq!(s.h.engine.status().conflicts, 0);
}

/// A forced logout and the same user logging back in while the push is on the wire.
#[tokio::test(flavor = "multi_thread")]
async fn a_conflict_for_a_batch_sent_before_a_logout_is_not_written_after_the_next_login() {
    let s = setup(None).await;
    mount_verdict(&s.h, "conflict").await;
    create_company(&s.h, "Mine");
    let held = s.proxy.hold("/api/sync/push");
    let done = AtomicBool::new(false);

    let switch = async {
        held.arrived().await;
        s.h.engine.logout(true).await.expect("logout");
        s.h.login_as(1, "a@example.com").await;
        assert!(!done.load(Ordering::SeqCst), "the push finished before the logout");
        held.release();
    };
    let (_round, ()) = tokio::join!(tracked(&done, s.h.engine.sync_now()), switch);

    assert_eq!(count(&s.h, "SELECT count(*) FROM conflicts"), 0, "a pre-logout conflict was written");
    assert_eq!(s.h.engine.status().conflicts, 0);
}

// ---------------------------------------------------------------------------
// 4. A login by the same user, and requeued mutations
// ---------------------------------------------------------------------------

/// The same user logs in again while a push is on the wire. Nothing was wiped, so the
/// server's verdict is applied as usual; an edit made afterwards is a new mutation and
/// reaches the server.
#[tokio::test(flavor = "multi_thread")]
async fn a_relogin_by_the_same_user_mid_push_keeps_the_verdict_and_the_next_edit() {
    let s = setup(None).await;
    let ledger = mount_ledger(&s.h).await;
    let id = create_company(&s.h, "Mine");
    let held = s.proxy.hold("/api/sync/push");
    let done = AtomicBool::new(false);

    let relogin = async {
        held.arrived().await;
        s.h.login_as(1, "a@example.com").await;
        assert!(!done.load(Ordering::SeqCst), "the push finished before the relogin");
        held.release();
    };
    let (round, ()) = tokio::join!(tracked(&done, s.h.engine.sync_now()), relogin);
    let queued_after_relogin = outbox(&s.h).len();

    rename(&s.h, id, "Renamed");
    s.h.engine.sync_now().await.expect("the next round");

    assert_eq!(ledger.row(id), Some(("Renamed".to_string(), false)), "the edit never reached the server");
    assert_eq!(s.h.engine.status().pending, 0);
    assert_eq!(round.expect("round").applied, 1, "the verdict was dropped");
    assert_eq!(queued_after_relogin, 0, "the accepted create was put back in the queue");
}

/// Same, with a delete after the relogin: the server's row must go too.
#[tokio::test(flavor = "multi_thread")]
async fn a_relogin_by_the_same_user_mid_push_then_a_delete_reaches_the_server() {
    let s = setup(None).await;
    let ledger = mount_ledger(&s.h).await;
    let id = create_company(&s.h, "Mine");
    let held = s.proxy.hold("/api/sync/push");

    let relogin = async {
        held.arrived().await;
        s.h.login_as(1, "a@example.com").await;
        held.release();
    };
    let (_round, ()) = tokio::join!(s.h.engine.sync_now(), relogin);

    delete(&s.h, id);
    s.h.engine.sync_now().await.expect("the next round");

    assert_eq!(ledger.row(id).map(|r| r.1), Some(true), "the server's row outlived the delete");
}

/// Another user logs in while user A's push is on the wire. A's outbox went with the wipe;
/// nothing of it is put back or sent again.
#[tokio::test(flavor = "multi_thread")]
async fn a_different_user_login_mid_push_puts_nothing_back() {
    let s = setup(None).await;
    let ledger = mount_ledger(&s.h).await;
    create_company(&s.h, "A's");
    let held = s.proxy.hold("/api/sync/push");

    let login_b = async {
        held.arrived().await;
        s.h.login_as(2, "b@example.com").await;
        held.release();
    };
    let (round, ()) = tokio::join!(s.h.engine.sync_now(), login_b);
    assert_empty_report(&round.expect("an overtaken round is not an error"));

    assert!(outbox(&s.h).is_empty(), "user A's batch came back into user B's outbox");
    s.h.engine.sync_now().await.expect("user B's round");
    assert_eq!(push_requests(&s.h.server).await.len(), 1, "user A's batch was sent again");
    assert_eq!(ledger.applied().len(), 1);
}

/// The server applies a create and its answer is lost (500). The device requeues the create
/// under its key; an edit and a delete made before the resend are queued on their own, so the
/// replay of the old key cannot swallow them.
#[tokio::test(flavor = "multi_thread")]
async fn an_answer_lost_after_the_server_applied_it_does_not_swallow_later_edits() {
    let s = setup(None).await;
    let ledger = mount_ledger(&s.h).await;
    ledger.answer_next(Answer::LostAfterApply);
    let id = create_company(&s.h, "Mine");

    let lost = s.h.engine.sync_now().await;
    assert!(lost.is_err(), "the 500 is an error the round reports");
    let first_key = outbox(&s.h)[0].0.clone();
    assert_eq!(outbox(&s.h), vec![(first_key.clone(), 1, "queued".to_string())]);

    rename(&s.h, id, "Renamed");
    delete(&s.h, id);
    let queued = outbox(&s.h);
    assert_eq!(queued.len(), 3, "the edit or the delete was folded into the sent create: {queued:?}");
    assert_eq!(queued[0], (first_key.clone(), 1, "queued".to_string()), "the sent create changed");

    // A 5xx marks the engine offline (O25); the network is back.
    s.h.engine.set_online(true);
    s.h.engine.sync_now().await.expect("the resend");

    let pushes = push_requests(&s.h.server).await;
    assert_eq!(pushes[1]["mutations"][0]["idempotency_key"], json!(first_key), "the resend changed key");
    let ops: Vec<String> = ledger
        .applied()
        .iter()
        .map(|m| m["op"].as_str().unwrap_or_default().to_string())
        .collect();
    assert_eq!(ops, ["create", "update", "delete"], "the server did not see every change");
    assert_eq!(ledger.row(id), Some(("Renamed".to_string(), true)));
    assert!(outbox(&s.h).is_empty());
}

/// A mutation the server left out of `results` (protocol §4.3 P10b) goes back to the queue
/// with its attempts untouched and its key unchanged, and a later edit does not ride on it.
#[tokio::test(flavor = "multi_thread")]
async fn a_deferred_mutation_keeps_its_key_and_attempts_and_takes_no_later_edit() {
    let s = setup(None).await;
    let ledger = mount_ledger(&s.h).await;
    let id = create_company(&s.h, "Mine");
    s.h.engine.sync_now().await.expect("create");
    ledger.answer_next(Answer::Deferred);
    rename(&s.h, id, "First");

    let report = s.h.engine.sync_now().await.expect("deferred round");
    assert_eq!(report.deferred, 1);
    let key = outbox(&s.h)[0].0.clone();
    assert_eq!(outbox(&s.h), vec![(key.clone(), 0, "queued".to_string())], "attempts moved");

    rename(&s.h, id, "Second");
    assert_eq!(outbox(&s.h).len(), 2, "the edit was folded into a row that was already sent");

    s.h.engine.sync_now().await.expect("the resend");
    let pushes = push_requests(&s.h.server).await;
    assert_eq!(pushes.last().unwrap()["mutations"][0]["idempotency_key"], json!(key));
    assert_eq!(ledger.row(id), Some(("Second".to_string(), false)));
    assert!(outbox(&s.h).is_empty());
}
