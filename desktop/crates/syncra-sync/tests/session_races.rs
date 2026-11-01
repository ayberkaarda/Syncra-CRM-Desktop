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
use syncra_sync::keystore::{KeyStore, KEY_DB, KEY_TOKEN};
use syncra_sync::{
    Entity, LocalMutation, LogoutOutcome, MemoryKeyStore, RealtimeEvent, SyncConfig, SyncEngine,
    SyncReport,
};
use uuid::Uuid;
use wiremock::matchers::{header, method, path};
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

/// One server row, with the write history the conflict check reads.
struct ServerRow {
    server_id: i64,
    name: String,
    deleted: bool,
    version: i64,
    /// `(version, created, fields written)`, one entry per write: the audit trail.
    history: Vec<(i64, bool, Vec<String>)>,
}

#[derive(Default)]
struct ServerState {
    /// `idempotency_key` → stored result, replayed for a known key whatever the payload.
    results: HashMap<String, Value>,
    /// `client_id` → row.
    rows: HashMap<String, ServerRow>,
    by_server_id: HashMap<i64, String>,
    version: i64,
    answers: Vec<Answer>,
    /// Every mutation that changed something, in order.
    applied: Vec<Value>,
    /// Every `conflict` or `rejected` result, in order.
    refused: Vec<Value>,
}

/// A push endpoint that behaves like the real one where it matters here:
///
/// * the idempotency ledger: a key it has seen gets the stored result back, and its payload
///   is ignored (`SyncPushService::replayOf`); only `applied` results are stored;
/// * the conflict check of `ConflictDetector::detect`: an update whose `base_sync_version` is
///   below the row's version conflicts on every field of its `changed_fields` that a write
///   above the base touched. With a base above 0 the row's `created` entry does not count;
///   with a base of 0, or none, it does;
/// * `changed_fields` is the contract: only those fields of the payload are written;
/// * a target addressed by an unknown `client_id` is `UNRESOLVED_REFERENCE`
///   (`MutationApplier::applyUpdate`/`applyDelete`), and a delete addressed by an unknown
///   `server_id` is a `duplicate`.
#[derive(Clone, Default)]
struct LedgerServer(Arc<Mutex<ServerState>>);

impl LedgerServer {
    fn answer_next(&self, answer: Answer) {
        self.0.lock().unwrap().answers.push(answer);
    }

    fn row(&self, client_id: Uuid) -> Option<(String, bool)> {
        self.0
            .lock()
            .unwrap()
            .rows
            .get(&client_id.to_string())
            .map(|row| (row.name.clone(), row.deleted))
    }

    fn applied(&self) -> Vec<Value> {
        self.0.lock().unwrap().applied.clone()
    }

    fn refused(&self) -> Vec<Value> {
        self.0.lock().unwrap().refused.clone()
    }

    /// Somebody else renames the row on the server.
    fn foreign_rename(&self, client_id: Uuid, name: &str) {
        let mut state = self.0.lock().unwrap();
        state.version += 1;
        let version = state.version;
        let row = state.rows.get_mut(&client_id.to_string()).expect("a known row");
        row.name = name.to_string();
        row.version = version;
        row.history.push((version, false, vec!["name".to_string()]));
    }
}

