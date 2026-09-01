//! Windows JumpList — the "last 5 records" list (`SYNCDESKTOP.md` §6.4, §10 F7, defter O85).
//!
//! §6.4: *"JumpList: son 5 kayıt"*. Right-clicking the taskbar or Start-menu entry shows one
//! custom category holding the five records the user opened most recently; clicking one starts
//! this app with `syncra://<entity>/<id>` as its only argument, which is byte-for-byte the shape
//! `crate::deep_link` already parses and vets.
//!
//! ## Two halves, and the invisible one is the AUMID
//!
//! A jump list is filed by **AppUserModelID**, not by executable path. Windows shows a custom
//! list only when the AUMID the process declares matches the AUMID stamped on the shortcut the
//! user launched from — the NSIS template writes `${BUNDLEID}` onto `Syncra.lnk` via
//! `SetLnkAppUserModelId`, so the shortcut side already reads `com.syncra.desktop`. Nothing in
//! this app (nor in `tao`/`wry`/`tauri`) ever called
//! [`SetCurrentProcessExplicitAppUserModelID`], so the process side declared nothing and every
//! list this module committed would have been filed under a system-derived id the shortcut does
//! not share: the code runs, every COM call returns `S_OK`, and the user sees an empty menu.
//!
//! Hence [`set_process_aumid`] and its call site: it is the **first statement of
//! [`crate::run`]**, before any window, plugin or webview exists, because Microsoft's own rule
//! is that the id must be set before the process shows any UI. [`APP_USER_MODEL_ID`] is pinned
//! to `tauri.conf.json`'s `identifier` by [`tests::the_aumid_is_the_bundle_identifier`] on this
//! side and by `desktop/scripts/check-identifier.mjs` on the other.
//!
//! ## Entries point at THIS executable, not at `explorer.exe`
//!
//! Each entry is an `IShellLinkW` whose path is [`std::env::current_exe`] and whose arguments
//! are the single string `syncra://<entity>/<id>`. That is deliberate and it is the same shape
//! protocol activation produces: the `HKCU` scheme registration Tauri writes is
//! `"…\syncra-desktop.exe" "%1"`, so a jump-list click and a `syncra://` link clicked in a
//! browser arrive at `main()` through the identical argv, are handled by the identical
//! `handle_cli_arguments` path in `crate::run`'s single-instance closure, and are vetted by the
//! identical (fuzz-locked, §9 item 5) `deep_link::parse_deep_link`. Routing through
//! `explorer.exe <url>` instead would add a process hop and hand the argument to a program whose
//! first instinct is to treat it as a file path.
//!
//! For the same reason the argument list carries **only** the url: `crate::run`'s
//! single-instance handler forwards the whole `args` iterator to the deep-link plugin, which
//! matches each argument against the declared schemes; an extra flag would not be understood by
//! anything and an extra *url* would be a second navigation.
//!
//! ## Where the titles come from, and why they are the privacy story
//!
//! Nowhere in this file. The webview supplies `title` already resolved, exactly as
//! [`crate::commands::os::notify`] takes resolved notification text: the shell owns no i18n
//! dictionary, and reading a record's name out of the mirror here would mean a second,
//! hand-maintained table of per-entity label columns (`title` / `subject` / `name` / …) beside
//! the one `desktop/src/ui/record-label.ts` already keeps. Deriving it twice is how the two
//! copies drift.
//!
//! **Those titles leave SQLCipher.** Both stores this module writes are plaintext:
//! `<app_data_dir>/syncra/recent.json` (ours) and
//! `%APPDATA%\Microsoft\Windows\Recent\CustomDestinations\*.customDestinations-ms` (the shell's,
//! which we do not own and cannot encrypt). That is the exact shape of the wipe leak F5 closed,
//! so this module ships with the closing move already made: [`clear`] deletes our file **and**
//! calls `ICustomDestinationList::DeleteList`, and it is wired into both paths that mean "this
//! machine should stop remembering this user's data" — `auth::logout` and
//! `storage::clear_local`.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use crate::commands::{CommandError, CommandResult};
use crate::deep_link::{self, SCHEME};

/// The AppUserModelID this process declares and every jump list is filed under.
///
/// **Byte-for-byte `tauri.conf.json`'s `identifier`**, which is what the NSIS installer stamps
/// onto the Start-menu shortcut (`SetLnkAppUserModelId "$INSTDIR\..." "${BUNDLEID}"`). The two
/// have to agree or the list is committed under an id no shortcut carries and the menu is empty
/// with no error anywhere — the same class of silent, config-valued failure
/// `check-identifier.mjs` was written for, which is why that script now asserts this constant
/// too.
///
/// `allow(dead_code)` off Windows and nowhere else: the only non-test reader is
/// `windows_impl`, so on the macOS and Linux CI legs this is an unused constant and
/// `-D warnings` would reject it. Deleting it there is not an option — it is the value
/// `check-identifier.mjs` greps for, on every platform, and a constant that exists only where
/// it is used is a contract that cannot be checked where it is broken. The `cfg_attr` keeps the
/// lint live on the one platform that has a caller.
#[cfg_attr(not(windows), allow(dead_code))]
pub const APP_USER_MODEL_ID: &str = "com.syncra.desktop";

/// `SYNCDESKTOP.md` §6.4: *"son **5** kayıt"*. Five, and not a setting — the spec fixes it.
pub const MAX_RECENT: usize = 5;

/// Longest title (in `char`s) an entry may carry.
///
/// The shell truncates a jump-list title to the width of the menu long before this, so the
/// limit is not about rendering: it is about what this process is willing to write to a
/// plaintext file and hand to the shell's own plaintext store. A record name is a short line of
/// text; anything past this length is a paste, a description or a payload, and refusing it is
/// cheaper than storing it.
pub const MAX_TITLE_CHARS: usize = 120;

