//! Which local files the webview may ask the shell to read and upload.
//!
//! `files::attach_from_paths` takes a list of paths from the webview and copies each file into
//! the attachment queue and onto the server. Before this registry existed it took **any**
//! string: whatever script ran in the webview could name `C:\Users\…\Documents\anything.pdf`
//! and the shell would read it and upload it with the user's token. The webview has no business
//! choosing paths at all — the only legitimate source of a path is the native layer: the OS
//! drag-drop event the runtime delivers to Rust, or a native file dialog run from Rust.
//!
//! So the native layer records what it produced here ([`DropGrants::grant`]), and
//! `attach_from_paths` spends one grant per path ([`DropGrants::take`]). A path that the native
//! layer never produced is refused, whatever its spelling.
//!
//! # Exact match, single use, short life
//!
//! * The comparison is on the exact path the runtime handed out. The webview receives the same
//!   paths in the `tauri://drag-drop` payload and sends them back unchanged, so the legitimate
//!   flow always matches; another spelling of the same file (case, `\\?\`, `..`) does not,
//!   which costs nothing and removes every normalisation question.
//! * A grant is spent by the first attach that uses it. Dropping the same file twice is two
//!   OS events and therefore two grants; replaying one call is not.
//! * Grants expire after [`GRANT_TTL`] and at most [`MAX_GRANTS`] are kept, so a drop that the
//!   webview never acted on does not stay usable for the rest of the session.
//!
//! # Ordering against the event the webview sees
//!
//! The runtime emits `tauri://drag-drop` to the webview and then calls the Rust window event
//! handlers, both synchronously on the main thread (`tauri` `manager/window.rs`, the
//! `on_window_event` closure). The webview's `invoke` is handled on the same thread afterwards,
//! so the grant is normally in place first. [`DropGrants::take_waiting`] still waits briefly
//! for a missing grant, so a different scheduling on another platform degrades to a short
//! delay instead of a refused drop.

use std::path::{Path, PathBuf};
use std::sync::Mutex;
use std::time::{Duration, Instant};

/// How long a native path stays usable.
pub const GRANT_TTL: Duration = Duration::from_secs(10 * 60);

/// The most grants kept at once; the oldest are dropped first.
pub const MAX_GRANTS: usize = 512;

/// How long [`DropGrants::take_waiting`] waits for a grant that has not arrived yet.
pub const GRANT_WAIT: Duration = Duration::from_secs(2);

/// See the module docs.
#[derive(Debug, Default)]
pub struct DropGrants {
    grants: Mutex<Vec<(PathBuf, Instant)>>,
    arrived: tokio::sync::Notify,
}

impl DropGrants {
    pub fn new() -> Self {
        Self::default()
    }

    /// Record paths the native layer produced (a drop event, a native dialog).
    pub fn grant<'a>(&self, paths: impl IntoIterator<Item = &'a PathBuf>) {
        self.grant_at(paths, Instant::now());
    }

    fn grant_at<'a>(&self, paths: impl IntoIterator<Item = &'a PathBuf>, now: Instant) {
        {
            let mut grants = self.lock();
            prune(&mut grants, now);
            for path in paths {
                grants.push((path.clone(), now));
            }
            let excess = grants.len().saturating_sub(MAX_GRANTS);
            if excess > 0 {
                grants.drain(..excess);
            }
        }
        self.arrived.notify_waiters();
    }

    /// Spend one grant for `path`. `false` when the native layer never produced it, it was
    /// already used, or it expired.
    pub fn take(&self, path: &Path) -> bool {
        self.take_at(path, Instant::now())
    }

    fn take_at(&self, path: &Path, now: Instant) -> bool {
        let mut grants = self.lock();
        prune(&mut grants, now);
        // Byte-for-byte, not `Path::eq`: the latter folds a drive letter's case and separators,
        // and the point here is that only the exact string the runtime produced is accepted.
        match grants
            .iter()
            .position(|(granted, _)| granted.as_os_str() == path.as_os_str())
        {
            Some(index) => {
                grants.remove(index);
                true
            }
            None => false,
        }
    }

    /// [`DropGrants::take`], waiting up to `wait` for the grant to arrive.
    pub async fn take_waiting(&self, path: &Path, wait: Duration) -> bool {
        let deadline = tokio::time::Instant::now() + wait;
        loop {
            // Registered before the check so a grant that arrives between the check and the
            // wait still wakes it.
            let notified = self.arrived.notified();
            tokio::pin!(notified);
            notified.as_mut().enable();
            if self.take(path) {
                return true;
            }
            if tokio::time::timeout_at(deadline, notified).await.is_err() {
                return self.take(path);
            }
        }
    }

    /// Forget every grant. Called when the session changes: a file the previous user dropped
    /// is not the next user's to upload.
    pub fn clear(&self) {
        self.lock().clear();
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, Vec<(PathBuf, Instant)>> {
        self.grants
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    }
}

