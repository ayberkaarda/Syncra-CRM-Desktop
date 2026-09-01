//! Shared harness for the integration suite.
//!
//! `SYNCDESKTOP.md` §5.7 requires the whole matrix to run against `wiremock`; no test in
//! this crate ever talks to a live backend, and none of them touch the developer's real OS
//! keychain (the engine is opened with [`MemoryKeyStore`]).

#![allow(dead_code)]

use serde_json::{json, Value};
use std::sync::Arc;
use syncra_sync::keystore::{KeyStore, KEY_DB};
use syncra_sync::{DeviceInfo, Entity, MemoryKeyStore, SyncConfig, SyncEngine};
use tempfile::TempDir;
use wiremock::matchers::{method, path};
use wiremock::{Mock, MockServer, ResponseTemplate};

/// A mock server plus an engine pointed at it.
pub struct Harness {
    pub server: MockServer,
    pub engine: SyncEngine,
    pub dir: TempDir,
    /// The SQLCipher key the harness generated, so a test can open a second connection and
    /// assert on engine tables the public API does not surface (`attempts`, for instance).
    pub db_key: String,
    pub db_path: std::path::PathBuf,
}

/// Tables granted by the default manifest. Small on purpose: a pull request carries a
/// cursor per granted table, and short bodies keep the assertions readable.
pub const GRANTED: &[Entity] = &[
    Entity::User,
    Entity::PipelineStage,
    Entity::Tag,
    Entity::Company,
    Entity::Contact,
    Entity::Deal,
    Entity::Task,
    Entity::Quote,
    Entity::ConversationUser,
    Entity::Notification,
];

impl Harness {
    /// Start a mock server and open an engine against it (not logged in yet).
    pub async fn start() -> Harness {
        Self::start_with_granted(GRANTED).await
    }

    /// Same, with an explicit manifest table set.
    pub async fn start_with_granted(granted: &[Entity]) -> Harness {
        let harness = Self::start_bare().await;
        mount_manifest(&harness.server, manifest_body(granted, 1)).await;
        harness
    }

    /// An engine and a mock server with **no** `GET /api/sync/manifest` mounted.
    ///
    /// wiremock resolves a request against the mocks in mount order, so a manifest the
    /// harness mounted cannot be overridden afterwards. A test that needs that endpoint to
    /// fail — the O46 connectivity probe's negative control, for one — starts from here and
    /// mounts its own.
    pub async fn start_bare() -> Harness {
        let server = MockServer::start().await;
        let dir = tempfile::tempdir().expect("tempdir");
        let cfg = SyncConfig::new(
            url::Url::parse(&format!("{}/api/", server.uri())).expect("url"),
            dir.path().join("syncra.db"),
        );
        let db_path = cfg.db_path.clone();
        let service = cfg.keychain_service.clone();
        let keystore = Arc::new(MemoryKeyStore::new());
        let engine = SyncEngine::open_with_keystore(cfg, keystore.clone())
            .await
            .expect("open engine");
        let db_key = keystore
            .get(&service, KEY_DB)
            .expect("keystore")
            .expect("the engine must have stored a database key");

        Harness {
            server,
            engine,
            dir,
            db_key,
            db_path,
        }
    }

    /// A second connection to the encrypted database, for assertions on engine tables.
    ///
    /// The engine runs in WAL mode, so a concurrent reader sees committed state.
    pub fn raw_conn(&self) -> rusqlite::Connection {
        let conn = rusqlite::Connection::open(&self.db_path).expect("open db");
        conn.pragma_update(None, "key", &self.db_key).expect("key");
        conn
    }

    /// `attempts` of every queued outbox row, in `seq` order.
    pub fn queued_attempts(&self) -> Vec<i64> {
        let conn = self.raw_conn();
        let mut stmt = conn
            .prepare("SELECT attempts FROM outbox WHERE state = 'queued' ORDER BY seq")
            .expect("prepare");
        let rows = stmt.query_map([], |r| r.get::<_, i64>(0)).expect("query");
        rows.map(|r| r.expect("row")).collect()
    }