/// `ConflictDetector::detect` over a [`ServerRow`]: the conflicting fields, empty to apply.
fn conflicting_fields(row: &ServerRow, changed: &[String], base: Option<i64>) -> Vec<String> {
    if base.is_some_and(|base| row.version <= base) {
        return Vec::new();
    }
    let since = base.unwrap_or(0);
    let touched: Vec<&String> = row
        .history
        .iter()
        .filter(|(version, created, _)| *version > since && !(*created && since > 0))
        .flat_map(|(_, _, fields)| fields.iter())
        .collect();
    changed.iter().filter(|f| touched.contains(f)).cloned().collect()
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
            let op = m["op"].as_str().expect("op").to_string();
            let target = match m["client_id"].as_str() {
                Some(client_id) => Some(client_id.to_string()),
                None => m["server_id"].as_i64().and_then(|id| state.by_server_id.get(&id).cloned()),
            };
            let known = target.as_ref().is_some_and(|c| state.rows.contains_key(c));

            let refusal = match op.as_str() {
                "create" => None,
                "update" | "delete" if !known && m["client_id"].is_string() => {
                    Some(rejected(seq, "UNRESOLVED_REFERENCE"))
                }
                "delete" if !known => {
                    results.push(duplicate(seq, m["server_id"].as_i64().unwrap_or_default()));
                    continue;
                }
                "update" if !known => Some(rejected(seq, "RECORD_DELETED")),
                "update" => {
                    let row = &state.rows[target.as_ref().unwrap()];
                    let changed: Vec<String> = m["changed_fields"]
                        .as_array()
                        .map(|a| a.iter().filter_map(|f| f.as_str().map(str::to_string)).collect())
                        .unwrap_or_default();
                    let fields = conflicting_fields(row, &changed, m["base_sync_version"].as_i64());
                    if fields.is_empty() {
                        None
                    } else {
                        let names: Vec<&str> = fields.iter().map(String::as_str).collect();
                        Some(conflict(
                            seq,
                            &names,
                            json!({ "id": row.server_id, "name": row.name, "sync_version": row.version }),
                            row.version,
                        ))
                    }
                }
                "delete" => None,
                other => panic!("unexpected op {other}"),
            };
            if let Some(refusal) = refusal {
                state.refused.push(refusal.clone());
                results.push(refusal);
                continue;
            }

            let client_id = target.expect("a mutation the server can place");
            state.version += 1;
            let version = state.version;
            match op.as_str() {
                "create" => {
                    if let Some(row) = state.rows.get(&client_id) {
                        // The UNIQUE `client_id` index: a second create of the same row is the
                        // first one replayed.
                        results.push(json!({ "seq": seq, "status": "duplicate", "server_id": row.server_id, "sync_version": row.version }));
                        continue;
                    }
                    let server_id = 500 + state.by_server_id.len() as i64;
                    state.by_server_id.insert(server_id, client_id.clone());
                    let name = m["payload"]["name"].as_str().unwrap_or_default().to_string();
                    let fields = m["payload"]
                        .as_object()
                        .map(|o| o.keys().cloned().collect())
                        .unwrap_or_default();
                    state.rows.insert(
                        client_id.clone(),
                        ServerRow { server_id, name, deleted: false, version, history: vec![(version, true, fields)] },
                    );
                }
                "update" => {
                    let changed: Vec<String> = m["changed_fields"]
                        .as_array()
                        .map(|a| a.iter().filter_map(|f| f.as_str().map(str::to_string)).collect())
                        .unwrap_or_default();
                    let row = state.rows.get_mut(&client_id).expect("update of a known row");
                    if changed.iter().any(|f| f == "name") {
                        if let Some(name) = m["payload"]["name"].as_str() {
                            row.name = name.to_string();
                        }
                    }
                    row.version = version;
                    row.history.push((version, false, changed));
                }
                "delete" => {
                    let row = state.rows.get_mut(&client_id).expect("delete of a known row");
                    row.deleted = true;
                    row.version = version;
                    row.history.push((version, false, vec!["deleted_at".to_string()]));
                }
                _ => unreachable!(),
            }
            let server_id = state.rows[&client_id].server_id;
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
    keystore: Arc<MemoryKeyStore>,
}

impl Setup {
    /// The device token in the keychain, as the next app start would find it.
    fn stored_token(&self) -> Option<String> {
        let service =
            SyncConfig::new(url::Url::parse("http://127.0.0.1/api/").unwrap(), "x").keychain_service;
        self.keystore.get(&service, KEY_TOKEN).expect("keystore")
    }
}

