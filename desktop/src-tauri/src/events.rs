//! `EngineEvent` -> webview bridge.
//!
//! [`syncra_sync::SyncEngine::subscribe`] hands out a `tokio::sync::broadcast` receiver. The
//! webview cannot hold one, so this module owns the single subscriber and re-emits every
//! event on the Tauri event bus under one name, [`ENGINE_EVENT`], with the engine's own JSON
//! shape (`{"type": "tables_changed", "entities": [...]}`) as the payload.
//!
//! The consumer is `desktop/src/bridge/events.ts`, which turns `tables_changed` into
//! TanStack Query invalidations through a hand-written entity -> key table (KARAR D-5).
//!
//! One name rather than one-per-variant: the discriminator already travels inside the
//! payload, and a single `listen()` keeps ordering intact — two listeners would let a
//! `status_changed` overtake the `tables_changed` that caused it.
//!
//! ## `paused` is a shell fact, not an engine fact (defter O71)
//!
//! The sync engine has no concept of a user pausing it — `Inner::halted` is a protocol
//! version mismatch, not a pause, and `syncra_sync`'s public API is frozen (see
//! `crate::tray::toggle_pause`'s doc comment for the full reasoning). Pausing is entirely a
//! shell decision: `AppState::scheduler` is `Some` while the background loop runs and `None`
//! while it is stopped, and that slot is the only place the fact lives.
//!
//! [`StatusWithPause`] is how that fact reaches the webview without teaching the engine
//! anything: it wraps the engine's own [`SyncStatus`] with one extra field read from the
//! scheduler slot. It is used in two places that must agree — [`crate::commands::sync::status`]
//! (polled) and this module's [`forward_engine_events`] (pushed) — so both read it from here.

use std::sync::Mutex;

use serde::Serialize;
use serde_json::Value;
use tauri::{AppHandle, Emitter, Manager, Runtime};
use tokio::sync::broadcast::error::RecvError;

use syncra_sync::{EngineEvent, SyncEngine, SyncStatus};

use crate::state::AppState;

/// Tauri event name every [`syncra_sync::EngineEvent`] is forwarded under.
pub const ENGINE_EVENT: &str = "engine-event";

/// Tauri event name carrying one [`syncra_sync::BootstrapProgress`].
///
/// Separate from [`ENGINE_EVENT`] because it is not an engine event: `bootstrap` takes a
/// progress *callback*, which never enters the broadcast channel, and it is scoped to one
/// in-flight `sync::bootstrap` command rather than to the process. The ordering argument that
/// keeps every `EngineEvent` on one name does not apply — a progress tick has no causal
/// relationship with a `tables_changed`.
pub const BOOTSTRAP_PROGRESS: &str = "bootstrap-progress";

// ------------------------------------------------------------------------------------------------
// `paused` enrichment
// ------------------------------------------------------------------------------------------------

/// [`SyncStatus`] plus whether the background sync loop is paused.
///
/// `#[serde(flatten)]` keeps every existing `SyncStatus` field at the top level of the JSON
/// object, so the wire shape is "everything `SyncStatus` already had, plus `paused`" rather
/// than a new nested object — `commands::sync::status`'s existing callers, and the
/// `StatusChanged` shape `bridge/events.ts` already parses, both keep working unchanged.
/// `desktop/src/ui/commands.ts`'s `SyncStatus` type (the web-facing wire type, out of this
/// change's scope) is untouched; `paused` is additive and optional on that side.
#[derive(Debug, Clone, Serialize)]
pub struct StatusWithPause {
    #[serde(flatten)]
    pub status: SyncStatus,
    pub paused: bool,
}

/// Whether a scheduler slot represents a paused loop: an empty slot.
///
/// Generic over the scheduler type so it is unit-testable without constructing a real
/// `syncra_sync::sync::SyncScheduler` (which needs a running tokio task) — the logic is
/// entirely "is the `Option` empty", independent of what it holds. `AppState::scheduler` is
/// the only real caller (through [`is_paused`] and `commands::sync::status`), and
/// `tray::is_paused` reads the exact same field independently (that copy is private to
/// `tray.rs` and out of this change's file scope).
pub(crate) fn scheduler_is_paused<T>(scheduler: &Mutex<Option<T>>) -> bool {
    scheduler.lock().is_ok_and(|slot| slot.is_none())
}

/// [`scheduler_is_paused`] applied to the live app's [`AppState::scheduler`].
///
/// A missing `AppState` (not yet managed) reads as "not paused" rather than an error — the
/// same default [`crate::tray::is_paused`] uses, since there is nothing running to be paused.
pub(crate) fn is_paused<R: Runtime>(app: &AppHandle<R>) -> bool {
    app.try_state::<AppState>()
        .is_some_and(|state| scheduler_is_paused(&state.scheduler))
}