    /// `state` of every outbox row, in `seq` order.
    pub fn outbox_states(&self) -> Vec<String> {
        let conn = self.raw_conn();
        let mut stmt = conn
            .prepare("SELECT state FROM outbox ORDER BY seq")
            .expect("prepare");
        let rows = stmt.query_map([], |r| r.get::<_, String>(0)).expect("query");
        rows.map(|r| r.expect("row")).collect()
    }

    /// Mount a device-auth response and log in.
    ///
    /// The matching `DELETE /api/me/devices/{token_id}` is mounted too: `logout` revokes the
    /// token on the server before wiping locally (§4.3), and a harness that left the route
    /// unmounted would only ever exercise wiremock's 404 fallback.
    pub async fn login_as(&self, user_id: i64, email: &str) {
        mount_device_auth(&self.server, user_id, email).await;
        mount_revoke_device(&self.server).await;
        self.engine
            .login(email, "secret", device_info())
            .await
            .expect("login");
    }

    /// Log in as user 1 and mark the engine online.
    pub async fn login(&self) {
        self.login_as(1, "ayberk@example.com").await;
    }

    /// Count rows in a mirror table, tombstones included.
    pub fn row_count(&self, entity: Entity) -> usize {
        self.engine
            .query(
                list_query(entity),
                syncra_sync::QueryParams {
                    limit: Some(500),
                    include_tombstones: true,
                    ..Default::default()
                },
            )
            .expect("query")
            .len()
    }
}

/// Close the engine's database connection **before** `dir` is removed.
///
/// Rust drops struct fields in declaration order, so `engine` already goes before `dir` — but
/// that only closes the connection when this `SyncEngine` happens to hold the last
/// `Arc<Inner>`. `SyncEngine::start_background_sync` moves a clone into a tokio task, and
/// `SyncScheduler::stop` merely *aborts* that task: the runtime drops its future — and the
/// engine clone captured inside it — after `block_on` returns, which is strictly after the test
/// body dropped this harness. The connection therefore outlived `dir`, `TempDir::drop` hit
/// Windows' sharing violation on `remove_dir_all`, and because `TempDir` deliberately swallows
/// that error the directory silently stayed behind with its `syncra.db` in it.
///
/// Measured (defter O104, `%LOCALAPPDATA%\Temp`): before this impl, `cargo test --test
/// engine_loop` left **4** abandoned directories per run — one for each test that starts the
/// background loop and stops the scheduler without calling `shutdown` — while every other test
/// binary in the crate leaked none. 245 such directories, 113.9 MB, had accumulated since
/// 2026-08-15.
///
/// `SyncEngine::shutdown` is refcount-independent: it takes the `Connection` out of the
/// engine's `Mutex<Option<..>>` and closes it, so the handle is released no matter who else
/// still holds a clone. It is idempotent, so a test that already shut its engine down is
/// unaffected, and it is the same call the F8/1 data-directory migration makes before it
/// deletes the old directory (`SYNCDESKTOP.md` §10) — which means every test in the suite now
/// exercises that precondition.
impl Drop for Harness {
    fn drop(&mut self) {
        // A `Drop` must never panic: a failing shutdown while the test is already unwinding
        // would replace the real assertion failure with an abort.
        let _ = self.engine.shutdown();
    }
}

/// Write a small real file under the temp dir of the harness and return its absolute path.
///
/// Shared by every suite that has to prove a cached blob is actually gone from disk (defter
/// O67's wipe tests, and the retention/eviction ones), so the "what does a recorded file look
/// like on disk" fixture is defined once.
pub fn write_blob(h: &Harness, name: &str) -> std::path::PathBuf {
    let dir = h.dir.path().join("cache");
    std::fs::create_dir_all(&dir).expect("cache dir");
    let path = dir.join(name);
    std::fs::write(&path, b"%PDF-1.7 fake").expect("write blob");
    assert!(path.is_absolute(), "the engine only accepts absolute paths");
    path
}

