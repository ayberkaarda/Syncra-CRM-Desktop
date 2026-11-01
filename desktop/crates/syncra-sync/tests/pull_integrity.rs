//! The mirror's two pull invariants, against the server's real contract:
//!
//! * a tombstone can never stall a pull, and
//! * a cursor only ever moves forward, however many tables a sync touches.
//!
//! [`ContractServer`] answers as `SyncPullService::pull` does: it visits every table, reads a
//! cursor missing from the body as 0, and reports `has_more` on a full page.

mod common;

use common::*;
use serde_json::{json, Map, Value};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::Arc;
use std::time::Duration;
use syncra_sync::{Entity, LocalMutation, NamedQuery, QueryParams, RealtimeEvent, SyncEngine};
use uuid::Uuid;
use wiremock::matchers::{method, path};
use wiremock::{Mock, Request, Respond, ResponseTemplate};

fn cursor(h: &Harness, table: &str) -> i64 {
    h.raw_conn()
        .query_row(
            "SELECT sync_version FROM cursors WHERE table_name = ?1",
            [table],
            |r| r.get(0),
        )
        .unwrap_or(-1)
}

fn company_names(h: &Harness) -> Vec<String> {
    h.engine
        .query(NamedQuery::companies(), QueryParams::default())
        .unwrap()
        .iter()
        .map(|r| r.get_str("name").unwrap_or("").to_string())
        .collect()
}

fn table_rows(h: &Harness, table: &str) -> i64 {
    h.raw_conn()
        .query_row(&format!("SELECT count(*) FROM {table}"), [], |r| r.get(0))
        .unwrap()
}

fn price_items(h: &Harness) -> i64 {
    h.raw_conn()
        .query_row("SELECT count(*) FROM price_list_items", [], |r| r.get(0))
        .unwrap()
}

// ---------------------------------------------------------------------------
// Tombstones
// ---------------------------------------------------------------------------

#[tokio::test]
async fn a_price_list_item_tombstone_is_applied_and_the_pull_goes_on() {
    let h = Harness::start_with_granted(&[Entity::Company, Entity::PriceListItem]).await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![
            ("companies", vec![json!({"id":1,"name":"Old","sync_version":10})], vec![], 10, false),
            (
                "price_list_items",
                vec![json!({"id":1,"price_list_id":1,"product_id":1,"unit_price":"10","sync_version":11})],
                vec![],
                11,
                false,
            ),
        ]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    assert_eq!(price_items(&h), 1);

    // The exact shape the server emits.
    mount_pull(
        &h.server,
        pull_body(vec![
            ("companies", vec![json!({"id":1,"name":"New","sync_version":13})], vec![], 13, false),
            ("price_list_items", vec![], vec![json!({"row_key":"1","sync_version":12})], 12, false),
        ]),
    )
    .await;

    h.engine.sync_now().await.expect("the tombstone must not fail the pull");

    assert_eq!(price_items(&h), 0, "the withdrawn price must leave the mirror");
    assert_eq!(company_names(&h), vec!["New".to_string()]);
    assert_eq!(cursor(&h, "price_list_items"), 12);
    assert_eq!(cursor(&h, "companies"), 13);
}

#[tokio::test]
async fn a_fresh_device_bootstraps_past_a_tombstone_for_a_row_it_never_had() {
    let h = Harness::start_with_granted(&[Entity::Company, Entity::PriceListItem]).await;
    h.login().await;
    mount_pull(
        &h.server,
        pull_body(vec![
            ("companies", vec![json!({"id":1,"name":"New","sync_version":13})], vec![], 13, false),
            ("price_list_items", vec![], vec![json!({"row_key":"1","sync_version":12})], 12, false),
        ]),
    )
    .await;

    h.engine.bootstrap(|_| {}).await.expect("bootstrap");

    assert_eq!(h.row_count(Entity::Company), 1);
    assert_eq!(cursor(&h, "price_list_items"), 12);
}