/// Serialize `event` the same way [`syncra_sync::EngineEvent`]'s own `Serialize` impl does,
/// then — only for `StatusChanged` — patch a `paused` field into the nested `status` object.
///
/// A pure function over JSON rather than a hand-built struct that re-spells `EngineEvent`'s
/// `#[serde(tag = "type")]` shape: the tag name (`"status_changed"`) and the nested field name
/// (`"status"`) both come straight out of `serde_json::to_value(event)`, so this cannot drift
/// from however `syncra-sync` actually serializes the enum. Every other variant passes
/// through byte-for-byte.
fn enrich(event: &EngineEvent, paused: bool) -> Result<Value, serde_json::Error> {
    let mut payload = serde_json::to_value(event)?;
    if payload.get("type").and_then(Value::as_str) == Some("status_changed") {
        if let Some(status) = payload.get_mut("status").and_then(Value::as_object_mut) {
            status.insert("paused".to_string(), Value::Bool(paused));
        }
    }
    Ok(payload)
}

/// Emit one already-known [`SyncStatus`] to the webview as a `paused`-enriched `StatusChanged`.
///
/// For the one case nothing else covers: pausing or resuming the background loop from the
/// tray moves `AppState::scheduler` without the engine emitting anything — the engine does not
/// know pause exists, so it has no event to fire. Without this call the `ConnectivityBar`
/// would only learn about the toggle on the next `TablesChanged`/`StatusChanged` the engine
/// happens to emit on its own, which could be up to 60 seconds away (or never, while offline).
/// See `tray::toggle_pause`'s call site.
pub fn emit_status_changed<R: Runtime>(app: &AppHandle<R>, status: SyncStatus) {
    let event = EngineEvent::StatusChanged { status };
    match enrich(&event, is_paused(app)) {
        Ok(payload) => {
            if let Err(error) = app.emit(ENGINE_EVENT, &payload) {
                tracing::warn!(%error, "could not emit status change to the webview");
            }
        }
        Err(error) => tracing::warn!(%error, "could not serialize status change"),
    }
}

// ------------------------------------------------------------------------------------------------
// Forwarding
// ------------------------------------------------------------------------------------------------

/// Start forwarding engine events to the webview. Called once from `.setup()`.
///
/// The task lives for the process. A `RecvError::Lagged` is logged and ignored rather than
/// treated as fatal: the broadcast channel drops the oldest events when the webview is slow,
/// and the correct recovery is the next `tables_changed`, not tearing down the bridge.
pub fn forward_engine_events<R: Runtime>(app: AppHandle<R>, engine: &SyncEngine) {
    let mut events = engine.subscribe();

    tauri::async_runtime::spawn(async move {
        loop {
            match events.recv().await {
                Ok(event) => match enrich(&event, is_paused(&app)) {
                    Ok(payload) => {
                        if let Err(error) = app.emit(ENGINE_EVENT, &payload) {
                            tracing::warn!(%error, "could not emit engine event to the webview");
                        }
                    }
                    Err(error) => tracing::warn!(%error, "could not serialize engine event"),
                },
                Err(RecvError::Lagged(skipped)) => {
                    tracing::warn!(skipped, "engine event bridge lagged");
                }
                // The engine dropped its sender, i.e. the process is going away.
                Err(RecvError::Closed) => break,
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    // --- scheduler_is_paused --------------------------------------------------------------

    #[test]
    fn a_full_slot_is_running() {
        let scheduler: Mutex<Option<i32>> = Mutex::new(Some(1));
        assert!(!scheduler_is_paused(&scheduler));
    }

    #[test]
    fn an_empty_slot_is_paused() {
        let scheduler: Mutex<Option<i32>> = Mutex::new(None);
        assert!(scheduler_is_paused(&scheduler));
    }

    // --- StatusWithPause --------------------------------------------------------------------

    /// `#[serde(flatten)]` really does put `paused` next to `online`/`syncing`/etc. rather
    /// than nesting it — this is the shape both `commands::sync::status` and `enrich` below
    /// depend on.
    #[test]
    fn status_with_pause_flattens_into_one_object() {
        let value = serde_json::to_value(StatusWithPause {
            status: SyncStatus::default(),
            paused: true,
        })
        .expect("serialize");

        assert_eq!(value["paused"], true);
        assert_eq!(value["online"], false);
        assert!(value.get("status").is_none(), "SyncStatus fields must be flattened, not nested");
    }

    // --- enrich ---------------------------------------------------------------------------

    #[test]
    fn status_changed_gains_a_paused_field() {
        let event = EngineEvent::StatusChanged { status: SyncStatus::default() };

        let enriched = enrich(&event, true).expect("serialize");

        assert_eq!(enriched["type"], "status_changed");
        assert_eq!(enriched["status"]["paused"], true);
        // The original SyncStatus fields are still there, untouched.
        assert_eq!(enriched["status"]["online"], false);
    }

    #[test]
    fn a_resumed_status_carries_paused_false() {
        let event = EngineEvent::StatusChanged { status: SyncStatus::default() };

        let enriched = enrich(&event, false).expect("serialize");

        assert_eq!(enriched["status"]["paused"], false);
    }

    #[test]
    fn other_variants_pass_through_unchanged() {
        let event = EngineEvent::AuthLost;

        let plain = serde_json::to_value(&event).expect("serialize");
        let enriched = enrich(&event, true).expect("serialize");

        assert_eq!(enriched, plain, "a non-StatusChanged event must not gain a paused field");
    }
}
