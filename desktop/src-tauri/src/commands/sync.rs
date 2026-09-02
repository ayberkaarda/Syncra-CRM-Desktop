//! `sync::*` — manual sync control and the Conflict Inbox (`SYNCDESKTOP.md` §6.2).

use tauri::{AppHandle, Emitter, Runtime, State};
use uuid::Uuid;

use syncra_sync::{Conflict, RealtimeEvent, Resolution, SyncReport};

use super::{CommandError, CommandResult};
use crate::events::{scheduler_is_paused, StatusWithPause, BOOTSTRAP_PROGRESS};
use crate::state::AppState;

/// First full download (`SYNCDESKTOP.md` §5.5, K12): every granted table from cursor 0, inside
/// the retention window, with progress reported as it goes.
///
/// `SyncEngine::bootstrap` is the only path that emits `BootstrapProgress`, and it was missing
/// from `generate_handler!`, so the webview could not reach it at all. The bootstrap screen had
/// to imitate it with `download_archive(0)` plus a 400 ms row-count poll — which is both a
/// different call and a worse progress signal (AUTH-1 U15).
///
/// Progress ticks go out as [`BOOTSTRAP_PROGRESS`] Tauri events. They are advisory: a dropped
/// tick costs a smoother bar, never correctness, since the command's own resolution is what
/// says the download finished.
#[tauri::command]
pub async fn bootstrap<R: Runtime>(
    app: AppHandle<R>,
    state: State<'_, AppState>,
) -> CommandResult<()> {
    let engine = state.engine();
    engine
        .bootstrap(move |progress| {
            if let Err(error) = app.emit(BOOTSTRAP_PROGRESS, &progress) {
                tracing::warn!(%error, "could not emit bootstrap progress to the webview");
            }
        })
        .await
        .map_err(CommandError::from)
}

/// One manual push-then-pull round. `SyncEngine::start_background_sync` (`SYNCDESKTOP.md` §5.5)
/// already runs on its own timer, started from `lib.rs`'s `.setup()` at launch, and probes for
/// connectivity on the offline branch — this command is still the separate, immediately-awaited
/// single round the UI triggers by hand (a manual "sync now" click, or right after resolving a
/// conflict), not a way to start or stop that loop.
#[tauri::command]
pub async fn sync_now(state: State<'_, AppState>) -> CommandResult<SyncReport> {
    let engine = state.engine();
    engine.sync_now().await.map_err(CommandError::from)
}

/// Cheap, synchronous snapshot of engine state — safe to poll.
///
/// Enriched with `paused`, which the engine itself has no concept of (defter O71): the loop
/// is stopped by clearing `AppState::scheduler`, not by anything inside `syncra_sync`, so
/// `paused` is read from that slot and layered onto the engine's own [`SyncStatus`] by
/// [`StatusWithPause`] — see that type's doc comment for why this lives in `crate::events`
/// rather than being duplicated here.
#[tauri::command]
pub fn status(state: State<'_, AppState>) -> StatusWithPause {
    StatusWithPause {
        paused: scheduler_is_paused(&state.scheduler),
        status: state.engine().status(),
    }
}

/// Everything waiting in the Conflict Inbox.
#[tauri::command]
pub fn conflicts(state: State<'_, AppState>) -> CommandResult<Vec<Conflict>> {
    state.engine().conflicts().map_err(CommandError::from)
}

/// Settle one conflict (`Resolution::{KeepMine, TakeServer, Merge}`).
#[tauri::command]
pub fn resolve_conflict(state: State<'_, AppState>, id: Uuid, choice: Resolution) -> CommandResult<()> {
    state
        .engine()
        .resolve_conflict(id, choice)
        .map_err(CommandError::from)
}

/// Widen the retention window and re-download the extra history (K12).
#[tauri::command]
pub async fn download_archive(state: State<'_, AppState>, extra_days: u32) -> CommandResult<()> {
    let engine = state.engine();
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
    let engine = state.engine();
    engine.handle_realtime(event).await;
    Ok(())
}
