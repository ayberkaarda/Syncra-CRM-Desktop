//! Where the encrypted mirror and the blob cache live, and how that place is changed
//! (`SYNCDESKTOP.md` §10 F8 item 1, KARAR K15).
//!
//! # Two directories, not one
//!
//! The shell owns two roots and they are deliberately different things:
//!
//! * the **anchor** — `app_data_dir()`, i.e. `%APPDATA%\com.syncra.desktop` on Windows. It is
//!   derived from the bundle identifier by the OS and can never move. Exactly one file of ours
//!   lives directly in it: [`POINTER_FILE`].
//! * the **data root** — [`DEFAULT_SUBDIR`] under the anchor by default, or anywhere the user
//!   picked. It holds `syncra.db` and its WAL siblings, `cache/`, and `recent.json`.
//!
//! The split exists because of a chicken-and-egg problem that has exactly one honest answer.
//! The choice of data root cannot be stored *in* the data root — reading it would require
//! already knowing where it is. It cannot go in `syncra_sync::DesktopSettings` either: those
//! live in the `settings` table of the very database whose location is in question (and that
//! API is frozen, `SYNCDESKTOP.md` §5.2). So it goes in a plain JSON file at the one location
//! that is fixed by the OS. That is the same shape `crate::jump_list`'s `recent.json` already
//! uses — a small serde struct in a file next to the data — applied one directory higher up.
//!
//! The pointer file holds a filesystem path and nothing else. No key, no token, no record
//! data; `SYNCDESKTOP.md` §9 item 4 (nothing sensitive on disk outside SQLCipher and the OS
//! keychain) is unaffected by it, in either directory.
//!
//! # The user picks a parent, we own a child
//!
//! [`root_for_target`] always appends [`DEFAULT_SUBDIR`] to whatever the folder picker
//! returned. Picking `D:\Data` produces `D:\Data\syncra`, never `D:\Data` itself. Two reasons,
//! both concrete: the move ends by DELETING the previous root, and a directory the user also
//! keeps other things in must never be a candidate for that; and the default layout stays the
//! same shape everywhere, so `clear_local`, the cache sweep and the jump-list store need no
//! notion of "the root might be a shared folder this time".
//!
//! # Fixed disks only (and why that is a measurement, not a preference)
//!
//! [`reject_unsupported_volume`] refuses anything that is not `DRIVE_FIXED` on Windows.
//! SQLite's WAL mode — which this database runs in (`SYNCDESKTOP.md` K3, set by
//! `syncra_sync::db::open`) — needs a shared-memory `-shm` file that is mapped, not merely
//! written, and SQLite's own documentation states that WAL does not work over a network
//! filesystem for exactly that reason. A removable volume adds a second failure mode that has
//! nothing to do with WAL: the root can simply be gone at the next launch. See the phase
//! report for what was and was not measured on this machine.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use syncra_sync::db;

/// The directory name appended under the anchor (or under whatever the user picked).
pub const DEFAULT_SUBDIR: &str = "syncra";

/// The file, in the anchor directory, that names the data root.
pub const POINTER_FILE: &str = "data-location.json";

/// The database file name, and the stem of its WAL siblings.
pub const DB_FILE: &str = "syncra.db";

/// The blob cache directory name, relative to the data root.
pub const CACHE_SUBDIR: &str = "cache";

/// Everything the shell itself puts in a data root.
///
/// Used by [`target_is_usable`] to answer "is this directory empty, or is it one of ours?".
/// `recent.json` is on the list because `crate::jump_list` writes it there;
/// `syncra.db-journal` is on it because a database that has ever been opened in rollback mode
/// (a failed `journal_mode = WAL` switch on a filesystem that refuses it) leaves one, and a
/// leftover we do not recognise would block a re-move for no reason.
const OWNED_ENTRIES: &[&str] = &[
    DB_FILE,
    "syncra.db-wal",
    "syncra.db-shm",
    "syncra.db-journal",
    CACHE_SUBDIR,
    "recent.json",
];

/// The on-disk shape of [`POINTER_FILE`].
///
/// One field, and it stays one field: this file is read before anything else exists, so every
/// value in it is a value that has no other source of truth to be checked against.
#[derive(Debug, Clone, Serialize, Deserialize)]
struct StoredLocation {
    /// Absolute path of the data root.
    root: String,
}

/// Why a proposed target was refused, and the `{code, message}` the UI will show.
///
/// Deliberately an enum rather than a string: `commands::storage` turns each variant into one
/// of three `desktop.errors.*` codes, and the UI's advice differs per variant (pick another
/// folder / pick another drive / your data was not touched).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Rejection {
    /// Not a directory, not writable, already in use, nested in the current root, or holding
    /// files that are not ours.
    Invalid(String),
    /// The volume is removable, remote, optical or unknown — see the module docs.
    ///
    /// Windows-only, because the classification that produces it is. The non-Windows arm of
    /// [`reject_unsupported_volume`] accepts every volume — a declared gap, argued below it —
    /// so off Windows this variant has no honest producer and is dead code that the macOS and
    /// Linux CI legs reject under `-D warnings`. Gating the variant is the truthful shape:
    /// "this refusal does not exist on this platform" rather than an `#[allow(dead_code)]`
    /// pretending it might.
    ///
    /// The `desktop.errors.DATA_DIR_UNSUPPORTED` key stays in all four locale dictionaries and
    /// in `KNOWN_ERROR_CODES` regardless. A dictionary key is not platform-conditional, and
    /// `npm run check:errors` compares `desktop/src/ui/errors.ts` against `tr/desktop.json` —
    /// it never reads this enum, so nothing here can drift the code contract.
    #[cfg(windows)]
    UnsupportedVolume(String),
}

impl Rejection {
    /// The `desktop.errors.*` code for this refusal.
    pub fn code(&self) -> &'static str {
        match self {
            Rejection::Invalid(_) => "DATA_DIR_INVALID",
            #[cfg(windows)]
            Rejection::UnsupportedVolume(_) => "DATA_DIR_UNSUPPORTED",
        }
    }

    /// The log/diagnostic detail. Never rendered as-is — the UI owns the copy (§0.6).
    pub fn message(&self) -> &str {
        match self {
            Rejection::Invalid(message) => message,
            #[cfg(windows)]
            Rejection::UnsupportedVolume(message) => message,
        }
    }
}

// ------------------------------------------------------------------------------------------------
// Paths
// ------------------------------------------------------------------------------------------------

/// The three paths derived from one data root.
///
/// Bundled into a struct rather than kept as three fields so that swapping the root after a
/// move is a single assignment that cannot leave `db_path` pointing at the old drive while
/// `cache_dir` points at the new one.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DataPaths {
    /// The data root itself.
    pub root: PathBuf,
    /// `<root>/syncra.db`.
    pub db: PathBuf,
    /// `<root>/cache`.
    pub cache: PathBuf,
}

impl DataPaths {
    /// Derive the layout for one root. Pure — touches no filesystem.
    pub fn for_root(root: impl Into<PathBuf>) -> Self {
        let root = root.into();
        DataPaths {
            db: root.join(DB_FILE),
            cache: root.join(CACHE_SUBDIR),
            root,
        }
    }
}

/// `<anchor>/data-location.json`.
pub fn pointer_path(anchor: &Path) -> PathBuf {
    anchor.join(POINTER_FILE)
}

/// The root used when the pointer file is absent: `<anchor>/syncra`.
pub fn default_root(anchor: &Path) -> PathBuf {
    anchor.join(DEFAULT_SUBDIR)
}

