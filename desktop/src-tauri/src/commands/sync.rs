//! `sync::*` — manual sync control and the Conflict Inbox (`SYNCDESKTOP.md` §6.2).

use tauri::State;
use uuid::Uuid;

use syncra_sync::{Conflict, RealtimeEvent, Resolution, SyncReport, SyncStatus};

use super::{CommandError, CommandResult};
use crate::state::AppState;

/// One manual push-then-pull round. The background loop (`SyncEngine::start_background_sync`,
/// `SYNCDESKTOP.md` §5.5) is not started by this shell yet — F5 wires the OS-integration
/// triggers (tray, network events); this turn only exposes the manual path.
#[tauri::command]
pub async fn sync_now(state: State<'_, AppState>) -> CommandResult<SyncReport> {
    let engine = state.engine.clone();
    engine.sync_now().await.map_err(CommandError::from)
}

/// Cheap, synchronous snapshot of engine state — safe to poll.
#[tauri::command]
pub fn status(state: State<'_, AppState>) -> SyncStatus {
    state.engine.status()
}

/// Everything waiting in the Conflict Inbox.
#[tauri::command]
pub fn conflicts(state: State<'_, AppState>) -> CommandResult<Vec<Conflict>> {
    state.engine.conflicts().map_err(CommandError::from)
}

/// Settle one conflict (`Resolution::{KeepMine, TakeServer, Merge}`).
#[tauri::command]
pub fn resolve_conflict(state: State<'_, AppState>, id: Uuid, choice: Resolution) -> CommandResult<()> {
    state
        .engine
        .resolve_conflict(id, choice)
        .map_err(CommandError::from)
}

/// Widen the retention window and re-download the extra history (K12).
#[tauri::command]
pub async fn download_archive(state: State<'_, AppState>, extra_days: u32) -> CommandResult<()> {
    let engine = state.engine.clone();
    engine
        .download_archive(extra_days)
        .await
        .map_err(CommandError::from)
}

/// Route one Reverb frame into the engine (`SYNCDESKTOP.md` §5.2, KARAR A11).
///
/// The webview never refreshes its own cache from a socket frame. It translates the frame into
/// a [`RealtimeEvent`] (`desktop/src/bridge/realtime.ts` owns that hand-written table) and hands
/// it here; the engine pulls just those tables and emits `TablesChanged`, which
/// `desktop/src/bridge/events.ts` turns into query invalidations. The local mirror therefore
/// stays the single source the UI reads — a direct invalidation would send the UI to the server
/// for a row the engine has never seen, which is the offline-first contract broken in one line.
///
/// Infallible by design: [`SyncEngine::handle_realtime`](syncra_sync::SyncEngine::handle_realtime)
/// returns `()`. A hint that arrives while offline, names no granted table, or races a failing
/// pull is dropped inside the engine — it is a *hint*, and the 60 second loop plus the next
/// `sync_now` are the guarantees. `CommandResult<()>` is still the return type because an async
/// command taking `State<'_, _>` must return a `Result` for Tauri to generate the handler.
#[tauri::command]
pub async fn handle_realtime(state: State<'_, AppState>, event: RealtimeEvent) -> CommandResult<()> {
    let engine = state.engine.clone();
    engine.handle_realtime(event).await;
    Ok(())
}
