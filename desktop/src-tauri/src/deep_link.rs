//! `syncra://` deep links (`SYNCDESKTOP.md` §6.4, F5-4; §9 item 5).
//!
//! §6.4: *"Deep link `syncra://{deal|lead|contact|company|ticket|quote|task|conversation}/{id}`;
//! regex `^[a-z]+/[0-9]{1,12}$`, anything else is rejected; forwarded to the existing window
//! via single-instance."*
//!
//! ## This is a security boundary, not a convenience
//!
//! A `syncra://` URL is **attacker-supplied input from outside the process**: any web page, any
//! email client, any other application on the machine can hand one to this app, and the OS will
//! start it (or wake it) with that string. It is the only untrusted input the shell accepts that
//! did not come through the sync protocol. §9 item 5 makes the refusal an acceptance criterion,
//! and [`self::tests::the_fuzz_corpus_is_rejected_in_full`] is the fifty-sample proof.
//!
//! The parser is therefore an **allowlist twice over**: the shape must match §6.4's regex
//! exactly, and the entity must be one of the eight names §6.4 lists. Everything else — path
//! traversal, percent-encoding, shell metacharacters, unicode look-alikes, oversized ids,
//! negative numbers, a bare scheme — is rejected before anything is emitted, so nothing
//! downstream has to be careful.
//!
//! Note what a rejection is *not*: it is not an error the user sees. A malicious link is not a
//! situation to explain to the person who clicked it, and a dialog would only tell an attacker
//! which of their probes parsed. It is logged and dropped.
//!
//! ## ⚠️ What this module does NOT deliver: the notification click
//!
//! §6.4 also asks for *"click → `syncra://<entity>/<id>` routing"* on native notifications.
//! **That cannot be built with the plugin this app uses** (defter O63):
//! `tauri-plugin-notification`'s desktop path hands the toast to `notify-rust` and returns —
//! there is no click callback anywhere in it. This module delivers the RECEIVING end (a link
//! from anywhere else on the machine opens the right record) and the single-instance
//! forwarding. The notification half stays open, and is not silently claimed here.

use std::sync::{Arc, Mutex, MutexGuard, OnceLock};

use regex::Regex;
use serde::Serialize;
use tauri::{AppHandle, Emitter, Listener, Manager, Runtime};
use tauri_plugin_deep_link::DeepLinkExt;

/// The scheme, as registered in `tauri.conf.json` -> `plugins.deep-link.desktop.schemes`.
pub const SCHEME: &str = "syncra";

/// Tauri event name the parsed target reaches the webview under.
///
/// Distinct from the plugin's own `deep-link://new-url`, which carries the RAW url: nothing in
/// `desktop/src` should ever see an unvalidated one, and having two events makes it impossible
/// to listen to the wrong one by accident.
pub const DEEP_LINK_EVENT: &str = "deep-link";

/// Tauri event the WEBVIEW emits, once, as soon as it is subscribed to [`DEEP_LINK_EVENT`].
///
/// The acknowledgement half of the cold-start handoff ([`LaunchHandoff`]). Emitted from the
/// resolution of `listen()`'s own promise in `desktop/src/bridge/deeplink.ts`, which is the
/// first instant at which an emitted target could actually be received; the shell holds the
/// launch target until it arrives. `deep_link::tests::the_webview_half_uses_the_same_event_names`
/// pins the two spellings together.
pub const DEEP_LINK_READY_EVENT: &str = "deep-link-ready";

/// The eight entities §6.4 lists, verbatim and in its order.
///
/// An allowlist, not a "does it look like a word" test: `entity` is interpolated into a client
/// route, so the set of values that may reach the router has to be closed and enumerated here
/// rather than inferred from the string.
pub const ENTITIES: [&str; 8] = [
    "deal",
    "lead",
    "contact",
    "company",
    "ticket",
    "quote",
    "task",
    "conversation",
];