/// File name under `<app_data_dir>/syncra`.
const RECENT_FILE: &str = "recent.json";

/// Suffix of the temporary file [`save_recent`] renames over [`RECENT_FILE`].
const RECENT_TEMP_FILE: &str = "recent.json.tmp";

/// One remembered record.
///
/// `id` is a **string** for the same reason [`deep_link::DeepLinkTarget::id`] is: it goes back
/// out as a URL path segment, and round-tripping it through an integer would rewrite `0042` as
/// `42`. It has already passed `^[0-9]{1,12}$` before it can reach this struct.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct RecentRecord {
    /// One of [`deep_link::ENTITIES`].
    pub entity: String,
    /// One to twelve ASCII digits, as text.
    pub id: String,
    /// The record's display name, resolved by the webview. Never an i18n key.
    pub title: String,
}

/// Everything `recent.json` holds.
///
/// The **category label is persisted with the records**, which looks redundant until the
/// startup rebuild: `.setup()` runs long before the webview has booted, so at that moment there
/// is no way to ask i18n for "Son kayıtlar" / "Recent records" — and a category heading is UI
/// text, which §0.6 forbids this crate from owning. Storing the last label the webview sent is
/// what makes the list survive a restart at all. Until the webview has sent one (a fresh
/// install), `category` is empty and the startup rebuild does nothing, which is correct: there
/// are no records to show either.
#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct RecentStore {
    /// The custom category's heading, exactly as the webview resolved it.
    #[serde(default)]
    pub category: String,
    /// Most recently opened first, at most [`MAX_RECENT`] entries.
    #[serde(default)]
    pub records: Vec<RecentRecord>,
}

/// `<root>/recent.json`, where `root` is `AppState::root_dir` (`<app_data_dir>/syncra`).
pub fn recent_path(root: &Path) -> PathBuf {
    root.join(RECENT_FILE)
}

/// The `syncra://<entity>/<id>` url one entry launches with.
///
/// Not validated here — [`validated_record`] is the gate, and it validates by *parsing this
/// function's output* rather than by re-stating the rules.
pub fn deep_link_url(entity: &str, id: &str) -> String {
    format!("{SCHEME}://{entity}/{id}")
}

// ------------------------------------------------------------------------------------------------
// Validation
// ------------------------------------------------------------------------------------------------

/// Vet one `record_opened` call and turn it into a [`RecentRecord`].
///
/// ## The entity allowlist and the id shape are not restated here
///
/// They are checked by building the url this entry will actually launch with and handing it to
/// [`deep_link::parse_deep_link`] — the same function the OS's own activation path goes
/// through, whose eight-name allowlist and `\A[a-z]+/[0-9]{1,12}\z` pattern are locked by an
/// eighty-three-sample fuzz corpus (§9 item 5). A second copy of those rules in this file would
/// be a second thing to keep in sync, and the one that drifted would be the one nobody fuzzed.
///
/// `title` and `category` are this module's own rules, because nothing else in the shell writes
/// user text to a plaintext file: non-blank, within [`MAX_TITLE_CHARS`], and free of control
/// characters (a `\n` in a jump-list title is not rendered — it is a way to make the stored
/// line and the displayed line disagree).
pub fn validated_record(entity: &str, id: &str, title: &str) -> CommandResult<RecentRecord> {
    let url = deep_link_url(entity, id);
    let target = deep_link::parse_deep_link(&url).map_err(|rejection| {
        CommandError::new(
            "VALIDATION_ERROR",
            format!("not an addressable record ({rejection:?})"),
        )
    })?;

    let title = validated_text(title, "title")?;

    Ok(RecentRecord {
        entity: target.entity,
        id: target.id,
        title,
    })
}

/// Vet the category heading the webview resolved. Same rules as a title — it is stored in the
/// same plaintext file and handed to the same shell API.
pub fn validated_category(category: &str) -> CommandResult<String> {
    validated_text(category, "categoryLabel")
}

/// Non-blank, no control characters, at most [`MAX_TITLE_CHARS`] `char`s. Returns the trimmed
/// value.
fn validated_text(value: &str, field: &str) -> CommandResult<String> {
    let trimmed = value.trim();

    if trimmed.is_empty() {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            format!("{field} is empty"),
        ));
    }

    if trimmed.chars().any(char::is_control) {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            format!("{field} contains a control character"),
        ));
    }

    let length = trimmed.chars().count();
    if length > MAX_TITLE_CHARS {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            format!("{field} is {length} characters, the limit is {MAX_TITLE_CHARS}"),
        ));
    }

    Ok(trimmed.to_string())
}

// ------------------------------------------------------------------------------------------------
// The store
// ------------------------------------------------------------------------------------------------

/// Read `recent.json`, or an empty store when there is nothing readable there.
///
/// **Never an error.** A missing file is the normal first-run state; a corrupt one is a
/// plaintext convenience cache with nothing irreplaceable in it. Failing a navigation because a
/// jump-list cache would not parse would be trading a feature the user asked for against one
/// they did not notice. The corrupt case is logged, because silently starting over is how a
/// serialisation bug hides for a release.
pub fn load_recent(path: &Path) -> RecentStore {
    let raw = match std::fs::read_to_string(path) {
        Ok(raw) => raw,
        Err(error) => {
            if error.kind() != std::io::ErrorKind::NotFound {
                tracing::warn!(%error, path = %path.display(), "jump list: cannot read the recent-records store");
            }
            return RecentStore::default();
        }
    };

    match serde_json::from_str::<RecentStore>(&raw) {
        Ok(mut store) => {
            // A file written by a future build (or edited by hand) must not be able to grow the
            // list past what §6.4 fixes; the cap is a property of the list, not of the writer.
            store.records.truncate(MAX_RECENT);
            store
        }
        Err(error) => {
            tracing::warn!(%error, path = %path.display(), "jump list: the recent-records store is not readable JSON; starting over");
            RecentStore::default()
        }
    }
}