/// An engine that talks to `server` through a [`gate::Proxy`].
async fn engine_behind_proxy(server: MockServer) -> (Harness, gate::Proxy) {
    let (h, proxy, _) = engine_behind_proxy_with_keystore(server).await;
    (h, proxy)
}

/// Same, also handing back the keystore the engine keeps its secrets in.
async fn engine_behind_proxy_with_keystore(
    server: MockServer,
) -> (Harness, gate::Proxy, Arc<MemoryKeyStore>) {
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
    (Harness { server, engine, dir, db_key, db_path }, proxy, keystore)
}

/// Logged in as user 1; every user's pull answers with that user's own rows. `manifest_ok`
/// limits how many manifests are served before `403 USER_DEACTIVATED`; `None` serves them
/// for the whole test.
async fn setup(manifest_ok: Option<u64>) -> Setup {
    setup_with_manifest(manifest_ok, manifest_body(&[Entity::Company], 1)).await
}

/// [`setup`] with pushes cut into batches of at most `batch_max` mutations.
async fn setup_with_batch_max(batch_max: u32) -> Setup {
    let mut manifest = manifest_body(&[Entity::Company], 1);
    manifest["policy"]["push_batch_max"] = json!(batch_max);
    setup_with_manifest(None, manifest).await
}

async fn setup_with_manifest(manifest_ok: Option<u64>, manifest_body: Value) -> Setup {
    let server = MockServer::start().await;
    let manifest = Mock::given(method("GET"))
        .and(path("/api/sync/manifest"))
        .respond_with(ResponseTemplate::new(200).set_body_json(manifest_body));
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
    let (h, proxy, keystore) = engine_behind_proxy_with_keystore(server).await;
    h.login_as(1, "a@example.com").await;
    Setup { h, proxy, keystore }
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
    // Pushes: the create, the deferred round, then the resend. The follow-up edit goes in a
    // batch of its own after the resend's verdict, which rebased it.
    assert_eq!(pushes[2]["mutations"][0]["idempotency_key"], json!(key), "the resend changed key");
    assert_eq!(ledger.row(id), Some(("Second".to_string(), false)), "the follow-up edit was lost");
    assert_eq!(pushes[2]["mutations"].as_array().map(Vec::len), Some(1));
    assert!(outbox(&s.h).is_empty());
}

// ---------------------------------------------------------------------------
// 5. A logout whose revocation is overtaken
// ---------------------------------------------------------------------------

/// User A's logout is waiting for the server to revoke A's token when user B logs in and
/// makes an edit. When the revocation comes back, the logout belongs to a session that no
/// longer exists: B's token, mirror and outbox stay.
#[tokio::test(flavor = "multi_thread")]
async fn a_logout_overtaken_by_another_login_leaves_the_new_session_alone() {
    let s = setup(None).await;
    let held = s.proxy.hold("/api/me/devices/10");

    let login_b = async {
        held.arrived().await;
        s.h.login_as(2, "b@example.com").await;
        create_company(&s.h, "B's");
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(true), login_b);

    assert!(logout.is_err(), "the overtaken logout reported success: {logout:?}");
    assert_eq!(s.h.engine.session().map(|s| s.user_id), Some(2), "B's session was dropped");
    assert_eq!(s.h.engine.status().pending, 1, "B's unsent work was wiped");
    assert_eq!(count(&s.h, "SELECT count(*) FROM companies WHERE name = 'B''s'"), 1);
    assert_eq!(s.stored_token().as_deref(), Some("token-for-2"), "B's stored token was deleted");
}