/// §6.4's regex, transcribed character for character.
///
/// `^` and `$` are load-bearing and `regex` anchors them to the whole string, not to a line —
/// but `\n` still matters, because Rust's `regex` lets `$` match before a trailing newline
/// unless the pattern is anchored with `\z`. `syncra://deal/42\nrm -rf /` would otherwise pass
/// a `^...$` test with the payload hidden on the second line. `(?s:\z)` is not needed here
/// because `\z` alone is absolute; it is spelled out below.
fn target_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| {
        Regex::new(r"\A[a-z]+/[0-9]{1,12}\z").expect("the §6.4 deep-link pattern must compile")
    })
}

/// One accepted `syncra://<entity>/<id>` link.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct DeepLinkTarget {
    /// One of [`ENTITIES`].
    pub entity: String,
    /// The record id, exactly as it appeared — a string, not a number.
    ///
    /// Kept as text on purpose: it is going into a URL path, and re-parsing it into an integer
    /// and printing it back would silently rewrite the caller's link (`0042` becoming `42`).
    /// The regex has already guaranteed it is one to twelve ASCII digits, which is a far
    /// stronger statement than "it parsed as a number".
    pub id: String,
}

/// Why a link was refused. Not shown to anyone — it exists so the log line and the tests can
/// say which rule caught the input rather than "rejected".
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Rejection {
    /// Not a `syncra://` URL at all.
    Scheme,
    /// The part after `syncra://` does not match `^[a-z]+/[0-9]{1,12}$`.
    Shape,
    /// Well-shaped, but the entity is not one of the eight §6.4 names.
    UnknownEntity,
}

impl Rejection {
    fn reason(self) -> &'static str {
        match self {
            Self::Scheme => "not a syncra:// url",
            Self::Shape => "does not match ^[a-z]+/[0-9]{1,12}$",
            Self::UnknownEntity => "entity is not one of the eight §6.4 names",
        }
    }
}

/// Parse and vet one deep link.
///
/// The whole validation is three rules and no normalisation whatsoever — the input is not
/// trimmed, lowercased, percent-decoded or otherwise repaired. Every one of those would be a
/// way for two different strings to become the same accepted target, which is precisely how
/// allowlists are bypassed.
pub fn parse_deep_link(raw: &str) -> Result<DeepLinkTarget, Rejection> {
    let prefix = format!("{SCHEME}://");
    let rest = raw.strip_prefix(&prefix).ok_or(Rejection::Scheme)?;

    if !target_pattern().is_match(rest) {
        return Err(Rejection::Shape);
    }

    // Infallible after the regex: the pattern guarantees exactly one `/`.
    let (entity, id) = rest.split_once('/').ok_or(Rejection::Shape)?;

    if !ENTITIES.contains(&entity) {
        return Err(Rejection::UnknownEntity);
    }

    Ok(DeepLinkTarget {
        entity: entity.to_string(),
        id: id.to_string(),
    })
}

// ------------------------------------------------------------------------------------------------
// Delivery
// ------------------------------------------------------------------------------------------------

/// Raise the main window. Half of a delivery — see [`deliver`] for why both halves matter.
///
/// D-8 keeps the app alive in the tray, so "the app is running" and "the window is visible" are
/// different facts; a target emitted at a hidden window routes a page nobody can see.
fn focus_main_window<R: Runtime>(app: &AppHandle<R>) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
    }
}

/// The other half: hand the webview one already-validated target.
fn emit_target<R: Runtime>(app: &AppHandle<R>, target: &DeepLinkTarget) {
    if let Err(error) = app.emit(DEEP_LINK_EVENT, target) {
        tracing::warn!(%error, "could not emit a deep link to the webview");
    }
}

/// The URL itself is NOT logged. It is attacker-controlled text of arbitrary length and
/// content, and a log file is the last place it should be able to write to.
fn log_rejection(rejection: Rejection) {
    tracing::warn!(reason = rejection.reason(), "deep link rejected");
}

