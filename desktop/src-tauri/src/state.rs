//! Tauri-managed state: the sync engine handle plus the bits of configuration the command
//! layer needs but the frozen [`syncra_sync::SyncEngine`] API does not hand back out
//! (`docs/DESKTOP-SYNC-PROTOCOL.md` §5, `SYNCDESKTOP.md` §5.2 — not touched from this crate).

use std::path::PathBuf;
use std::sync::{Mutex, RwLock};

use syncra_sync::{sync::SyncScheduler, SyncConfig, SyncEngine, SyncError};
use tauri::{AppHandle, Manager, Runtime};
use url::Url;

use crate::data_dir::{self, DataPaths};

/// Everything a command handler reaches for through `tauri::State<'_, AppState>`.
///
/// [`SyncEngine`] is cheap to clone (`Arc<Inner>` inside, per its own doc comment); command
/// handlers clone it out of the `State` guard before an `.await` rather than holding the
/// guard across one.
///
/// # Why the engine and the paths sit behind locks (F8/1, K15)
///
/// They used to be plain fields, and could be while the data directory was a constant derived
/// from the bundle identifier. `storage::move_data_dir` makes that directory a user choice,
/// and a moved directory means a NEW `SyncEngine` opened against a new file — the old one has
/// had its connection closed by `SyncEngine::shutdown` and can never serve another query.
///
/// A plain field could not be replaced: `tauri::State` hands out a shared reference and the
/// state is `Sync`, so the swap needs interior mutability. [`RwLock`] rather than [`Mutex`]
/// because every reader ([`AppState::engine`], [`AppState::paths`]) is a clone-and-release,
/// the writer runs once per move, and readers must not serialise behind each other on the hot
/// `data::query` path.
///
/// The lock is never held across an `.await`: every accessor below clones out of the guard and
/// drops it before returning, which is what keeps `std::sync::RwLock` (rather than tokio's)
/// the right choice — and what keeps the async commands `Send`.
pub struct AppState {
    /// The sync engine. Owns the encrypted local database, the outbox, the conflict store
    /// and the HTTP conversation with the Laravel sync API.
    ///
    /// Read through [`AppState::engine`]; replaced only by [`AppState::adopt`].
    engine: RwLock<SyncEngine>,
    /// Base URL of the Laravel API (`.../api/`). Mirrors `SyncConfig::api_base`; kept here
    /// too because the engine does not expose its config back out, and
    /// [`crate::commands::auth::list_devices`]/[`crate::commands::auth::revoke_device`] need
    /// it directly (they are not `SyncEngine` methods — `docs/DESKTOP-ARCHITECTURE.md` §5.2).
    pub api_base: Url,
    /// OS keychain service name (`SyncConfig::keychain_service`). Needed directly by the same
    /// two `auth::*` commands, by [`crate::commands::storage::clear_local`] and by
    /// [`crate::commands::storage::move_data_dir`], which has to decrypt the copied database
    /// in order to verify it.
    ///
    /// Not behind a lock, and that is the K15 decision made visible: the SQLCipher key is
    /// derived from this SERVICE name and never from the database path
    /// (`syncra_sync::keystore::ensure_db_key`), so moving the file does not change it. If it
    /// were path-derived, a move would be a re-encryption rather than a copy.
    pub keychain_service: String,
    /// Where the mirror, the blob cache and the jump-list store currently live
    /// (`crate::data_dir`). Read through [`AppState::paths`]; replaced by [`AppState::adopt`].
    paths: RwLock<DataPaths>,
    /// `app_data_dir()` — the OS-derived directory that can never move, and therefore the one
    /// place the choice of data root can be recorded (`crate::data_dir`, module docs).
    pub anchor_dir: PathBuf,
    /// The data root the pointer file names but that was not reachable at startup, if any.
    ///
    /// Set once, at `init`, and read by `storage::data_location` so the Storage tab can say
    /// "your data is on a volume that is not attached" instead of silently presenting an empty
    /// mirror as normal. `None` on every ordinary launch.
    pub unavailable_root: Option<PathBuf>,
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
    /// Three callers need exactly that: `tray::toggle_pause` ("Pause sync" is stopping the
    /// loop, resuming is starting a new one), `storage::move_data_dir` (the loop has to be off
    /// while the files move) and the `RunEvent::Exit` teardown in `crate::run`.
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
        // `app_data_dir()` is `{roaming}/com.syncra.desktop` on Windows: the identifier-scoped
        // per-user directory the OS derives from `tauri.conf.json`'s `identifier` (which
        // `npm run check:identifier` pins). This is the ANCHOR — see `crate::data_dir`.
        let anchor = app
            .path()
            .app_data_dir()
            .map_err(|e| SyncError::Validation(format!("app data dir: {e}")))?;