/// The same with the same user signing in again: the new token is not the one being revoked.
#[tokio::test(flavor = "multi_thread")]
async fn a_logout_overtaken_by_a_relogin_keeps_the_new_token() {
    let s = setup(None).await;
    let held = s.proxy.hold("/api/me/devices/10");

    let relogin = async {
        held.arrived().await;
        s.h.login_as(1, "a@example.com").await;
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(false), relogin);

    assert!(logout.is_err(), "the overtaken logout reported success: {logout:?}");
    assert!(s.h.engine.session().is_some(), "the new session was dropped");
    assert_eq!(s.stored_token().as_deref(), Some("token-for-1"), "the new stored token was deleted");
}

/// An edit made while the revocation is on the wire is refused rather than wiped.
#[tokio::test(flavor = "multi_thread")]
async fn an_edit_made_during_a_logout_is_kept() {
    let s = setup(None).await;
    let held = s.proxy.hold("/api/me/devices/10");

    let edit = async {
        held.arrived().await;
        create_company(&s.h, "Late");
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(false), edit);

    assert_eq!(logout.expect("logout"), LogoutOutcome::PendingMutations(1));
    assert_eq!(s.h.engine.status().pending, 1, "the late edit was wiped");
    assert!(s.h.engine.session().is_some());
}

/// `force` covers the edits that existed when the user agreed to lose them, not one made
/// after that.
#[tokio::test(flavor = "multi_thread")]
async fn a_forced_logout_does_not_wipe_an_edit_made_after_it_started() {
    let s = setup(None).await;
    create_company(&s.h, "Early");
    let held = s.proxy.hold("/api/me/devices/10");

    let edit = async {
        held.arrived().await;
        create_company(&s.h, "Late");
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(true), edit);

    assert_eq!(logout.expect("logout"), LogoutOutcome::PendingMutations(2));
    assert_eq!(count(&s.h, "SELECT count(*) FROM companies"), 2, "an edit was wiped");
}

/// The same with the late edit folded into a row that already existed (no new `seq`).
#[tokio::test(flavor = "multi_thread")]
async fn a_forced_logout_does_not_wipe_an_edit_folded_after_it_started() {
    let s = setup(None).await;
    let id = create_company(&s.h, "Early");
    let held = s.proxy.hold("/api/me/devices/10");

    let edit = async {
        held.arrived().await;
        rename(&s.h, id, "Late");
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(true), edit);

    assert_eq!(logout.expect("logout"), LogoutOutcome::PendingMutations(1));
    assert_eq!(count(&s.h, "SELECT count(*) FROM companies WHERE name = 'Late'"), 1);
}

/// Nothing happened during the revocation: a forced logout still wipes.
#[tokio::test(flavor = "multi_thread")]
async fn a_forced_logout_with_no_late_edit_still_wipes() {
    let s = setup(None).await;
    create_company(&s.h, "Early");
    assert_eq!(s.h.engine.logout(true).await.expect("logout"), LogoutOutcome::Wiped);
    assert_eq!(count(&s.h, "SELECT count(*) FROM outbox"), 0);
    assert!(s.h.engine.session().is_none());
    assert_eq!(s.stored_token(), None);
}

/// From here on, every request made with `user`'s token answers 401.
async fn reject_token_of(h: &Harness, user: i64) {
    Mock::given(header("authorization", format!("Bearer token-for-{user}").as_str()))
        .respond_with(ResponseTemplate::new(401).set_body_json(json!({ "message": "Unauthenticated." })))
        .with_priority(1)
        .mount(&h.server)
        .await;
}

/// A's revocation is on the wire; B logs in, pulls B's rows, and a 401 then drops B's token
/// (the data stays for B, §5.5). When A's revocation comes back, no token exists, but the
/// mirror is B's: the logout must not wipe it.
#[tokio::test(flavor = "multi_thread")]
async fn a_logout_overtaken_by_a_login_that_then_lost_its_token_leaves_the_new_data_alone() {
    let s = setup(None).await;
    let held = s.proxy.hold("/api/me/devices/10");

    let login_b = async {
        held.arrived().await;
        s.h.login_as(2, "b@example.com").await;
        s.h.engine.sync_now().await.expect("B's round");
        assert_eq!(rows_of(&s.h, 2), 3, "B's rows did not come down");
        reject_token_of(&s.h, 2).await;
        assert!(s.h.engine.sync_now().await.is_err(), "B's 401 was not reported");
        assert!(s.h.engine.session().is_none(), "the 401 did not drop B's session");
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(true), login_b);

    assert!(logout.is_err(), "the overtaken logout reported success: {logout:?}");
    assert_eq!(rows_of(&s.h, 2), 3, "B's mirror was wiped by A's logout");
    assert_eq!(companies_cursor(&s.h), 203, "B's cursor was wiped by A's logout");
}

