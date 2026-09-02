//! Manual smoke test for the engine (`SYNCDESKTOP.md` §10, F2).
//!
//! It drives the four things the Tauri shell will drive in F3 — login, bootstrap, mutate,
//! sync — against a real backend, with no UI in the way.
//!
//! ```text
//! cargo run --example cli -- \
//!     --api https://crm.example.com/api/ \
//!     --db  ./smoke.db \
//!     --email me@example.com --password secret
//! ```
//!
//! Flags:
//!
//! * `--api <url>`        base API URL, must end in `/api/`
//! * `--db <path>`        SQLCipher database file (created if missing)
//! * `--email`/`--password` credentials for `POST /api/auth/device`
//! * `--no-bootstrap`     skip the first full download
//! * `--mutate <name>`    create one company with this name before syncing
//! * `--search <text>`    run a local full-text search at the end
//! * `--keychain`         use the real OS keychain instead of an in-process store
//! * `--logout`           log out at the end, which also revokes the token on the server
//!
//! Without `--keychain` the run is self-contained: the database key lives only in memory,
//! so the file is unreadable after the process exits. That is the right default for a
//! throwaway smoke test; pass `--keychain` when you want to exercise the real K9 path.

use std::collections::HashMap;
use std::sync::Arc;
use syncra_sync::{
    DeviceInfo, Entity, LocalMutation, MemoryKeyStore, NamedQuery, QueryParams, SyncConfig,
    SyncEngine, SystemKeyStore,
};
use uuid::Uuid;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let args = parse_args();

    let api = args
        .get("api")
        .cloned()
        .unwrap_or_else(|| "http://localhost:8000/api/".to_string());
    let db = args
        .get("db")
        .cloned()
        .unwrap_or_else(|| "./syncra-smoke.db".to_string());

    let cfg = SyncConfig::new(url::Url::parse(&api)?, &db);
    println!("opening {db} against {api}");

    let engine = if args.contains_key("keychain") {
        SyncEngine::open_with_keystore(cfg, Arc::new(SystemKeyStore)).await?
    } else {
        SyncEngine::open_with_keystore(cfg, Arc::new(MemoryKeyStore::new())).await?
    };

    // Watch the event stream in the background, the way the Tauri shell will.
    let mut events = engine.subscribe();
    tokio::spawn(async move {
        while let Ok(event) = events.recv().await {
            println!("  event: {event:?}");
        }
    });

    // 1. login
    let email = args.get("email").cloned().unwrap_or_default();
    let password = args.get("password").cloned().unwrap_or_default();
    if email.is_empty() || password.is_empty() {
        eprintln!("--email and --password are required");
        std::process::exit(2);
    }
    let session = engine
        .login(
            &email,
            &password,
            DeviceInfo {
                device_name: hostname(),
                device_fingerprint: fingerprint(&email),
                platform: platform().to_string(),
                app_version: env!("CARGO_PKG_VERSION").to_string(),
            },
        )
        .await?;
    println!(
        "logged in as user {} (abilities: {:?}, must_change_password: {})",
        session.user_id, session.abilities, session.must_change_password
    );

    // 2. bootstrap
    if !args.contains_key("no-bootstrap") {
        println!("bootstrapping...");
        engine
            .bootstrap(|progress| {
                println!(
                    "  {} / {} tables, {} rows",
                    progress.tables_done, progress.tables_total, progress.rows_loaded
                );
            })
            .await?;
    }

    // 3. mutate
    if let Some(name) = args.get("mutate") {
        let client_id = Uuid::now_v7();
        engine.mutate(LocalMutation::create(
            Entity::Company,
            client_id,
            serde_json::json!({ "name": name }),
        ))?;
        println!("queued a company create as {client_id}");
    }

    // 4. sync
    println!("syncing...");
    let report = engine.sync_now().await?;
    println!(
        "  pushed={} applied={} duplicates={} conflicts={} rejected={} deferred={}",
        report.pushed,
        report.applied,
        report.duplicates,
        report.conflicts,
        report.rejected,
        report.deferred
    );
    println!(
        "  pulled {} rows, {} deletions, tables: {:?}",
        report.pulled_rows, report.deletions, report.tables_changed
    );

    // Show what landed locally.
    let companies = engine.query(
        NamedQuery::companies(),
        QueryParams {
            limit: Some(10),
            ..Default::default()
        },
    )?;
    println!("first {} companies:", companies.len());
    for row in &companies {
        println!(
            "  {} [{}] {}",
            row.get_str("client_id").unwrap_or("-"),
            row.get_str("sync_state").unwrap_or("-"),
            row.get_str("name").unwrap_or("-")
        );
    }

    if let Some(text) = args.get("search") {
        let hits = engine.search(text, &[], 10)?;
        println!("search {text:?} -> {} hits", hits.len());
        for hit in hits {
            println!("  {} {} {}", hit.entity, hit.client_id, hit.title);
        }
    }

    let status = engine.status();
    println!(
        "status: online={} pending={} conflicts={} blocked={:?}",
        status.online, status.pending, status.conflicts, status.write_blocked
    );
    let stats = engine.storage_stats();
    println!(
        "storage: {} bytes ({}% of the ceiling), outbox {}/{}",
        stats.db_bytes, stats.db_usage_percent, stats.outbox_count, stats.max_outbox
    );

    for conflict in engine.conflicts()? {
        println!(
            "conflict {} {} {} fields={:?}",
            conflict.id, conflict.entity, conflict.code, conflict.conflicting_fields
        );
    }

    // 5. logout (opt-in). Forced, because a smoke run routinely leaves a queued mutation
    // behind and the point here is to exercise the server-side revocation.
    if args.contains_key("logout") {
        println!("logging out (token_id {})...", session.token_id);
        println!("  outcome: {:?}", engine.logout(true).await?);
    }

    Ok(())
}

