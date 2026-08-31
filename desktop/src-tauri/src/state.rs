//! Tauri-managed state: the sync engine handle plus the bits of configuration the command
//! layer needs but the frozen [`syncra_sync::SyncEngine`] API does not hand back out
//! (`docs/DESKTOP-SYNC-PROTOCOL.md` §5, `SYNCDESKTOP.md` §5.2 — not touched from this crate).

use std::path::PathBuf;
use std::sync::Mutex;

use syncra_sync::{sync::SyncScheduler, SyncConfig, SyncEngine, SyncError};
use tauri::{AppHandle, Manager, Runtime};
use url::Url;

/// Everything a command handler reaches for through `tauri::State<'_, AppState>`.
///
/// [`SyncEngine`] is cheap to clone (`Arc<Inner>` inside, per its own doc comment); command
/// handlers clone it out of the `State` guard before an `.await` rather than holding the
/// guard across one.
pub struct AppState {
    /// The sync engine. Owns the encrypted local database, the outbox, the conflict store
    /// and the HTTP conversation with the Laravel sync API.
    pub engine: SyncEngine,
    /// Base URL of the Laravel API (`.../api/`). Mirrors `SyncConfig::api_base`; kept here
    /// too because the engine does not expose its config back out, and
    /// [`crate::commands::auth::list_devices`]/[`crate::commands::auth::revoke_device`] need
    /// it directly (they are not `SyncEngine` methods — `docs/DESKTOP-ARCHITECTURE.md` §5.2).
    pub api_base: Url,
    /// OS keychain service name (`SyncConfig::keychain_service`). Needed directly by the same
    /// two `auth::*` commands and by [`crate::commands::storage::clear_local`].
    pub keychain_service: String,
    /// SQLCipher database path. Needed directly by
    /// [`crate::commands::storage::clear_local`], which opens a second, short-lived
    /// connection to it (see that command's doc comment for why).
    pub db_path: PathBuf,
    /// `$APPDATA/syncra/cache` — quote PDF cache and the offline attachment queue.
    /// Written by `commands::files` since F5-5; `commands::files::open_cached` will only
    /// open a file that canonicalises to somewhere under this directory.
    pub cache_dir: PathBuf,
    /// Shared HTTP client for the account-management calls the engine does not own
    /// (`auth::list_devices`, `auth::revoke_device`).
    pub http: reqwest::Client,
    /// The background sync loop's handle — and, by being empty or full, the pause flag
    /// (O46 B2, F5-1).
    ///
    /// `SyncScheduler` wraps the loop's `JoinHandle`, and dropping a `JoinHandle` detaches the
    /// task rather than cancelling it, so the loop would survive being thrown away — but only
    /// as something nothing can stop or account for. Parking it here ties its lifetime to the
    /// app's explicitly.
    ///
    /// **Why a `Mutex<Option<_>>` rather than a plain `Option`:** `SyncScheduler::stop`
    /// consumes `self`, and everything that needs to call it reaches the state through
    /// `tauri::State<'_, AppState>` / `AppHandle::state`, which hand out a shared reference.
    /// Two callers need exactly that: `tray::toggle_pause` ("Pause sync" is stopping the loop,
    /// resuming is starting a new one) and the `RunEvent::Exit` teardown in `crate::run`.
    /// `Some` therefore means "the loop is running", `None` means "paused, or torn down" —
    /// one source of truth instead of a boolean that can disagree with the handle.
    ///
    /// The lock is only ever held across synchronous work (`abort`, `tokio::spawn`), never
    /// across an `.await`, so a `std::sync::Mutex` is the right one.
    pub scheduler: Mutex<Option<SyncScheduler>>,
}

impl AppState {
    /// Resolve OS paths, open (or create) the local database, and hand back everything the
    /// command layer needs. Called once from `.setup()`.
    pub async fn init<R: Runtime>(app: &AppHandle<R>) -> Result<Self, SyncError> {
        // `$APPDATA/syncra` — chosen to line up literally with the `fs:scope` entries in
        // `capabilities/default.json` (`"$APPDATA/syncra/**"`), which are copied verbatim
        // from `docs/DESKTOP-ARCHITECTURE.md` §5.3. Tauri's own `$APPDATA` path variable
        // already resolves to the identifier-scoped app data dir
        // (`{roaming}/com.syncra.desktop`), so nesting one more `syncra/` here keeps the
        // capability scope meaningful regardless of the bundle identifier.
        let root = app
            .path()
            .app_data_dir()
            .map_err(|e| SyncError::Validation(format!("app data dir: {e}")))?
            .join("syncra");

        let db_path = root.join("syncra.db");
        let cache_dir = root.join("cache");
        let api_base = api_base_from_env();

        let cfg = SyncConfig::new(api_base.clone(), db_path.clone());
        let keychain_service = cfg.keychain_service.clone();

        // `SyncEngine::open` creates the directory, generates/reads the SQLCipher key from
        // the OS keychain, and migrates the database (`SYNCDESKTOP.md` K9).
        let engine = SyncEngine::open(cfg).await?;

        Ok(AppState {
            engine,
            api_base,
            keychain_service,
            db_path,
            cache_dir,
            http: reqwest::Client::new(),
            // Started in `.setup()` rather than here: the engine event bridge has to exist
            // before the loop can emit anything, or the first `TablesChanged` is lost.
            scheduler: Mutex::new(None),
        })
    }
}

/// `SYNCDESKTOP.md` §11 D-3: the API host is a build-time constant, consistent with the CSP
/// (`docs/DESKTOP-ARCHITECTURE.md` §5.5) and the updater manifest. No Rust-side env var name
/// is fixed by the spec yet (D-3 only names the frontend's `VITE_API_URL`), so this mirrors
/// the same `.../api/` default `frontend/src/lib/axios.ts` and `frontend/.env.example` use
/// for the web build, and stays overridable at compile time via `SYNCRA_API_URL` without
/// touching this file — see the phase report for why this is flagged as an open point rather
/// than a settled contract.
fn api_base_from_env() -> Url {
    let raw = option_env!("SYNCRA_API_URL").unwrap_or("http://localhost:8000/api/");
    Url::parse(raw).expect("SYNCRA_API_URL (or the built-in default) must be a valid URL")
}