/// A named query that simply lists an entity, for assertions.
pub fn list_query(entity: Entity) -> syncra_sync::NamedQuery {
    use syncra_sync::NamedQuery as Q;
    match entity {
        Entity::Company => Q::companies(),
        Entity::Contact => Q::contacts(),
        Entity::Deal => Q::deals(),
        Entity::Task => Q::tasks(),
        Entity::Quote => Q::quotes(),
        Entity::Notification => Q::notifications(None),
        Entity::PipelineStage => Q::PipelineStages,
        Entity::User => Q::users(),
        other => panic!("no list query for {other}"),
    }
}

/// The device identity every test logs in with.
pub fn device_info() -> DeviceInfo {
    DeviceInfo {
        device_name: "TEST-PC".into(),
        device_fingerprint: "sha256-test".into(),
        platform: "windows".into(),
        app_version: "0.1.0".into(),
    }
}

/// `GET /api/sync/manifest` body.
pub fn manifest_body(granted: &[Entity], protocol_version: u32) -> Value {
    let mut tables = serde_json::Map::new();
    for entity in granted {
        tables.insert(
            entity.table().to_string(),
            json!({ "mode": match entity.default_mode() {
                syncra_sync::TableMode::Rw => "rw",
                syncra_sync::TableMode::Ro => "ro",
            }}),
        );
    }
    json!({
        "protocol_version": protocol_version,
        "server_time": "2026-08-30T12:00:00.000Z",
        "tables": tables,
        "permissions": ["deals.view", "deals.update", "contacts.view"],
        "user": { "id": 1, "name": "Ayberk", "email": "ayberk@example.com" },
        "policy": {
            "retention_days_max": 365,
            "push_batch_max": 200,
            "push_bytes_max": 2_097_152,
            "pull_limit_max": 1000
        }
    })
}

/// One table's slice of a pull response: `(table, rows, deletions, next_cursor, has_more)`.
pub type PullTableFixture<'a> = (&'a str, Vec<Value>, Vec<Value>, i64, bool);

/// `POST /api/sync/pull` body.
pub fn pull_body(tables: Vec<PullTableFixture<'_>>) -> Value {
    let mut map = serde_json::Map::new();
    for (table, rows, deletions, next_cursor, has_more) in tables {
        map.insert(
            table.to_string(),
            json!({
                "rows": rows,
                "deletions": deletions,
                "next_cursor": next_cursor,
                "has_more": has_more
            }),
        );
    }
    json!({ "server_time": "2026-08-30T12:00:00.000Z", "tables": map })
}

/// `POST /api/sync/push` body.
pub fn push_body(results: Vec<Value>) -> Value {
    json!({
        "batch_id": "batch-1",
        "results": results,
        "server_time": "2026-08-30T12:00:00.000Z"
    })
}

/// An `applied` push result.
pub fn applied(seq: i64, server_id: i64, sync_version: i64) -> Value {
    json!({ "seq": seq, "status": "applied", "server_id": server_id, "sync_version": sync_version })
}

/// A `duplicate` push result.
pub fn duplicate(seq: i64, server_id: i64) -> Value {
    json!({ "seq": seq, "status": "duplicate", "server_id": server_id })
}

/// A field-level `conflict` push result.
pub fn conflict(seq: i64, fields: &[&str], server_row: Value, sync_version: i64) -> Value {
    json!({
        "seq": seq,
        "status": "conflict",
        "code": "FIELD_CONFLICT",
        "conflicting_fields": fields,
        "server_row": server_row,
        "sync_version": sync_version
    })
}