/// The root the user picked, if the pointer file names one this process can parse.
///
/// `None` for "no pointer file" and for "a pointer file we could not read" alike, because the
/// two are the same decision: fall back to the default. A malformed pointer is logged by
/// [`resolve_root`], not swallowed here.
fn stored_root(anchor: &Path) -> Option<PathBuf> {
    let raw = std::fs::read_to_string(pointer_path(anchor)).ok()?;
    let parsed: StoredLocation = serde_json::from_str(&raw).ok()?;
    let root = PathBuf::from(parsed.root);
    if root.as_os_str().is_empty() {
        return None;
    }
    Some(root)
}

/// The root to open at startup, plus the configured-but-unreachable root when there is one.
///
/// # Why an unreachable root falls back instead of failing
///
/// If the pointer names a directory that is not there — the external drive is unplugged, the
/// folder was renamed from outside the app — the alternative to falling back is `.setup()`
/// returning `Err`, which means the app does not open at all and offers no way to fix itself.
/// The mirror is a *cache* of the server (`clear_local` exists precisely because losing it is
/// survivable), so opening at the default root and re-syncing is recoverable, while an app
/// that will not start is not.
///
/// What that costs is real and is why the second return value exists: an outbox that had
/// unpushed mutations is still sitting on the volume that is missing, and the user has to be
/// told rather than left to notice. The pointer file is deliberately NOT rewritten in this
/// case, so plugging the drive back in and restarting restores the previous root untouched.
pub fn resolve_root(anchor: &Path) -> (PathBuf, Option<PathBuf>) {
    let Some(configured) = stored_root(anchor) else {
        return (default_root(anchor), None);
    };
    if configured.is_dir() {
        return (configured, None);
    }
    tracing::warn!(
        path = %configured.display(),
        "the configured data directory is not reachable; falling back to the default root and \
         keeping the pointer file so a reconnect restores it"
    );
    (default_root(anchor), Some(configured))
}

/// Persist the choice, or remove the pointer file when the choice is the default again.
///
/// Removing rather than writing `{"root": "<default>"}` keeps "no file" meaning exactly one
/// thing (this install never moved its data), so a default install and an install that was
/// moved back are indistinguishable — which they should be.
pub fn store_root(anchor: &Path, root: &Path) -> std::io::Result<()> {
    let pointer = pointer_path(anchor);
    if root == default_root(anchor) {
        return match std::fs::remove_file(&pointer) {
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
            other => other,
        };
    }
    std::fs::create_dir_all(anchor)?;
    let body = serde_json::to_string_pretty(&StoredLocation {
        root: root.to_string_lossy().into_owned(),
    })
    .map_err(std::io::Error::other)?;
    std::fs::write(&pointer, body)
}

// ------------------------------------------------------------------------------------------------
// Validating a proposed target
// ------------------------------------------------------------------------------------------------

/// The data root that would result from the user picking `picked`.
pub fn root_for_target(picked: &Path) -> PathBuf {
    // A parent that is ALREADY our own directory name is taken at face value rather than
    // nested a second time: re-picking `D:\Data\syncra` in the folder dialog (which is what a
    // user who wants to point at an existing move does) must not produce
    // `D:\Data\syncra\syncra`.
    if picked.file_name().and_then(|name| name.to_str()) == Some(DEFAULT_SUBDIR) {
        picked.to_path_buf()
    } else {
        picked.join(DEFAULT_SUBDIR)
    }
}

/// Full pre-flight for a move: everything that can be checked before a single byte is copied.
///
/// Returns the data root to move to. **Nothing** in this function mutates the current root, so
/// a refusal here is always a no-op for the user's data — which is the property the whole
/// procedure's error story rests on.
pub fn validate_target(current_root: &Path, picked: &Path) -> Result<PathBuf, Rejection> {
    if picked.as_os_str().is_empty() {
        return Err(Rejection::Invalid("the chosen path is empty".into()));
    }
    if !picked.is_absolute() {
        return Err(Rejection::Invalid(format!(
            "{} is not an absolute path",
            picked.display()
        )));
    }
    if !picked.is_dir() {
        return Err(Rejection::Invalid(format!(
            "{} is not an existing directory",
            picked.display()
        )));
    }

    reject_unsupported_volume(picked)?;

    let target = root_for_target(picked);

    // Canonicalised comparison, not string comparison: `D:\data` and `D:\Data\..\data` are the
    // same directory and a case-insensitive filesystem makes `d:\data` one too. Getting this
    // wrong means copying a directory into itself and then deleting the result.
    let current_real = canonical(current_root);
    let target_real = canonical(&target);

    if current_real == target_real {
        return Err(Rejection::Invalid(format!(
            "{} is already the data directory",
            target.display()
        )));
    }
    if target_real.starts_with(&current_real) {
        return Err(Rejection::Invalid(format!(
            "{} is inside the current data directory",
            target.display()
        )));
    }
    if current_real.starts_with(&target_real) {
        return Err(Rejection::Invalid(format!(
            "{} contains the current data directory",
            target.display()
        )));
    }

    target_is_usable(&target)?;
    parent_is_writable(picked)?;

    Ok(target)
}

/// Best-effort canonical form. Falls back to the input when the path does not exist yet, which
/// is the normal case for the target: only its parent is guaranteed to exist.
fn canonical(path: &Path) -> PathBuf {
    if let Ok(real) = std::fs::canonicalize(path) {
        return real;
    }
    match (path.parent(), path.file_name()) {
        (Some(parent), Some(name)) => match std::fs::canonicalize(parent) {
            Ok(real_parent) => real_parent.join(name),
            Err(_) => path.to_path_buf(),
        },
        _ => path.to_path_buf(),
    }
}

/// The target root must not exist, or must be an empty directory.
///
/// It used to accept a directory holding only file names this shell uses, so that a retry
/// after a half-finished move could reuse it (security review O11). Those names are exactly
/// what a complete data directory left by another install, an earlier move or a restored backup
/// holds — possibly with an outbox of changes that were never sent — and the move then copied
/// over it on success and deleted it whole on failure. A name says nothing about whose data is
/// behind it, so anything non-empty is refused now; a failed move removes only what it created
/// itself ([`claim_destination`], [`release_destination`]), which is what keeps a retry possible
/// without this exception.
fn target_is_usable(target: &Path) -> Result<(), Rejection> {
    let mut entries = match std::fs::read_dir(target) {
        Ok(entries) => entries,
        // Not there yet: the copy step creates it.
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(()),
        Err(error) => {
            return Err(Rejection::Invalid(format!(
                "cannot read {}: {error}",
                target.display()
            )))
        }
    };

    if let Some(entry) = entries.next() {
        let entry =
            entry.map_err(|error| Rejection::Invalid(format!("cannot read an entry: {error}")))?;
        let name = entry.file_name();
        let name = name.to_string_lossy();
        let whose = if OWNED_ENTRIES.iter().any(|owned| *owned == name) {
            "which may be another data directory's"
        } else {
            "which this application did not create"
        };
        return Err(Rejection::Invalid(format!(
            "{} already contains {name}, {whose}; choose an empty folder",
            target.display()
        )));
    }
    Ok(())
}

/// A destination the move has taken for itself, and what it may remove again on failure.
#[derive(Debug)]
pub struct ClaimedDestination {
    root: PathBuf,
    /// `true` when the move created `root`; `false` when it was an existing, empty directory.
    created: bool,
    /// Every path below `root` this move created, in creation order (a directory before what
    /// is in it). Rollback removes exactly these and nothing else: a file another process put
    /// at the destination meanwhile — even under one of our own names — is not on it.
    ledger: CopyLedger,
}

/// The files and directories a copy created, recorded the moment each one is created.
#[derive(Debug, Default)]
struct CopyLedger {
    entries: Vec<(PathBuf, bool)>,
}

impl CopyLedger {
    fn created_file(&mut self, path: &Path) {
        self.entries.push((path.to_path_buf(), false));
    }

    fn created_dir(&mut self, path: &Path) {
        self.entries.push((path.to_path_buf(), true));
    }

    fn holds_file(&self, path: &Path) -> bool {
        self.entries.iter().any(|(entry, is_dir)| !is_dir && entry == path)
    }
}