/// `record` in front, its previous appearance removed, capped at [`MAX_RECENT`].
///
/// Dedupe is by `(entity, id)` and **not** by title: re-opening a record that has since been
/// renamed has to move it to the front *and* adopt the new name, not add a second entry that
/// launches the same url under the old one.
///
/// Pure, and takes the list by reference: the caller owns the file, this owns the ordering.
pub fn push_recent(records: &[RecentRecord], record: RecentRecord) -> Vec<RecentRecord> {
    let mut next = Vec::with_capacity(records.len().min(MAX_RECENT) + 1);
    next.push(record.clone());
    next.extend(
        records
            .iter()
            .filter(|existing| !(existing.entity == record.entity && existing.id == record.id))
            .take(MAX_RECENT - 1)
            .cloned(),
    );
    next
}

/// Write the store, atomically.
///
/// Temp file + rename rather than a truncating write, for the reason every other durable write
/// in this tree uses it: a truncating write that dies between `set_len(0)` and the last byte
/// leaves a file that exists, parses as nothing, and is indistinguishable from a corrupt one.
/// `fs::rename` over an existing file is atomic on both NTFS and POSIX, so a reader sees either
/// the whole previous store or the whole new one.
pub fn save_recent(path: &Path, store: &RecentStore) -> CommandResult<()> {
    let parent = path.parent().ok_or_else(|| {
        CommandError::new("OS_ERROR", "the recent-records store has no parent directory")
    })?;

    std::fs::create_dir_all(parent).map_err(|error| {
        CommandError::new(
            "OS_ERROR",
            format!("cannot create {}: {error}", parent.display()),
        )
    })?;

    let json = serde_json::to_string_pretty(store).map_err(|error| {
        CommandError::new(
            "OS_ERROR",
            format!("cannot serialise the recent-records store: {error}"),
        )
    })?;

    let temp = parent.join(RECENT_TEMP_FILE);
    std::fs::write(&temp, json).map_err(|error| {
        CommandError::new(
            "OS_ERROR",
            format!("cannot write {}: {error}", temp.display()),
        )
    })?;

    std::fs::rename(&temp, path).map_err(|error| {
        // The temp file must not survive a failed rename: it holds the same plaintext titles
        // the real store does, and nothing would ever clean it up.
        let _ = std::fs::remove_file(&temp);
        CommandError::new(
            "OS_ERROR",
            format!("cannot replace {}: {error}", path.display()),
        )
    })
}

/// Whether this platform has a jump list at all.
///
/// Windows is the only one `SYNCDESKTOP.md` §6.4 asks for, and §0.5 says a feature the document
/// does not name is not added — macOS's dock menu and Linux's desktop actions are different
/// features with different lifetimes, not this one wearing a different coat.
///
/// A `const fn` over `cfg!` rather than two `#[cfg]` bodies, on purpose: every function below it
/// stays compiled and lint-checked on all three CI platforms, so a change that breaks
/// [`push_recent`] or [`save_recent`] fails on the Linux leg too instead of waiting for a
/// Windows runner.
pub const fn has_jump_list() -> bool {
    cfg!(windows)
}

/// Record one visit: push it onto the store, persist, rebuild the shell's category.
///
/// The whole `record_opened` pipeline after validation, in one place so the command stays a
/// delegation like every other one in `commands::os`.
///
/// **Off Windows this keeps nothing.** The caller has already validated the input — the
/// `{code, message}` contract must not differ by platform, or a `VALIDATION_ERROR` becomes
/// something only Windows users can discover — but persisting five record titles to a
/// plaintext file that no menu on this machine can ever read would be the privacy cost of the
/// feature with none of the feature.
pub fn remember(root: &Path, record: RecentRecord, category: String) -> CommandResult<()> {
    if !has_jump_list() {
        return Ok(());
    }

    let path = recent_path(root);
    let previous = load_recent(&path);
    let store = RecentStore {
        category,
        records: push_recent(&previous.records, record),
    };

    // Persist first, rebuild second: the store is what the next launch reads, and a shell that
    // refuses the list must not also cost us the record we just learned about.
    save_recent(&path, &store)?;
    rebuild(&store)
}

/// Forget everything: the plaintext store on disk **and** the list the shell is holding.
///
/// Wired into `auth::logout` and `storage::clear_local` (defter O107). Both mean "this machine
/// should stop remembering this user's data", and until this call existed both left five record
/// names sitting in two plaintext files — ours and the shell's
/// `CustomDestinations` store, which survives an uninstall.
///
/// Best-effort and infallible on purpose, exactly like
/// `storage::clear_local`'s cache sweep: by the time it runs the irreversible part (the
/// SQLCipher wipe, or the session drop) has already happened, and turning an otherwise
/// successful logout into a reported failure because a jump list would not clear would leave
/// the user unsure which half went through. Every failure is logged.
pub fn clear(root: &Path) {
    let path = recent_path(root);
    if let Err(error) = std::fs::remove_file(&path) {
        if error.kind() != std::io::ErrorKind::NotFound {
            tracing::warn!(%error, path = %path.display(), "jump list: cannot delete the recent-records store");
        }
    }

    // The temp file too: a crash between write and rename would otherwise leave the same titles
    // behind under a name nothing reads and nothing cleans.
    if let Some(parent) = path.parent() {
        let temp = parent.join(RECENT_TEMP_FILE);
        if let Err(error) = std::fs::remove_file(&temp) {
            if error.kind() != std::io::ErrorKind::NotFound {
                tracing::warn!(%error, path = %temp.display(), "jump list: cannot delete the recent-records temp file");
            }
        }
    }

    #[cfg(windows)]
    if let Err(error) = windows_impl::run_on_sta(windows_impl::delete_list) {
        tracing::warn!(%error, "jump list: cannot delete the shell's custom destination list");
    }
}