/// A `rejected` push result.
pub fn rejected(seq: i64, code: &str) -> Value {
    json!({ "seq": seq, "status": "rejected", "code": code })
}

// ---------------------------------------------------------------------------
// Mounting
// ---------------------------------------------------------------------------

/// Mount `POST /api/auth/device`.
pub async fn mount_device_auth(server: &MockServer, user_id: i64, email: &str) {
    Mock::given(method("POST"))
        .and(path("/api/auth/device"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({
            "token": format!("token-for-{user_id}"),
            "token_id": user_id * 10,
            "user": { "id": user_id, "name": "Test", "email": email },
            "must_change_password": false,
            "abilities": ["desktop"]
        })))
        .up_to_n_times(1)
        .mount(server)
        .await;
}

/// Mount `DELETE /api/me/devices/{id}` (204), the call `logout` makes to revoke the token.
pub async fn mount_revoke_device(server: &MockServer) {
    Mock::given(method("DELETE"))
        .and(wiremock::matchers::path_regex(r"^/api/me/devices/\d+$"))
        .respond_with(ResponseTemplate::new(204))
        .mount(server)
        .await;
}

/// Every `DELETE /api/me/devices/*` path the server received, in order.
pub async fn revoke_requests(server: &MockServer) -> Vec<String> {
    server
        .received_requests()
        .await
        .unwrap_or_default()
        .into_iter()
        .filter(|r| r.method == wiremock::http::Method::DELETE)
        .map(|r| r.url.path().to_string())
        .collect()
}

/// Mount one of the sync endpoints with the **real** Laravel error envelope,
/// `{"errors": {"code": ..., "retry_after": ...}}`.
pub async fn mount_error(
    server: &MockServer,
    verb: &str,
    endpoint: &str,
    status: u16,
    code: &str,
    retry_after: Option<u64>,
) {
    let mut errors = serde_json::Map::new();
    errors.insert("message".into(), json!("server said no"));
    errors.insert("code".into(), json!(code));
    if let Some(seconds) = retry_after {
        errors.insert("retry_after".into(), json!(seconds));
    }
    Mock::given(method(verb))
        .and(path(endpoint))
        .respond_with(ResponseTemplate::new(status).set_body_json(json!({ "errors": errors })))
        .mount(server)
        .await;
}

/// Mount `GET /api/sync/manifest` for the rest of the test.
pub async fn mount_manifest(server: &MockServer, body: Value) {
    Mock::given(method("GET"))
        .and(path("/api/sync/manifest"))
        .respond_with(ResponseTemplate::new(200).set_body_json(body))
        .mount(server)
        .await;
}

/// Mount one `POST /api/sync/pull` response, consumed once.
pub async fn mount_pull_once(server: &MockServer, body: Value) {
    Mock::given(method("POST"))
        .and(path("/api/sync/pull"))
        .respond_with(ResponseTemplate::new(200).set_body_json(body))
        .up_to_n_times(1)
        .mount(server)
        .await;
}

/// Mount a `POST /api/sync/pull` response for the rest of the test.
pub async fn mount_pull(server: &MockServer, body: Value) {
    Mock::given(method("POST"))
        .and(path("/api/sync/pull"))
        .respond_with(ResponseTemplate::new(200).set_body_json(body))
        .mount(server)
        .await;
}

/// Mount an empty pull, so a `sync_now` can complete without pulling anything.
pub async fn mount_empty_pull(server: &MockServer) {
    mount_pull(server, pull_body(vec![])).await;
}

/// Mount one `POST /api/sync/push` response, consumed once.
pub async fn mount_push_once(server: &MockServer, body: Value) {
    Mock::given(method("POST"))
        .and(path("/api/sync/push"))
        .respond_with(ResponseTemplate::new(200).set_body_json(body))
        .up_to_n_times(1)
        .mount(server)
        .await;
}