/// The stall was never about one table: any tombstone this build cannot place had the same
/// effect, for 90 days, on every device.
#[tokio::test]
async fn a_tombstone_this_build_cannot_place_does_not_stall_the_pull_either() {
    let h = Harness::start_with_granted(&[
        Entity::Company,
        Entity::PipelineStage,
        Entity::Tag,
        Entity::ConversationUser,
    ])
    .await;
    h.login().await;
    mount_pull(
        &h.server,
        pull_body(vec![
            ("companies", vec![json!({"id":1,"name":"Kept","sync_version":40})], vec![], 40, false),
            // A table the contract has no tombstone for.
            ("pipeline_stages", vec![], vec![json!({"row_key":"7","sync_version":41})], 41, false),
            // Keys that do not parse.
            ("tags", vec![], vec![json!({"row_key":"not-a-number","sync_version":42})], 42, false),
            ("conversation_user", vec![], vec![json!({"row_key":"12","sync_version":43})], 43, false),
        ]),
    )
    .await;

    h.engine.bootstrap(|_| {}).await.expect("bootstrap");

    assert_eq!(company_names(&h), vec!["Kept".to_string()]);
    assert_eq!(cursor(&h, "pipeline_stages"), 41);
    assert_eq!(cursor(&h, "tags"), 42);
    assert_eq!(cursor(&h, "conversation_user"), 43);
}

