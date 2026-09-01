//! defter O83 — the mirror must speak exactly one timestamp dialect.
//!
//! Two writers used to fill the same `TEXT` columns with two different shapes:
//!
//! ```text
//! server row   2026-09-01 23:59:00        MySQL DATETIME text, SPACE at offset 10
//! offline row  2026-09-01T08:00:00.000Z   outbox::now_iso(), RFC 3339, `T` at offset 10
//! ```
//!
//! Every comparison `db/query.rs` makes on those columns is a plain string comparison, and
//! `'T'` (0x54) sorts above `' '` (0x20). The tests below are the three user-visible
//! consequences — list order, the `overdue` filter and the chat keyset cursor — plus the two
//! write boundaries that have to agree (`upsert_row` on the pull path, `apply_server_row` on
//! the conflict path).
//!
//! These run against `wiremock` through the real engine, not against hand-built SQL: the bug
//! was never in one function, it was in what the two halves of the app wrote past each other.

mod common;

use chrono::{Duration, Utc};
use common::*;
use serde_json::json;
use syncra_sync::{
    Entity, LocalMutation, NamedQuery, QueryParams, Resolution, SortDir, SortField,
};
use uuid::Uuid;

/// The canonical shape everything in the mirror is expected to be stored in.
///
/// Asserted structurally rather than against a literal, so a fixture cannot claim a shape the
/// production writer does not actually produce.
fn assert_canonical(value: &str) {
    assert_eq!(value.len(), 24, "expected `YYYY-MM-DDTHH:MM:SS.mmmZ`, got {value:?}");
    assert_eq!(&value[10..11], "T", "{value:?} must use the `T` separator");
    assert_eq!(&value[19..20], ".", "{value:?} must carry milliseconds");
    assert_eq!(&value[23..], "Z", "{value:?} must be explicitly UTC");
}

/// A MySQL `DATETIME`-shaped stamp that is strictly inside today's UTC date and strictly in
/// the future.
///
/// The `overdue` predicate compares against SQLite's own `strftime(..., 'now')`, so the
/// fixture has to be pinned to the same day the query will compute — a hard-coded date would
/// test nothing. Within ten seconds of midnight UTC there is no "later today" that survives
/// truncation to whole seconds, so the helper waits that out rather than asserting against a
/// moving target.
fn later_today_server_dialect() -> String {
    loop {
        let now = Utc::now();
        let midnight = (now.date_naive() + Duration::days(1))
            .and_hms_opt(0, 0, 0)
            .expect("midnight")
            .and_utc();
        let remaining = midnight - now;
        if remaining < Duration::seconds(10) {
            std::thread::sleep(std::time::Duration::from_secs(11));
            continue;
        }
        return (now + remaining / 2).format("%Y-%m-%d %H:%M:%S").to_string();
    }
}

/// Yesterday, in the server's dialect. Genuinely overdue by any reading.
fn yesterday_server_dialect() -> String {
    (Utc::now() - Duration::days(1))
        .format("%Y-%m-%d %H:%M:%S")
        .to_string()
}

/// THE REGRESSION (defter O83). A server row created late in the day and an offline row
/// created early the *same* day must come back in real-clock order.
///
/// Before the fix the offline row's `T` beat the server row's space for the whole date, so
/// `ORDER BY created_at DESC` put 08:00 above 23:59 — the exact inversion the ledger shows.
#[tokio::test]
async fn same_day_server_and_offline_rows_sort_by_the_real_clock() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "companies",
            vec![json!({
                "id": 900,
                "name": "Server 23:59",
                // Exactly what `SyncPullService::fetchRows()` puts on the wire: `DB::table()`
                // reads the column with no Eloquent cast, so the raw MySQL text ships as-is.
                "created_at": "2026-09-01 23:59:00",
                "updated_at": "2026-09-01 23:59:00",
                "sync_version": 1
            })],
            vec![],
            1,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    mount_empty_pull(&h.server).await;

    let offline = Uuid::now_v7();
    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            offline,
            json!({ "name": "Offline 08:00", "created_at": "2026-09-01T08:00:00.000Z" }),
        ))
        .expect("offline create");

    let rows = h
        .engine
        .query(
            NamedQuery::companies(),
            QueryParams {
                sort_by: Some(SortField::CreatedAt),
                sort_dir: SortDir::Desc,
                ..Default::default()
            },
        )
        .expect("query");

    let order: Vec<&str> = rows.iter().filter_map(|r| r.get_str("name")).collect();
    assert_eq!(
        order,
        vec!["Server 23:59", "Offline 08:00"],
        "newest first means 23:59 above 08:00 — a dialect must never outrank the clock"
    );

    // ...and ascending is the mirror image, so the test cannot be satisfied by a fluke.
    let rows = h
        .engine
        .query(
            NamedQuery::companies(),
            QueryParams {
                sort_by: Some(SortField::CreatedAt),
                sort_dir: SortDir::Asc,
                ..Default::default()
            },
        )
        .expect("query");
    let order: Vec<&str> = rows.iter().filter_map(|r| r.get_str("name")).collect();
    assert_eq!(order, vec!["Offline 08:00", "Server 23:59"]);
}