/// Mount a `POST /api/sync/push` response for the rest of the test.
pub async fn mount_push(server: &MockServer, body: Value) {
    Mock::given(method("POST"))
        .and(path("/api/sync/push"))
        .respond_with(ResponseTemplate::new(200).set_body_json(body))
        .mount(server)
        .await;
}

/// Mount a bare status code on one of the sync endpoints.
pub async fn mount_status(server: &MockServer, verb: &str, endpoint: &str, status: u16) {
    Mock::given(method(verb))
        .and(path(endpoint))
        .respond_with(ResponseTemplate::new(status).set_body_json(json!({ "code": "UNAUTHENTICATED" })))
        .mount(server)
        .await;
}

/// Every `POST /api/sync/push` request body the server received, in order.
pub async fn push_requests(server: &MockServer) -> Vec<Value> {
    server
        .received_requests()
        .await
        .unwrap_or_default()
        .into_iter()
        .filter(|r| r.url.path() == "/api/sync/push")
        .map(|r| serde_json::from_slice(&r.body).expect("push body is json"))
        .collect()
}

/// How many `GET /api/sync/manifest` requests the server has answered so far.
pub async fn manifest_request_count(server: &MockServer) -> usize {
    server
        .received_requests()
        .await
        .unwrap_or_default()
        .into_iter()
        .filter(|r| r.url.path() == "/api/sync/manifest")
        .count()
}

/// Every `POST /api/sync/pull` request body the server received, in order.
pub async fn pull_requests(server: &MockServer) -> Vec<Value> {
    server
        .received_requests()
        .await
        .unwrap_or_default()
        .into_iter()
        .filter(|r| r.url.path() == "/api/sync/pull")
        .map(|r| serde_json::from_slice(&r.body).expect("pull body is json"))
        .collect()
}

// ---------------------------------------------------------------------------
// Dynamic responders
// ---------------------------------------------------------------------------

/// Answers a push with `applied` for every mutation it received.
///
/// A static body cannot do this once batching splits the queue, and it also lets a test
/// assert on real `seq` values instead of guessing them.
pub struct ApplyAll;

impl wiremock::Respond for ApplyAll {
    fn respond(&self, request: &wiremock::Request) -> ResponseTemplate {
        let body: Value = serde_json::from_slice(&request.body).expect("push body");
        let results: Vec<Value> = body["mutations"]
            .as_array()
            .expect("mutations array")
            .iter()
            .map(|m| {
                // Derive the identity from `seq`, which is unique across the whole outbox;
                // an index inside the batch would repeat once batching splits the queue.
                let seq = m["seq"].as_i64().expect("seq");
                json!({
                    "seq": seq,
                    "status": "applied",
                    "server_id": 1000 + seq,
                    "sync_version": 200_000 + seq
                })
            })
            .collect();
        ResponseTemplate::new(200).set_body_json(json!({
            "batch_id": body["batch_id"],
            "results": results,
            "server_time": "2026-08-30T12:00:00.000Z"
        }))
    }
}

/// Answers a push with `duplicate` for every mutation it received.
pub struct DuplicateAll;

impl wiremock::Respond for DuplicateAll {
    fn respond(&self, request: &wiremock::Request) -> ResponseTemplate {
        let body: Value = serde_json::from_slice(&request.body).expect("push body");
        let results: Vec<Value> = body["mutations"]
            .as_array()
            .expect("mutations array")
            .iter()
            .map(|m| json!({ "seq": m["seq"], "status": "duplicate", "server_id": 4242 }))
            .collect();
        ResponseTemplate::new(200).set_body_json(json!({
            "batch_id": body["batch_id"],
            "results": results,
            "server_time": "2026-08-30T12:00:00.000Z"
        }))
    }
}

/// Answers a push with `applied` for every mutation **except** the first `skip` of them,
/// which are simply left out of `results`.
///
/// This is the shape protocol §4.3 P10b describes: the server hit lock contention
/// (protocol §2.4 P4a), stopped processing, and returned HTTP 200 with a short array.
pub struct ApplyAllButLast(pub usize);