/// Control: a 401 for the departing session itself, with no login in between, is still that
/// session, and the logout wipes it.
#[tokio::test(flavor = "multi_thread")]
async fn a_logout_whose_own_token_answers_401_meanwhile_still_wipes() {
    let s = setup(None).await;
    s.h.engine.sync_now().await.expect("A's round");
    assert_eq!(rows_of(&s.h, 1), 3);
    let held = s.proxy.hold("/api/me/devices/10");

    let lost = async {
        held.arrived().await;
        reject_token_of(&s.h, 1).await;
        // The revocation itself is held at the proxy, so this 401 comes from a sync request.
        assert!(s.h.engine.sync_now().await.is_err(), "A's 401 was not reported");
        assert!(s.h.engine.session().is_none(), "the 401 did not drop A's session");
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(true), lost);

    assert!(
        matches!(logout, Ok(LogoutOutcome::Wiped | LogoutOutcome::WipedLocalOnly(_))),
        "the logout did not wipe: {logout:?}"
    );
    assert_eq!(rows_of(&s.h, 1), 0, "A's mirror outlived A's logout");
    assert_eq!(s.stored_token(), None);
}

/// A row stamped ahead of a clock that has since gone back (or in the same millisecond) does
/// not hide a later edit folded into it: the fold is newer by revision, not by wall clock.
#[tokio::test(flavor = "multi_thread")]
async fn a_forced_logout_does_not_wipe_a_fold_whose_clock_went_back() {
    let s = setup(None).await;
    let id = create_company(&s.h, "Early");
    s.h.raw_conn()
        .execute("UPDATE outbox SET occurred_at = '2999-01-01T00:00:00.000Z'", [])
        .expect("stamp ahead");
    let held = s.proxy.hold("/api/me/devices/10");

    let edit = async {
        held.arrived().await;
        rename(&s.h, id, "Late");
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(true), edit);

    assert_eq!(logout.expect("logout"), LogoutOutcome::PendingMutations(1));
    assert_eq!(count(&s.h, "SELECT count(*) FROM companies WHERE name = 'Late'"), 1);
}

/// The newest row settles while the revocation is on the wire, and a new edit then takes its
/// `seq` again. It is still newer than the logout's mark.
#[tokio::test(flavor = "multi_thread")]
async fn a_forced_logout_does_not_wipe_an_edit_that_reused_a_settled_seq() {
    let s = setup(None).await;
    let _ledger = mount_ledger(&s.h).await;
    create_company(&s.h, "Early");
    s.h.raw_conn()
        .execute("UPDATE outbox SET occurred_at = '2999-01-01T00:00:00.000Z'", [])
        .expect("stamp ahead");
    let held = s.proxy.hold("/api/me/devices/10");

    let edit = async {
        held.arrived().await;
        s.h.engine.sync_now().await.expect("the early edit settles");
        assert!(outbox(&s.h).is_empty());
        create_company(&s.h, "Late");
        assert_eq!(count(&s.h, "SELECT seq FROM outbox"), 1, "the new row did not reuse the seq");
        held.release();
    };
    let (logout, ()) = tokio::join!(s.h.engine.logout(true), edit);

    assert_eq!(logout.expect("logout"), LogoutOutcome::PendingMutations(1));
    assert_eq!(count(&s.h, "SELECT count(*) FROM companies WHERE name = 'Late'"), 1);
}