/// The `overdue` filter is `due_at < strftime('%Y-%m-%dT%H:%M:%SZ','now')` — a `T`-form
/// "now". A server task's space-form `due_at` sorted below that for the whole day, so every
/// server task due later *today* was reported overdue.
///
/// The yesterday task is the positive control: an implementation that simply returned nothing
/// would pass the first assertion and fail this one.
#[tokio::test]
async fn a_server_task_due_later_today_is_not_overdue() {
    let h = Harness::start().await;
    h.login().await;

    let later_today = later_today_server_dialect();
    let yesterday = yesterday_server_dialect();

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "tasks",
            vec![
                json!({
                    "id": 41, "title": "Due later today", "status": "pending",
                    "priority": "high", "due_at": later_today, "sync_version": 1
                }),
                json!({
                    "id": 42, "title": "Due yesterday", "status": "pending",
                    "priority": "high", "due_at": yesterday, "sync_version": 1
                }),
            ],
            vec![],
            1,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    mount_empty_pull(&h.server).await;

    let overdue = NamedQuery::TaskList {
        q: None,
        status: None,
        priority: None,
        assigned_to: None,
        created_by: None,
        taskable_type: None,
        taskable_id: None,
        overdue: Some(true),
        from: None,
        to: None,
    };
    let rows = h
        .engine
        .query(overdue, QueryParams::default())
        .expect("query");
    let titles: Vec<&str> = rows.iter().filter_map(|r| r.get_str("title")).collect();

    assert_eq!(
        titles,
        vec!["Due yesterday"],
        "a task due later today is not overdue; one due yesterday is (due_at fixture: \
         later_today={later_today:?}, yesterday={yesterday:?})"
    );
}

/// The chat keyset cursor is `created_at < (SELECT created_at FROM messages WHERE
/// server_id = ?)`. With two dialects in the column an offline message sitting inside the
/// page was compared against a server cursor value and dropped, so "load older" silently
/// skipped it.
#[tokio::test]
async fn the_message_keyset_cursor_cuts_the_page_across_both_dialects() {
    let h = Harness::start_with_granted(&[Entity::Conversation, Entity::Message]).await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "messages",
            vec![
                json!({ "id": 1, "conversation_id": 7, "body": "server 09:00",
                        "created_at": "2026-09-01 09:00:00", "sync_version": 1 }),
                json!({ "id": 2, "conversation_id": 7, "body": "server 10:00",
                        "created_at": "2026-09-01 10:00:00", "sync_version": 2 }),
                json!({ "id": 3, "conversation_id": 7, "body": "server 11:00",
                        "created_at": "2026-09-01 11:00:00", "sync_version": 3 }),
            ],
            vec![],
            3,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");
    mount_empty_pull(&h.server).await;

    // Sent offline, between the 09:00 and 10:00 server messages.
    h.engine
        .mutate(LocalMutation::create(
            Entity::Message,
            Uuid::now_v7(),
            json!({
                "conversation_id": 7,
                "body": "offline 09:30",
                "created_at": "2026-09-01T09:30:00.000Z"
            }),
        ))
        .expect("offline message");

    let rows = h
        .engine
        .query(
            NamedQuery::ConversationMessages {
                conversation_id: 7,
                before_server_id: Some(3),
            },
            QueryParams::default(),
        )
        .expect("query");
    let bodies: Vec<&str> = rows.iter().filter_map(|r| r.get_str("body")).collect();

    assert_eq!(
        bodies,
        vec!["server 10:00", "offline 09:30", "server 09:00"],
        "everything before the 11:00 cursor belongs on the page, offline messages included"
    );
}