// ------------------------------------------------------------------------------------------------
// Platform surface
// ------------------------------------------------------------------------------------------------

/// Declare [`APP_USER_MODEL_ID`] for this process. **Must be the first thing `run()` does.**
///
/// Best-effort: a failure here costs the jump list, not the app, and there is no user-facing
/// recourse. See the module header for why the call has to exist at all.
#[cfg(windows)]
pub fn set_process_aumid() {
    if let Err(error) = windows_impl::set_process_aumid() {
        tracing::warn!(%error, "jump list: cannot declare the AppUserModelID");
    }
}

/// Rebuild the shell's custom category from `store`.
///
/// Windows only. Returns `OS_ERROR` when the shell refused the list — the caller decides
/// whether that is worth surfacing (`record_opened` reports it; the startup rebuild logs it).
#[cfg(windows)]
pub fn rebuild(store: &RecentStore) -> CommandResult<()> {
    if store.category.is_empty() {
        // Nothing the webview has ever labelled — see `RecentStore::category`.
        return Ok(());
    }
    let store = store.clone();
    windows_impl::run_on_sta(move || windows_impl::rebuild(&store)).map_err(|error| {
        CommandError::new("OS_ERROR", format!("cannot build the jump list: {error}"))
    })
}

/// Non-Windows: there is no jump list, and pretending otherwise is worse than doing nothing.
///
/// The same shape `commands::os::apply_badge` and the autostart plugin use — one command name,
/// one `{code, message}` contract, and the platform difference confined to which body compiles.
/// macOS's dock menu and Linux's desktop actions are different features with different lifetimes
/// (a dock menu is built at runtime and holds no user data at all); §6.4 asks for the Windows
/// one, and §0.5 says a feature the document does not name is not added.
#[cfg(not(windows))]
pub fn set_process_aumid() {}

#[cfg(not(windows))]
pub fn rebuild(_store: &RecentStore) -> CommandResult<()> {
    Ok(())
}

// ------------------------------------------------------------------------------------------------
// Windows
// ------------------------------------------------------------------------------------------------

#[cfg(windows)]
mod windows_impl {
    //! The COM half. Everything `unsafe` in this module lives here.

    use windows::core::{Interface, HSTRING, PCWSTR};
    use windows::Win32::Foundation::PROPERTYKEY;
    use windows::Win32::Storage::EnhancedStorage::PKEY_Title;
    use windows::Win32::System::Com::StructuredStorage::{PropVariantClear, PROPVARIANT};
    use windows::Win32::System::Com::{
        CoCreateInstance, CoInitializeEx, CoUninitialize, CLSCTX_INPROC_SERVER,
        COINIT_APARTMENTTHREADED,
    };
    use windows::Win32::System::Variant::VT_LPWSTR;
    use windows::Win32::UI::Shell::Common::{IObjectArray, IObjectCollection};
    use windows::Win32::UI::Shell::PropertiesSystem::IPropertyStore;
    use windows::Win32::UI::Shell::{
        DestinationList, EnumerableObjectCollection, ICustomDestinationList, IShellLinkW,
        SetCurrentProcessExplicitAppUserModelID, SHStrDupW, ShellLink,
    };

    use super::{deep_link_url, RecentStore, APP_USER_MODEL_ID, MAX_RECENT};

    /// Longest `IShellLinkW::GetArguments` string [`removed_urls`] will read back.
    ///
    /// A `syncra://<entity>/<id>` url is at most 12 + 12 + 12 characters; this is generous by
    /// two orders of magnitude and exists only so a hostile or corrupt entry in the shell's
    /// removed-destinations list cannot ask this process for an unbounded buffer.
    const MAX_ARGUMENT_CHARS: usize = 1024;

    /// Declare the process AUMID. See [`super::set_process_aumid`].
    pub(super) fn set_process_aumid() -> windows::core::Result<()> {
        let id = HSTRING::from(APP_USER_MODEL_ID);
        // SAFETY: `id` is a NUL-terminated UTF-16 buffer that outlives the call, which is the
        // function's only requirement.
        unsafe { SetCurrentProcessExplicitAppUserModelID(&id) }
    }

    /// Run `work` on a fresh single-threaded apartment, and wait for it.
    ///
    /// ## Why a thread of its own
    ///
    /// `ICustomDestinationList` is an apartment-threaded shell object, so the calling thread has
    /// to be an STA. The main thread *is* one — `tao` calls `OleInitialize` on it — but that is
    /// a fact about a dependency's internals, not a contract, and a `#[tauri::command]` is not
    /// guaranteed to run there in the first place. Initialising COM on a thread that already has
    /// it returns `S_FALSE` (or `RPC_E_CHANGED_MODE` if the model disagrees) and pairs with a
    /// `CoUninitialize` that would decrement somebody else's reference count. A private thread
    /// owns its apartment outright: nothing else can be inside it, and tearing it down cannot
    /// affect anyone.
    ///
    /// Joined rather than detached because the outcome is the command's return value — a
    /// fire-and-forget rebuild would make `record_opened` report a success it never observed.
    /// The work is a handful of in-process shell calls and one file write by the shell.
    pub(super) fn run_on_sta<F>(work: F) -> windows::core::Result<()>
    where
        F: FnOnce() -> windows::core::Result<()> + Send + 'static,
    {
        std::thread::spawn(move || {
            // SAFETY: this thread has just been created and has no apartment yet, so this call
            // establishes one and the matching `CoUninitialize` below tears down that same one.
            unsafe { CoInitializeEx(None, COINIT_APARTMENTTHREADED).ok()? };

            let result = work();

            // SAFETY: paired with the `CoInitializeEx` above, on the same thread, and every COM
            // pointer `work` created has been dropped by now (it returns owned nothing).
            unsafe { CoUninitialize() };

            result
        })
        .join()
        .unwrap_or_else(|_| {
            Err(windows::core::Error::new(
                windows::Win32::Foundation::E_FAIL,
                "the jump-list worker thread panicked",
            ))
        })
    }