/// Minimal `--key value` / `--flag` parser; the example deliberately has no CLI dependency.
fn parse_args() -> HashMap<String, String> {
    let mut out = HashMap::new();
    let mut args = std::env::args().skip(1).peekable();
    while let Some(arg) = args.next() {
        let Some(key) = arg.strip_prefix("--") else {
            continue;
        };
        let takes_value = !matches!(key, "no-bootstrap" | "keychain" | "logout");
        let value = if takes_value {
            args.next().unwrap_or_default()
        } else {
            String::new()
        };
        out.insert(key.to_string(), value);
    }
    out
}

fn hostname() -> String {
    std::env::var("COMPUTERNAME")
        .or_else(|_| std::env::var("HOSTNAME"))
        .unwrap_or_else(|_| "syncra-cli".to_string())
}

/// A stable-per-machine-and-account fingerprint, which is what the server keys the
/// one-token-per-device rule on (protocol §3.5 K-E).
///
/// **64 lowercase hex characters, not 32.** `DeviceTokenRequest` validates `size:64` +
/// `/^[0-9a-f]{64}$/`, so the single `Uuid::new_v5(...).simple()` this used to return — 32
/// characters — was rejected with a 422 exactly like the shell's own generator was (AUTH-1
/// U1). Two v5 UUIDs over different seeds give the required width while staying deterministic,
/// which is what a repeatable smoke test wants: the same run reuses the same device row on the
/// server instead of leaving a new one behind each time.
///
/// The shell does **not** do this — it stores 256 random bits in the OS keychain instead (see
/// `desktop/src-tauri/src/commands/auth.rs`). A derived value is right here, where there is no
/// keychain to persist anything and reproducibility is the whole point, and wrong there, where
/// it would put the customer's machine and account names into a server column.
fn fingerprint(email: &str) -> String {
    let seed = format!("{}:{}", hostname(), email);
    let a = Uuid::new_v5(&Uuid::NAMESPACE_DNS, seed.as_bytes());
    let b = Uuid::new_v5(&a, seed.as_bytes());
    format!("{}{}", a.simple(), b.simple())
}

fn platform() -> &'static str {
    if cfg!(target_os = "windows") {
        "windows"
    } else if cfg!(target_os = "macos") {
        "macos"
    } else {
        "linux"
    }
}
