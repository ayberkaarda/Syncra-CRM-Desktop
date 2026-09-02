//! Consumer 1 of `wire-fixtures/`: **the client's serialiser**.
//!
//! # Why this file exists
//!
//! Four times in this project the same failure shape reached a live round-trip with both test
//! suites green:
//!
//! 1. AUTH-1 — 16 mismatches, none of which either suite could see;
//! 2. `ApiErrorBody` — a flat `Deserialize` against a nested envelope, so `code` was **always**
//!    `None`;
//! 3. the ticket SLA columns — the server sent them, the mirror had no column, `upsert_row`
//!    dropped them without a word;
//! 4. B1 — the client sent a bare `"move"` and the server was being tested against its own
//!    dotted `'deal.move'`, so twelve verbs were dead on the wire.
//!
//! Every one of them has the same cause: **each side tested its own mock.** Nothing anywhere
//! asserted that the two mocks described the same bytes. In B1 the backend fixtures were
//! written in the server's own dialect, which is why PHPUnit was green about a body no client
//! ever sends.
//!
//! `wire-fixtures/` is one canonical, versioned set of JSON bodies with three consumers, and
//! **no consumer keeps a copy**. This file is the first:
//!
//! * `wire-fixtures/push/*.json` -> `local_mutation` is deserialised into the real
//!   [`LocalMutation`], the real [`OutboxRow`] is built from it, and the real
//!   [`OutboxRow::to_wire`] runs. Its output is compared to `wire` **key by key, both
//!   directions** — a missing field and a surplus field are both red.
//! * `wire-fixtures/pull/*.json` -> every non-envelope key of the canonical row must resolve to
//!   a column of the mirror table, because a key that does not is a key `upsert_row` silently
//!   discards. That is case 3, mechanised.
//! * `wire-fixtures/errors/*.json` -> [`ApiErrorBody::from_value`] against the shape the real
//!   Laravel handlers produce. That is case 2, mechanised.
//!
//! The other two consumers are `backend/tests/Feature/Sync/WireFixtureTest.php` (the same
//! bodies, POSTed at a live MariaDB) and `desktop/src/platform/data/wire-fixtures.test.ts` (the
//! same bodies, produced by the TypeScript composers). Change one side without touching the
//! fixture and that side goes red; change the fixture and the *other* side goes red. There is
//! no third state in which everyone is green and the wire is broken.
//!
//! # What these fixtures are NOT
//!
//! They record **today's** wire, not a wire anybody wishes for. A disagreement between a
//! fixture and a consumer is a finding to report, never a fixture to edit into agreement.
//!
//! # Scope of the current set
//!
//! `push/` is complete: one canonical body per `op` (`create`/`update`/`delete`/`action`) and
//! one per whitelisted action, all twelve. `pull/` and `errors/` are skeletons with a worked
//! example each — the remaining entities and error codes are listed in the hand-off report.

use std::collections::BTreeSet;
use std::path::{Path, PathBuf};

use serde_json::{Map, Value};
use syncra_sync::db;
use syncra_sync::db::schema::spec_for;
use syncra_sync::outbox::OutboxRow;
use syncra_sync::protocol::{ApiErrorBody, ACTION_WHITELIST};
use syncra_sync::{Entity, LocalMutation, Op};

// ---------------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------------

/// The repository's `wire-fixtures/` directory.
///
/// Resolved from `CARGO_MANIFEST_DIR` rather than from the working directory: `cargo test`
/// runs with the crate root as cwd, but `cargo test --workspace` from `desktop/` does not, and
/// a path that only works under one of them is a test that mysteriously vanishes under the
/// other.
fn fixture_root() -> PathBuf {
    let manifest = Path::new(env!("CARGO_MANIFEST_DIR"));
    let root = manifest
        .ancestors()
        .nth(3)
        .expect("crates/syncra-sync sits three levels below the repository root")
        .join("wire-fixtures");
    assert!(
        root.is_dir(),
        "wire-fixtures/ not found at {}",
        root.display()
    );
    root
}