    /// Replace the custom category with `store`'s records. Runs inside [`run_on_sta`].
    pub(super) fn rebuild(store: &RecentStore) -> windows::core::Result<()> {
        // SAFETY: every call below is on the STA `run_on_sta` established, each interface
        // pointer is owned by this frame, and the only raw pointer handed out (`&mut slots`) is
        // a live stack slot.
        unsafe {
            let list: ICustomDestinationList =
                CoCreateInstance(&DestinationList, None, CLSCTX_INPROC_SERVER)?;
            list.SetAppID(&HSTRING::from(APP_USER_MODEL_ID))?;

            let mut slots: u32 = 0;
            let removed: IObjectArray = list.BeginList(&mut slots)?;

            // "Removed" is not advisory. The documented contract is that an entry the user
            // deleted from the menu must not be put back — `AppendCategory` fails outright if
            // the collection contains one — so the removals have to be subtracted from what we
            // are about to append. This is also the one place a user can veto us remembering a
            // record at all, which is worth honouring for its own sake.
            let removed = removed_urls(&removed);

            let capacity = (slots as usize).min(MAX_RECENT);
            let collection: IObjectCollection =
                CoCreateInstance(&EnumerableObjectCollection, None, CLSCTX_INPROC_SERVER)?;

            let exe_path = std::env::current_exe().map_err(|error| {
                windows::core::Error::new(
                    windows::Win32::Foundation::E_FAIL,
                    format!("cannot resolve the current executable: {error}"),
                )
            })?;
            let exe = HSTRING::from(exe_path.as_path());

            let mut appended = 0usize;
            for record in &store.records {
                if appended >= capacity {
                    break;
                }
                let url = deep_link_url(&record.entity, &record.id);
                if removed.iter().any(|gone| gone == &url) {
                    continue;
                }
                collection.AddObject(&shell_link(&exe, &url, &record.title)?)?;
                appended += 1;
            }

            // An empty collection is not an empty category: `AppendCategory` refuses one. The
            // committed list is then simply empty, which is the correct rendering of "there is
            // nothing to show" and also what a fresh install and a post-logout `clear` produce.
            if appended > 0 {
                let array: IObjectArray = collection.cast()?;
                list.AppendCategory(&HSTRING::from(store.category.as_str()), &array)?;
            }

            list.CommitList()
        }
    }

    /// Drop this application's whole custom list from the shell's store. See [`super::clear`].
    pub(super) fn delete_list() -> windows::core::Result<()> {
        // SAFETY: same apartment guarantee as `rebuild`; the interface pointer is owned here.
        unsafe {
            let list: ICustomDestinationList =
                CoCreateInstance(&DestinationList, None, CLSCTX_INPROC_SERVER)?;
            list.DeleteList(&HSTRING::from(APP_USER_MODEL_ID))
        }
    }

    /// The urls behind the entries the user deleted from the menu.
    ///
    /// Every read is fallible and every failure is skipped rather than propagated: the shell's
    /// removed list can hold `IShellItem`s (from the automatic Recent/Frequent categories) that
    /// are not `IShellLinkW` at all, and one of those must not abort a rebuild.
    ///
    /// # Safety
    ///
    /// Caller must be on the apartment `run_on_sta` established and must own `removed`.
    unsafe fn removed_urls(removed: &IObjectArray) -> Vec<String> {
        let count = match unsafe { removed.GetCount() } {
            Ok(count) => count,
            Err(_) => return Vec::new(),
        };

        let mut urls = Vec::new();
        for index in 0..count {
            let Ok(link) = (unsafe { removed.GetAt::<IShellLinkW>(index) }) else {
                continue;
            };
            let mut buffer = vec![0u16; MAX_ARGUMENT_CHARS];
            if unsafe { link.GetArguments(&mut buffer) }.is_err() {
                continue;
            }
            let end = buffer.iter().position(|unit| *unit == 0).unwrap_or(0);
            if end == 0 {
                continue;
            }
            urls.push(String::from_utf16_lossy(&buffer[..end]));
        }
        urls
    }

    /// One jump-list entry: this executable, launched with `url`, shown as `title`.
    ///
    /// # Safety
    ///
    /// Caller must be on the apartment `run_on_sta` established.
    unsafe fn shell_link(
        exe: &HSTRING,
        url: &str,
        title: &str,
    ) -> windows::core::Result<IShellLinkW> {
        unsafe {
            let link: IShellLinkW = CoCreateInstance(&ShellLink, None, CLSCTX_INPROC_SERVER)?;
            link.SetPath(exe)?;
            link.SetArguments(&HSTRING::from(url))?;
            // The entry inherits the app's own icon; without this the shell draws a blank sheet
            // for a link whose target is an executable it has not indexed.
            link.SetIconLocation(exe, 0)?;

            // The visible label is a property, not a field on the link: `IShellLink` has no
            // "title", and `SetDescription` sets the tooltip. `PKEY_Title` on the link's own
            // property store is the documented way, and `Commit` is what writes it.
            let store: IPropertyStore = link.cast()?;
            let mut value = propvariant_string(title)?;
            let result = store
                .SetValue(&PKEY_Title as *const PROPERTYKEY, &value)
                .and_then(|()| store.Commit());
            // Frees the `CoTaskMemAlloc`'d string `propvariant_string` put in `value`, on both
            // paths — `SetValue` copies what it needs.
            let _ = PropVariantClear(&mut value);
            result?;

            Ok(link)
        }
    }