impl ClaimedDestination {
    /// The claimed root.
    pub fn root(&self) -> &Path {
        &self.root
    }

    /// Copy `src` into the claimed root ([`copy_dir_all`]'s rules), recording everything it
    /// creates so [`release_destination`] can remove precisely that.
    pub fn copy_from(&mut self, src: &Path) -> std::io::Result<()> {
        copy_tree(src, &self.root, &mut self.ledger)
    }
}

/// Take `root` as the destination of a move: create it, or accept it if it is an existing
/// directory with nothing in it. Anything else is refused with nothing changed.
///
/// [`validate_target`] already checked this, but some time passes between that check and the
/// copy (the engine is shut down in between), so it is checked again at the moment it matters.
pub fn claim_destination(root: &Path) -> std::io::Result<ClaimedDestination> {
    if let Some(parent) = root.parent() {
        std::fs::create_dir_all(parent)?;
    }
    match std::fs::create_dir(root) {
        Ok(()) => Ok(ClaimedDestination {
            root: root.to_path_buf(),
            created: true,
            ledger: CopyLedger::default(),
        }),
        Err(error) if error.kind() == std::io::ErrorKind::AlreadyExists => {
            if root.is_dir() && std::fs::read_dir(root)?.next().is_none() {
                Ok(ClaimedDestination {
                    root: root.to_path_buf(),
                    created: false,
                    ledger: CopyLedger::default(),
                })
            } else {
                Err(std::io::Error::new(
                    std::io::ErrorKind::AlreadyExists,
                    format!("{} exists and is not an empty directory", root.display()),
                ))
            }
        }
        Err(error) => Err(error),
    }
}

/// Undo a failed copy into `claim`, removing only what the move put there.
///
/// Only the paths [`ClaimedDestination::copy_from`] recorded as created are removed, newest
/// first. Names are not evidence: when another process writes `syncra.db` into the destination
/// after the claim, `create_new` refuses to copy over it, and a rollback by source names would
/// then delete that foreign database. Directories are removed only once empty, so anything
/// foreign added inside one (`cache/`, the root) stays, and with it the directory. The root
/// itself goes only if the move created it and nothing else is left in it.
///
/// SQLite may leave `-wal`/`-shm`/`-journal` siblings behind after verification opened the
/// copied database; they are removed too, but only when the database file next to them is one
/// this move created.
pub fn release_destination(claim: &ClaimedDestination) -> std::io::Result<()> {
    let mut first_error = None;
    let mut note = |result: std::io::Result<()>| {
        if let Err(error) = result {
            first_error.get_or_insert(error);
        }
    };

    let db = claim.root.join(DB_FILE);
    if claim.ledger.holds_file(&db) {
        for sibling in ["syncra.db-wal", "syncra.db-shm", "syncra.db-journal"] {
            let sibling = claim.root.join(sibling);
            if !claim.ledger.holds_file(&sibling) {
                note(remove_file_if_present(&sibling));
            }
        }
    }
    for (path, is_dir) in claim.ledger.entries.iter().rev() {
        note(if *is_dir {
            remove_dir_if_empty(path)
        } else {
            remove_file_if_present(path)
        });
    }
    if claim.created {
        note(remove_dir_if_empty(&claim.root));
    }
    match first_error {
        Some(error) => Err(error),
        None => Ok(()),
    }
}

fn remove_file_if_present(path: &Path) -> std::io::Result<()> {
    match std::fs::remove_file(path) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        other => other,
    }
}

/// Remove a directory only if nothing is left in it; one still holding something is kept,
/// and that is not an error — what is in it is not ours to delete.
fn remove_dir_if_empty(path: &Path) -> std::io::Result<()> {
    match std::fs::remove_dir(path) {
        Err(error)
            if matches!(
                error.kind(),
                std::io::ErrorKind::NotFound | std::io::ErrorKind::DirectoryNotEmpty
            ) =>
        {
            Ok(())
        }
        other => other,
    }
}

/// Prove the volume accepts a write from this process, rather than assuming it from the
/// absence of a read-only attribute.
///
/// A read-only mount, a per-user ACL and a full disk all present identically to `is_dir()`;
/// each of them turns into a half-copied data directory if the first thing that discovers it
/// is the copy loop.
fn parent_is_writable(parent: &Path) -> Result<(), Rejection> {
    let probe = parent.join(".syncra-write-probe");
    let outcome = std::fs::write(&probe, b"syncra")
        .and_then(|()| std::fs::remove_file(&probe))
        .map_err(|error| {
            Rejection::Invalid(format!("{} is not writable: {error}", parent.display()))
        });
    if outcome.is_err() {
        // The write may have succeeded and the removal failed; do not leave the probe behind.
        let _ = std::fs::remove_file(&probe);
    }
    outcome
}

// ------------------------------------------------------------------------------------------------
// Volume kind
// ------------------------------------------------------------------------------------------------

/// Refuse a volume SQLite's WAL mode cannot be trusted on. See the module docs.
#[cfg(windows)]
pub fn reject_unsupported_volume(path: &Path) -> Result<(), Rejection> {
    use std::path::{Component, Prefix};
    use windows::core::{HSTRING, PCWSTR};
    use windows::Win32::Storage::FileSystem::GetDriveTypeW;

    // `winbase.h`'s `DRIVE_*` return codes. windows-rs 0.61 exports `GetDriveTypeW` but not
    // these constants (measured: `no DRIVE_FIXED in Win32::Storage::FileSystem`), so they are
    // transcribed from the header with their documented values. The names are carried along
    // so a refusal says "removable" rather than "type 2" in the log.
    const DRIVE_KINDS: [(u32, &str); 7] = [
        (0, "unknown"),
        (1, "no root directory"),
        (2, "removable"),
        (3, "fixed"),
        (4, "remote"),
        (5, "cd-rom"),
        (6, "ram disk"),
    ];
    const DRIVE_FIXED: u32 = 3;

    // Bound, not inlined: `Prefix<'_>` borrows the `PathBuf` the components came from, so a
    // temporary would be dropped at the end of the `match` statement.
    let real = canonical(path);
    let prefix = match real.components().next() {
        Some(Component::Prefix(prefix)) => prefix.kind(),
        // A path with no prefix on Windows is a relative one, and `validate_target` has
        // already refused those. Nothing to classify.
        _ => {
            return Err(Rejection::Invalid(format!(
                "{} has no drive letter",
                path.display()
            )))
        }
    };

    let drive = match prefix {
        Prefix::Disk(letter) | Prefix::VerbatimDisk(letter) => {
            format!("{}:\\", letter as char)
        }
        // A UNC path never reaches `GetDriveTypeW` usefully — it has no drive letter to ask
        // about — and it is by construction the case the WAL restriction is about.
        Prefix::UNC(..) | Prefix::VerbatimUNC(..) => {
            return Err(Rejection::UnsupportedVolume(format!(
                "{} is a network path; SQLite's WAL mode is not supported on one",
                path.display()
            )))
        }
        Prefix::DeviceNS(..) | Prefix::Verbatim(..) => {
            return Err(Rejection::UnsupportedVolume(format!(
                "{} is a device path, not a fixed disk",
                path.display()
            )))
        }
    };

    // SAFETY: `GetDriveTypeW` reads a null-terminated wide string and returns an integer. The
    // `HSTRING` owns the buffer for the duration of the call and outlives the `PCWSTR`.
    let kind = unsafe { GetDriveTypeW(PCWSTR(HSTRING::from(drive.as_str()).as_ptr())) };
    if kind == DRIVE_FIXED {
        return Ok(());
    }
    let name = DRIVE_KINDS
        .iter()
        .find_map(|(code, name)| (*code == kind).then_some(*name))
        .unwrap_or("unrecognised");
    Err(Rejection::UnsupportedVolume(format!(
        "{} is on a {name} volume (GetDriveTypeW = {kind}); SQLite's WAL mode is only          supported on a fixed disk",
        path.display()
    )))
}