/// Every `*.json` under `wire-fixtures/<kind>/`, sorted by file name.
///
/// Sorted so a failure names the same fixture on every machine, and so "how many are there"
/// is a stable number rather than whatever order the filesystem felt like.
fn load(kind: &str) -> Vec<(String, Value)> {
    let dir = fixture_root().join(kind);
    let mut entries: Vec<PathBuf> = std::fs::read_dir(&dir)
        .unwrap_or_else(|e| panic!("cannot read {}: {e}", dir.display()))
        .map(|e| e.expect("dir entry").path())
        .filter(|p| p.extension().map(|x| x == "json").unwrap_or(false))
        .collect();
    entries.sort();

    entries
        .into_iter()
        .map(|path| {
            let name = path
                .file_stem()
                .expect("file stem")
                .to_string_lossy()
                .to_string();
            let text = std::fs::read_to_string(&path)
                .unwrap_or_else(|e| panic!("cannot read {}: {e}", path.display()));
            let value: Value = serde_json::from_str(&text)
                .unwrap_or_else(|e| panic!("{} is not valid JSON: {e}", path.display()));
            (name, value)
        })
        .collect()
}

fn object<'a>(value: &'a Value, key: &str, fixture: &str) -> &'a Map<String, Value> {
    value
        .get(key)
        .unwrap_or_else(|| panic!("{fixture}: missing `{key}`"))
        .as_object()
        .unwrap_or_else(|| panic!("{fixture}: `{key}` is not an object"))
}

// ---------------------------------------------------------------------------
// push — the client's serialiser
// ---------------------------------------------------------------------------

/// Rebuild the outbox row a fixture describes.
///
/// The split is the one the engine really makes: `local_mutation` is what the UI hands to
/// `SyncEngine::mutate`, and the `outbox` block is what `outbox::enqueue` adds on the way to
/// disk — the sequence number, the replay key, the timestamp, the identity captured off the
/// mirror row, and the `scope` that only `notification.read_all` carries. Assembling them here
/// rather than driving a live enqueue is deliberate: `seq`, `idempotency_key` and `occurred_at`
/// are generated values, and a fixture that had to accommodate them could not be compared
/// literally at all.
fn outbox_row(fixture: &str, doc: &Value) -> OutboxRow {
    let mutation: LocalMutation = serde_json::from_value(
        doc.get("local_mutation")
            .unwrap_or_else(|| panic!("{fixture}: missing `local_mutation`"))
            .clone(),
    )
    .unwrap_or_else(|e| {
        panic!("{fixture}: `local_mutation` is not a syncra_sync::LocalMutation: {e}")
    });

    let ob = object(doc, "outbox", fixture);
    let opt_i64 = |key: &str| ob.get(key).and_then(|v| v.as_i64());
    let opt_str = |key: &str| {
        ob.get(key)
            .and_then(|v| v.as_str())
            .map(|s| s.to_string())
    };

    OutboxRow {
        id: uuid::Uuid::nil(),
        seq: opt_i64("seq").unwrap_or_else(|| panic!("{fixture}: outbox.seq")),
        idempotency_key: opt_str("idempotency_key")
            .unwrap_or_else(|| panic!("{fixture}: outbox.idempotency_key")),
        entity: mutation.entity,
        op: mutation.op,
        action: mutation.action.clone(),
        scope: opt_str("scope"),
        client_id: mutation.client_id.map(|u| u.to_string()),
        server_id: opt_i64("server_id"),
        base_sync_version: opt_i64("base_sync_version"),
        changed_fields: mutation.changed_fields.clone(),
        // `enqueue` stores a JSON null payload as SQL NULL, so the round-tripped row carries
        // `None`, not `Some(Value::Null)`. Reproducing that here keeps `to_wire`'s input equal
        // to what it really receives.
        payload: if mutation.payload.is_null() {
            None
        } else {
            Some(mutation.payload.clone())
        },
        occurred_at: opt_str("occurred_at")
            .unwrap_or_else(|| panic!("{fixture}: outbox.occurred_at")),
        attempts: 0,
        state: "queued".to_string(),
    }
}

