//! Session generations for the work the shell does on its own, outside the engine.
//!
//! The engine guards its own writes with a data generation that every wipe advances, and it
//! checks that generation under its database lock right before a response is written. That
//! generation is private to the engine and covers only what the engine writes. The shell
//! writes too: the quote PDF cache, the attachment staging queue, and the bearer token it reads
//! for uploads. None of that ever passed through the engine's check, so a download that started
//! under one user could land in the cache — and in the ledger of the next user's database —
//! after a login, a logout, a local wipe or a data directory move had happened in between.
//!
//! [`SessionGate`] is the shell-side counterpart. Every operation that changes whose data the
//! shell is looking at (`auth::login`, `auth::logout`, `storage::clear_local`,
//! `storage::move_data_dir`) runs inside a [`Transition`]. Every shell write that belongs to a
//! session takes a [`SessionStamp`] before it starts and then performs the write itself through
//! [`SessionGate::commit`], which runs the write only when no transition overlapped the interval
//! between the stamp and the commit.
//!
//! # Why the generation moves twice per transition
//!
//! A transition advances the generation when it begins **and** when it ends, and counts itself
//! as active in between. A stamp taken before the transition differs after the first bump; a
//! stamp taken while the transition runs (say, a PDF request that started while the login was
//! still waiting for the server) differs after the second; and a commit attempted while any
//! transition is active is refused outright. So a commit that succeeds proves that no
//! transition began or ended since its stamp, which is exactly "the session this work belongs
//! to is still the current one".
//!
//! # Why the write happens under the lock
//!
//! [`SessionGate::commit`] holds the read side of the lock for the whole write, and a
//! transition takes the write side to begin. Checking first and writing afterwards would leave
//! a window in which a wipe or a move runs between the check and the write; holding the lock
//! makes the transition wait for a write that already passed the check, and every write that
//! comes after the transition began is refused. The writes covered here are short (one cached
//! PDF, one staged file, one keychain read), so the wait is bounded.

use std::sync::{Arc, RwLock};

/// What the lock protects.
#[derive(Debug, Default)]
struct GateState {
    /// Advanced at the start and at the end of every transition.
    generation: u64,
    /// Transitions currently running.
    active: u32,
}

/// The session a piece of shell work was started under. Opaque on purpose: the only thing a
/// caller may do with it is hand it back to [`SessionGate::commit`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct SessionStamp(u64);

/// See the module docs.
#[derive(Debug, Default)]
pub struct SessionGate {
    state: RwLock<GateState>,
}

impl SessionGate {
    /// A gate with no transition behind it.
    pub fn new() -> Arc<Self> {
        Arc::new(Self::default())
    }

    /// The current session, to be taken before any work that will later write something that
    /// belongs to it (and before the bearer token for that work is read).
    pub fn stamp(&self) -> SessionStamp {
        SessionStamp(self.read().generation)
    }

    /// Whether `stamp` still names the current session and no transition is running.
    #[cfg(test)]
    pub fn is_current(&self, stamp: SessionStamp) -> bool {
        let state = self.read();
        state.active == 0 && state.generation == stamp.0
    }

    /// Run `work` if `stamp` still names the current session, holding the gate so no transition
    /// can begin until `work` returns. `None` means the session changed and nothing ran.
    ///
    /// `work` must not begin a transition itself; that would wait for the lock it is holding.
    pub fn commit<T>(&self, stamp: SessionStamp, work: impl FnOnce() -> T) -> Option<T> {
        let state = self.read();
        if state.active != 0 || state.generation != stamp.0 {
            return None;
        }
        let out = work();
        drop(state);
        Some(out)
    }

    /// Begin a transition. Every stamp taken before this call is stale from now on, and every
    /// commit is refused until the returned guard is dropped (which ends the transition and
    /// makes stamps taken during it stale as well).
    ///
    /// Waits for a commit that is already running to finish.
    pub fn begin(self: &Arc<Self>) -> Transition {
        {
            let mut state = self.write();
            state.generation = state.generation.wrapping_add(1);
            state.active = state.active.saturating_add(1);
        }
        Transition {
            gate: Arc::clone(self),
        }
    }

