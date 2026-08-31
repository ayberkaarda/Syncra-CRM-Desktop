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

use tauri::{AppHandle, Emitter, Runtime};
use tokio::sync::broadcast::error::RecvError;

use syncra_sync::SyncEngine;

/// Tauri event name every [`syncra_sync::EngineEvent`] is forwarded under.
pub const ENGINE_EVENT: &str = "engine-event";

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
                Ok(event) => {
                    if let Err(error) = app.emit(ENGINE_EVENT, &event) {
                        tracing::warn!(%error, "could not emit engine event to the webview");
                    }
                }
                Err(RecvError::Lagged(skipped)) => {
                    tracing::warn!(skipped, "engine event bridge lagged");
                }
                // The engine dropped its sender, i.e. the process is going away.
                Err(RecvError::Closed) => break,
            }
        }
    });
}