/// Compare two JSON objects key by key, in both directions.
///
/// `assert_eq!` on the whole `Value` would do the job, but its failure output is two walls of
/// JSON and the reader has to diff them by eye. This reports the offending KEY, which is what
/// every one of the four historical mismatches actually was — a name, a dialect, a field that
/// was not there.
fn assert_same_object(fixture: &str, expected: &Map<String, Value>, actual: &Map<String, Value>) {
    let want: BTreeSet<&String> = expected.keys().collect();
    let got: BTreeSet<&String> = actual.keys().collect();

    let missing: Vec<&&String> = want.difference(&got).collect();
    assert!(
        missing.is_empty(),
        "{fixture}: to_wire() did not emit {missing:?}\n  fixture: {}\n  emitted: {}",
        Value::Object(expected.clone()),
        Value::Object(actual.clone()),
    );

    let surplus: Vec<&&String> = got.difference(&want).collect();
    assert!(
        surplus.is_empty(),
        "{fixture}: to_wire() emitted {surplus:?}, which the fixture does not describe. \
         A field the fixture has never seen is a field the server has never been asked about.\n  \
         fixture: {}\n  emitted: {}",
        Value::Object(expected.clone()),
        Value::Object(actual.clone()),
    );

    for key in want {
        assert_eq!(
            actual.get(key),
            expected.get(key),
            "{fixture}: `{key}` differs between the fixture and to_wire()"
        );
    }
}

/// The whole point of the file: what the client puts on the wire is what the fixture says.
#[test]
fn every_push_fixture_matches_what_to_wire_produces() {
    let fixtures = load("push");
    assert!(!fixtures.is_empty(), "wire-fixtures/push is empty");

    for (name, doc) in &fixtures {
        let row = outbox_row(name, doc);
        let emitted = serde_json::to_value(row.to_wire()).expect("WireMutation serialises");
        let emitted = emitted.as_object().expect("a wire mutation is an object");

        assert_same_object(name, object(doc, "wire", name), emitted);
    }
}

/// The fixture's own `op` / `entity` / `action` header must agree with its body.
///
/// Cheap, and it catches the copy-paste that produces a "new" fixture which is really the old
/// one under a new file name — the failure mode a directory of near-identical JSON invites.
#[test]
fn every_push_fixture_is_labelled_with_the_mutation_it_carries() {
    for (name, doc) in load("push") {
        let wire = object(&doc, "wire", &name);
        assert_eq!(
            doc["op"].as_str(),
            wire["op"].as_str(),
            "{name}: header `op` disagrees with the body"
        );
        assert_eq!(
            doc["entity"].as_str(),
            wire["entity"].as_str(),
            "{name}: header `entity` disagrees with the body"
        );
        assert_eq!(
            doc["action"].as_str(),
            wire.get("action").and_then(|v| v.as_str()),
            "{name}: header `action` disagrees with the body"
        );
    }
}

/// O45 / B1: `action` is the BARE verb. The server refuses an entity-qualified one outright.
///
/// This is the assertion that would have caught B1 on the day it was written, because it is
/// about the fixture set itself and needs neither side to be running.
#[test]
fn no_fixture_speaks_the_entity_qualified_action_dialect() {
    for (name, doc) in load("push") {
        let Some(action) = doc["wire"].get("action").and_then(|v| v.as_str()) else {
            continue;
        };
        assert!(
            !action.contains('.'),
            "{name}: `action` is {action:?}. The wire carries the two halves separately \
             (`{{\"entity\":\"deal\",\"action\":\"move\"}}`); a dotted verb is rejected with \
             INVALID_MUTATION (O45)."
        );
    }
}