/// Deliver one link that arrived **while the app was running**.
///
/// Both halves matter. Emitting without showing would route a hidden window; showing without
/// emitting would open the app on whatever screen it was last on.
///
/// Straight to the webview, with no handoff, because by the time `on_open_url` can fire the
/// webview has been subscribed for as long as the app has been up. The cold-start url is the
/// only one with a listener problem, and it goes through [`hold_launch_url`] instead.
fn deliver<R: Runtime>(app: &AppHandle<R>, raw: &str) {
    match parse_deep_link(raw) {
        Ok(target) => {
            focus_main_window(app);
            emit_target(app, &target);
        }
        Err(rejection) => log_rejection(rejection),
    }
}

// ------------------------------------------------------------------------------------------------
// The cold-start handoff
// ------------------------------------------------------------------------------------------------

/// What has become of the url this process was LAUNCHED with.
///
/// ## Why a handoff has to exist
///
/// [`install`] runs from `.setup()`, which is long before the webview has executed a line of
/// JavaScript. **A Tauri event with no listener is not queued — it is dropped.** Emitting the
/// launch target there put it on the wire at the one moment nothing could hear it: clicking
/// `syncra://ticket/8` with the app closed opened the app on `/` and lost the link. (The
/// rejection log for a *hostile* launch url still appeared, which is what proved `get_current()`
/// itself was working and the loss was on the listener side.)
///
/// ## Why an acknowledgement event, and not a command
///
/// The obvious fix is a `take_launch_deep_link` command the webview pulls on mount. That would
/// widen the §6.2 command surface — the spec text, `scripts/check-command-wiring.mjs`'s
/// `CONTRACT`, and every document that quotes them — for one string that flows in the direction
/// the shell is *already* pushing. An event in the other direction needs none of that:
/// `core:event:default`, reached through `core:default` in `capabilities/default.json`, allows
/// the webview both `listen` and `emit`, so no capability moves either.
///
/// ## Why a state machine, and not `Option<DeepLinkTarget>`
///
/// The two facts — "a launch target exists" and "the webview is subscribed" — are produced by
/// different threads and can be observed in either order, and the target has to survive **both**
/// orders. A bare `Option` only handles one of them: whoever runs first wins and the other side
/// silently does nothing. Here, whichever of [`LaunchHandoff::remember`] /
/// [`LaunchHandoff::webview_ready`] happens *second* is the one that emits, and both take the
/// same lock, so there is no window between the check and the write. [`LaunchState::Delivered`]
/// is terminal, which is what makes a reloading webview (F5, dev HMR) re-announcing itself
/// unable to route the same launch a second time.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
enum LaunchState {
    /// No launch target, no webview. The state a normal (linkless) start stays in.
    #[default]
    Idle,
    /// A validated launch target is held, waiting for the webview to announce itself.
    Waiting(DeepLinkTarget),
    /// The webview is subscribed and nothing was waiting for it.
    Ready,
    /// The launch target has been emitted. Terminal: this process routes one launch, once.
    Delivered,
}

/// The shared cell both halves of the handoff meet in. One per [`install`] call — deliberately
/// not a `static`, so it has the app's lifetime rather than the test binary's.
#[derive(Debug, Default)]
struct LaunchHandoff {
    state: Mutex<LaunchState>,
}

impl LaunchHandoff {
    /// Poisoning is ignored on purpose: a panic in one of these two closures must not escalate
    /// into every later deep link panicking too. The worst a recovered state can do is drop one
    /// launch target, which is the failure this whole module exists to make less bad.
    fn state(&self) -> MutexGuard<'_, LaunchState> {
        self.state
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    }

    /// Record a validated launch target.
    ///
    /// `Some(target)` means the webview is already listening and the caller must emit it now;
    /// `None` means it is held (or that this launch already has a target — see below).
    fn remember(&self, target: DeepLinkTarget) -> Option<DeepLinkTarget> {
        let mut state = self.state();
        match *state {
            LaunchState::Idle => {
                *state = LaunchState::Waiting(target);
                None
            }
            LaunchState::Ready => {
                *state = LaunchState::Delivered;
                Some(target)
            }
            // A launch carrying two urls is not something to route twice — the second
            // navigation would only overwrite the first. The FIRST one wins, deterministically,
            // rather than "whichever the plugin happened to list last".
            LaunchState::Waiting(_) | LaunchState::Delivered => None,
        }
    }

    /// The webview has subscribed to [`DEEP_LINK_EVENT`]. `Some` is the target that was waiting
    /// for it, and it is handed out exactly once however many times this is called.
    fn webview_ready(&self) -> Option<DeepLinkTarget> {
        let mut state = self.state();
        match std::mem::replace(&mut *state, LaunchState::Ready) {
            LaunchState::Waiting(target) => {
                *state = LaunchState::Delivered;
                Some(target)
            }
            LaunchState::Delivered => {
                *state = LaunchState::Delivered;
                None
            }
            LaunchState::Idle | LaunchState::Ready => None,
        }
    }
}