fn prune(grants: &mut Vec<(PathBuf, Instant)>, now: Instant) {
    grants.retain(|(_, at)| now.saturating_duration_since(*at) < GRANT_TTL);
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_granted_path_is_accepted_once() {
        let grants = DropGrants::new();
        let path = PathBuf::from(r"C:\Users\someone\Desktop\offer.pdf");
        grants.grant([&path]);
        assert!(grants.take(&path));
        assert!(!grants.take(&path), "a grant is spent by its first use");
    }

    #[test]
    fn a_path_the_native_layer_never_produced_is_refused() {
        let grants = DropGrants::new();
        grants.grant([&PathBuf::from(r"C:\drop\a.pdf")]);
        assert!(!grants.take(Path::new(r"C:\Users\someone\.ssh\id_rsa.txt")));
    }

    /// Another spelling of a granted file is not the granted path.
    #[test]
    fn another_spelling_of_a_granted_path_is_refused() {
        let grants = DropGrants::new();
        let path = PathBuf::from(r"C:\drop\a.pdf");
        grants.grant([&path]);
        for other in [
            r"c:\drop\a.pdf",
            r"\\?\C:\drop\a.pdf",
            r"C:\drop\x\..\a.pdf",
        ] {
            assert!(!grants.take(Path::new(other)), "{other}");
        }
        assert!(grants.take(&path));
    }

    /// Dropping the same file twice is two events and two grants.
    #[test]
    fn two_drops_of_the_same_file_are_two_grants() {
        let grants = DropGrants::new();
        let path = PathBuf::from(r"C:\drop\a.pdf");
        grants.grant([&path]);
        grants.grant([&path]);
        assert!(grants.take(&path));
        assert!(grants.take(&path));
        assert!(!grants.take(&path));
    }

    #[test]
    fn a_grant_expires() {
        let grants = DropGrants::new();
        let path = PathBuf::from(r"C:\drop\a.pdf");
        let start = Instant::now();
        grants.grant_at([&path], start);
        assert!(!grants.take_at(&path, start + GRANT_TTL + Duration::from_secs(1)));
    }

    #[test]
    fn the_registry_is_bounded() {
        let grants = DropGrants::new();
        let paths: Vec<PathBuf> = (0..MAX_GRANTS + 10)
            .map(|i| PathBuf::from(format!(r"C:\drop\{i}.pdf")))
            .collect();
        grants.grant(paths.iter());
        assert!(
            !grants.take(&paths[0]),
            "the oldest grants are dropped first"
        );
        assert!(grants.take(&paths[MAX_GRANTS + 9]));
    }

    #[test]
    fn clearing_forgets_every_grant() {
        let grants = DropGrants::new();
        let path = PathBuf::from(r"C:\drop\a.pdf");
        grants.grant([&path]);
        grants.clear();
        assert!(!grants.take(&path));
    }

    #[tokio::test]
    async fn waiting_picks_up_a_grant_that_arrives_late() {
        let grants = std::sync::Arc::new(DropGrants::new());
        let path = PathBuf::from(r"C:\drop\late.pdf");
        let granting = {
            let grants = std::sync::Arc::clone(&grants);
            let path = path.clone();
            tokio::spawn(async move {
                tokio::time::sleep(Duration::from_millis(50)).await;
                grants.grant([&path]);
            })
        };
        assert!(grants.take_waiting(&path, GRANT_WAIT).await);
        granting.await.expect("granting task");
    }

    #[tokio::test]
    async fn waiting_gives_up_on_a_path_that_never_arrives() {
        let grants = DropGrants::new();
        assert!(
            !grants
                .take_waiting(Path::new(r"C:\nowhere.pdf"), Duration::from_millis(50))
                .await
        );
    }
}