impl wiremock::Respond for ApplyAllButLast {
    fn respond(&self, request: &wiremock::Request) -> ResponseTemplate {
        let body: Value = serde_json::from_slice(&request.body).expect("push body");
        let mutations = body["mutations"].as_array().expect("mutations array");
        let keep = mutations.len().saturating_sub(self.0);
        let results: Vec<Value> = mutations
            .iter()
            .take(keep)
            .map(|m| {
                let seq = m["seq"].as_i64().expect("seq");
                json!({
                    "seq": seq,
                    "status": "applied",
                    "server_id": 1000 + seq,
                    "sync_version": 200_000 + seq
                })
            })
            .collect();
        ResponseTemplate::new(200).set_body_json(json!({
            "batch_id": body["batch_id"],
            "results": results,
            "server_time": "2026-08-30T12:00:00.000Z"
        }))
    }
}

/// A `GET /api/sync/manifest` responder that can be closed and reopened while the test runs.
///
/// The O46 connectivity probe made "is the server reachable?" a question the engine asks on
/// its own, so a test that needs the engine to *stay* offline has to be able to take the
/// endpoint away — and a test about the `set_online` wake trigger has to keep it away, or the
/// probe would be an equally good explanation for the round it observes.
///
/// A closed gate answers `503`. What an unplugged machine really produces is a connect error,
/// which the transport maps to [`syncra_sync::SyncError::Offline`], and wiremock cannot
/// refuse a connection — but both are "no manifest came back", which is the only thing the
/// probe asks about, and `503` is the harder of the two to get right.
#[derive(Clone)]
pub struct ManifestGate {
    is_open: Arc<std::sync::atomic::AtomicBool>,
    body: Value,
}

impl ManifestGate {
    /// A gate serving `body`, open or closed to start with.
    pub fn new(body: Value, open: bool) -> Self {
        ManifestGate {
            is_open: Arc::new(std::sync::atomic::AtomicBool::new(open)),
            body,
        }
    }

    /// The server is reachable from now on.
    pub fn open(&self) {
        self.is_open
            .store(true, std::sync::atomic::Ordering::SeqCst);
    }

    /// The server is unreachable from now on.
    pub fn close(&self) {
        self.is_open
            .store(false, std::sync::atomic::Ordering::SeqCst);
    }
}

impl wiremock::Respond for ManifestGate {
    fn respond(&self, _request: &wiremock::Request) -> ResponseTemplate {
        if self.is_open.load(std::sync::atomic::Ordering::SeqCst) {
            ResponseTemplate::new(200).set_body_json(self.body.clone())
        } else {
            ResponseTemplate::new(503)
                .set_body_json(json!({ "errors": { "code": "SERVICE_UNAVAILABLE" } }))
        }
    }
}

/// Mount a [`ManifestGate`] on `GET /api/sync/manifest`.
pub async fn mount_manifest_gate(server: &MockServer, gate: ManifestGate) {
    Mock::given(method("GET"))
        .and(path("/api/sync/manifest"))
        .respond_with(gate)
        .mount(server)
        .await;
}

/// Mount a dynamic push responder for the rest of the test.
pub async fn mount_push_responder<R>(server: &MockServer, responder: R)
where
    R: wiremock::Respond + 'static,
{
    Mock::given(method("POST"))
        .and(path("/api/sync/push"))
        .respond_with(responder)
        .mount(server)
        .await;
}

/// Mount a dynamic push responder that is consumed once.
pub async fn mount_push_responder_once<R>(server: &MockServer, responder: R)
where
    R: wiremock::Respond + 'static,
{
    Mock::given(method("POST"))
        .and(path("/api/sync/push"))
        .respond_with(responder)
        .up_to_n_times(1)
        .mount(server)
        .await;
}