/// What [`hold_launch_url`] decided about one launch url.
#[derive(Debug, Clone, PartialEq, Eq)]
enum LaunchOutcome {
    /// `parse_deep_link` refused it. Nothing was held and nothing will ever be emitted for it —
    /// the window is not even raised, so a hostile link cannot so much as flash the app open.
    Rejected,
    /// Accepted. Either held for the webview, or discarded because this launch already has a
    /// target; both are "the caller emits nothing right now".
    Held,
    /// Accepted, and the webview is already subscribed — emit this immediately.
    EmitNow(DeepLinkTarget),
}

/// Parse one launch url and hand it to `handoff`.
///
/// Split out of [`install`] so the whole cold-start decision is testable without an
/// `AppHandle`: everything below this line in `install` is `show()`/`emit()`, which a unit test
/// could not observe anyway.
fn hold_launch_url(handoff: &LaunchHandoff, raw: &str) -> LaunchOutcome {
    match parse_deep_link(raw) {
        Ok(target) => match handoff.remember(target) {
            Some(target) => LaunchOutcome::EmitNow(target),
            None => LaunchOutcome::Held,
        },
        Err(rejection) => {
            log_rejection(rejection);
            LaunchOutcome::Rejected
        }
    }
}

/// Start listening for `syncra://` links, and consume the one this process was launched with.
///
/// Called once from `.setup()`. Two arrival paths, both covered here:
///
/// * **while running** — `on_open_url`, which the plugin fires for a macOS `Open URL` event and
///   for the `deep-link://new-url` the single-instance handler in `crate::run` produces on
///   Windows and Linux;
/// * **cold start** — `get_current()`, the URL in this process's own command line. Without it a
///   link clicked while the app is closed starts the app on the dashboard and the link is lost.
///
/// The two paths differ in one way and only one: the cold-start target is **held** in a
/// [`LaunchHandoff`] until the webview says it is listening, because at `.setup()` time it is
/// not. See that type for why the fix is an event and not a command.
pub fn install<R: Runtime>(app: &AppHandle<R>) {
    let handle = app.clone();
    app.deep_link().on_open_url(move |event| {
        for url in event.urls() {
            deliver(&handle, url.as_str());
        }
    });

    let handoff = Arc::new(LaunchHandoff::default());

    // Registered BEFORE `get_current()` reads the launch url below. Not because the order
    // decides the outcome — the handoff is symmetric and works either way round — but because
    // a `deep-link-ready` emitted before this line has no listener at all, and that is the one
    // ordering no state machine on this side can rescue. In practice it cannot happen: the
    // webview's first JavaScript runs on the event loop, which `.setup()` precedes.
    let ready_handoff = Arc::clone(&handoff);
    let ready_handle = app.clone();
    app.listen(DEEP_LINK_READY_EVENT, move |_event| {
        if let Some(target) = ready_handoff.webview_ready() {
            // No `focus_main_window` here: the window was raised the moment the url was read,
            // milliseconds ago, and raising it again would steal focus from whatever the user
            // has clicked on since.
            emit_target(&ready_handle, &target);
        }
    });

    match app.deep_link().get_current() {
        Ok(Some(urls)) => {
            for url in urls {
                match hold_launch_url(&handoff, url.as_str()) {
                    // Raised now rather than when the target is finally emitted: the user
                    // clicked a link and expects a window, not a window once React has booted.
                    LaunchOutcome::Held => focus_main_window(app),
                    LaunchOutcome::EmitNow(target) => {
                        focus_main_window(app);
                        emit_target(app, &target);
                    }
                    LaunchOutcome::Rejected => {}
                }
            }
        }
        Ok(None) => {}
        Err(error) => tracing::warn!(%error, "could not read the launch deep link"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    // --- the happy path -------------------------------------------------------------------

    /// Every one of the eight entities §6.4 names is accepted.
    #[test]
    fn all_eight_entities_are_accepted() {
        for entity in ENTITIES {
            let target = parse_deep_link(&format!("syncra://{entity}/42"))
                .unwrap_or_else(|error| panic!("`{entity}` must be accepted: {error:?}"));
            assert_eq!(target.entity, entity);
            assert_eq!(target.id, "42");
        }
    }

    /// The regex's own bounds: one digit is the shortest legal id, twelve the longest.
    #[test]
    fn the_id_length_bounds_are_the_regex_bounds() {
        assert!(parse_deep_link("syncra://deal/1").is_ok());
        assert!(parse_deep_link("syncra://deal/999999999999").is_ok(), "12 digits");
        assert_eq!(
            parse_deep_link("syncra://deal/1234567890123"),
            Err(Rejection::Shape),
            "13 digits must be refused"
        );
    }

    /// The id keeps the spelling it arrived with — it is a path segment, not a number this
    /// module gets to normalise.
    #[test]
    fn the_id_is_not_renumbered() {
        assert_eq!(parse_deep_link("syncra://deal/0042").expect("parse").id, "0042");
    }

    // --- §9 item 5: the fuzz corpus ---------------------------------------------------------

    /// Fifty hostile inputs, none of which may parse (`SYNCDESKTOP.md` §9 item 5).
    ///
    /// The corpus is written out rather than generated: a generator would produce fifty
    /// variations of whatever its author already thought of, and the value of this list is that
    /// each entry is a specific way a URL allowlist gets bypassed in the field — traversal,
    /// encoding, case, unicode confusables, embedded terminators, oversized numbers, nested
    /// schemes, and the shell metacharacters that matter because a deep link is very often
    /// handed to something that eventually execs.
    const FUZZ_CORPUS: [&str; 50] = [
        // -- path traversal (1-6) --
        "syncra://deal/../42",
        "syncra://../deal/42",
        "syncra://deal/..%2F..%2Fetc%2Fpasswd",
        "syncra://deal/42/../../lead/1",
        "syncra://./deal/42",
        "syncra://deal/%2e%2e/42",
        // -- absolute and foreign paths (7-11) --
        "syncra:///etc/passwd",
        "syncra://C:/Windows/System32",
        "syncra://\\\\attacker\\share\\payload",
        "syncra:////deal/42",
        "syncra://deal/42/",
        // -- wrong or nested scheme (12-18) --
        "https://deal/42",
        "file:///deal/42",
        "javascript:alert(1)",
        "syncra:deal/42",
        "syncra:/deal/42",
        "syncra://syncra://deal/42",
        "SYNCRA://deal/42",
        // -- case and unicode (19-25) --
        "syncra://DEAL/42",
        "syncra://Deal/42",
        "syncra://dea\u{0142}/42",
        "syncra://ⅾeal/42",
        "syncra://deal\u{200b}/42",
        "syncra://deal/٤٢",
        "syncra://deal/４２",
        // -- id shape (26-33) --
        "syncra://deal/-1",
        "syncra://deal/+1",
        "syncra://deal/ 42",
        "syncra://deal/42 ",
        "syncra://deal/4.2",
        "syncra://deal/0x2a",
        "syncra://deal/99999999999999999999",
        "syncra://deal/",
        // -- empty and structural (34-39) --
        "",
        "syncra://",
        "syncra://deal",
        "syncra:///42",
        "syncra://42",
        "syncra:///",
        // -- injected terminators and metacharacters (40-46) --
        "syncra://deal/42\n",
        "syncra://deal/42\nsyncra://lead/1",
        "syncra://deal/42\0",
        "syncra://deal/42;rm -rf /",
        "syncra://deal/42|whoami",
        "syncra://deal/42`id`",
        "syncra://deal/42$(id)",
        // -- query, fragment, credentials, unknown entity (47-50) --
        "syncra://deal/42?redirect=https://evil.example",
        "syncra://deal/42#/settings",
        "syncra://user:pass@deal/42",
        "syncra://setting/42",
    ];

    #[test]
    fn the_fuzz_corpus_is_rejected_in_full() {
        // The count is asserted so the corpus cannot quietly shrink below what §9 asks for.
        assert_eq!(FUZZ_CORPUS.len(), 50, "§9 item 5 asks for a fifty-sample fuzz");

        let accepted: Vec<&str> = FUZZ_CORPUS
            .iter()
            .copied()
            .filter(|sample| parse_deep_link(sample).is_ok())
            .collect();

        assert!(
            accepted.is_empty(),
            "these hostile deep links were ACCEPTED: {accepted:?}"
        );
    }

    /// Every entry of the corpus is distinct — fifty samples, not one sample fifty times.
    #[test]
    fn the_fuzz_corpus_has_no_duplicates() {
        let mut unique = FUZZ_CORPUS.to_vec();
        unique.sort_unstable();
        unique.dedup();
        assert_eq!(unique.len(), FUZZ_CORPUS.len(), "the corpus repeats itself");
    }

    /// A trailing newline must not smuggle a payload past `$`.
    ///
    /// Called out separately because it is the one rejection that depends on a detail of the
    /// `regex` crate rather than on the pattern text: `$` matches before a final `\n`, so the
    /// pattern is anchored with `\z`. Swap `\z` back to `$` and this test — and only this test
    /// — goes red.
    #[test]
    fn a_trailing_newline_does_not_pass_the_anchor() {
        assert_eq!(parse_deep_link("syncra://deal/42\n"), Err(Rejection::Shape));
    }

    /// The three rules are distinguishable, so a log line says which one caught the input.
    #[test]
    fn each_rule_has_its_own_rejection() {
        assert_eq!(parse_deep_link("https://deal/42"), Err(Rejection::Scheme));
        assert_eq!(parse_deep_link("syncra://deal/x"), Err(Rejection::Shape));
        assert_eq!(
            parse_deep_link("syncra://setting/42"),
            Err(Rejection::UnknownEntity)
        );
    }

    // --- the cold-start handoff -------------------------------------------------------------
    //
    // The bug these cover, measured on the running app: `syncra://ticket/8` started the closed
    // app, the window opened, and the route stayed `/`. `get_current()` was fine — a hostile
    // launch url still logged its rejection — the target was simply emitted at a webview that
    // had not subscribed yet, and a listenerless Tauri event is dropped.

    fn ticket_8() -> DeepLinkTarget {
        DeepLinkTarget {
            entity: "ticket".to_string(),
            id: "8".to_string(),
        }
    }

    /// THE BUG. A launch target that arrives before the webview exists is kept, and handed over
    /// when the webview finally announces itself.
    #[test]
    fn a_launch_target_survives_a_webview_that_subscribes_later() {
        let handoff = LaunchHandoff::default();

        assert_eq!(
            hold_launch_url(&handoff, "syncra://ticket/8"),
            LaunchOutcome::Held,
            "nothing can be emitted yet — this is `.setup()`, the webview has no listener"
        );
        assert_eq!(
            handoff.webview_ready(),
            Some(ticket_8()),
            "the late subscriber must receive the launch target"
        );
    }

    /// The other order, which is the whole reason this is a state machine: if the webview is
    /// already subscribed when the url is read, the target goes out immediately.
    #[test]
    fn a_launch_target_read_after_the_webview_subscribed_goes_out_at_once() {
        let handoff = LaunchHandoff::default();

        assert_eq!(handoff.webview_ready(), None, "nothing is waiting yet");
        assert_eq!(
            hold_launch_url(&handoff, "syncra://ticket/8"),
            LaunchOutcome::EmitNow(ticket_8())
        );
    }

    /// One launch, one navigation. A webview that reloads (F5, dev HMR) re-announces itself,
    /// and must not be sent to the launch target again — the user may have navigated away.
    #[test]
    fn a_launch_target_is_delivered_exactly_once() {
        let held = LaunchHandoff::default();
        hold_launch_url(&held, "syncra://ticket/8");
        assert_eq!(held.webview_ready(), Some(ticket_8()));
        assert_eq!(held.webview_ready(), None, "a reload must not re-route");
        assert_eq!(held.webview_ready(), None);

        // …and by the other path, too.
        let immediate = LaunchHandoff::default();
        assert_eq!(immediate.webview_ready(), None);
        assert_eq!(
            hold_launch_url(&immediate, "syncra://ticket/8"),
            LaunchOutcome::EmitNow(ticket_8())
        );
        assert_eq!(immediate.webview_ready(), None, "already delivered");
    }

    /// A launch that somehow carries two urls routes the first, not both and not the last.
    #[test]
    fn a_second_launch_url_cannot_displace_the_first() {
        let handoff = LaunchHandoff::default();

        assert_eq!(
            hold_launch_url(&handoff, "syncra://ticket/8"),
            LaunchOutcome::Held
        );
        assert_eq!(
            hold_launch_url(&handoff, "syncra://deal/1"),
            LaunchOutcome::Held,
            "accepted, but the first target keeps the slot"
        );
        assert_eq!(handoff.webview_ready(), Some(ticket_8()));
    }

    /// The handoff does not weaken the boundary: every one of the fifty hostile samples is
    /// refused *before* anything is held, so no rejected link can be waiting to be delivered.
    #[test]
    fn no_rejected_launch_url_is_ever_held() {
        for hostile in FUZZ_CORPUS {
            let handoff = LaunchHandoff::default();
            assert_eq!(
                hold_launch_url(&handoff, hostile),
                LaunchOutcome::Rejected,
                "`{hostile}` must not be held"
            );
            assert_eq!(
                handoff.webview_ready(),
                None,
                "`{hostile}` was held and would have been delivered"
            );
        }
    }

    /// A start with no deep link at all delivers nothing, however often the webview reloads.
    #[test]
    fn an_ordinary_start_delivers_nothing() {
        let handoff = LaunchHandoff::default();
        assert_eq!(handoff.webview_ready(), None);
        assert_eq!(handoff.webview_ready(), None);
    }

    /// The two events must stay distinct — one carries a target down, the other an
    /// acknowledgement up, and collapsing them would make the webview answer itself.
    #[test]
    fn the_two_handoff_events_are_distinct() {
        assert_ne!(DEEP_LINK_EVENT, DEEP_LINK_READY_EVENT);
    }

    /// Event names are strings on both sides of the IPC and nothing links them, so the webview
    /// half is read here — the same trick as `the_scheme_is_configured_in_tauri_conf` below.
    /// Rename either side alone and this goes red instead of the deep link going quiet.
    #[test]
    fn the_webview_half_uses_the_same_event_names() {
        let bridge = include_str!("../../src/bridge/deeplink.ts");
        for event in [DEEP_LINK_EVENT, DEEP_LINK_READY_EVENT] {
            assert!(
                bridge.contains(&format!("'{event}'")),
                "src/bridge/deeplink.ts must speak the `{event}` event"
            );
        }
    }

    /// The scheme this module parses is the one the bundler will register.
    #[test]
    fn the_scheme_is_configured_in_tauri_conf() {
        let config = include_str!("../tauri.conf.json");
        assert!(
            config.contains(&format!("\"{SCHEME}\"")),
            "tauri.conf.json must declare the `{SCHEME}` scheme under plugins.deep-link"
        );
    }
}