    fn read(&self) -> std::sync::RwLockReadGuard<'_, GateState> {
        // A poisoned lock means a write panicked while holding it. The state itself is two
        // integers that are only ever assigned whole, so it is still consistent.
        self.state
            .read()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    }

    fn write(&self) -> std::sync::RwLockWriteGuard<'_, GateState> {
        self.state
            .write()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    }
}

/// A running transition. Ends when dropped, on every path out of the operation that began it,
/// including an error or a panic.
#[derive(Debug)]
pub struct Transition {
    gate: Arc<SessionGate>,
}

impl Drop for Transition {
    fn drop(&mut self) {
        let mut state = self.gate.write();
        state.generation = state.generation.wrapping_add(1);
        state.active = state.active.saturating_sub(1);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_commit_with_a_fresh_stamp_runs() {
        let gate = SessionGate::new();
        let stamp = gate.stamp();
        assert_eq!(gate.commit(stamp, || 7), Some(7));
        assert!(gate.is_current(stamp));
    }

    /// A stamp taken before a transition is refused after it, whatever happened in between.
    #[test]
    fn a_stamp_from_before_a_transition_is_refused_after_it() {
        let gate = SessionGate::new();
        let stamp = gate.stamp();
        drop(gate.begin());
        let mut ran = false;
        assert_eq!(gate.commit(stamp, || ran = true), None);
        assert!(!ran, "the work must not run under a stale stamp");
    }

    /// The case a single bump would miss: the work starts while the transition is still
    /// running, and tries to commit after it ended.
    #[test]
    fn a_stamp_taken_during_a_transition_is_refused_after_it() {
        let gate = SessionGate::new();
        let transition = gate.begin();
        let stamp = gate.stamp();
        assert_eq!(gate.commit(stamp, || ()), None, "refused while it runs");
        drop(transition);
        assert_eq!(
            gate.commit(stamp, || ()),
            None,
            "and refused after it ended"
        );
        assert!(!gate.is_current(stamp));
    }

    /// Two overlapping transitions keep the gate closed until the last one ends.
    #[test]
    fn overlapping_transitions_keep_the_gate_closed_until_both_end() {
        let gate = SessionGate::new();
        let first = gate.begin();
        let second = gate.begin();
        drop(first);
        let stamp = gate.stamp();
        assert_eq!(gate.commit(stamp, || ()), None);
        drop(second);
        let fresh = gate.stamp();
        assert_eq!(gate.commit(fresh, || ()), Some(()));
    }

    /// A transition that begins while a commit is running waits for it, so the write that
    /// passed the check finishes before the session changes, and nothing after the change can
    /// use the old stamp.
    #[test]
    fn a_transition_waits_for_a_running_commit() {
        use std::sync::mpsc;
        use std::time::Duration;

        let gate = SessionGate::new();
        let stamp = gate.stamp();
        let (entered_tx, entered_rx) = mpsc::channel();
        let (release_tx, release_rx) = mpsc::channel::<()>();

        let committing = {
            let gate = Arc::clone(&gate);
            std::thread::spawn(move || {
                gate.commit(stamp, || {
                    entered_tx.send(()).expect("signal");
                    release_rx.recv().expect("release");
                })
            })
        };
        entered_rx.recv().expect("the commit is running");

        let (began_tx, began_rx) = mpsc::channel();
        let beginning = {
            let gate = Arc::clone(&gate);
            std::thread::spawn(move || {
                let transition = gate.begin();
                began_tx.send(()).expect("signal");
                drop(transition);
            })
        };
        assert!(
            began_rx.recv_timeout(Duration::from_millis(200)).is_err(),
            "the transition must not begin while a commit holds the gate"
        );
        release_tx.send(()).expect("release");
        assert_eq!(committing.join().expect("commit thread"), Some(()));
        began_rx
            .recv_timeout(Duration::from_secs(5))
            .expect("the transition begins once the commit is done");
        beginning.join().expect("transition thread");
        assert_eq!(gate.commit(stamp, || ()), None);
    }
}