/// Non-Windows: every volume is accepted, and that is a declared gap rather than a claim.
///
/// The equivalent classification is `statfs`'s `f_type` on Linux and `getmntinfo` on macOS,
/// neither of which has been measured for this project — and asserting a restriction we have
/// not tested would be worse than not asserting one. `SYNCDESKTOP.md` K11 makes Windows the
/// shipping target; this arm exists so the CI legs for the other two compile and run.
#[cfg(not(windows))]
pub fn reject_unsupported_volume(_path: &Path) -> Result<(), Rejection> {
    Ok(())
}

// ------------------------------------------------------------------------------------------------
// Copy / verify / delete
// ------------------------------------------------------------------------------------------------

/// Copy every file and sub-directory of `src` into `dst`, creating `dst` if needed.
///
/// # Why the whole directory and not a file list
///
/// The obvious implementation copies `syncra.db`, then `syncra.db-wal`, then `cache/`. That
/// list is right today and has already been wrong once in this project's history: a
/// `-wal` left behind by a run that did not close cleanly carries COMMITTED transactions, and
/// a copy that skips it silently rolls the mirror back to the last checkpoint. The same
/// argument applies to every file a later phase adds next to the database — `recent.json`
/// (`crate::jump_list`) is already one of them, and it is not in `SYNCDESKTOP.md` §10's copy
/// list either.
///
/// The data root is ours end to end, so "copy all of it" is both the safe answer and the one
/// that cannot drift. `verify_copy` below then asserts that the two files that MUST be there
/// are there, so an empty-source bug still fails loudly rather than succeeding vacuously.
///
/// # Never over an existing file
///
/// Every file is created with `create_new`, so a file already present at the destination makes
/// the copy fail instead of being overwritten (O11). The destination is claimed empty first
/// ([`claim_destination`]); this is the second line, for anything that appears in between.
///
/// A move copies through [`ClaimedDestination::copy_from`] instead, which records what it
/// creates for the rollback; this ledger-free form is kept for the tests that copy a tree
/// without a claim.
#[cfg(test)]
pub fn copy_dir_all(src: &Path, dst: &Path) -> std::io::Result<()> {
    std::fs::create_dir_all(dst)?;
    copy_tree(src, dst, &mut CopyLedger::default())
}

/// The copy itself, into an existing `dst`, recording each file and directory it creates.
fn copy_tree(src: &Path, dst: &Path, ledger: &mut CopyLedger) -> std::io::Result<()> {
    for entry in std::fs::read_dir(src)? {
        let entry = entry?;
        let from = entry.path();
        let to = dst.join(entry.file_name());
        if entry.file_type()?.is_dir() {
            match std::fs::create_dir(&to) {
                Ok(()) => ledger.created_dir(&to),
                // An existing directory is entered, never claimed: what is in it stays out of
                // the ledger, and every file copied into it is still `create_new`.
                Err(error) if error.kind() == std::io::ErrorKind::AlreadyExists && to.is_dir() => {}
                Err(error) => return Err(error),
            }
            copy_tree(&from, &to, ledger)?;
        } else {
            copy_file_new(&from, &to, ledger)?;
        }
    }
    Ok(())
}

/// Copy one file to a path that must not exist yet.
fn copy_file_new(from: &Path, to: &Path, ledger: &mut CopyLedger) -> std::io::Result<()> {
    let mut reader = std::fs::File::open(from)?;
    let mut writer = std::fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(to)?;
    // Recorded as soon as it exists: a copy that fails halfway still leaves a file that is ours.
    ledger.created_file(to);
    std::io::copy(&mut reader, &mut writer)?;
    writer.sync_all()
}

/// `syncra.db` and its WAL/SHM/journal siblings at the top of a data root.
fn is_database_file(relative: &Path) -> bool {
    matches!(
        relative.to_str(),
        Some(DB_FILE | "syncra.db-wal" | "syncra.db-shm" | "syncra.db-journal")
    )
}

/// Relative path -> size, for every file below `root` except the database files.
fn file_tree(root: &Path) -> std::io::Result<BTreeMap<PathBuf, u64>> {
    fn walk(base: &Path, dir: &Path, out: &mut BTreeMap<PathBuf, u64>) -> std::io::Result<()> {
        for entry in std::fs::read_dir(dir)? {
            let entry = entry?;
            let path = entry.path();
            if entry.file_type()?.is_dir() {
                walk(base, &path, out)?;
            } else {
                let relative = path.strip_prefix(base).unwrap_or(&path).to_path_buf();
                if !is_database_file(&relative) {
                    out.insert(relative, entry.metadata()?.len());
                }
            }
        }
        Ok(())
    }
    let mut out = BTreeMap::new();
    walk(root, root, &mut out)?;
    Ok(out)
}