    /// A `VT_LPWSTR` [`PROPVARIANT`] holding `value`.
    ///
    /// Built by hand because the C helper that does this (`InitPropVariantFromString`) is an
    /// inline function in `propvarutil.h` and therefore has no import library and no binding in
    /// `windows`. The vector-valued `InitPropVariantFromStringVector` *is* bound, but it
    /// produces `VT_LPWSTR | VT_VECTOR`, which is not what `PKEY_Title` is typed as.
    ///
    /// `SHStrDupW` is the allocation: it uses `CoTaskMemAlloc`, which is precisely what
    /// [`PropVariantClear`] frees, so ownership transfers cleanly into the variant.
    ///
    /// # Safety
    ///
    /// The returned variant owns a `CoTaskMemAlloc`'d buffer; the caller must pass it to
    /// [`PropVariantClear`].
    unsafe fn propvariant_string(value: &str) -> windows::core::Result<PROPVARIANT> {
        let wide = HSTRING::from(value);
        let duplicate = unsafe { SHStrDupW(PCWSTR(wide.as_ptr())) }?;

        let mut variant = PROPVARIANT::default();
        // SAFETY: `PROPVARIANT::default()` is a zeroed union, so the `Anonymous.Anonymous` arm
        // is the inactive-but-valid one every initialiser starts from; writing `vt` first is
        // what makes it the active arm.
        unsafe {
            let inner = &mut *variant.Anonymous.Anonymous;
            inner.vt = VT_LPWSTR;
            inner.Anonymous.pwszVal = duplicate;
        }
        Ok(variant)
    }
}

