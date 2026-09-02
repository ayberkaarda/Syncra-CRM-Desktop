//! # syncra-sync
//!
//! Offline-first sync engine for the Syncra CRM desktop client.
//!
//! The crate is deliberately UI-agnostic (`SYNCDESKTOP.md` K2): it owns the encrypted local
//! database, the outbox, the conflict store and the HTTP conversation with the Laravel
//! sync API, and it exposes them through [`SyncEngine`]. The Tauri layer is a thin adapter
//! over that type; nothing here knows about windows, commands or React.
//!
//! ## Shape of the thing
//!
//! ```text
//!   UI  ──mutate()──▶ mirror row (pending) + outbox row
//!                              │
//!   sync_now() ────────────────┼──▶ push  POST /api/sync/push
//!                              │        applied  → row synced, outbox entry dropped
//!                              │        conflict → Conflict Inbox, row 'conflict'
//!                              │        missing  → back to 'queued', attempts untouched
//!                              └──▶ pull  POST /api/sync/pull
//!                                       upsert, tombstones, cursor advance
//! ```
//!
//! ## The three contract rules that shape most of the code
//!
//! * **Three tables are not mirrored.** `taggables`, `quote_items` and
//!   `custom_field_values` live inside the owning row's `tags`, `items` and
//!   `custom_fields` columns (protocol §1.4, §1.5, §6.2 P13). There is no `quote_item`
//!   level in the push order and no tombstone surface for them.
//! * **A short push response is normal.** Any mutation whose `seq` is missing from
//!   `results` was never processed: it returns to `queued` with `attempts` untouched
//!   (protocol §4.3 P10b). See [`sync::push::apply_results`].
//! * **One scalar cursor per table.** Not `(sync_version, id)` — the server guarantees a
//!   unique version per row, which is what makes the keyset stable (protocol §2.5 K-C).
//!
//! ## Getting started
//!
//! ```no_run
//! use syncra_sync::{DeviceInfo, SyncConfig, SyncEngine};
//! # async fn run() -> Result<(), syncra_sync::SyncError> {
//! let cfg = SyncConfig::new(
//!     url::Url::parse("https://crm.example.com/api/").unwrap(),
//!     "C:/Users/me/AppData/Roaming/syncra/syncra.db",
//! );
//! let engine = SyncEngine::open(cfg).await?;
//!
//! engine.login("me@example.com", "secret", DeviceInfo {
//!     device_name: "AYBERK-PC".into(),
//!     device_fingerprint: "sha256-hex".into(),
//!     platform: "windows".into(),
//!     app_version: env!("CARGO_PKG_VERSION").into(),
//! }).await?;
//!
//! engine.bootstrap(|progress| println!("{progress:?}")).await?;
//! let report = engine.sync_now().await?;
//! println!("{} rows pulled", report.pulled_rows);
//! # Ok(())
//! # }
//! ```

#![forbid(unsafe_code)]
#![warn(missing_docs)]

pub mod conflicts;
pub mod config;
pub mod db;
pub mod error;
pub mod events;
pub mod keystore;
pub mod outbox;
pub mod protocol;
pub mod retention;
pub mod sync;
pub mod transport;
pub mod types;

pub use config::{DesktopSettings, ServerPolicy, SyncConfig, PROTOCOL_VERSION};
pub use db::query::{CountScope, NamedQuery, QueryParams, ReadFilter, SortDir, SortField};
pub use error::{ServerError, SyncError};
pub use events::EngineEvent;
pub use keystore::{KeyStore, KeyStoreHandle, MemoryKeyStore, SystemKeyStore};
pub use sync::SyncEngine;
pub use types::{
    BootstrapProgress, Conflict, DeviceInfo, Entity, LocalMutation, LogoutOutcome, Op,
    RealtimeEvent, Resolution, Row, SearchHit, Session, StorageStats, SyncReport, SyncState,
    SyncStatus, TableMode, WriteBlockReason,
};