        // The root is `<anchor>/syncra` unless the user moved it (F8/1, K15). That default is
        // unchanged and deliberately so: it lines up literally with the `fs:scope` entries in
        // `capabilities/default.json` (`"$APPDATA/syncra/**"`), copied verbatim from
        // `docs/DESKTOP-ARCHITECTURE.md` §5.3, and every existing install already has its
        // database there.
        let (root, unavailable_root) = data_dir::resolve_root(&anchor);
        let paths = DataPaths::for_root(root);
        let api_base = api_base_from_env();

        let cfg = SyncConfig::new(api_base.clone(), paths.db.clone());
        let keychain_service = cfg.keychain_service.clone();

        // `SyncEngine::open` creates the directory, generates/reads the SQLCipher key from
        // the OS keychain, and migrates the database (`SYNCDESKTOP.md` K9).
        let engine = SyncEngine::open(cfg).await?;

        Ok(AppState {
            engine: RwLock::new(engine),
            api_base,
            keychain_service,
            paths: RwLock::new(paths),
            anchor_dir: anchor,
            unavailable_root,
            http: reqwest::Client::new(),
            // Started in `.setup()` rather than here: the engine event bridge has to exist
            // before the loop can emit anything, or the first `TablesChanged` is lost.
            scheduler: Mutex::new(None),
        })
    }

    /// The engine currently serving this process.
    ///
    /// Returns a clone rather than a guard on purpose: a guard would have to be held across
    /// the `.await` in every async command, which both deadlocks against [`AppState::adopt`]
    /// and makes those futures non-`Send`. Cloning a `SyncEngine` is an `Arc` bump.
    ///
    /// # Panics
    ///
    /// Only on a poisoned lock, which no code path here can produce: the lock is held across a
    /// clone or a single assignment and nothing else, so there is nothing that can panic while
    /// holding it. `expect` rather than a silent fallback, because a poisoned engine lock would
    /// mean the process is in a state from which no command can be answered correctly.
    pub fn engine(&self) -> SyncEngine {
        self.engine
            .read()
            .expect("the engine lock is only ever held across a clone")
            .clone()
    }

    /// Where the mirror, the cache and the jump-list store live right now.
    pub fn paths(&self) -> DataPaths {
        self.paths
            .read()
            .expect("the paths lock is only ever held across a clone")
            .clone()
    }

    /// `<root>/syncra.db` — the SQLCipher database.
    pub fn db_path(&self) -> PathBuf {
        self.paths().db
    }

    /// The data root: the parent of everything below, and where `crate::jump_list` keeps its
    /// plaintext `recent.json`.
    pub fn root_dir(&self) -> PathBuf {
        self.paths().root
    }

    /// `<root>/cache` — quote PDF cache and the offline attachment queue. Written by
    /// `commands::files` since F5-5; `commands::files::open_cached` will only open a file that
    /// canonicalises to somewhere under this directory.
    pub fn cache_dir(&self) -> PathBuf {
        self.paths().cache
    }

    /// Install a freshly opened engine and its new paths, after a completed move.
    ///
    /// Both slots are written under their own locks and in this order — paths first, engine
    /// second — so no reader can ever observe the NEW engine alongside the OLD paths. The
    /// reverse order has a real failure behind it: `commands::files::cache_quote_pdf` reads
    /// `cache_dir()` and then writes through `engine()`, and a caller that interleaved there
    /// would record a blob in the new database under the old directory's path.
    ///
    /// Returns the engine that was replaced, so the caller can drop it deliberately. Its
    /// connection is already closed by then; what matters is that the last `Arc` goes away,
    /// which is what lets the old directory be deleted on Windows.
    pub fn adopt(&self, paths: DataPaths, engine: SyncEngine) -> SyncEngine {
        *self
            .paths
            .write()
            .expect("the paths lock is only ever held across a clone") = paths;
        std::mem::replace(
            &mut *self
                .engine
                .write()
                .expect("the engine lock is only ever held across a clone"),
            engine,
        )
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