// ------------------------------------------------------------------------------------------------
// Tests
// ------------------------------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;
    use uuid::Uuid;

    /// A throwaway directory under the OS temp dir, removed on drop — the same pattern
    /// `commands::storage`'s and `commands::files`'s test modules use.
    struct TempDir(PathBuf);

    impl TempDir {
        fn new() -> Self {
            let path = std::env::temp_dir().join(format!("syncra-jumplist-test-{}", Uuid::new_v4()));
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

    fn record(entity: &str, id: &str, title: &str) -> RecentRecord {
        RecentRecord {
            entity: entity.into(),
            id: id.into(),
            title: title.into(),
        }
    }

    /// `source` with every `//` comment blanked, so a call site named only in prose is not
    /// mistaken for a call. Same idiom as `updater::tests` and `check-command-wiring.mjs`.
    fn code_of(source: &str) -> String {
        source
            .lines()
            .map(|line| match line.find("//") {
                Some(at) => &line[..at],
                None => line,
            })
            .collect::<Vec<_>>()
            .join("\n")
    }

    // --- the call sites ---------------------------------------------------------------------

    /// The AUMID is declared **before anything else `run()` does**.
    ///
    /// Not a style preference: `SetCurrentProcessExplicitAppUserModelID` is documented as having
    /// to be called before the process shows any UI, and a call moved into `.setup()` — after
    /// the builder has created the window — is a call that runs too late on some Windows
    /// versions and appears to work on others. That is the kind of ordering nothing but an
    /// assertion on the source can hold, because both orderings compile and both run.
    #[test]
    fn the_aumid_is_declared_as_the_first_thing_run_does() {
        let code = code_of(include_str!("lib.rs"));
        let body = code
            .split_once("pub fn run() {")
            .expect("lib.rs must still declare `pub fn run()`")
            .1;
        let first = body
            .lines()
            .map(str::trim)
            .find(|line| !line.is_empty())
            .expect("run() must have a body");

        assert_eq!(
            first, "jump_list::set_process_aumid();",
            "the AppUserModelID must be declared before any window, plugin or webview exists — \
             see `crate::jump_list`'s module header"
        );
    }

    /// The privacy hook, half one (defter O107): a logout deletes the plaintext record titles.
    ///
    /// Asserted on the source because the alternative is an integration test that needs a real
    /// `AppState`, a real SQLCipher database and a real keychain entry — none of which exist in
    /// a unit run, and the thing that can actually rot is the *call site*, not the function it
    /// calls (which `clearing_removes_the_plaintext_store` covers directly).
    #[test]
    fn logging_out_clears_the_jump_list() {
        let code = code_of(include_str!("commands/auth.rs"));
        assert!(
            code.contains("jump_list::clear("),
            "auth::logout no longer clears the jump list — a logout would leave up to five \
             record names in `recent.json` and in the shell's CustomDestinations store, both \
             plaintext, both outside SQLCipher"
        );
    }

    /// The privacy hook, half two (defter O107): "clear local data" means the jump list too.
    #[test]
    fn clearing_local_data_clears_the_jump_list() {
        let code = code_of(include_str!("commands/storage.rs"));
        assert!(
            code.contains("jump_list::clear("),
            "storage::clear_local no longer clears the jump list — the wipe would leave the \
             last five record names on the taskbar"
        );
    }

    // --- the AUMID ------------------------------------------------------------------------

    /// The constant this process declares and the identifier the installer stamps on the
    /// shortcut are the SAME string.
    ///
    /// Read out of `tauri.conf.json` rather than restated, because restating it is what the
    /// mismatch would look like. `desktop/scripts/check-identifier.mjs` holds the third side
    /// (its own pinned literal) so that changing the config alone fails there, and changing
    /// both without meaning to still fails here.
    #[test]
    fn the_aumid_is_the_bundle_identifier() {
        let config: serde_json::Value =
            serde_json::from_str(include_str!("../tauri.conf.json")).expect("tauri.conf.json");
        assert_eq!(
            config["identifier"].as_str(),
            Some(APP_USER_MODEL_ID),
            "the AUMID must equal tauri.conf.json's identifier — the NSIS template stamps that \
             value onto the Start-menu shortcut, and a list committed under any other id is \
             filed where no shortcut can find it"
        );
    }

    /// §6.4 says five. Not four, not a setting.
    #[test]
    fn the_cap_is_the_five_the_spec_names() {
        assert_eq!(MAX_RECENT, 5);
    }

    // --- deep_link_url --------------------------------------------------------------------

    /// The url an entry launches with is one the app's OWN deep-link parser accepts, for every
    /// entity the spec lists. This is the whole reason the entries are `exe + argument` rather
    /// than `explorer.exe <url>`: the jump-list path and the protocol path are the same path.
    #[test]
    fn every_entity_produces_a_url_the_deep_link_parser_accepts() {
        for entity in deep_link::ENTITIES {
            let url = deep_link_url(entity, "29");
            let target = deep_link::parse_deep_link(&url)
                .unwrap_or_else(|rejection| panic!("{url} was rejected: {rejection:?}"));
            assert_eq!(target.entity, entity);
            assert_eq!(target.id, "29");
        }
    }

    /// The literal shape, restated independently of `deep_link::SCHEME` so that a change to the
    /// scheme has to be a deliberate edit in two places rather than a silent rename.
    #[test]
    fn the_url_shape_is_the_one_6_4_fixes() {
        assert_eq!(deep_link_url("deal", "29"), "syncra://deal/29");
    }

    /// An id is a path segment, not a number: leading zeros survive the round trip.
    #[test]
    fn an_id_is_not_renumbered() {
        let record = validated_record("deal", "0042", "Acme").expect("valid");
        assert_eq!(record.id, "0042");
    }

    // --- validation -----------------------------------------------------------------------

    #[test]
    fn an_entity_outside_the_eight_is_refused() {
        for entity in ["user", "product", "setting", "", "DEAL", "deal/../admin"] {
            let error = validated_record(entity, "1", "x").expect_err(entity);
            assert_eq!(error.code, "VALIDATION_ERROR", "{entity}");
        }
    }

    #[test]
    fn an_id_outside_the_6_4_pattern_is_refused() {
        for id in ["", "-1", "1.5", "abc", "1234567890123", "1 2", "1/2", "٤٢"] {
            let error = validated_record("deal", id, "x").expect_err(id);
            assert_eq!(error.code, "VALIDATION_ERROR", "{id}");
        }
    }

    #[test]
    fn a_blank_or_oversized_or_control_bearing_title_is_refused() {
        let long = "a".repeat(MAX_TITLE_CHARS + 1);
        for title in ["", "   ", "line\nbreak", "bell\u{7}", long.as_str()] {
            let error = validated_record("deal", "1", title).expect_err(title);
            assert_eq!(error.code, "VALIDATION_ERROR", "{title}");
        }
    }

    /// The boundary itself is accepted — an off-by-one here would silently refuse legitimate
    /// record names.
    #[test]
    fn a_title_exactly_at_the_limit_is_accepted() {
        let title = "a".repeat(MAX_TITLE_CHARS);
        assert!(validated_record("deal", "1", &title).is_ok());
    }

    /// The limit counts characters, not bytes: a Turkish record name is not shorter than an
    /// English one because of its encoding.
    #[test]
    fn the_title_limit_counts_characters_not_bytes() {
        let title = "ş".repeat(MAX_TITLE_CHARS);
        assert!(title.len() > MAX_TITLE_CHARS, "the sample must be multi-byte");
        assert!(validated_record("deal", "1", &title).is_ok());
    }

    #[test]
    fn a_title_is_trimmed_rather_than_stored_with_its_padding() {
        let record = validated_record("deal", "1", "  Acme Holding  ").expect("valid");
        assert_eq!(record.title, "Acme Holding");
    }

    #[test]
    fn a_blank_category_is_refused() {
        assert_eq!(
            validated_category("   ").expect_err("blank").code,
            "VALIDATION_ERROR"
        );
        assert_eq!(validated_category("Son kayıtlar").expect("valid"), "Son kayıtlar");
    }

    // --- push_recent ----------------------------------------------------------------------

    #[test]
    fn the_newest_record_goes_to_the_front() {
        let list = push_recent(&[record("deal", "1", "A")], record("ticket", "2", "B"));
        assert_eq!(
            list,
            vec![record("ticket", "2", "B"), record("deal", "1", "A")]
        );
    }

    /// Re-opening a record moves it up and adopts its current name instead of adding a second
    /// entry that launches the same url.
    #[test]
    fn revisiting_a_record_deduplicates_and_refreshes_the_title() {
        let existing = vec![
            record("ticket", "2", "B"),
            record("deal", "1", "old name"),
            record("quote", "3", "C"),
        ];
        let list = push_recent(&existing, record("deal", "1", "new name"));
        assert_eq!(
            list,
            vec![
                record("deal", "1", "new name"),
                record("ticket", "2", "B"),
                record("quote", "3", "C"),
            ]
        );
    }

    /// The same id under a DIFFERENT entity is a different record — `deal/1` and `ticket/1`
    /// both belong on the list.
    #[test]
    fn the_same_id_under_another_entity_is_not_a_duplicate() {
        let list = push_recent(&[record("deal", "1", "A")], record("ticket", "1", "B"));
        assert_eq!(list.len(), 2);
    }

    #[test]
    fn the_list_never_grows_past_five_and_drops_the_oldest() {
        let mut list: Vec<RecentRecord> = Vec::new();
        for index in 1..=8 {
            list = push_recent(&list, record("deal", &index.to_string(), "x"));
            assert!(list.len() <= MAX_RECENT, "grew to {}", list.len());
        }
        let ids: Vec<&str> = list.iter().map(|entry| entry.id.as_str()).collect();
        assert_eq!(ids, vec!["8", "7", "6", "5", "4"]);
    }

    // --- the store ------------------------------------------------------------------------

    #[test]
    fn a_missing_store_reads_as_empty_rather_than_failing() {
        let dir = TempDir::new();
        assert_eq!(load_recent(&recent_path(dir.path())), RecentStore::default());
    }

    #[test]
    fn a_corrupt_store_reads_as_empty_rather_than_failing() {
        let dir = TempDir::new();
        let path = recent_path(dir.path());
        std::fs::write(&path, "{not json at all").expect("write");
        assert_eq!(load_recent(&path), RecentStore::default());
    }

    /// A file that somehow holds more than five entries is truncated on read: the cap belongs to
    /// the list, not to whoever last wrote the file.
    #[test]
    fn an_oversized_store_is_truncated_on_read() {
        let dir = TempDir::new();
        let path = recent_path(dir.path());
        let store = RecentStore {
            category: "Recent".into(),
            records: (1..=9)
                .map(|index| record("deal", &index.to_string(), "x"))
                .collect(),
        };
        std::fs::write(&path, serde_json::to_string(&store).expect("json")).expect("write");
        assert_eq!(load_recent(&path).records.len(), MAX_RECENT);
    }

    #[test]
    fn a_saved_store_round_trips() {
        let dir = TempDir::new();
        let path = recent_path(dir.path());
        let store = RecentStore {
            category: "Son kayıtlar".into(),
            records: vec![record("deal", "29", "Acme — yıllık sözleşme")],
        };
        save_recent(&path, &store).expect("save");
        assert_eq!(load_recent(&path), store);
    }

    /// The write is temp-file + rename, and the temp file does not survive it — it holds the
    /// same plaintext titles the real store does.
    #[test]
    fn saving_leaves_no_temporary_file_behind() {
        let dir = TempDir::new();
        let path = recent_path(dir.path());
        save_recent(
            &path,
            &RecentStore {
                category: "Recent".into(),
                records: vec![record("deal", "1", "A")],
            },
        )
        .expect("save");

        let leftovers: Vec<String> = std::fs::read_dir(dir.path())
            .expect("read dir")
            .flatten()
            .map(|entry| entry.file_name().to_string_lossy().into_owned())
            .filter(|name| name != RECENT_FILE)
            .collect();
        assert!(leftovers.is_empty(), "left behind: {leftovers:?}");
    }

    /// Saving over an existing store replaces it whole — no merge, no append.
    #[test]
    fn saving_replaces_the_previous_store() {
        let dir = TempDir::new();
        let path = recent_path(dir.path());
        save_recent(
            &path,
            &RecentStore {
                category: "Recent".into(),
                records: vec![record("deal", "1", "A"), record("deal", "2", "B")],
            },
        )
        .expect("first save");
        save_recent(
            &path,
            &RecentStore {
                category: "Recent".into(),
                records: vec![record("quote", "9", "C")],
            },
        )
        .expect("second save");

        let store = load_recent(&path);
        assert_eq!(store.records, vec![record("quote", "9", "C")]);
    }

    /// `save_recent` creates the directory rather than failing on a first run that reaches it
    /// before anything else has written under `<app_data_dir>/syncra`.
    #[test]
    fn saving_creates_the_directory() {
        let dir = TempDir::new();
        let nested = dir.path().join("not-created-yet");
        let path = recent_path(&nested);
        save_recent(&path, &RecentStore::default()).expect("save");
        assert!(path.exists());
    }

    // --- clear (defter O107) ---------------------------------------------------------------

    /// The privacy hook: after `clear`, the plaintext titles are not on disk.
    ///
    /// This is the half that runs on every platform. The other half — the shell's own
    /// `CustomDestinations` store — is `DeleteList`, and it is covered by the logout step of
    /// the manual verification procedure, because no test in this process can observe another
    /// process's cache.
    #[test]
    fn clearing_removes_the_plaintext_store() {
        let dir = TempDir::new();
        let path = recent_path(dir.path());
        save_recent(
            &path,
            &RecentStore {
                category: "Recent".into(),
                records: vec![record("deal", "29", "Acme Holding")],
            },
        )
        .expect("save");
        assert!(path.exists());

        clear(dir.path());

        assert!(!path.exists(), "the recent-records store survived clear()");
        assert_eq!(load_recent(&path), RecentStore::default());
    }

    /// `clear` on a machine that never wrote a store is a no-op, not a failure: `logout` calls
    /// it unconditionally.
    #[test]
    fn clearing_an_absent_store_is_a_no_op() {
        let dir = TempDir::new();
        clear(dir.path());
        clear(dir.path());
    }

    /// A temp file left by a crash between write and rename is swept too — it holds the same
    /// titles the real store does.
    #[test]
    fn clearing_removes_an_orphaned_temp_file() {
        let dir = TempDir::new();
        let temp = dir.path().join(RECENT_TEMP_FILE);
        std::fs::write(&temp, "{}").expect("write");
        clear(dir.path());
        assert!(!temp.exists());
    }
}
