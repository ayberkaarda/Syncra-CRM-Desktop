//! `storage::*` — retention settings and local storage accounting (`SYNCDESKTOP.md` §6.2).

use std::path::Path;

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
///
/// Second known gap, not fixed here: `db::wipe` only clears the `cached_files` *rows* — it has
/// no filesystem access and cannot touch the blobs those rows pointed at. This command closes
/// that gap for itself by clearing `state.cache_dir`'s contents right after (below), but the
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

    let conn = db::open(&state.db_path, &key).map_err(CommandError::from)?;
    db::wipe(&conn).map_err(CommandError::from)?;
    drop(conn);

    clear_cache_dir_contents(&state.cache_dir);

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