// ---------------------------------------------------------------------------
// 6. A manifest from before a wipe
// ---------------------------------------------------------------------------

/// User 1 is granted companies only, everybody else companies and contacts.
struct PerUserGrants;

impl Respond for PerUserGrants {
    fn respond(&self, req: &Request) -> ResponseTemplate {
        let granted: &[Entity] = if user_of(req) == 1 {
            &[Entity::Company]
        } else {
            &[Entity::Company, Entity::Contact]
        };
        ResponseTemplate::new(200).set_body_json(manifest_body(granted, 1))
    }
}

async fn setup_per_user_grants() -> Setup {
    let server = MockServer::start().await;
    Mock::given(method("GET"))
        .and(path("/api/sync/manifest"))
        .respond_with(PerUserGrants)
        .mount(&server)
        .await;
    Mock::given(method("POST")).and(path("/api/sync/pull")).respond_with(PerUserPull).mount(&server).await;
    let (h, proxy, keystore) = engine_behind_proxy_with_keystore(server).await;
    h.login_as(1, "a@example.com").await;
    Setup { h, proxy, keystore }
}

/// `(user, body)` of every pull the server received.
async fn pulls_by_user(server: &MockServer) -> Vec<(i64, Value)> {
    server
        .received_requests()
        .await
        .unwrap_or_default()
        .into_iter()
        .filter(|r| r.url.path() == "/api/sync/pull")
        .map(|r| (user_of(&r), serde_json::from_slice(&r.body).expect("pull body")))
        .collect()
}

/// Hold user A's realtime pull (so the pull lock is taken), run `call` until its manifest has
/// come back and it waits for that lock, then log user B in and let the pull go.
async fn overtake_after_manifest<F: std::future::Future>(s: &Setup, call: F) -> F::Output {
    s.h.engine.restore_session().await.expect("warm the manifest cache");
    let before = manifest_request_count(&s.h.server).await;
    let held = s.proxy.hold("/api/sync/pull");
    let done = AtomicBool::new(false);

    let race = async {
        held.arrived().await;
        for _ in 0..2000 {
            if manifest_request_count(&s.h.server).await > before {
                break;
            }
            tokio::time::sleep(Duration::from_millis(5)).await;
        }
        assert!(manifest_request_count(&s.h.server).await > before, "the call fetched no manifest");
        // The answer is applied and the call is queued on the pull lock well inside this.
        tokio::time::sleep(Duration::from_millis(300)).await;
        s.h.login_as(2, "b@example.com").await;
        assert!(!done.load(Ordering::SeqCst), "the call finished before B logged in");
        held.release();
    };
    let call = async {
        // Polled after the realtime pull below has taken the lock.
        held.arrived().await;
        tracked(&done, call).await
    };
    let ((), out, ()) = tokio::join!(s.h.engine.handle_realtime(realtime_companies()), call, race);
    out
}

fn assert_no_pull_of_b_used_a_manifest(pulls: &[(i64, Value)]) {
    for (user, body) in pulls {
        if *user == 2 {
            assert!(
                body["cursors"].get("contacts").is_some(),
                "a pull with B's token was driven by A's manifest: {body}"
            );
        }
    }
}

/// An archive download for user A whose manifest arrived before user B logged in: it neither
/// resets B's cursors nor pulls with B's token from A's table list.
#[tokio::test(flavor = "multi_thread")]
async fn an_archive_download_does_not_run_on_a_manifest_from_before_a_login() {
    let s = setup_per_user_grants().await;
    let archive = overtake_after_manifest(&s, s.h.engine.download_archive(30)).await;

    archive.expect("an overtaken archive download is not an error");
    let pulls = pulls_by_user(&s.h.server).await;
    assert_no_pull_of_b_used_a_manifest(&pulls);
    assert!(pulls.iter().all(|(user, _)| *user == 1), "B pulled for an archive B never asked for");
}