/// The price of not stalling: the local row stays behind and the cursor moves past it. That
/// is accepted, and it has to be visible: a skipped tombstone is not a deletion.
#[tokio::test]
async fn a_skipped_tombstone_leaves_its_row_and_is_not_reported_as_a_deletion() {
    let h = Harness::start_with_granted(&[Entity::PipelineStage, Entity::Tag]).await;
    h.login().await;
    mount_pull_once(
        &h.server,
        pull_body(vec![
            ("pipeline_stages", vec![json!({"id":7,"name":"Stage","sync_version":10})], vec![], 10, false),
            (
                "tags",
                vec![
                    json!({"id":3,"name":"Kept","sync_version":11}),
                    json!({"id":4,"name":"Gone","sync_version":12}),
                ],
                vec![],
                12,
                false,
            ),
        ]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");

    mount_pull(
        &h.server,
        pull_body(vec![
            ("pipeline_stages", vec![], vec![json!({"row_key":"7","sync_version":20})], 20, false),
            (
                "tags",
                vec![],
                vec![
                    json!({"row_key":"3-corrupt","sync_version":21}),
                    json!({"row_key":"4","sync_version":22}),
                ],
                22,
                false,
            ),
        ]),
    )
    .await;

    let report = h.engine.sync_now().await.expect("the pull must go on");

    assert_eq!(report.deletions, 1, "only the tombstone that was applied counts");
    assert_eq!(table_rows(&h, "tags"), 1, "tag 3 is left behind, tag 4 is gone");
    assert_eq!(table_rows(&h, "pipeline_stages"), 1);
    assert_eq!(cursor(&h, "tags"), 22);
    assert_eq!(cursor(&h, "pipeline_stages"), 20);
}

// ---------------------------------------------------------------------------
// Cursors
// ---------------------------------------------------------------------------

struct ContractServer {
    tables: Vec<(&'static str, Vec<i64>)>,
    /// Stand-in for the byte ceiling: once a response holds this many rows no further table
    /// is visited, and the last one visited says `has_more`.
    budget: Option<usize>,
    /// Hold the next response this long, computed from the cursors it was asked with.
    stall_next_ms: Arc<AtomicU64>,
    /// Hold every other response this long.
    delay_ms: Arc<AtomicU64>,
    requests: Arc<AtomicU64>,
    rows_sent: Arc<AtomicU64>,
}

impl Respond for ContractServer {
    fn respond(&self, req: &Request) -> ResponseTemplate {
        let body: Value = serde_json::from_slice(&req.body).unwrap();
        let limit = body["limit"].as_u64().unwrap() as usize;
        self.requests.fetch_add(1, Ordering::SeqCst);

        let mut tables = Map::new();
        let mut sent = 0usize;
        let mut last = None;
        for (name, versions) in &self.tables {
            if self.budget.is_some_and(|budget| sent >= budget) {
                let visited: &mut Value = tables.get_mut(last.unwrap()).unwrap();
                visited["has_more"] = json!(true);
                break;
            }
            let from = body["cursors"][*name].as_i64().unwrap_or(0);
            let page: Vec<i64> = versions.iter().copied().filter(|v| *v > from).take(limit).collect();
            let rows: Vec<Value> = page
                .iter()
                .map(|v| json!({"id": v, "name": format!("r{v}"), "subject": "s", "title": "t", "sync_version": v}))
                .collect();
            self.rows_sent.fetch_add(rows.len() as u64, Ordering::SeqCst);
            sent += rows.len();
            last = Some(*name);
            tables.insert(
                (*name).to_string(),
                json!({
                    "rows": rows,
                    "deletions": [],
                    "next_cursor": page.iter().copied().max().unwrap_or(from),
                    "has_more": page.len() >= limit,
                }),
            );
        }
        let stall = self.stall_next_ms.swap(0, Ordering::SeqCst);
        let delay = if stall > 0 { stall } else { self.delay_ms.load(Ordering::SeqCst) };
        ResponseTemplate::new(200)
            .set_delay(Duration::from_millis(delay))
            .set_body_json(json!({"server_time": "2026-08-30T12:00:00.000Z", "tables": tables}))
    }
}

struct Sync {
    h: Harness,
    requests: Arc<AtomicU64>,
    rows_sent: Arc<AtomicU64>,
    stall_next_ms: Arc<AtomicU64>,
    delay_ms: Arc<AtomicU64>,
    unique: u64,
    outcome: Result<(), String>,
}

async fn serve(pages: &[(&'static str, Entity, usize)], limit: u64, budget: Option<usize>) -> Sync {
    let h = Harness::start_bare().await;
    let granted: Vec<Entity> = pages.iter().map(|p| p.1).collect();
    let mut manifest = manifest_body(&granted, 1);
    manifest["policy"]["pull_limit_max"] = json!(limit);
    mount_manifest(&h.server, manifest).await;

    let mut next = 1i64;
    let mut tables = Vec::new();
    for (name, _, count) in pages {
        tables.push((*name, (next..next + *count as i64).collect::<Vec<i64>>()));
        next += *count as i64;
    }
    let requests = Arc::new(AtomicU64::new(0));
    let rows_sent = Arc::new(AtomicU64::new(0));
    let stall_next_ms = Arc::new(AtomicU64::new(0));
    let delay_ms = Arc::new(AtomicU64::new(0));
    Mock::given(method("POST"))
        .and(path("/api/sync/pull"))
        .respond_with(ContractServer {
            tables,
            budget,
            stall_next_ms: stall_next_ms.clone(),
            delay_ms: delay_ms.clone(),
            requests: requests.clone(),
            rows_sent: rows_sent.clone(),
        })
        .mount(&h.server)
        .await;
    h.login().await;
    Sync {
        h,
        requests,
        rows_sent,
        stall_next_ms,
        delay_ms,
        unique: (next - 1) as u64,
        outcome: Ok(()),
    }
}

async fn bootstrap_against(pages: &[(&'static str, Entity, usize)], limit: u64) -> Sync {
    let mut sync = serve(pages, limit, None).await;
    sync.outcome = sync.h.engine.bootstrap(|_| {}).await.map_err(|e| e.to_string());
    sync
}

const SIX_TABLES: &[(&str, Entity, usize)] = &[
    ("companies", Entity::Company, 3),
    ("contacts", Entity::Contact, 5),
    ("deals", Entity::Deal, 9),
    ("tasks", Entity::Task, 13),
    ("quotes", Entity::Quote, 21),
    ("tags", Entity::Tag, 25),
];

#[tokio::test(flavor = "multi_thread")]
async fn a_multi_table_first_sync_converges_and_fetches_every_row_once() {
    let sync = bootstrap_against(SIX_TABLES, 2).await;

    sync.outcome.as_ref().expect("the first sync must converge");
    assert_eq!(
        sync.rows_sent.load(Ordering::SeqCst),
        sync.unique,
        "a finished table must not be downloaded again"
    );
    // 25 rows on a page of 2: the 13th request is the short page that ends the loop.
    assert_eq!(sync.requests.load(Ordering::SeqCst), 13);
    assert_eq!(cursor(&sync.h, "tags"), 76);
    assert_eq!(cursor(&sync.h, "companies"), 3);
}

#[tokio::test(flavor = "multi_thread")]
async fn every_pull_request_carries_every_granted_cursor() {
    let sync = bootstrap_against(SIX_TABLES, 2).await;
    sync.outcome.as_ref().expect("bootstrap");

    for request in pull_requests(&sync.h.server).await {
        let cursors = request["cursors"].as_object().unwrap();
        assert_eq!(cursors.len(), SIX_TABLES.len(), "a cursor was omitted: {request}");
    }
}

#[tokio::test(flavor = "multi_thread")]
async fn a_one_table_realtime_event_leaves_the_other_cursors_alone() {
    let sync = bootstrap_against(
        &[("companies", Entity::Company, 5), ("contacts", Entity::Contact, 5)],
        2,
    )
    .await;
    sync.outcome.as_ref().expect("bootstrap");
    let h = &sync.h;

    let before = (cursor(h, "companies"), cursor(h, "contacts"));
    let (requests, rows) = (
        sync.requests.load(Ordering::SeqCst),
        sync.rows_sent.load(Ordering::SeqCst),
    );

    h.engine
        .handle_realtime(RealtimeEvent { entities: vec![Entity::Contact] })
        .await;

    assert_eq!((cursor(h, "companies"), cursor(h, "contacts")), before);
    assert_eq!(sync.rows_sent.load(Ordering::SeqCst), rows, "nothing was owed");
    assert_eq!(sync.requests.load(Ordering::SeqCst), requests + 1);
}

/// A response that was already on the wire when a newer one landed, or a server that answers
/// a missing cursor from 0: whichever way a lower cursor arrives, it does not get written.
#[tokio::test]
async fn a_lower_cursor_in_a_response_does_not_move_the_mirror_backwards() {
    let h = Harness::start_with_granted(&[Entity::Company, Entity::Contact]).await;
    h.login().await;
    mount_pull_once(
        &h.server,
        pull_body(vec![
            ("companies", vec![json!({"id":1,"name":"A","sync_version":50})], vec![], 50, false),
            ("contacts", vec![], vec![], 60, false),
        ]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");

    mount_pull(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({"id":1,"name":"Stale","sync_version":2})],
            vec![],
            2,
            false,
        )]),
    )
    .await;
    h.engine
        .handle_realtime(RealtimeEvent { entities: vec![Entity::Contact] })
        .await;

    assert_eq!(cursor(&h, "companies"), 50);
    assert_eq!(cursor(&h, "contacts"), 60);
    assert_eq!(
        company_names(&h),
        vec!["A".to_string()],
        "the cursor stays ahead, so an overwritten row would never be fetched again"
    );
}

/// Answers the first pull late with the older state of the row, and the second at once with
/// the newer one, so the older response is the one that lands last.
struct OlderResponseLandsLast {
    calls: AtomicU64,
}

impl Respond for OlderResponseLandsLast {
    fn respond(&self, _: &Request) -> ResponseTemplate {
        let (name, version, delay) = match self.calls.fetch_add(1, Ordering::SeqCst) {
            0 => ("Older", 11, 400),
            _ => ("Newer", 12, 0),
        };
        ResponseTemplate::new(200)
            .set_delay(Duration::from_millis(delay))
            .set_body_json(pull_body(vec![(
                "companies",
                vec![json!({"id":1,"name":name,"sync_version":version})],
                vec![],
                version,
                false,
            )]))
    }
}

#[tokio::test(flavor = "multi_thread")]
async fn overlapping_pulls_cannot_leave_the_older_row_behind_a_newer_cursor() {
    let h = Harness::start_with_granted(&[Entity::Company]).await;
    h.login().await;
    Mock::given(method("POST"))
        .and(path("/api/sync/pull"))
        .respond_with(OlderResponseLandsLast { calls: AtomicU64::new(0) })
        .mount(&h.server)
        .await;

    let event = || RealtimeEvent { entities: vec![Entity::Company] };
    tokio::join!(h.engine.handle_realtime(event()), async {
        tokio::time::sleep(Duration::from_millis(100)).await;
        h.engine.handle_realtime(event()).await;
    });

    assert_eq!(cursor(&h, "companies"), 12);
    assert_eq!(company_names(&h), vec!["Newer".to_string()]);
}

/// A shadow is what "take theirs" restores, so an older one must not replace a newer one
/// either.
#[tokio::test]
async fn a_stale_row_does_not_replace_a_newer_shadow() {
    let h = Harness::start_with_granted(&[Entity::Company]).await;
    h.login().await;
    let row = Uuid::now_v7().to_string();
    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({"id":1,"client_id":row,"name":"Base","sync_version":10})],
            vec![],
            10,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    h.engine
        .mutate(LocalMutation::update(
            Entity::Company,
            Uuid::parse_str(&row).unwrap(),
            &["city"],
            json!({"city": "Izmir"}),
        ))
        .unwrap();

    for (name, version) in [("Theirs", 30), ("Older", 20), ("Ancient", 5)] {
        let conn = h.raw_conn();
        let tx = conn.unchecked_transaction().unwrap();
        syncra_sync::db::upsert::upsert_row(
            &tx,
            syncra_sync::db::schema::spec_for(Entity::Company),
            &json!({"id":1,"client_id":row,"name":name,"sync_version":version}),
            &mut syncra_sync::db::upsert::IdMap::new(),
            &syncra_sync::db::columns(&conn, "companies").unwrap(),
        )
        .unwrap();
        tx.commit().unwrap();
    }

    let version: i64 = h
        .raw_conn()
        .query_row("SELECT server_sync_version FROM pending_shadows", [], |r| r.get(0))
        .unwrap();
    assert_eq!(version, 30);
}

/// The byte budget can end a response before the table a realtime event asked for, with
/// `has_more` on a table it did not ask for.
#[tokio::test(flavor = "multi_thread")]
async fn a_realtime_pull_keeps_going_for_a_table_the_budget_left_out() {
    let sync = serve(
        &[("companies", Entity::Company, 3), ("contacts", Entity::Contact, 2)],
        500,
        Some(3),
    )
    .await;

    sync.h
        .engine
        .handle_realtime(RealtimeEvent { entities: vec![Entity::Contact] })
        .await;

    assert_eq!(sync.requests.load(Ordering::SeqCst), 2);
    assert_eq!(cursor(&sync.h, "contacts"), 5, "the event's own table was left undone");
}

/// `has_more` on a table nobody asked about is the next round's work, not this event's.
#[tokio::test]
async fn a_realtime_pull_does_not_wait_for_a_table_it_did_not_name() {
    let h = Harness::start_with_granted(&[Entity::Company, Entity::Contact]).await;
    h.login().await;
    mount_pull(
        &h.server,
        pull_body(vec![
            ("companies", vec![json!({"id":1,"name":"A","sync_version":5})], vec![], 5, true),
            ("contacts", vec![], vec![], 0, false),
        ]),
    )
    .await;

    h.engine
        .handle_realtime(RealtimeEvent { entities: vec![Entity::Contact] })
        .await;

    assert_eq!(pull_requests(&h.server).await.len(), 1);
    assert_eq!(cursor(&h, "companies"), 5, "what did arrive still counts");
}

/// The archive path resets cursors on purpose; the forward-only rule must not get in its way.
#[tokio::test(flavor = "multi_thread")]
async fn the_archive_download_can_still_start_again_from_zero() {
    let sync = bootstrap_against(
        &[("companies", Entity::Company, 3), ("contacts", Entity::Contact, 5)],
        2,
    )
    .await;
    sync.outcome.as_ref().expect("bootstrap");
    let rows = sync.rows_sent.load(Ordering::SeqCst);

    sync.h.engine.download_archive(30).await.expect("archive");

    assert_eq!(sync.rows_sent.load(Ordering::SeqCst), rows + 8, "the whole set again, once");
    assert_eq!(cursor(&sync.h, "contacts"), 8);
}

/// A pull that went out before the reset must not put the old cursors back once the reset has
/// happened: the archive's next page would then start from the top and skip what lies between.
#[tokio::test(flavor = "multi_thread")]
async fn a_response_started_before_the_archive_reset_cannot_undo_it() {
    let sync = bootstrap_against(
        &[("companies", Entity::Company, 1), ("contacts", Entity::Contact, 12)],
        2,
    )
    .await;
    sync.outcome.as_ref().expect("bootstrap");
    let rows = sync.rows_sent.load(Ordering::SeqCst);

    sync.delay_ms.store(120, Ordering::SeqCst);
    sync.stall_next_ms.store(400, Ordering::SeqCst);

    let archive = async {
        tokio::time::sleep(Duration::from_millis(100)).await;
        sync.h.engine.download_archive(30).await
    };
    let (_, archive) = tokio::join!(
        sync.h.engine.handle_realtime(RealtimeEvent { entities: vec![Entity::Contact] }),
        archive,
    );

    archive.expect("archive");
    assert_eq!(
        sync.rows_sent.load(Ordering::SeqCst),
        rows + 13,
        "the archive stopped short of what lay between the two cursors"
    );
    assert_eq!(cursor(&sync.h, "contacts"), 13);
}

/// Clearing local data while a pull is on the wire. Had the response landed on the emptied
/// mirror, its cursors would carry the next delta past everything the clear removed.
#[tokio::test(flavor = "multi_thread")]
async fn a_pull_on_the_wire_cannot_refill_a_cleared_mirror() {
    let sync = serve(
        &[("companies", Entity::Company, 3), ("contacts", Entity::Contact, 12)],
        2,
        None,
    )
    .await;
    sync.stall_next_ms.store(400, Ordering::SeqCst);

    let clear = async {
        tokio::time::sleep(Duration::from_millis(100)).await;
        sync.h.engine.clear_local().await
    };
    let (_, cleared) = tokio::join!(
        sync.h.engine.handle_realtime(RealtimeEvent { entities: vec![Entity::Contact] }),
        clear,
    );

    cleared.expect("clear");
    assert_wiped(&sync.h);
}

/// Logging out wipes the same way, and the departing user's rows must not be written back.
#[tokio::test(flavor = "multi_thread")]
async fn a_pull_on_the_wire_cannot_refill_the_mirror_after_a_logout() {
    let sync = serve(
        &[("companies", Entity::Company, 3), ("contacts", Entity::Contact, 12)],
        2,
        None,
    )
    .await;
    sync.stall_next_ms.store(400, Ordering::SeqCst);

    let logout = async {
        tokio::time::sleep(Duration::from_millis(100)).await;
        sync.h.engine.logout(true).await
    };
    let (_, logged_out) = tokio::join!(
        sync.h.engine.handle_realtime(RealtimeEvent { entities: vec![Entity::Contact] }),
        logout,
    );

    logged_out.expect("logout");
    assert_wiped(&sync.h);
}

fn assert_wiped(h: &Harness) {
    assert_eq!(
        (table_rows(h, "companies"), table_rows(h, "contacts")),
        (0, 0),
        "rows from before the wipe came back"
    );
    assert_eq!(
        (cursor(h, "companies"), cursor(h, "contacts")),
        (-1, -1),
        "a cursor from before the wipe came back"
    );
}

// ---------------------------------------------------------------------------
// The base of an edit made while its create was in flight
// ---------------------------------------------------------------------------

/// Answers the first push (the create) after making a further edit, the way a user does while
/// a round is on the wire.
struct EditDuringCreate {
    engine: SyncEngine,
    row: Uuid,
    edited: AtomicBool,
}

impl Respond for EditDuringCreate {
    fn respond(&self, req: &Request) -> ResponseTemplate {
        let body: Value = serde_json::from_slice(&req.body).unwrap();
        let seq = body["mutations"][0]["seq"].as_i64().unwrap();
        if !self.edited.swap(true, Ordering::SeqCst) {
            self.engine
                .mutate(LocalMutation::update(
                    Entity::Company,
                    self.row,
                    &["city"],
                    json!({"city": "Izmir"}),
                ))
                .expect("an edit while the round is in flight");
            return ResponseTemplate::new(200).set_body_json(push_body(vec![applied(seq, 700, 91)]));
        }
        ResponseTemplate::new(200).set_body_json(push_body(vec![applied(seq, 700, 92)]))
    }
}

/// The row was born at the version the create returned: that is what the edit was based on,
/// and anything newer on the server is a real change somebody else made.
#[tokio::test(flavor = "multi_thread")]
async fn an_edit_made_during_its_own_create_is_based_on_the_created_version() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;

    let row = Uuid::now_v7();
    h.engine
        .mutate(LocalMutation::create(Entity::Company, row, json!({"name": "Born offline"})))
        .unwrap();
    mount_push_responder(
        &h.server,
        EditDuringCreate {
            engine: h.engine.clone(),
            row,
            edited: AtomicBool::new(false),
        },
    )
    .await;

    h.engine.sync_now().await.expect("first round");
    h.engine.sync_now().await.expect("second round");

    let pushes = push_requests(&h.server).await;
    let update = pushes
        .iter()
        .flat_map(|p| p["mutations"].as_array().unwrap().iter())
        .find(|m| m["op"] == "update")
        .expect("the edit was pushed");
    assert_eq!(update["base_sync_version"], 91, "got {update}");
}

// ---------------------------------------------------------------------------
// The base of an edit made while an earlier update of the row was in flight
// ---------------------------------------------------------------------------

struct EditDuringUpdate {
    engine: SyncEngine,
    row: Uuid,
    second: Value,
    fields: &'static [&'static str],
    edited: AtomicBool,
}

impl Respond for EditDuringUpdate {
    fn respond(&self, req: &Request) -> ResponseTemplate {
        let body: Value = serde_json::from_slice(&req.body).unwrap();
        let seq = body["mutations"][0]["seq"].as_i64().unwrap();
        if !self.edited.swap(true, Ordering::SeqCst) {
            self.engine
                .mutate(LocalMutation::update(
                    Entity::Company,
                    self.row,
                    self.fields,
                    self.second.clone(),
                ))
                .expect("an edit while the round is in flight");
            return ResponseTemplate::new(200).set_body_json(push_body(vec![applied(seq, 700, 11)]));
        }
        ResponseTemplate::new(200).set_body_json(push_body(vec![applied(seq, 700, 12)]))
    }
}

/// `(first update's fields, second update's fields) -> base the second one goes out with`.
async fn second_edit_base(fields: &'static [&'static str], second: Value) -> Value {
    let h = Harness::start().await;
    h.login().await;
    let row = Uuid::now_v7();
    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({"id":700,"client_id":row.to_string(),"name":"Base","sync_version":10})],
            vec![],
            10,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    mount_empty_pull(&h.server).await;

    h.engine
        .mutate(LocalMutation::update(
            Entity::Company,
            row,
            &["city"],
            json!({"city": "Ankara"}),
        ))
        .unwrap();
    mount_push_responder(
        &h.server,
        EditDuringUpdate {
            engine: h.engine.clone(),
            row,
            second,
            fields,
            edited: AtomicBool::new(false),
        },
    )
    .await;

    h.engine.sync_now().await.expect("first round");
    h.engine.sync_now().await.expect("second round");

    let pushes = push_requests(&h.server).await;
    let second = pushes
        .iter()
        .flat_map(|p| p["mutations"].as_array().unwrap().iter())
        .nth(1)
        .expect("the second edit was pushed")
        .clone();
    second["base_sync_version"].clone()
}

/// The first update was accepted, which proves nobody else had written its fields below the
/// version it returned. An edit confined to those fields can stand on that version, and its
/// own earlier write no longer reads as somebody else's.
#[tokio::test(flavor = "multi_thread")]
async fn a_second_edit_of_the_same_field_is_based_on_the_first_one() {
    assert_eq!(second_edit_base(&["city"], json!({"city": "Izmir"})).await, 11);
}

/// The acceptance proves nothing about `phone`: someone may have written it in between, so
/// the base must stay where it is, even though that costs a conflict with the user's own write.
#[tokio::test(flavor = "multi_thread")]
async fn a_second_edit_reaching_beyond_the_first_keeps_its_base() {
    let base = second_edit_base(&["city", "phone"], json!({"city": "Izmir", "phone": "1"})).await;
    assert_eq!(base, 10);
}
