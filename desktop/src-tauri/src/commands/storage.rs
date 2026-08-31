//! `storage::*` — retention settings and local storage accounting (`SYNCDESKTOP.md` §6.2).

use tauri::State;

use syncra_sync::db;
use syncra_sync::keystore::{KeyStore, SystemKeyStore, KEY_DB};
use syncra_sync::{DesktopSettings, StorageStats};

use super::{CommandError, CommandResult};
use crate::state::AppState;

/// Current local storage accounting (`page_count * page_size`, outbox size, cache size).
///
/// Named `storage_stats`, not `stats`: `generate_handler!` registers a command under the
/// FUNCTION name, so the module path never reaches the wire and a bare `stats` would say
/// nothing about what it counts. `SYNCDESKTOP.md` §6.2 spells the contract name
/// `storage_stats` (ledger O5), and `npm run check:commands` compares the three sides.
#[tauri::command]
pub fn storage_stats(state: State<'_, AppState>) -> StorageStats {
    state.engine.storage_stats()
}

/// Change the user-tunable ceilings; values below the K8 minimums are clamped by the engine.
#[tauri::command]
pub fn update_settings(state: State<'_, AppState>, settings: DesktopSettings) -> CommandResult<()> {
    state
        .engine
        .update_settings(settings)
        .map_err(CommandError::from)
}

/// Read back the settings the engine actually has persisted (`SyncEngine::settings`) — the
/// symmetric read for `update_settings`'s write. Named `storage_settings`, not `settings`, for
/// the same reason `storage_stats` is not `stats`: `generate_handler!` registers by function
/// name, so the module prefix has to live in the name itself (`SYNCDESKTOP.md` §6.2 pattern,
/// ledger O5).
#[tauri::command]
pub fn storage_settings(state: State<'_, AppState>) -> DesktopSettings {
    state.engine.settings()
}

/// Wipe the local mirror and the file cache, **keeping the session**
/// (`docs/DESKTOP-ARCHITECTURE.md` §5.2: "Lokal DB + dosya cache silme").
///
/// This is deliberately not `auth::logout(force: true)`, which also drops the device token.
/// `SyncEngine` keeps its own `rusqlite::Connection` private (`SYNCDESKTOP.md` §5.2 — the
/// public API is frozen and has no `clear_local`/`wipe` method), so this opens a **second**,
/// short-lived connection to the same SQLCipher file with `syncra_sync::db::open` and calls
/// the crate's public `syncra_sync::db::wipe` on it — exactly the use `db::wipe`'s own doc
/// comment describes ("Used when a *different* user logs in ... and by `clear_local`").
/// SQLite's WAL mode (`db::open` sets `journal_mode = WAL`) is what makes a second connection
/// to the live file safe.
///
/// Known gap (see the phase report): the live engine's cached `SyncStatus` (`pending`,
/// `conflicts`) is not recomputed by this call, because `SyncEngine::refresh_status` is
/// private. It self-heals on the next `mutate`/`sync_now`.
#[tauri::command]
pub fn clear_local(state: State<'_, AppState>) -> CommandResult<()> {
    let key = SystemKeyStore
        .get(&state.keychain_service, KEY_DB)
        .map_err(CommandError::from)?
        .ok_or_else(|| {
            CommandError::new("VALIDATION_ERROR", "no local database key in the keychain")
        })?;

    let conn = db::open(&state.db_path, &key).map_err(CommandError::from)?;
    db::wipe(&conn).map_err(CommandError::from)?;
    drop(conn);

    if state.cache_dir.exists() {
        std::fs::remove_dir_all(&state.cache_dir).map_err(|e| {
            CommandError::new("VALIDATION_ERROR", format!("cannot clear cache directory: {e}"))
        })?;
    }
    std::fs::create_dir_all(&state.cache_dir).map_err(|e| {
        CommandError::new(
            "VALIDATION_ERROR",
            format!("cannot recreate cache directory: {e}"),
        )
    })?;

    Ok(())
}
