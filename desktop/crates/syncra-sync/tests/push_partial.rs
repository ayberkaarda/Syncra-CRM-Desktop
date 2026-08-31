//! Protocol §4.3 P10b / §6.4 P15 — the partial push response.
//!
//! When the server's lock-contention retry budget runs out (protocol §2.4 P4a) it stops
//! processing the batch and answers **HTTP 200 with a short `results` array**. The binding
//! sentence:
//!
//! > every mutation whose `seq` is absent from `results` is considered unprocessed; it
//! > stays `queued` on the client and is resent on the next round.
//!
//! The two things that must hold are that it goes back to `queued` *and* that `attempts` is
//! not incremented — a mutation the server never looked at must not burn retry budget.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::{Entity, LocalMutation};
use uuid::Uuid;

#[tokio::test]
async fn mutations_missing_from_results_stay_queued_without_counting_an_attempt() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;

    // The first push answers for all but the last two mutations.
    mount_push_responder_once(&h.server, ApplyAllButLast(2)).await;
    mount_push_responder(&h.server, ApplyAll).await;

    for i in 0..5 {
        h.engine
            .mutate(LocalMutation::create(
                Entity::Company,
                Uuid::now_v7(),
                json!({ "name": format!("Company {i}") }),
            ))
            .unwrap();
    }
    assert_eq!(h.engine.status().pending, 5);

    let report = h.engine.sync_now().await.expect("first round");
    assert_eq!(report.pushed, 5);
    assert_eq!(report.applied, 3);
    assert_eq!(
        report.deferred, 2,
        "two mutations were left out of results and must be reported as deferred"
    );
    assert_eq!(report.conflicts, 0);
    assert_eq!(report.rejected, 0);

    assert_eq!(
        h.engine.status().pending,
        2,
        "the unanswered mutations must still be pending, not failed"
    );
    assert_eq!(
        h.engine.conflicts().unwrap().len(),
        0,
        "an unanswered mutation is not a conflict"
    );

    // The deferred rows carry attempts = 0: the server never processed them.
    let attempts = h.queued_attempts();
    assert_eq!(
        attempts,
        vec![0, 0],
        "attempts must not be incremented for mutations the server did not answer"
    );

    // Next round sends exactly those two, with their original idempotency keys.
    let first_keys = deferred_keys(&h.server, 3).await;
    let report = h.engine.sync_now().await.expect("second round");
    assert_eq!(report.applied, 2);
    assert_eq!(report.deferred, 0);
    assert_eq!(h.engine.status().pending, 0);

    let requests = push_requests(&h.server).await;
    assert_eq!(requests.len(), 2);
    let resent = requests[1]["mutations"].as_array().unwrap();
    assert_eq!(resent.len(), 2, "only the unanswered mutations are resent");
    let resent_keys: Vec<String> = resent
        .iter()
        .map(|m| m["idempotency_key"].as_str().unwrap().to_string())
        .collect();
    assert_eq!(
        resent_keys, first_keys,
        "the resend must reuse the original idempotency keys"
    );
}

/// A transport failure is the opposite case: the whole batch returns to the queue *and*
/// counts an attempt, because the request genuinely failed.
#[tokio::test]
async fn a_transport_failure_does_count_an_attempt() {
    let h = Harness::start().await;
    h.login().await;
    mount_empty_pull(&h.server).await;

    wiremock::Mock::given(wiremock::matchers::method("POST"))
        .and(wiremock::matchers::path("/api/sync/push"))
        .respond_with(wiremock::ResponseTemplate::new(503))
        .mount(&h.server)
        .await;

    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Acme" }),
        ))
        .unwrap();

    assert!(h.engine.sync_now().await.is_err());
    assert_eq!(h.engine.status().pending, 1);
    assert_eq!(
        h.queued_attempts(),
        vec![1],
        "a failed request must count against the retry budget"
    );
}

/// The idempotency keys of the mutations the first push left unanswered.
async fn deferred_keys(server: &wiremock::MockServer, answered: usize) -> Vec<String> {
    let requests = push_requests(server).await;
    requests[0]["mutations"]
        .as_array()
        .unwrap()
        .iter()
        .skip(answered)
        .map(|m| m["idempotency_key"].as_str().unwrap().to_string())
        .collect()
}
