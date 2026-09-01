//! `storage::*` — retention settings and local storage accounting (`SYNCDESKTOP.md` §6.2).

use std::path::{Path, PathBuf};

use serde::Serialize;
use tauri::{AppHandle, Manager, Runtime, State};
use tauri_plugin_dialog::DialogExt;

use syncra_sync::db;
use syncra_sync::keystore::{KeyStore, SystemKeyStore, KEY_DB};
use syncra_sync::{DesktopSettings, StorageStats, SyncConfig, SyncEngine};

use super::{CommandError, CommandResult};
use crate::data_dir::{self, DataPaths};
use crate::state::AppState;

/// Current local storage accounting (`page_count * page_size`, outbox size, cache size).
///
/// Named `storage_stats`, not `stats`: `generate_handler!` registers a command under the
/// FUNCTION name, so the module path never reaches the wire and a bare `stats` would say
/// nothing about what it counts. `SYNCDESKTOP.md` §6.2 spells the contract name
/// `storage_stats` (ledger O5), and `npm run check:commands` compares the three sides.
#[tauri::command]
pub fn storage_stats(state: State<'_, AppState>) -> StorageStats {
    state.engine().storage_stats()
}

/// Change the user-tunable ceilings; values below the K8 minimums are clamped by the engine.
#[tauri::command]
pub fn update_settings(state: State<'_, AppState>, settings: DesktopSettings) -> CommandResult<()> {
    state
        .engine()
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
    state.engine().settings()
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
///
/// Second known gap, not fixed here: `db::wipe` only clears the `cached_files` *rows* — it has
/// no filesystem access and cannot touch the blobs those rows pointed at. This command closes
/// that gap for itself by clearing `state.cache_dir()`'s contents right after (below), but the
/// crate's *own* internal wipe on a different-user login (§5.5 — inside `SyncEngine::login`,
/// not reachable from this module) has the identical gap and nothing here calls it: that path
/// runs entirely inside the engine, with no hook for the shell to clear the cache dir alongside
/// it. Concretely, user A's cached quote PDFs and queued attachments can still be sitting under
/// `$APPDATA/syncra/cache` after user B logs in on the same machine. Fixing that needs either a
/// new engine event this shell can react to, or a crate-side cache-dir hook — an API change,
/// not a shell-side one, so it is out of scope this turn and is flagged here rather than
/// silently left unmentioned.
#[tauri::command]
pub fn clear_local(state: State<'_, AppState>) -> CommandResult<()> {
    let key = SystemKeyStore
        .get(&state.keychain_service, KEY_DB)
        .map_err(CommandError::from)?
        .ok_or_else(|| {
            CommandError::new("VALIDATION_ERROR", "no local database key in the keychain")
        })?;

    let conn = db::open(&state.db_path(), &key).map_err(CommandError::from)?;
    db::wipe(&conn).map_err(CommandError::from)?;
    drop(conn);

    clear_cache_dir_contents(&state.cache_dir());

    // Third half of the wipe, and the one that leaves the machine (defter O107). The jump list
    // holds up to five record TITLES in two plaintext stores neither `db::wipe` nor the cache
    // sweep can reach — `<app_data_dir>/syncra/recent.json` and the shell's own
    // `CustomDestinations` cache. "Clear local data" that leaves the last five record names on
    // the taskbar has not cleared the local data; see `crate::jump_list::clear`.
    crate::jump_list::clear(&state.root_dir());

    Ok(())
}

/// Delete everything **inside** `cache_dir`, without deleting `cache_dir` itself.
///
/// `db::wipe` (above) only clears the `cached_files` ledger rows; this is the other half —
/// removing the quote PDFs and queued attachments those rows (and the offline attachment queue)
/// actually point at, so a local wipe empties the disk, not just the database.
///
/// Best-effort by design, not `Result`-returning: the local database has *already* been wiped
/// by the time this runs, and there is no way back from that, so a single locked file (say, a
/// cached PDF the OS's default viewer still has open from `commands::files::open_cached`) must
/// not turn an otherwise-successful wipe into a reported failure that leaves the caller unsure
/// whether the (already irreversible) database wipe went through. Each entry is removed
/// independently; a failure is logged and the rest of the directory is still cleared.
fn clear_cache_dir_contents(cache_dir: &Path) {
    let entries = match std::fs::read_dir(cache_dir) {
        Ok(entries) => entries,
        Err(error) => {
            // `NotFound` means there was never anything to clear (no cache write has happened
            // yet) — not worth a log line. Anything else is unexpected and worth one.
            if error.kind() != std::io::ErrorKind::NotFound {
                tracing::warn!(
                    %error,
                    path = %cache_dir.display(),
                    "clear_local: cannot read cache directory"
                );
            }
            return;
        }
    };

    for entry in entries.flatten() {
        let path = entry.path();
        let result = match entry.file_type() {
            Ok(file_type) if file_type.is_dir() => std::fs::remove_dir_all(&path),
            _ => std::fs::remove_file(&path),
        };
        if let Err(error) = result {
            tracing::warn!(%error, path = %path.display(), "clear_local: cannot remove cached entry");
        }
    }
}


// ------------------------------------------------------------------------------------------------
// Data location (F8/1, KARAR K15)
// ------------------------------------------------------------------------------------------------

/// Wire shape of [`data_location`].
///
/// Paths travel as strings because that is all the webview can do with them: the Storage tab
/// displays the current one and hands it to nothing. Every path that comes BACK from the
/// webview is re-validated in Rust (`crate::data_dir::validate_target`), so nothing here is a
/// capability the UI gains by being told a path.
#[derive(Debug, Clone, Serialize)]
pub struct DataLocation {
    /// The data root actually in use right now.
    pub path: String,
    /// `<app_data_dir>/syncra` — where an install that has never moved keeps its data.
    pub default_path: String,
    /// Whether `path` is that default.
    pub is_default: bool,
    /// A configured root that was NOT reachable when the app started.
    ///
    /// `Some` means the app fell back to the default and is running on an empty (or stale)
    /// mirror while the real one sits on a volume that is not attached — including, possibly,
    /// an outbox of unpushed changes. The Storage tab shows this as a warning; the pointer
    /// file is deliberately left alone, so reattaching the volume and restarting restores the
    /// previous location. See `crate::data_dir::resolve_root`.
    pub unavailable_path: Option<String>,
}

/// Wire shape of [`move_data_dir`].
#[derive(Debug, Clone, Serialize)]
pub struct MoveOutcome {
    /// `false` when the user dismissed the folder picker. Nothing was touched, and this is
    /// **not** an error — a cancelled dialog is a normal outcome and must not raise a toast
    /// that reads like a failure.
    pub moved: bool,
    /// The data root in use after the call. Equal to the previous one when `moved` is `false`.
    pub path: String,
    /// The old data root, when one was actually vacated.
    pub previous_path: Option<String>,
    /// Set when everything succeeded EXCEPT deleting the old directory.
    ///
    /// The move itself is complete and the app is running from the new location; what is left
    /// behind is a second, still-SQLCipher-encrypted copy of the mirror. That is a threat-model
    /// fact (`docs/DESKTOP-THREAT-MODEL.md`: one encrypted mirror, in one known place), not an
    /// inconvenience, so it is reported rather than swallowed — the user is told exactly where
    /// the leftover is so they can delete it themselves.
    pub old_dir_remaining: Option<String>,
}

/// The data root in use, the default one, and whether a configured root went missing.
///
/// Cheap and synchronous: it reads `AppState`, never the disk.
#[tauri::command]
pub fn data_location(state: State<'_, AppState>) -> DataLocation {
    let root = state.root_dir();
    let default = data_dir::default_root(&state.anchor_dir);
    DataLocation {
        is_default: root == default,
        path: root.to_string_lossy().into_owned(),
        default_path: default.to_string_lossy().into_owned(),
        unavailable_path: state
            .unavailable_root
            .as_ref()
            .map(|path| path.to_string_lossy().into_owned()),
    }
}

/// Move the encrypted mirror and the blob cache to another directory (`SYNCDESKTOP.md` §10
/// F8 item 1, KARAR K15).
///
/// `target` is the folder to move INTO; the data root becomes `<target>/syncra`
/// (`crate::data_dir::root_for_target`). Passing `None` opens the OS folder picker and uses
/// what the user chooses; dismissing it returns `moved: false` rather than an error.
///
/// # Why the folder picker runs in Rust
///
/// `SYNCDESKTOP.md` §10 names `dialog:allow-open` — which is present in
/// `capabilities/default.json` — and that permission gates the dialog plugin's `plugin:dialog|open`
/// command **for the webview**. The webview cannot reach it here: `desktop/package.json` has
/// exactly one Tauri package (`@tauri-apps/api`), no `@tauri-apps/plugin-dialog`, and that file
/// is outside this change's scope. So the pick happens through the plugin's Rust API instead,
/// where capabilities do not apply — the same reasoning `capabilities/default.json` already
/// records for `clipboard-manager:allow-read-text` (read from Rust, so the webview never gains
/// the permission at all). The capability is left in place because the `target` parameter keeps
/// the webview-side path open for a later phase that adds the npm package; nothing is lost by
/// it and removing it would be an unrelated change.
///
/// # The procedure, and why the order is not negotiable
///
/// 1. **Validate the target first.** Everything `crate::data_dir::validate_target` checks is
///    checked before a single byte moves, so a refusal is always a no-op.
/// 2. **Read the SQLCipher key.** Before, not after, the teardown: if the keychain cannot be
///    reached there is no point in closing a database we would then be unable to verify.
/// 3. **Stop the background loop, then `SyncEngine::shutdown`.** `SyncScheduler::stop` is only
///    an `abort()` and is not synchronous — the aborted task's `SyncEngine` clone, and with it
///    the SQLite connection, can outlive the call. On Windows an open handle makes
///    `remove_dir_all` fail with OS error 32, so `shutdown` (which is deterministic:
///    `PRAGMA wal_checkpoint(TRUNCATE)` then `Connection::close`) is what actually frees the
///    directory. `syncra-sync`'s `shutdown_frees_the_data_directory_for_the_f8_migration` and
///    this crate's `without_shutdown_the_old_directory_cannot_be_deleted` are the two halves of
///    that proof.
/// 4. **Copy the whole directory.** Not a file list — see `crate::data_dir::copy_dir_all` for
///    why a list loses a `-wal` (and therefore committed rows) the first time a run ends badly.
/// 5. **Verify.** `crate::data_dir::verify_copy` opens the copy with the key and compares every
///    table's row count against the source. Comparing file sizes would pass a copy that lost
///    its log.
/// 6. **Record the choice, then delete the old directory.** In that order: a pointer written
///    after a successful delete would leave a window in which a crash loses the location of
///    data that is no longer where the default says it is.
/// 7. **Reopen.** A new `SyncEngine` against the new file, a new event bridge, a new background
///    loop.
///
/// # If a step fails
///
/// The old directory is never touched until step 6, so any failure before it leaves the user's
/// data exactly where it was — and this command then **reopens the engine at the old root**
/// before returning the error. That last part is the difference between a failed move and a
/// bricked app: after step 3 the engine's connection is closed for good, so returning an error
/// without reopening would leave every subsequent command failing until a restart.
///
/// The one failure that is reported rather than raised is step 6's delete: by then the data is
/// at the new location and the app works, so the honest outcome is success plus
/// `old_dir_remaining`, not a rollback of a move that already happened.
#[tauri::command]
pub async fn move_data_dir<R: Runtime>(
    app: AppHandle<R>,
    target: Option<String>,
) -> CommandResult<MoveOutcome> {
    let current = app.state::<AppState>().paths();

    // --- 1. Where to -------------------------------------------------------------------
    let picked = match target {
        Some(raw) => PathBuf::from(raw),
        None => match pick_folder(&app).await? {
            Some(path) => path,
            None => {
                return Ok(MoveOutcome {
                    moved: false,
                    path: current.root.to_string_lossy().into_owned(),
                    previous_path: None,
                    old_dir_remaining: None,
                })
            }
        },
    };
    let new_root = data_dir::validate_target(&current.root, &picked)
        .map_err(|rejection| CommandError::new(rejection.code(), rejection.message()))?;
    let new_paths = DataPaths::for_root(new_root);

    // --- 2. The key, while the database is still open ----------------------------------
    let key = {
        let state = app.state::<AppState>();
        SystemKeyStore
            .get(&state.keychain_service, KEY_DB)
            .map_err(CommandError::from)?
            .ok_or_else(|| {
                CommandError::new("VALIDATION_ERROR", "no local database key in the keychain")
            })?
    };

    // --- 3. Close everything that holds the old directory open --------------------------
    {
        let state = app.state::<AppState>();
        stop_background_loop(&state);
        // Held by value, then dropped: `shutdown` closes the connection, and what is left is
        // an `Arc` that must not outlive this scope any longer than it has to.
        let engine = state.engine();
        engine.shutdown().map_err(CommandError::from)?;
    }

    // --- 4-6. Copy, verify, record, delete ---------------------------------------------
    //
    // `spawn_blocking` because this is unbounded file IO — a full mirror plus its blob cache
    // can be hundreds of megabytes — and a runtime worker thread parked in `fs::copy` is a
    // worker that cannot serve the status polls the UI is making while the progress spinner
    // turns.
    let anchor = app.state::<AppState>().anchor_dir.clone();
    let source = current.root.clone();
    let destination = new_paths.root.clone();
    let transfer = tokio::task::spawn_blocking(move || transfer(&source, &destination, &anchor, &key))
        .await
        .map_err(|error| {
            CommandError::new(
                "DATA_DIR_MOVE_FAILED",
                format!("the move task did not finish: {error}"),
            )
        })?;

    // --- 7. Reopen, at the new root on success and at the OLD one on failure ------------
    match transfer {
        Ok(old_dir_remaining) => {
            reopen(&app, new_paths.clone()).await?;
            Ok(MoveOutcome {
                moved: true,
                path: new_paths.root.to_string_lossy().into_owned(),
                previous_path: Some(current.root.to_string_lossy().into_owned()),
                old_dir_remaining: old_dir_remaining
                    .map(|path| path.to_string_lossy().into_owned()),
            })
        }
        Err(failure) => {
            if let Err(restore) = reopen(&app, current.clone()).await {
                // Both the move and the recovery failed. Say so in one message rather than
                // reporting only the first: the user needs to know that a restart is required,
                // not just that the move did not happen.
                return Err(CommandError::new(
                    "DATA_DIR_MOVE_FAILED",
                    format!(
                        "{} — and the previous data directory could not be reopened either \
                         ({}); restart the application",
                        failure.message, restore.message
                    ),
                ));
            }
            Err(failure)
        }
    }
}

/// Steps 4-6, off the async runtime. Returns the old root when it survived its own deletion.
///
/// Split out of [`move_data_dir`] so the whole blocking section is one function with one
/// failure story: every early return here happens with the OLD directory still intact.
fn transfer(
    source: &Path,
    destination: &Path,
    anchor: &Path,
    key: &str,
) -> Result<Option<PathBuf>, CommandError> {
    if let Err(error) = data_dir::copy_dir_all(source, destination) {
        return Err(abandon_partial_copy(
            destination,
            format!("copying to {} failed: {error}", destination.display()),
        ));
    }

    if let Err(reason) = data_dir::verify_copy(source, destination, key) {
        return Err(abandon_partial_copy(
            destination,
            format!("the copy at {} did not verify: {reason}", destination.display()),
        ));
    }

    // Before the delete, not after: a pointer written afterwards leaves a window where a crash
    // loses the only record of where data that is no longer at the default now lives.
    data_dir::store_root(anchor, destination).map_err(|error| {
        CommandError::new(
            "DATA_DIR_MOVE_FAILED",
            format!("the new location could not be recorded: {error}"),
        )
    })?;

    match data_dir::remove_root(source) {
        Ok(()) => Ok(None),
        Err(error) => {
            // NOT `let _ =`. An encrypted second copy of the mirror surviving in a place the
            // user does not know about is a threat-model regression, so the path travels back
            // to the UI and is logged here as well.
            tracing::warn!(
                %error,
                path = %source.display(),
                "the data directory was moved, but the old one could not be deleted"
            );
            Ok(Some(source.to_path_buf()))
        }
    }
}

/// Remove a copy that failed partway, and fold whatever happened into one `{code, message}`.
///
/// A half-written copy is left behind by neither choice being good: keeping it means a second
/// partial mirror on disk, deleting it means the user cannot inspect what went wrong. Deleting
/// wins for the same reason the old directory is deleted on success — one encrypted mirror, in
/// one known place — and a delete that itself fails is named in the message rather than hidden.
fn abandon_partial_copy(destination: &Path, reason: String) -> CommandError {
    match data_dir::remove_root(destination) {
        Ok(()) => CommandError::new("DATA_DIR_MOVE_FAILED", reason),
        Err(error) => CommandError::new(
            "DATA_DIR_MOVE_FAILED",
            format!(
                "{reason}; the incomplete copy at {} could not be removed either ({error})",
                destination.display()
            ),
        ),
    }
}

/// Open a `SyncEngine` at `paths`, install it, and restart everything that hangs off it.
///
/// The order mirrors `.setup()`'s and for the same reason: the event bridge has to exist
/// before the background loop can emit anything, or the first `TablesChanged` after the move
/// is lost and the UI's query cache silently keeps the pre-move rows.
async fn reopen<R: Runtime>(app: &AppHandle<R>, paths: DataPaths) -> CommandResult<()> {
    let api_base = app.state::<AppState>().api_base.clone();
    let engine = SyncEngine::open(SyncConfig::new(api_base, paths.db.clone()))
        .await
        .map_err(CommandError::from)?;

    crate::events::forward_engine_events(app.clone(), &engine);

    let state = app.state::<AppState>();
    // Dropping the engine this replaces is what finally releases the last `Arc` on the old
    // one; its connection was already closed by `shutdown`, so nothing else is riding on it.
    drop(state.adopt(paths, engine.clone()));

    let scheduler = engine.start_background_sync();
    match state.scheduler.lock() {
        Ok(mut slot) => *slot = Some(scheduler),
        Err(error) => {
            tracing::warn!(%error, "scheduler mutex poisoned; the background loop was not re-parked")
        }
    }

    // The tray and the connectivity bar both render `SyncStatus`, and neither has any reason
    // to poll in the seconds after a move. Pushing the new engine's status closes that gap.
    crate::events::emit_status_changed(app, engine.status());
    Ok(())
}

/// Stop the background sync loop if it is running, leaving the slot empty.
///
/// An empty slot is also what "Pause sync" means (`crate::tray::toggle_pause`), which is
/// correct here too: between this call and [`reopen`] the loop genuinely is not running.
fn stop_background_loop(state: &AppState) {
    match state.scheduler.lock() {
        Ok(mut slot) => {
            if let Some(scheduler) = slot.take() {
                scheduler.stop();
            }
        }
        Err(error) => tracing::warn!(%error, "scheduler mutex poisoned; skipping the task half"),
    }
}

/// The OS folder picker, or `None` if the user dismissed it.
///
/// `spawn_blocking`: `blocking_pick_folder` parks the calling thread until the dialog closes,
/// which must not be a runtime worker (and must not be the main thread either — the dialog
/// needs the main thread to pump its own event loop, so blocking there would deadlock).
async fn pick_folder<R: Runtime>(app: &AppHandle<R>) -> CommandResult<Option<PathBuf>> {
    let handle = app.clone();
    let picked = tokio::task::spawn_blocking(move || handle.dialog().file().blocking_pick_folder())
        .await
        .map_err(|error| {
            CommandError::new("OS_ERROR", format!("the folder picker failed: {error}"))
        })?;

    match picked {
        None => Ok(None),
        Some(file) => file.into_path().map(Some).map_err(|error| {
            CommandError::new(
                "DATA_DIR_INVALID",
                format!("the chosen folder is not a filesystem path: {error}"),
            )
        }),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;
    use uuid::Uuid;

    /// A throwaway directory under the OS temp dir, removed on drop — same pattern
    /// `commands::files`'s test module uses.
    struct TempDir(PathBuf);

    impl TempDir {
        fn new() -> Self {
            let path = std::env::temp_dir().join(format!("syncra-storage-test-{}", Uuid::new_v4()));
            std::fs::create_dir_all(&path).expect("temp dir");
            TempDir(path)
        }
        fn path(&self) -> &Path {
            &self.0
        }
    }

    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    /// The cache directory survives; every quote PDF, sub-directory and loose file under it
    /// does not.
    #[test]
    fn clear_cache_dir_contents_empties_the_directory_but_keeps_it() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        std::fs::create_dir_all(cache.join("quotes")).expect("quotes subdir");
        std::fs::write(cache.join("quotes").join("42-3.pdf"), b"%PDF").expect("pdf");
        std::fs::create_dir_all(cache.join("attachments")).expect("attachments subdir");
        std::fs::write(cache.join("attachments").join("staged.bin"), b"x").expect("staged file");
        std::fs::write(cache.join("loose.tmp"), b"y").expect("loose file");

        clear_cache_dir_contents(&cache);

        assert!(cache.is_dir(), "the cache directory itself must survive");
        assert_eq!(
            std::fs::read_dir(&cache).expect("read_dir").count(),
            0,
            "every entry inside it must be gone"
        );
    }

    /// A cache directory that was never created (no cache write has happened yet) is a silent
    /// no-op: nothing to clear, and nothing is created either.
    #[test]
    fn clear_cache_dir_contents_on_a_missing_directory_is_a_no_op() {
        let temp = TempDir::new();
        let missing = temp.path().join("never-created");

        clear_cache_dir_contents(&missing);

        assert!(!missing.exists());
    }

    // --------------------------------------------------------------------------------------
    // The blocking half of `move_data_dir` (F8/1)
    // --------------------------------------------------------------------------------------

    use crate::data_dir::{self, CACHE_SUBDIR, DB_FILE, DEFAULT_SUBDIR};
    use syncra_sync::db;

    /// A source data root with a real, key-openable database and one cached blob in it.
    fn seeded_root(under: &Path, key: &str) -> PathBuf {
        let root = under.join(DEFAULT_SUBDIR);
        let conn = db::open(&root.join(DB_FILE), key).expect("open");
        db::put_setting(&conn, "f8-probe", "value").expect("write a row");
        conn.close().expect("close");
        std::fs::create_dir_all(root.join(CACHE_SUBDIR)).expect("cache");
        std::fs::write(root.join(CACHE_SUBDIR).join("42-3.pdf"), b"%PDF").expect("blob");
        root
    }

    /// The happy path, end to end: the copy lands, the pointer is written, the old root is
    /// gone, and nothing is reported as left behind.
    #[test]
    fn transfer_copies_records_the_choice_and_deletes_the_old_root() {
        let temp = TempDir::new();
        let anchor = temp.path().join("anchor");
        let key = "0".repeat(64);
        let source = seeded_root(&temp.path().join("old"), &key);
        let destination = temp.path().join("new").join(DEFAULT_SUBDIR);

        let remaining = transfer(&source, &destination, &anchor, &key).expect("transfer");

        assert_eq!(remaining, None, "nothing may be left behind on the happy path");
        assert!(!source.exists(), "the old data directory must be gone");
        assert!(destination.join(DB_FILE).is_file());
        assert!(destination.join(CACHE_SUBDIR).join("42-3.pdf").is_file());
        assert_eq!(data_dir::resolve_root(&anchor).0, destination);
    }

    /// A verification failure must leave the OLD directory untouched — that is the property
    /// the whole error story rests on — and must not leave the half-written copy behind
    /// either. The fixture makes verification fail by pre-creating a destination whose
    /// database is a file the key cannot open.
    #[test]
    fn a_failed_transfer_keeps_the_old_root_and_records_nothing() {
        let temp = TempDir::new();
        let anchor = temp.path().join("anchor");
        let key = "0".repeat(64);
        let source = seeded_root(&temp.path().join("old"), &key);
        let destination = temp.path().join("new").join(DEFAULT_SUBDIR);
        // `copy_dir_all` overwrites file by file, so a LOCKED destination is what makes the
        // copy fail; a read-only directory is the portable way to arrange that.
        std::fs::create_dir_all(&destination).expect("destination");
        std::fs::write(destination.join(DB_FILE), b"not a database").expect("decoy");
        let mut permissions = std::fs::metadata(&destination)
            .expect("metadata")
            .permissions();
        permissions.set_readonly(true);
        std::fs::set_permissions(destination.join(DB_FILE), permissions).expect("read-only");

        let failure = transfer(&source, &destination, &anchor, &key).expect_err("must fail");

        assert_eq!(failure.code, "DATA_DIR_MOVE_FAILED");
        assert!(source.join(DB_FILE).is_file(), "the old root must survive");
        assert!(
            !data_dir::pointer_path(&anchor).exists(),
            "a failed move must not record a new location"
        );
    }

    /// An undeletable old directory is a SUCCESS with a report, not a failure: the data is
    /// already at the new location and the app works from there. What must not happen is the
    /// leftover going unmentioned — a second encrypted mirror the user does not know about.
    #[test]
    fn an_undeletable_old_root_is_reported_rather_than_swallowed() {
        let temp = TempDir::new();
        let anchor = temp.path().join("anchor");
        let key = "0".repeat(64);
        let source = seeded_root(&temp.path().join("old"), &key);
        let destination = temp.path().join("new").join(DEFAULT_SUBDIR);

        // Make `remove_dir_all` fail while everything before it succeeds.
        //
        // On Windows that needs a handle opened WITHOUT `FILE_SHARE_DELETE`: Rust's plain
        // `File::open` sets read | write | delete sharing, so a file held that way deletes
        // happily — measured, and the reason this test originally passed vacuously. SQLite's
        // own handles are the restrictive kind, which is exactly why `SyncEngine::shutdown` is
        // load-bearing for the real procedure.
        //
        // On POSIX an open handle does not block `unlink` at all, so the equivalent is a
        // parent directory the process may not write to.
        #[cfg(windows)]
        let held = {
            use std::os::windows::fs::OpenOptionsExt;
            const FILE_SHARE_READ: u32 = 0x0000_0001;
            std::fs::OpenOptions::new()
                .read(true)
                .share_mode(FILE_SHARE_READ)
                // A cached blob, not the database: the verification step legitimately opens
                // the SOURCE database to census it, and a lock on that file would make the
                // move fail for the wrong reason. The blob is equally effective — the delete
                // is recursive and fails on any child it cannot remove.
                .open(source.join(CACHE_SUBDIR).join("42-3.pdf"))
                .expect("hold a cached blob open without delete sharing")
        };
        #[cfg(not(windows))]
        let held = {
            let mut permissions = std::fs::metadata(source.parent().expect("parent"))
                .expect("metadata")
                .permissions();
            permissions.set_readonly(true);
            std::fs::set_permissions(source.parent().expect("parent"), permissions)
                .expect("read-only parent");
        };

        let remaining = transfer(&source, &destination, &anchor, &key).expect("transfer");

        assert!(
            destination.join(DB_FILE).is_file(),
            "the move itself must have completed"
        );
        assert_eq!(
            data_dir::resolve_root(&anchor).0,
            destination,
            "the new location must be recorded even when the cleanup fails"
        );
        assert_eq!(
            remaining.as_deref(),
            Some(source.as_path()),
            "the surviving copy's path must reach the caller"
        );

        drop(held);
        #[cfg(not(windows))]
        {
            let mut permissions = std::fs::metadata(source.parent().expect("parent"))
                .expect("metadata")
                .permissions();
            #[allow(clippy::permissions_set_readonly_false)]
            permissions.set_readonly(false);
            let _ = std::fs::set_permissions(source.parent().expect("parent"), permissions);
        }
    }

    /// An already-empty cache directory stays exactly that.
    #[test]
    fn clear_cache_dir_contents_on_an_empty_directory_is_a_no_op() {
        let temp = TempDir::new();
        let cache = temp.path().join("cache");
        std::fs::create_dir_all(&cache).expect("cache dir");

        clear_cache_dir_contents(&cache);

        assert!(cache.is_dir());
        assert_eq!(std::fs::read_dir(&cache).expect("read_dir").count(), 0);
    }
}