/// Table name -> row count, for every user table in one database.
///
/// This is the "did the data actually arrive" half of the verification. Comparing file sizes
/// would pass for a copy that stopped inside the last page and for a copy whose `-wal` was
/// dropped; comparing what SQLite can actually read back, table by table, will not.
///
/// The connection is opened with the SQLCipher key, so a successful census additionally proves
/// the key still decrypts the file at its new address — which is the K15 assumption
/// (`syncra_sync::keystore` derives the key from the keychain SERVICE name, never from the
/// path) checked rather than believed.
pub fn table_census(db_path: &Path, key: &str) -> Result<BTreeMap<String, i64>, String> {
    let conn = db::open(db_path, key).map_err(|error| error.to_string())?;

    let integrity: String = conn
        .query_row("PRAGMA integrity_check", [], |row| row.get(0))
        .map_err(|error| error.to_string())?;
    if integrity != "ok" {
        return Err(format!("integrity_check reported: {integrity}"));
    }

    let mut census = BTreeMap::new();
    let names: Vec<String> = {
        let mut stmt = conn
            .prepare(
                "SELECT name FROM sqlite_master \
                 WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            )
            .map_err(|error| error.to_string())?;
        let rows = stmt
            .query_map([], |row| row.get::<_, String>(0))
            .map_err(|error| error.to_string())?;
        rows.collect::<Result<_, _>>()
            .map_err(|error| error.to_string())?
    };

    for name in names {
        // The name comes from `sqlite_master`, not from user input, and SQLite has no
        // parameter form for an identifier; quoting it is what keeps the FTS shadow tables
        // (`search_idx_data`, `search_idx_docsize`, …) addressable.
        let count: i64 = conn
            .query_row(&format!("SELECT count(*) FROM \"{name}\""), [], |row| {
                row.get(0)
            })
            .map_err(|error| error.to_string())?;
        census.insert(name, count);
    }

    conn.close().map_err(|(_, error)| error.to_string())?;
    Ok(census)
}

/// Assert the copy at `dst` is a faithful, openable replica of `src`.
///
/// Runs BEFORE the old directory is deleted, and its failure is what keeps that deletion from
/// happening. Three things are checked, in increasing order of strength:
///
/// 1. the mandatory members are present (`syncra.db`, and `cache/` if the source had one);
/// 2. the database opens with the SQLCipher key and passes `PRAGMA integrity_check`;
/// 3. every table holds the same number of rows as it does in the source.
///
/// (3) is the one that catches a dropped `-wal`: the committed rows that only exist in the log
/// are visible to a reader of the source and absent from a copy that left the log behind.
pub fn verify_copy(src: &Path, dst: &Path, key: &str) -> Result<(), String> {
    let dst_db = dst.join(DB_FILE);
    if !dst_db.is_file() {
        return Err(format!("{} was not copied", dst_db.display()));
    }
    if src.join(CACHE_SUBDIR).is_dir() && !dst.join(CACHE_SUBDIR).is_dir() {
        return Err(format!("{} was not copied", dst.join(CACHE_SUBDIR).display()));
    }

    // Every other file, by relative path and size. The census below proves the database (its
    // files are left out here: opening a database can checkpoint its log and change their
    // sizes without changing a row); this proves the rest — the cache, `recent.json`, staged
    // attachments — which the old directory's deletion would otherwise take with it unchecked.
    let source_tree = file_tree(src).map_err(|error| format!("cannot list the source: {error}"))?;
    let target_tree = file_tree(dst).map_err(|error| format!("cannot list the copy: {error}"))?;
    if source_tree != target_tree {
        let mut differences = Vec::new();
        for (path, size) in &source_tree {
            match target_tree.get(path) {
                Some(copied) if copied == size => {}
                Some(copied) => {
                    differences.push(format!("{}: {size} -> {copied} bytes", path.display()))
                }
                None => differences.push(format!("{}: missing", path.display())),
            }
        }
        for path in target_tree.keys() {
            if !source_tree.contains_key(path) {
                differences.push(format!("{}: absent in the source", path.display()));
            }
        }
        return Err(format!("files differ ({})", differences.join(", ")));
    }

    let source_census = table_census(&src.join(DB_FILE), key)?;
    let target_census = table_census(&dst_db, key)?;

    if source_census != target_census {
        let mut differences = Vec::new();
        for (table, expected) in &source_census {
            let actual = target_census.get(table).copied().unwrap_or(-1);
            if actual != *expected {
                differences.push(format!("{table}: {expected} -> {actual}"));
            }
        }
        for table in target_census.keys() {
            if !source_census.contains_key(table) {
                differences.push(format!("{table}: absent in the source"));
            }
        }
        return Err(format!("row counts differ ({})", differences.join(", ")));
    }
    Ok(())
}

/// Delete the directory a successful, verified move left behind.
///
/// On Windows this is the step that fails if ANY file below it is still open — which is why
/// the move procedure calls `SyncEngine::shutdown` and not just `SyncScheduler::stop`
/// (`SYNCDESKTOP.md` §10 F8/1; `syncra-sync`'s
/// `shutdown_frees_the_data_directory_for_the_f8_migration` is the crate-side proof that
/// `shutdown` is sufficient).
///
/// The caller must NOT swallow the error: a failure here leaves a second, still-encrypted copy
/// of the user's mirror on the old volume, and the user is the only one who can decide what to
/// do about that. `commands::storage::move_data_dir` reports the surviving path back to the UI.
pub fn remove_root(root: &Path) -> std::io::Result<()> {
    match std::fs::remove_dir_all(root) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        other => other,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Arc;
    use syncra_sync::keystore::{KeyStore, KEY_DB};
    use syncra_sync::{Entity, LocalMutation, MemoryKeyStore, SyncConfig, SyncEngine};
    use uuid::Uuid;

    /// A throwaway directory under the OS temp dir, removed on drop — the pattern
    /// `commands::files` and `commands::storage` already use in this crate.
    struct TempDir(PathBuf);

    impl TempDir {
        fn new() -> Self {
            let path = std::env::temp_dir().join(format!("syncra-data-dir-{}", Uuid::new_v4()));
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

    /// A keystore whose key is known to the test, so a copied database can be opened again.
    fn keystore_with_known_key(service: &str) -> (Arc<MemoryKeyStore>, String) {
        let store = Arc::new(MemoryKeyStore::new());
        let key = "0".repeat(64);
        store.set(service, KEY_DB, &key).expect("seed the key");
        (store, key)
    }

    // --------------------------------------------------------------------------------------
    // Pointer file
    // --------------------------------------------------------------------------------------

    /// No pointer file at all is the default install: the default root, and no complaint.
    #[test]
    fn a_fresh_install_resolves_to_the_default_root() {
        let temp = TempDir::new();
        let (root, unavailable) = resolve_root(temp.path());
        assert_eq!(root, temp.path().join(DEFAULT_SUBDIR));
        assert_eq!(unavailable, None);
    }

    /// A stored root that exists is used verbatim.
    #[test]
    fn a_stored_root_that_exists_wins_over_the_default() {
        let temp = TempDir::new();
        let elsewhere = temp.path().join("elsewhere").join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&elsewhere).expect("elsewhere");

        store_root(temp.path(), &elsewhere).expect("store");
        let (root, unavailable) = resolve_root(temp.path());

        assert_eq!(root, elsewhere);
        assert_eq!(unavailable, None);
    }

    /// A stored root that is gone falls back — and says so, instead of pretending the install
    /// was always at the default. The pointer file survives so a reconnect restores it.
    #[test]
    fn an_unreachable_stored_root_falls_back_and_is_reported() {
        let temp = TempDir::new();
        let missing = temp.path().join("unplugged").join(DEFAULT_SUBDIR);
        store_root(temp.path(), &missing).expect("store");

        let (root, unavailable) = resolve_root(temp.path());

        assert_eq!(root, default_root(temp.path()));
        assert_eq!(unavailable, Some(missing));
        assert!(
            pointer_path(temp.path()).is_file(),
            "the pointer must survive so reconnecting the volume restores the choice"
        );
    }

    /// Storing the default root removes the pointer instead of writing it, so "never moved"
    /// and "moved back" are the same state on disk.
    #[test]
    fn storing_the_default_root_removes_the_pointer_file() {
        let temp = TempDir::new();
        let elsewhere = temp.path().join("elsewhere");
        std::fs::create_dir_all(&elsewhere).expect("elsewhere");
        store_root(temp.path(), &elsewhere).expect("store elsewhere");
        assert!(pointer_path(temp.path()).is_file());

        store_root(temp.path(), &default_root(temp.path())).expect("store default");

        assert!(!pointer_path(temp.path()).exists());
        assert_eq!(resolve_root(temp.path()).0, default_root(temp.path()));
    }

    /// A corrupt pointer is the same decision as no pointer: fall back, do not crash.
    #[test]
    fn a_malformed_pointer_file_falls_back_to_the_default() {
        let temp = TempDir::new();
        std::fs::write(pointer_path(temp.path()), b"{ not json").expect("write");
        assert_eq!(resolve_root(temp.path()).0, default_root(temp.path()));
    }

    // --------------------------------------------------------------------------------------
    // Target validation
    // --------------------------------------------------------------------------------------

    /// The picked folder is a PARENT; the root is always our own subdirectory inside it.
    #[test]
    fn the_target_root_is_a_subdirectory_of_what_the_user_picked() {
        let picked = Path::new("D:").join("Data");
        assert_eq!(root_for_target(&picked), picked.join(DEFAULT_SUBDIR));
    }

    /// Re-picking a directory that is already named `syncra` does not nest a second one.
    #[test]
    fn picking_an_existing_syncra_directory_is_not_nested_twice() {
        let picked = Path::new("D:").join("Data").join(DEFAULT_SUBDIR);
        assert_eq!(root_for_target(&picked), picked);
    }

    /// The current root is not a legal target.
    #[test]
    fn the_current_directory_is_refused() {
        let temp = TempDir::new();
        let current = temp.path().join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&current).expect("current");

        let rejection = validate_target(&current, temp.path()).expect_err("must be refused");
        assert_eq!(rejection.code(), "DATA_DIR_INVALID");
    }

    /// A target inside the current root would be copied into itself and then deleted with it.
    #[test]
    fn a_target_inside_the_current_root_is_refused() {
        let temp = TempDir::new();
        let current = temp.path().join(DEFAULT_SUBDIR);
        let inside = current.join("nested");
        std::fs::create_dir_all(&inside).expect("inside");

        let rejection = validate_target(&current, &inside).expect_err("must be refused");
        assert_eq!(rejection.code(), "DATA_DIR_INVALID");
    }

    /// A target that CONTAINS the current root is the same trap from the other side: deleting
    /// the old directory at the end of the procedure would delete part of the new one.
    ///
    /// The fixture is deliberately literal about how that shape arises. Picking `<temp>/outer`
    /// resolves the target to `<temp>/outer/syncra`, so the current root has to be BELOW that,
    /// not merely below `<temp>/outer` — a current root at `<temp>/outer/deep/syncra` is a
    /// sibling of the target and is genuinely safe to move.
    #[test]
    fn a_target_containing_the_current_root_is_refused() {
        let temp = TempDir::new();
        let outer = temp.path().join("outer");
        let current = outer
            .join(DEFAULT_SUBDIR)
            .join("deep")
            .join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&current).expect("current");

        let rejection = validate_target(&current, &outer).expect_err("must be refused");
        assert_eq!(rejection.code(), "DATA_DIR_INVALID");
        assert!(
            rejection.message().contains("contains the current"),
            "got: {}",
            rejection.message()
        );
    }

    /// The mirror image, and the reason the check above is written against the RESOLVED target
    /// rather than against the folder the user picked: a current root that merely shares an
    /// ancestor with the target is disjoint from it, so copying and then deleting is safe and
    /// must not be refused.
    #[test]
    fn a_target_that_is_only_a_sibling_of_the_current_root_is_accepted() {
        let temp = TempDir::new();
        let outer = temp.path().join("outer");
        let current = outer.join("deep").join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&current).expect("current");

        let resolved = validate_target(&current, &outer).expect("must be accepted");
        assert_eq!(resolved, outer.join(DEFAULT_SUBDIR));
    }

    /// A directory holding somebody else's files is refused before anything is copied.
    #[test]
    fn a_target_holding_foreign_files_is_refused() {
        let temp = TempDir::new();
        let current = temp.path().join("current").join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&current).expect("current");
        let picked = temp.path().join("picked");
        std::fs::create_dir_all(picked.join(DEFAULT_SUBDIR)).expect("target root");
        std::fs::write(picked.join(DEFAULT_SUBDIR).join("taxes.xlsx"), b"x").expect("foreign");

        let rejection = validate_target(&current, &picked).expect_err("must be refused");
        assert_eq!(rejection.code(), "DATA_DIR_INVALID");
        assert!(rejection.message().contains("taxes.xlsx"));
    }

    /// O11 (inverted): this test used to assert that a target holding only our own file names
    /// is ACCEPTED, so that a half-finished attempt could be retried. That acceptance was the
    /// defect: the same names are what another install's complete data directory holds, outbox
    /// included, and the move then overwrote it on success and deleted it on failure. A target
    /// with anything in it is refused now; a failed move cleans up only what it created, so a
    /// retry does not need the exception.
    #[test]
    fn a_target_holding_our_own_file_names_is_refused() {
        let temp = TempDir::new();
        let current = temp.path().join("current").join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&current).expect("current");
        let picked = temp.path().join("picked");
        let target = picked.join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(target.join(CACHE_SUBDIR)).expect("target");
        std::fs::write(target.join(DB_FILE), b"partial").expect("partial db");

        let rejection = validate_target(&current, &picked).expect_err("must be refused");
        assert_eq!(rejection.code(), "DATA_DIR_INVALID");
        assert!(target.join(DB_FILE).is_file(), "nothing may be touched");
    }

    /// An existing but empty target root is still a legal destination.
    #[test]
    fn an_empty_existing_target_is_accepted() {
        let temp = TempDir::new();
        let current = temp.path().join("current").join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&current).expect("current");
        let picked = temp.path().join("picked");
        std::fs::create_dir_all(picked.join(DEFAULT_SUBDIR)).expect("empty target");

        let resolved = validate_target(&current, &picked).expect("must be accepted");
        assert_eq!(resolved, picked.join(DEFAULT_SUBDIR));
    }

    /// A destination that is not empty cannot be claimed, even if it appeared after
    /// validation; an empty one can, and a missing one is created.
    #[test]
    fn claiming_refuses_a_non_empty_destination() {
        let temp = TempDir::new();
        let full = temp.path().join("full");
        std::fs::create_dir_all(&full).expect("full");
        std::fs::write(full.join(DB_FILE), b"someone's data").expect("db");
        assert!(claim_destination(&full).is_err());
        assert_eq!(std::fs::read(full.join(DB_FILE)).expect("db"), b"someone's data");

        let empty = temp.path().join("empty");
        std::fs::create_dir_all(&empty).expect("empty");
        assert!(!claim_destination(&empty).expect("empty is claimable").created);

        let missing = temp.path().join("deep").join("missing");
        let claim = claim_destination(&missing).expect("missing is created");
        assert!(claim.created && missing.is_dir());
    }

    /// The copy never writes over a file that is already at the destination.
    #[test]
    fn copying_never_overwrites_an_existing_file() {
        let temp = TempDir::new();
        let src = temp.path().join("src");
        std::fs::create_dir_all(&src).expect("src");
        std::fs::write(src.join(DB_FILE), b"new").expect("src db");
        let dst = temp.path().join("dst");
        std::fs::create_dir_all(&dst).expect("dst");
        std::fs::write(dst.join(DB_FILE), b"old").expect("dst db");

        assert!(copy_dir_all(&src, &dst).is_err());
        assert_eq!(std::fs::read(dst.join(DB_FILE)).expect("dst db"), b"old");
    }

    /// Releasing a destination that existed before the move removes only what the move put
    /// there; a destination the move created goes entirely.
    #[test]
    fn releasing_removes_only_what_the_move_created() {
        let temp = TempDir::new();
        let src = temp.path().join("src");
        std::fs::create_dir_all(src.join(CACHE_SUBDIR)).expect("src");
        std::fs::write(src.join(DB_FILE), b"db").expect("db");
        std::fs::write(src.join(CACHE_SUBDIR).join("a.pdf"), b"pdf").expect("pdf");

        let existing = temp.path().join("existing");
        std::fs::create_dir_all(&existing).expect("existing");
        let mut claim = claim_destination(&existing).expect("claim");
        claim.copy_from(&src).expect("copy");
        // Something that appeared there that the move did not write.
        std::fs::write(existing.join("notes.txt"), b"keep").expect("other");
        release_destination(&claim).expect("release");
        assert!(existing.is_dir(), "a root that existed before stays");
        assert!(existing.join("notes.txt").is_file(), "what the move did not write stays");
        assert!(!existing.join(DB_FILE).exists() && !existing.join(CACHE_SUBDIR).exists());

        let created = temp.path().join("created");
        let mut claim = claim_destination(&created).expect("claim");
        claim.copy_from(&src).expect("copy");
        release_destination(&claim).expect("release");
        assert!(!created.exists(), "a root the move created goes entirely");
    }

    /// A database another process wrote into the claimed destination makes the copy fail
    /// (`create_new`), and the rollback must not then delete it because it carries our name.
    #[test]
    fn rollback_keeps_a_foreign_database_that_blocked_the_copy() {
        for preexisting in [true, false] {
            let temp = TempDir::new();
            let src = temp.path().join("src");
            std::fs::create_dir_all(src.join(CACHE_SUBDIR)).expect("src");
            std::fs::write(src.join(CACHE_SUBDIR).join("a.pdf"), b"pdf").expect("pdf");
            std::fs::write(src.join(DB_FILE), b"ours").expect("db");

            let dst = temp.path().join("dst");
            if preexisting {
                std::fs::create_dir_all(&dst).expect("dst");
            }
            let mut claim = claim_destination(&dst).expect("claim");
            // Another process, after the claim and before the copy.
            std::fs::write(dst.join(DB_FILE), b"foreign").expect("foreign db");

            assert!(claim.copy_from(&src).is_err(), "create_new refuses the foreign db");
            release_destination(&claim).expect("release");
            assert_eq!(
                std::fs::read(dst.join(DB_FILE)).expect("the foreign db survives"),
                b"foreign"
            );
            assert!(
                !dst.join(CACHE_SUBDIR).join("a.pdf").exists(),
                "what the move copied goes"
            );
        }
    }

    /// A file another process adds inside a directory the move created is kept, and so is
    /// that directory (and the root holding it).
    #[test]
    fn rollback_keeps_foreign_files_inside_directories_the_move_created() {
        let temp = TempDir::new();
        let src = temp.path().join("src");
        std::fs::create_dir_all(src.join(CACHE_SUBDIR)).expect("src");
        std::fs::write(src.join(DB_FILE), b"db").expect("db");
        std::fs::write(src.join(CACHE_SUBDIR).join("a.pdf"), b"pdf").expect("pdf");

        let dst = temp.path().join("dst");
        let mut claim = claim_destination(&dst).expect("claim");
        claim.copy_from(&src).expect("copy");
        std::fs::write(dst.join(CACHE_SUBDIR).join("foreign.bin"), b"keep").expect("foreign");

        release_destination(&claim).expect("release");
        assert_eq!(
            std::fs::read(dst.join(CACHE_SUBDIR).join("foreign.bin")).expect("kept"),
            b"keep"
        );
        assert!(!dst.join(DB_FILE).exists() && !dst.join(CACHE_SUBDIR).join("a.pdf").exists());
    }

    /// Verification compares every non-database file too: a cached blob that did not arrive
    /// whole fails it, so the old directory is not deleted over a short copy.
    #[test]
    fn verification_refuses_a_copy_with_a_short_cached_file() {
        let temp = TempDir::new();
        let key = "0".repeat(64);
        let src = temp.path().join("src");
        let conn = db::open(&src.join(DB_FILE), &key).expect("open");
        db::put_setting(&conn, "probe", "value").expect("row");
        conn.close().expect("close");
        std::fs::create_dir_all(src.join(CACHE_SUBDIR)).expect("cache");
        std::fs::write(src.join(CACHE_SUBDIR).join("42-3.pdf"), b"%PDF-1.7 full").expect("blob");

        let dst = temp.path().join("dst");
        copy_dir_all(&src, &dst).expect("copy");
        verify_copy(&src, &dst, &key).expect("a whole copy verifies");

        std::fs::write(dst.join(CACHE_SUBDIR).join("42-3.pdf"), b"%PDF").expect("truncate");
        let failure = verify_copy(&src, &dst, &key).expect_err("a short blob must fail");
        assert!(failure.contains("42-3.pdf"), "{failure}");

        std::fs::remove_file(dst.join(CACHE_SUBDIR).join("42-3.pdf")).expect("remove");
        assert!(verify_copy(&src, &dst, &key).is_err(), "a missing blob must fail");
    }

    /// A path that does not exist is refused, and the probe file the writability check uses is
    /// never left behind on a path that does.
    #[test]
    fn a_missing_target_is_refused_and_the_write_probe_leaves_nothing() {
        let temp = TempDir::new();
        let current = temp.path().join("current").join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&current).expect("current");

        let rejection =
            validate_target(&current, &temp.path().join("nope")).expect_err("must be refused");
        assert_eq!(rejection.code(), "DATA_DIR_INVALID");

        let picked = temp.path().join("picked");
        std::fs::create_dir_all(&picked).expect("picked");
        validate_target(&current, &picked).expect("must be accepted");
        assert_eq!(
            std::fs::read_dir(&picked).expect("read_dir").count(),
            0,
            "the write probe must not survive the check"
        );
    }

    /// The volume guard accepts the fixed disk the test suite itself runs from, so the check
    /// is proved to be reachable and not a blanket refusal.
    #[test]
    fn the_volume_guard_accepts_the_disk_the_tests_run_on() {
        let temp = TempDir::new();
        reject_unsupported_volume(temp.path()).expect("the temp dir is on a fixed disk");
    }

    /// A UNC path is refused on Windows without a filesystem round trip, because WAL over a
    /// network redirector is the case K15's restriction exists for.
    #[cfg(windows)]
    #[test]
    fn a_unc_path_is_refused_as_an_unsupported_volume() {
        let rejection = reject_unsupported_volume(Path::new(r"\\server\share\syncra"))
            .expect_err("a UNC path must be refused");
        assert_eq!(rejection.code(), "DATA_DIR_UNSUPPORTED");
    }

    // --------------------------------------------------------------------------------------
    // Copy, verify, delete — the procedure itself
    // --------------------------------------------------------------------------------------

    /// The whole F8/1 procedure over a real engine and a real filesystem.
    ///
    /// This is the acceptance test the phase asks for: shut the engine down, copy, verify,
    /// delete the old root, and reopen at the new one. The deletion is the load-bearing
    /// assertion — on Windows a single leaked SQLite handle makes `remove_dir_all` fail with
    /// OS error 32, which is why `SyncEngine::shutdown` (and not `SyncScheduler::stop`) is
    /// what the procedure calls.
    #[tokio::test]
    async fn an_end_to_end_move_leaves_the_old_directory_deletable_and_the_data_readable() {
        let temp = TempDir::new();
        let old_root = temp.path().join("old").join(DEFAULT_SUBDIR);
        let picked = temp.path().join("new");
        std::fs::create_dir_all(&picked).expect("picked");

        let cfg = SyncConfig::new(
            url::Url::parse("http://127.0.0.1/api/").expect("url"),
            old_root.join(DB_FILE),
        );
        let (store, key) = keystore_with_known_key(&cfg.keychain_service);
        let engine = SyncEngine::open_with_keystore(cfg, store)
            .await
            .expect("open engine");

        // Real rows, so the census below is not comparing two empty databases.
        for index in 0..3 {
            engine
                .mutate(LocalMutation::create(
                    Entity::Company,
                    Uuid::now_v7(),
                    serde_json::json!({ "name": format!("F8 move {index}") }),
                ))
                .expect("mutate");
        }
        // The other half of what moves.
        std::fs::create_dir_all(old_root.join(CACHE_SUBDIR).join("quotes")).expect("cache");
        std::fs::write(
            old_root.join(CACHE_SUBDIR).join("quotes").join("42-3.pdf"),
            b"%PDF-1.7",
        )
        .expect("blob");

        let target = validate_target(&old_root, &picked).expect("target accepted");
        assert_eq!(target, picked.join(DEFAULT_SUBDIR));

        // Step 1 of the procedure. Everything after it depends on this having happened.
        engine.shutdown().expect("shutdown");
        drop(engine);

        copy_dir_all(&old_root, &target).expect("copy");
        verify_copy(&old_root, &target, &key).expect("verify");

        remove_root(&old_root).expect(
            "the old data directory must be deletable after shutdown; a leaked handle makes \
             this fail with OS error 32 on Windows",
        );
        assert!(!old_root.exists(), "the old data directory must be gone");

        // K15's assumption, asserted from both sides rather than believed.
        //
        // The SQLCipher key is derived from the keychain SERVICE name, never from the database
        // path (`syncra_sync::keystore::ensure_db_key`), which is the entire reason a move is
        // possible at all. So the SAME key must still open the file at its new address, and a
        // different one must not.
        let reopened = SyncEngine::open_with_keystore(
            SyncConfig::new(
                url::Url::parse("http://127.0.0.1/api/").expect("url"),
                target.join(DB_FILE),
            ),
            Arc::new(MemoryKeyStore::new()),
        )
        .await;
        assert!(
            reopened.is_err(),
            "a different key must not open the moved database"
        );

        let census = table_census(&target.join(DB_FILE), &key).expect("census with the real key");
        assert_eq!(
            census.get("outbox").copied(),
            Some(3),
            "the three queued mutations must have arrived at the new location"
        );
        assert!(
            target.join(CACHE_SUBDIR).join("quotes").join("42-3.pdf").is_file(),
            "the blob cache must have moved with the database"
        );
    }

    /// The negative control for the step above: WITHOUT `shutdown`, the old directory is still
    /// held open and cannot be deleted on Windows.
    ///
    /// Windows-only on purpose — POSIX unlinks an open file happily, so the assertion is not
    /// available there and pretending otherwise would make the test lie on two of the three CI
    /// legs.
    #[cfg(windows)]
    #[tokio::test]
    async fn without_shutdown_the_old_directory_cannot_be_deleted() {
        let temp = TempDir::new();
        let old_root = temp.path().join("old").join(DEFAULT_SUBDIR);

        let cfg = SyncConfig::new(
            url::Url::parse("http://127.0.0.1/api/").expect("url"),
            old_root.join(DB_FILE),
        );
        let (store, _key) = keystore_with_known_key(&cfg.keychain_service);
        let engine = SyncEngine::open_with_keystore(cfg, store)
            .await
            .expect("open engine");
        engine
            .mutate(LocalMutation::create(
                Entity::Company,
                Uuid::now_v7(),
                serde_json::json!({ "name": "still open" }),
            ))
            .expect("mutate");

        let error = remove_root(&old_root)
            .expect_err("an open SQLite connection must keep the directory undeletable");
        assert!(
            old_root.join(DB_FILE).is_file(),
            "nothing may have been deleted: {error}"
        );

        engine.shutdown().expect("shutdown");
        drop(engine);
        remove_root(&old_root).expect("and it becomes deletable once the engine is shut down");
    }

    /// The negative control for the copy list: a copy that leaves the `-wal` behind loses
    /// COMMITTED rows, and `verify_copy` catches it.
    ///
    /// The source here is a database with an uncheckpointed log — the state a run that was
    /// killed rather than closed leaves behind, and the one case where the `-wal` is not an
    /// empty file. `copy_dir_all` takes it; the deliberately-wrong `copy_database_only` below
    /// does not, and the assertion is that the verification step tells the two apart.
    #[test]
    fn a_copy_that_drops_the_wal_loses_committed_rows_and_fails_verification() {
        let temp = TempDir::new();
        let src = temp.path().join("src").join(DEFAULT_SUBDIR);
        let key = "0".repeat(64);

        let conn = db::open(&src.join(DB_FILE), &key).expect("open");
        db::put_setting(&conn, "f8-wal-probe", "committed-in-the-log").expect("commit a row");
        // NOT closed and NOT checkpointed: the row is durable, and it is in the log, not in
        // the main database file. This is exactly the on-disk state the copy has to survive.
        let wal = src.join("syncra.db-wal");
        assert!(
            wal.is_file() && std::fs::metadata(&wal).expect("wal metadata").len() > 0,
            "the fixture needs a non-empty WAL, or this test proves nothing"
        );

        let faithful = temp.path().join("faithful").join(DEFAULT_SUBDIR);
        copy_dir_all(&src, &faithful).expect("faithful copy");

        // The tempting "optimisation": the database file and nothing else.
        let db_only = temp.path().join("db-only").join(DEFAULT_SUBDIR);
        std::fs::create_dir_all(&db_only).expect("db-only dir");
        std::fs::copy(src.join(DB_FILE), db_only.join(DB_FILE)).expect("copy the db alone");

        // The source is still open; read its census through the live connection's own file so
        // the comparison below has something to compare against.
        let source_census = table_census(&src.join(DB_FILE), &key).expect("source census");
        assert_eq!(
            source_census.get("desktop_settings").copied(),
            Some(1),
            "the committed row must be visible from the source"
        );

        verify_copy(&src, &faithful, &key).expect("a whole-directory copy must verify");

        let failure = verify_copy(&src, &db_only, &key)
            .expect_err("dropping the -wal must be caught by verification, not shipped");
        assert!(
            failure.contains("desktop_settings"),
            "the failure must name the table that lost rows, got: {failure}"
        );

        conn.close().expect("close");
    }

    /// `verify_copy` refuses a copy that is missing the database outright, so an empty source
    /// or a copy that never ran cannot pass vacuously.
    #[test]
    fn verification_refuses_a_copy_with_no_database_in_it() {
        let temp = TempDir::new();
        let src = temp.path().join("src");
        let dst = temp.path().join("dst");
        std::fs::create_dir_all(&src).expect("src");
        std::fs::create_dir_all(&dst).expect("dst");

        let failure = verify_copy(&src, &dst, &"0".repeat(64)).expect_err("must refuse");
        assert!(failure.contains(DB_FILE));
    }

    /// `remove_root` on a directory that is already gone is a success, not an error: a retry
    /// after a partly-completed move must not fail on the step that already happened.
    #[test]
    fn removing_an_absent_root_is_a_no_op() {
        let temp = TempDir::new();
        remove_root(&temp.path().join("never-existed")).expect("must be Ok");
    }

    /// The volume measurement `SYNCDESKTOP.md` §10 F8/1's acceptance criterion asks for,
    /// against a real SMB path.
    ///
    /// `#[ignore]` because it needs a reachable UNC share and a machine that grants access to
    /// it; the assertion is on the WAL journal mode, not on this project's code, so a run that
    /// cannot reach the share must not paint the gate red. Run it explicitly with
    /// `cargo test -- --ignored network_wal` and put the output in the phase report — that is
    /// what makes the "fixed disks only" restriction a measurement rather than a preference.
    ///
    /// Set `SYNCRA_WAL_PROBE_UNC` to the share to test, e.g. `\\localhost\C$\Temp`.
    ///
    /// # What this measured on 2026-09-01, and why the restriction stayed
    ///
    /// Against `\\localhost\C$\Temp` — an SMB loopback, the only share reachable from the
    /// development machine, which has a single fixed volume — the probe printed
    /// `journal_mode=wal, write=Ok(())`. SQLite did **not** refuse, and did not quietly fall
    /// back to a rollback journal either.
    ///
    /// That is an argument FOR the static restriction, not against it. A loopback share is the
    /// friendliest possible network path: the SMB server is the same machine, so the `-shm`
    /// mapping the WAL index needs is backed by a local file after all. A genuine remote server
    /// is the case SQLite's documentation rules out, and this probe shows the failure mode is
    /// SILENT — `journal_mode` answers `wal` and the write succeeds — so a runtime probe cannot
    /// be used to decide whether a volume is safe: it would pass, and then corrupt. Hence
    /// [`reject_unsupported_volume`], which decides from the volume's kind and never from a
    /// trial write. A real remote share has NOT been measured; see the phase report.
    #[test]
    #[ignore = "needs a reachable UNC share; run with --ignored and report the output"]
    fn network_wal_behaviour_is_measured_not_assumed() {
        let Ok(share) = std::env::var("SYNCRA_WAL_PROBE_UNC") else {
            panic!("set SYNCRA_WAL_PROBE_UNC to a writable UNC path");
        };
        let root = Path::new(&share).join(format!("syncra-wal-probe-{}", Uuid::new_v4()));
        std::fs::create_dir_all(&root).expect("create the probe directory on the share");

        let key = "0".repeat(64);
        let outcome = db::open(&root.join(DB_FILE), &key);
        match outcome {
            Ok(conn) => {
                let mode: String = conn
                    .query_row("PRAGMA journal_mode", [], |row| row.get(0))
                    .expect("read journal_mode");
                let write = db::put_setting(&conn, "probe", "value");
                println!("UNC probe {}: journal_mode={mode}, write={write:?}", root.display());
                let _ = conn.close();
                assert_eq!(
                    mode.to_lowercase(),
                    "wal",
                    "the share silently fell back to journal_mode={mode}; WAL is not available \
                     there, which is what the fixed-disk restriction encodes"
                );
            }
            Err(error) => println!("UNC probe {}: open failed: {error}", root.display()),
        }
        let _ = std::fs::remove_dir_all(&root);
    }
}