/// A bootstrap for user A in the same position starts again with B's own manifest.
#[tokio::test(flavor = "multi_thread")]
async fn a_bootstrap_does_not_run_on_a_manifest_from_before_a_login() {
    let s = setup_per_user_grants().await;
    let boot = overtake_after_manifest(&s, s.h.engine.bootstrap(|_| {})).await;

    boot.expect("an overtaken bootstrap is not an error");
    let pulls = pulls_by_user(&s.h.server).await;
    assert_no_pull_of_b_used_a_manifest(&pulls);
    assert!(pulls.iter().any(|(user, _)| *user == 2), "the bootstrap did not start again for B");
}

// ---------------------------------------------------------------------------
// 7. Push batches read right before they are sent
// ---------------------------------------------------------------------------

/// The first batch is on the wire when a create in the second one is edited. The second batch
/// carries the edit.
#[tokio::test(flavor = "multi_thread")]
async fn an_edit_to_a_later_batch_during_a_round_is_sent() {
    let s = setup_with_batch_max(1).await;
    let ledger = mount_ledger(&s.h).await;
    create_company(&s.h, "X");
    let y = create_company(&s.h, "Y");
    let held = s.proxy.hold("/api/sync/push");

    let edit = async {
        held.arrived().await;
        rename(&s.h, y, "Y2");
        held.release();
    };
    let (round, ()) = tokio::join!(s.h.engine.sync_now(), edit);

    round.expect("round");
    assert_eq!(ledger.row(y), Some(("Y2".to_string(), false)), "the edit never reached the server");
    assert!(outbox(&s.h).is_empty());
}

/// A create in a later batch is cancelled by a delete while the first batch is on the wire.
/// It is not sent.
#[tokio::test(flavor = "multi_thread")]
async fn a_create_cancelled_during_a_round_is_not_sent() {
    let s = setup_with_batch_max(1).await;
    let ledger = mount_ledger(&s.h).await;
    create_company(&s.h, "X");
    let y = create_company(&s.h, "Y");
    let held = s.proxy.hold("/api/sync/push");

    let edit = async {
        held.arrived().await;
        delete(&s.h, y);
        held.release();
    };
    let (round, ()) = tokio::join!(s.h.engine.sync_now(), edit);

    round.expect("round");
    assert_eq!(ledger.row(y), None, "a create the user cancelled reached the server");
    assert!(outbox(&s.h).is_empty());
}

/// The same with two updates folded together in a later batch.
#[tokio::test(flavor = "multi_thread")]
async fn an_update_folded_into_a_later_batch_during_a_round_is_sent() {
    let s = setup_with_batch_max(1).await;
    let ledger = mount_ledger(&s.h).await;
    let x = create_company(&s.h, "X");
    let y = create_company(&s.h, "Y");
    s.h.engine.sync_now().await.expect("creates");
    rename(&s.h, x, "X1");
    rename(&s.h, y, "Y1");
    let held = s.proxy.hold("/api/sync/push");

    let edit = async {
        held.arrived().await;
        rename(&s.h, y, "Y2");
        held.release();
    };
    let (round, ()) = tokio::join!(s.h.engine.sync_now(), edit);

    round.expect("round");
    assert_eq!(ledger.row(y), Some(("Y2".to_string(), false)), "the folded edit was lost");
    assert!(outbox(&s.h).is_empty());
}

// ---------------------------------------------------------------------------
// 8. Nothing goes out ahead of a create the server has not settled
// ---------------------------------------------------------------------------

