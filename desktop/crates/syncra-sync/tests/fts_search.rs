//! `SYNCDESKTOP.md` §5.7 / §5.3 — local full-text search, including the Turkish `İ`/`ı`
//! case that `remove_diacritics 2` plus application-side folding is there to handle.

mod common;

use common::*;
use serde_json::json;
use syncra_sync::{Entity, LocalMutation};
use uuid::Uuid;

#[tokio::test]
async fn search_finds_turkish_text_regardless_of_case() {
    let h = Harness::start().await;
    h.login().await;

    for (name, city) in [
        ("İstanbul Şirketi", "Kadıköy"),
        ("Ankara Ltd", "Çankaya"),
        ("ÇAĞDAŞ Yazılım", "İzmir"),
    ] {
        h.engine
            .mutate(LocalMutation::create(
                Entity::Company,
                Uuid::now_v7(),
                json!({ "name": name, "city": city }),
            ))
            .unwrap();
    }

    // Lowercase query against an uppercase Turkish dotted I.
    let hits = h.engine.search("istanbul", &[Entity::Company], 10).unwrap();
    assert_eq!(hits.len(), 1, "İstanbul must match a lowercase query: {hits:?}");
    assert_eq!(hits[0].entity, Entity::Company);

    // The same word written the way the user typed it.
    let hits = h.engine.search("İSTANBUL", &[Entity::Company], 10).unwrap();
    assert_eq!(hits.len(), 1);

    // Other Turkish letters round-trip too.
    let hits = h.engine.search("şirketi", &[Entity::Company], 10).unwrap();
    assert_eq!(hits.len(), 1);
    let hits = h.engine.search("ÇAĞDAŞ", &[Entity::Company], 10).unwrap();
    assert_eq!(hits.len(), 1);

    // Prefix search, which is what an as-you-type box produces.
    let hits = h.engine.search("ank", &[Entity::Company], 10).unwrap();
    assert_eq!(hits.len(), 1);
}

#[tokio::test]
async fn search_is_scoped_to_the_requested_entities() {
    let h = Harness::start().await;
    h.login().await;

    h.engine
        .mutate(LocalMutation::create(
            Entity::Company,
            Uuid::now_v7(),
            json!({ "name": "Zenith" }),
        ))
        .unwrap();
    h.engine
        .mutate(LocalMutation::create(
            Entity::Deal,
            Uuid::now_v7(),
            json!({ "title": "Zenith renewal", "status": "open", "amount": "1.00" }),
        ))
        .unwrap();

    assert_eq!(h.engine.search("zenith", &[], 10).unwrap().len(), 2);
    assert_eq!(
        h.engine
            .search("zenith", &[Entity::Deal], 10)
            .unwrap()
            .len(),
        1
    );
    assert_eq!(
        h.engine
            .search("zenith", &[Entity::Company], 10)
            .unwrap()[0]
            .entity,
        Entity::Company
    );
}

/// A pulled row is indexed by the same triggers as a locally created one.
#[tokio::test]
async fn pulled_rows_are_indexed_too() {
    let h = Harness::start().await;
    h.login().await;

    mount_pull(
        &h.server,
        pull_body(vec![
            (
                "companies",
                vec![json!({ "id": 44, "name": "Öztürk Holding", "sync_version": 10 })],
                vec![],
                10,
                false,
            ),
            (
                "deals",
                vec![json!({
                    "id": 1, "title": "Öztürk yenileme", "status": "open",
                    "company_id": 44, "amount": "5.00", "position": "a0", "sync_version": 11
                })],
                vec![],
                11,
                false,
            ),
        ]),
    )
    .await;
    h.engine.bootstrap(|_| {}).await.unwrap();

    let hits = h.engine.search("öztürk", &[], 10).unwrap();
    assert_eq!(hits.len(), 2, "{hits:?}");

    // The deal indexes its company's name in the body column (§5.3).
    let deal_hit = hits
        .iter()
        .find(|h| h.entity == Entity::Deal)
        .expect("the deal must be indexed");
    assert!(deal_hit.title.contains("yenileme"));
}

/// An empty or punctuation-only query is not an error and matches nothing.
#[tokio::test]
async fn an_empty_query_returns_nothing() {
    let h = Harness::start().await;
    h.login().await;
    assert!(h.engine.search("", &[], 10).unwrap().is_empty());
    assert!(h.engine.search("   ***  ", &[], 10).unwrap().is_empty());
}