/// Coverage, stated as a test rather than as a comment somebody has to keep true.
///
/// A thirteenth entry added to `ACTION_WHITELIST` without a fixture is a verb no consumer is
/// checking, which is precisely the state the twelve were in before B1 was found.
#[test]
fn every_whitelisted_action_has_a_fixture() {
    let fixtures = load("push");

    let covered: BTreeSet<(String, String)> = fixtures
        .iter()
        .filter_map(|(_, doc)| {
            let wire = doc.get("wire")?;
            let action = wire.get("action")?.as_str()?;
            let entity = wire.get("entity")?.as_str()?;
            Some((entity.to_string(), action.to_string()))
        })
        .collect();

    let missing: Vec<String> = ACTION_WHITELIST
        .iter()
        .filter(|(entity, action)| !covered.contains(&(entity.to_string(), action.to_string())))
        .map(|(entity, action)| format!("{entity}.{action}"))
        .collect();

    assert!(
        missing.is_empty(),
        "no wire fixture covers: {missing:?}. Every whitelisted action needs a canonical body, \
         or it is a verb both sides can break independently."
    );

    let stray: Vec<String> = covered
        .iter()
        .filter(|(entity, action)| {
            !ACTION_WHITELIST
                .iter()
                .any(|(e, a)| *e == entity && *a == action)
        })
        .map(|(entity, action)| format!("{entity}.{action}"))
        .collect();

    assert!(
        stray.is_empty(),
        "fixtures exist for actions that are NOT whitelisted: {stray:?}. The server answers \
         those `rejected`, so a fixture claiming otherwise is a lie the client would believe."
    );
}

/// All four ops carry a canonical body, and each one is really shaped like its op.
///
/// The per-op invariants below are the ones the historical bugs were about: a delete that grew
/// a payload, an update that lost `changed_fields`, an action that started carrying a delta
/// cursor it has no use for.
#[test]
fn every_op_has_a_fixture_shaped_like_that_op() {
    let fixtures = load("push");
    let ops: BTreeSet<String> = fixtures
        .iter()
        .filter_map(|(_, doc)| doc["wire"]["op"].as_str().map(str::to_owned))
        .collect();

    for op in [Op::Create, Op::Update, Op::Action, Op::Delete] {
        assert!(
            ops.contains(op.wire_name()),
            "wire-fixtures/push has no `{}` body",
            op.wire_name()
        );
    }

    for (name, doc) in &fixtures {
        let wire = object(doc, "wire", name);
        let has = |key: &str| wire.contains_key(key);

        match wire["op"].as_str().expect("op") {
            "create" => {
                assert!(has("client_id"), "{name}: a create is addressed by client_id");
                assert!(!has("server_id"), "{name}: a create has no server id yet");
                assert!(
                    !has("base_sync_version"),
                    "{name}: a create is not based on a server version"
                );
                assert!(!has("changed_fields"), "{name}: a create writes everything");
            }
            "update" => {
                assert!(
                    has("changed_fields"),
                    "{name}: `changed_fields` IS the contract for an update (K6); without it \
                     the server would write the client's whole record over everyone else's edits"
                );
                assert!(has("base_sync_version"), "{name}: an update carries its base");
                assert!(has("payload"), "{name}: an update carries the new values");
            }
            "delete" => {
                assert!(
                    !has("payload"),
                    "{name}: a delete carries no payload (SYNCDESKTOP 4.4)"
                );
                assert!(
                    !has("occurred_at"),
                    "{name}: a delete carries no occurred_at — its conflict decision is the \
                     base_sync_version comparison alone"
                );
                assert!(has("base_sync_version"), "{name}: a delete carries its base");
            }
            "action" => {
                assert!(has("action"), "{name}: an action names its verb");
                assert!(
                    !has("base_sync_version"),
                    "{name}: `to_wire` sends base_sync_version for update/delete only; an \
                     action is settled by its own domain rules"
                );
            }
            other => panic!("{name}: unknown op {other:?}"),
        }
    }
}

/// Protocol §4.3 P10, the one mutation with no row identity at all.
///
/// Split out of the op test because it is an exception to it, and an exception buried inside a
/// `match` arm is an exception nobody reads.
#[test]
fn read_all_is_the_only_body_without_a_row_identity() {
    for (name, doc) in load("push") {
        let wire = object(&doc, "wire", &name);
        let identified = wire.contains_key("client_id") || wire.contains_key("server_id");
        let is_read_all = wire["entity"] == "notification" && wire.get("action") == Some(&Value::from("read_all"));

        if is_read_all {
            assert!(
                !identified,
                "{name}: notification.read_all is user-scoped and carries neither id"
            );
            assert_eq!(
                wire.get("scope"),
                Some(&Value::from("user")),
                "{name}: without `scope: \"user\"` the server answers INVALID_MUTATION"
            );
            assert!(
                !wire.contains_key("payload"),
                "{name}: read_all has nothing to say beyond its scope"
            );
        } else {
            assert!(identified, "{name}: every other mutation names its row");
            assert!(
                !wire.contains_key("scope"),
                "{name}: `scope` is validated as `in:user` and belongs to read_all alone"
            );
        }
    }
}

