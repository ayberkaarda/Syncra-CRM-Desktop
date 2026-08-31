//! `sync::*` — manual sync control and the Conflict Inbox (`SYNCDESKTOP.md` §6.2).

use tauri::State;
use uuid::Uuid;

use syncra_sync::{Conflict, Resolution, SyncReport, SyncStatus};

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