/// The pull write boundary, end to end: what `upsert_row` stores for each input shape.
///
/// `expected_close_date` is the guard rail — a `date()` column whose value is already right
/// and must survive untouched. Fabricating a midnight for it would be a new bug, not a fix.
#[tokio::test]
async fn a_pull_normalises_only_what_needs_it() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "deals",
            vec![json!({
                "id": 700,
                "title": "Shapes",
                "amount": "10.00",
                "status": "open",
                "position": "a0",
                "version": 1,
                "created_at": "2026-09-01 23:59:00",   // server dialect -> normalised
                "updated_at": "2026-09-02T07:15:00Z",  // already zoned  -> untouched
                "closed_at": null,                     // NULL           -> untouched
                "expected_close_date": "2026-09-05",   // date-only      -> untouched
                "sync_version": 1
            })],
            vec![],
            1,
            false,
        )]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.expect("bootstrap");

    let rows = h
        .engine
        .query(NamedQuery::deals(), QueryParams::default())
        .expect("query");
    let row = &rows[0];

    let created = row.get_str("created_at").expect("created_at");
    assert_canonical(created);
    assert_eq!(created, "2026-09-01T23:59:00.000Z");
    assert_eq!(
        row.get_str("updated_at"),
        Some("2026-09-02T07:15:00Z"),
        "a stamp that already carries a zone is unambiguous — leave it alone"
    );
    assert_eq!(row.get_str("closed_at"), None, "NULL stays NULL");
    assert_eq!(
        row.get_str("expected_close_date"),
        Some("2026-09-05"),
        "a date-only column must never grow a fabricated midnight"
    );
}

/// The second write boundary. `apply_server_row` replays the raw server row — the
/// `server_row` a push conflict returned, or a `pending_shadows.row_json` a §5.5 pull parked
/// — so a `TakeServer` would otherwise write the old dialect straight back over a row the
/// pull path had already normalised.
#[tokio::test]
async fn take_server_writes_the_canonical_shape_too() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull_once(
        &h.server,
        pull_body(vec![(
            "deals",
            vec![json!({
                "id": 18342, "title": "Server title", "amount": "1000.00",
                "status": "open", "position": "a0", "version": 8,
                "created_at": "2026-09-01 08:00:00", "sync_version": 184000
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
        .query(NamedQuery::deals(), QueryParams::default())
        .expect("query");
    let deal = Uuid::parse_str(rows[0].get_str("client_id").expect("client_id")).expect("uuid");

    mount_push(
        &h.server,
        push_body(vec![conflict(
            1,
            &["amount"],
            json!({
                "id": 18342, "title": "Server title", "amount": "9999.00",
                "status": "open",
                // The conflict's server row carries the server dialect, same as a pull row.
                "created_at": "2026-09-01 08:00:00",
                "updated_at": "2026-09-01 23:59:00",
                "sync_version": 184990
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
        .expect("mutate");
    h.engine.sync_now().await.expect("sync");

    let conflict_id = h.engine.conflicts().expect("conflicts")[0].id;
    h.engine
        .resolve_conflict(conflict_id, Resolution::TakeServer)
        .expect("resolve");

    let row = h.engine.get(Entity::Deal, deal).expect("get").expect("row");
    assert_eq!(row.get_str("amount"), Some("9999.00"), "the server row won");
    assert_canonical(row.get_str("updated_at").expect("updated_at"));
    assert_eq!(
        row.get_str("updated_at"),
        Some("2026-09-01T23:59:00.000Z"),
        "a conflict resolution must not re-introduce the server dialect"
    );
}