// ---------------------------------------------------------------------------
// pull — the mirror schema
// ---------------------------------------------------------------------------

/// Case 3, mechanised: a pulled key with no mirror column is dropped in silence.
///
/// `upsert_row` skips any key `table_columns` does not contain — deliberately, because the
/// server is free to add columns a shipped build does not know. The cost of that tolerance is
/// that a column the client is *supposed* to have looks exactly like one it is *allowed* to
/// ignore. This test is the difference: a key named in a fixture is a key the mirror owes a
/// column, and the four SLA fields are in `wire-fixtures/pull/ticket.row.json` for that reason.
#[test]
fn every_pulled_key_has_a_mirror_column() {
    let fixtures = load("pull");
    assert!(!fixtures.is_empty(), "wire-fixtures/pull is empty");

    let dir = tempfile::tempdir().expect("tempdir");
    let conn = db::open(
        &dir.path().join("fixtures.db"),
        // 64 hex characters, as `SyncEngine::open` supplies from the keychain (K9).
        &"a".repeat(64),
    )
    .expect("open mirror");

    for (name, doc) in &fixtures {
        let entity = Entity::from_wire_name(doc["entity"].as_str().expect("entity"))
            .unwrap_or_else(|| panic!("{name}: unknown entity"));
        let spec = spec_for(entity);
        let columns = db::columns(&conn, entity.table()).expect("columns");

        let envelope: BTreeSet<&str> = doc["envelope_keys"]
            .as_array()
            .unwrap_or_else(|| panic!("{name}: missing `envelope_keys`"))
            .iter()
            .map(|v| v.as_str().expect("envelope key"))
            .collect();

        for key in object(doc, "row", name).keys() {
            if envelope.contains(key.as_str()) {
                continue;
            }
            // Same resolution `upsert_row` performs, in the same order.
            let column = spec
                .aliases
                .iter()
                .find(|(server, _)| server == key)
                .map(|(_, local)| (*local).to_string())
                .unwrap_or_else(|| key.clone());

            assert!(
                columns.contains(&column),
                "{name}: the server sends `{key}` but `{}` has no `{column}` column, so \
                 upsert_row discards it without a word. This is exactly how the ticket SLA \
                 fields went missing.",
                entity.table()
            );
        }
    }
}

// ---------------------------------------------------------------------------
// errors — the envelope
// ---------------------------------------------------------------------------

/// Case 2, mechanised: the code lives one level down, under `errors`.
///
/// `ApiErrorBody` used to be a plain derive on the flat shape, so every real refusal parsed to
/// `code: None` and the desktop could not tell a lockout from a bad password. These fixtures
/// are the bodies the Laravel handlers actually produce.
#[test]
fn every_error_fixture_parses_into_the_code_it_declares() {
    let fixtures = load("errors");
    assert!(!fixtures.is_empty(), "wire-fixtures/errors is empty");

    for (name, doc) in &fixtures {
        let parsed = ApiErrorBody::from_value(&doc["body"]);
        let expect = object(doc, "expect", name);

        assert_eq!(
            parsed.code.as_deref(),
            expect["code"].as_str(),
            "{name}: the server's own code must survive the parse"
        );
        assert_eq!(
            parsed.retry_after,
            expect["retry_after"].as_u64(),
            "{name}: retry_after is what the lockout countdown reads"
        );
        if expect["message_present"] == Value::Bool(true) {
            assert!(
                parsed.message.is_some(),
                "{name}: a refusal with no message reaches the log as an empty line"
            );
        }
    }
}