/// A create went out once and was deferred; the user deletes the row. Next round the create is
/// deferred again, and the delete in the next batch must wait for it instead of being answered
/// `UNRESOLVED_REFERENCE` and parked, which would leave the row on the server for good.
#[tokio::test(flavor = "multi_thread")]
async fn a_delete_waits_for_a_deferred_create() {
    let s = setup_with_batch_max(1).await;
    let ledger = mount_ledger(&s.h).await;
    let x = create_company(&s.h, "X");
    ledger.answer_next(Answer::Deferred);
    s.h.engine.sync_now().await.expect("first round");
    delete(&s.h, x);

    ledger.answer_next(Answer::Deferred);
    s.h.engine.sync_now().await.expect("second round");
    assert!(ledger.refused().is_empty(), "the delete went out ahead of its create: {:?}", ledger.refused());
    assert_eq!(s.h.engine.status().conflicts, 0);

    s.h.engine.sync_now().await.expect("third round");
    assert_eq!(ledger.row(x).map(|r| r.1), Some(true), "the server's row outlived the delete");
    assert!(outbox(&s.h).is_empty());
}

/// The same with an update behind the deferred create.
#[tokio::test(flavor = "multi_thread")]
async fn an_update_waits_for_a_deferred_create() {
    let s = setup_with_batch_max(1).await;
    let ledger = mount_ledger(&s.h).await;
    let x = create_company(&s.h, "X");
    ledger.answer_next(Answer::Deferred);
    s.h.engine.sync_now().await.expect("first round");
    rename(&s.h, x, "X2");

    ledger.answer_next(Answer::Deferred);
    s.h.engine.sync_now().await.expect("second round");
    assert!(ledger.refused().is_empty(), "the update went out ahead of its create: {:?}", ledger.refused());

    s.h.engine.sync_now().await.expect("third round");
    assert_eq!(ledger.row(x), Some(("X2".to_string(), false)));
    assert!(ledger.refused().is_empty(), "the update conflicted with its own create: {:?}", ledger.refused());
    assert!(outbox(&s.h).is_empty());
}

// ---------------------------------------------------------------------------
// 9. A follow-up edit of a mutation whose answer was lost
// ---------------------------------------------------------------------------

/// An update is applied and its answer lost; the next edit of the same field is queued on its
/// own, on the old base. When both go out, the server must not count the first as somebody
/// else's write.
#[tokio::test(flavor = "multi_thread")]
async fn an_edit_after_an_update_whose_answer_was_lost_does_not_conflict_with_it() {
    let s = setup(None).await;
    let ledger = mount_ledger(&s.h).await;
    let id = create_company(&s.h, "Mine");
    s.h.engine.sync_now().await.expect("create");
    rename(&s.h, id, "First");
    ledger.answer_next(Answer::LostAfterApply);
    assert!(s.h.engine.sync_now().await.is_err());
    rename(&s.h, id, "Second");

    s.h.engine.set_online(true);
    s.h.engine.sync_now().await.expect("the resend");

    assert!(ledger.refused().is_empty(), "the device conflicted with itself: {:?}", ledger.refused());
    assert_eq!(ledger.row(id), Some(("Second".to_string(), false)));
    assert_eq!(s.h.engine.status().conflicts, 0);
    assert!(outbox(&s.h).is_empty());
}

/// The rebase stops at what the device wrote: somebody else's write in between is still a
/// conflict.
#[tokio::test(flavor = "multi_thread")]
async fn a_foreign_write_after_a_lost_create_is_still_a_conflict() {
    let s = setup(None).await;
    let ledger = mount_ledger(&s.h).await;
    ledger.answer_next(Answer::LostAfterApply);
    let id = create_company(&s.h, "Mine");
    assert!(s.h.engine.sync_now().await.is_err());
    ledger.foreign_rename(id, "Theirs");
    rename(&s.h, id, "Renamed");

    s.h.engine.set_online(true);
    s.h.engine.sync_now().await.expect("the resend");

    assert_eq!(ledger.row(id), Some(("Theirs".to_string(), false)), "somebody else's write was overwritten");
    assert_eq!(s.h.engine.status().conflicts, 1);
}
