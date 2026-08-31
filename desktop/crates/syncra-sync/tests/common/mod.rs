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

        mount_manifest(&server, manifest_body(granted, 1)).await;

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
    pub async fn login_as(&self, user_id: i64, email: &str) {
        mount_device_auth(&self.server, user_id, email).await;
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

/// A named query that simply lists an entity, for assertions.
pub fn list_query(entity: Entity) -> syncra_sync::NamedQuery {
    use syncra_sync::NamedQuery as Q;
    match entity {
        Entity::Company => Q::CompanyList,
        Entity::Contact => Q::ContactList {
            company_client_id: None,
        },
        Entity::Deal => Q::DealsList {
            status: None,
            owner_client_id: None,
        },
        Entity::Task => Q::TaskList {
            status: None,
            assigned_to_client_id: None,
        },
        Entity::Quote => Q::QuoteList { status: None },
        Entity::Notification => Q::NotificationList { unread_only: false },
        Entity::PipelineStage => Q::PipelineStages,
        Entity::User => Q::UserList,
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
