//! Opt-in clipboard capture (`SYNCDESKTOP.md` §6.4 item 6, F5-6; §9 item 6).
//!
//! §6.4: *"Clipboard (opt-in, default off): 1 s polling, regex e-mail / E.164 phone; a match →
//! a quiet tray notification 'Add as lead?'; the content is not written to disk or to the log."*
//!
//! ## §9 item 6 — how "the content is never written to disk or log" is guaranteed here
//!
//! Not by being careful. By making it impossible to be careless:
//!
//! 1. **The detector throws the text away.** [`detect`] returns a [`ClipboardHint`], a fieldless
//!    enum. There is no field on it that could hold the clipboard, so no downstream caller can
//!    accidentally forward one — the type system carries the guarantee, not a code review.
//! 2. **The notification is built from the dictionary alone.** [`notification_text`] takes a
//!    [`ClipboardHint`] and the embedded i18n strings, and the clipboard string is not one of
//!    its parameters. A toast reading "Add as lead? ada@example.com" cannot be written without
//!    changing that signature.
//! 3. **Nothing is remembered but a hash.** The poll loop has to know whether the clipboard
//!    changed since the last tick, and the obvious way — keeping the previous text — would put
//!    clipboard content in a long-lived process global. [`fingerprint`] reduces it to a `u64`
//!    instead, which answers "did it change" and nothing else.
//! 4. **No log line takes the text.** Asserted mechanically by
//!    [`self::tests::no_log_or_file_write_can_reach_the_clipboard_text`], which reads this
//!    module's own source.
//!
//! `logging.rs`'s PII mask (§9 item 9) would already redact an e-mail or an E.164 number on the
//! way out — but "it would be masked" is a weaker claim than "it is never passed", and §9 item 6
//! asks for the second one.
//!
//! ## Why the whole clipboard has to BE the match
//!
//! [`detect`] anchors: the trimmed clipboard must be exactly an address or exactly a number, not
//! merely contain one. Copying a paragraph that happens to mention an e-mail is the common case,
//! and a toast on every such copy is an app people switch off — at which point the feature is
//! worth nothing anyway. Deliberately over-strict, and recorded as such.
//!
//! ## Capabilities: nothing is granted for this
//!
//! `clipboard-manager:allow-read-text` is deliberately absent from `capabilities/default.json`.
//! Capabilities gate what the WEBVIEW may invoke; this module reads the clipboard from Rust,
//! where no ACL applies. So the webview still cannot read the clipboard at all, which is exactly
//! the narrow surface §6.3 asks for.

use std::collections::hash_map::DefaultHasher;
use std::hash::{Hash, Hasher};
use std::sync::OnceLock;
use std::time::Duration;

use regex::Regex;
use tauri::{AppHandle, Manager, Runtime};
use tauri_plugin_clipboard_manager::ClipboardExt;
use tauri_plugin_notification::NotificationExt;

use crate::state::AppState;
use crate::tray::ClipboardLabels;

/// §6.4: "1 s polling".
const POLL_INTERVAL: Duration = Duration::from_secs(1);

/// Longest clipboard the detector will even look at.
///
/// A clipboard can hold megabytes — a copied spreadsheet, a file, a document. None of that is an
/// e-mail address or a phone number, so refusing to examine it costs nothing and buys two
/// things: the regex engine never walks a large buffer once a second, and the large buffer is
/// discarded immediately instead of being carried through this module.
///
/// 320 characters is roughly four times the longest address RFC 5321 permits (254), which is
/// generous for something that must match end to end.
const MAX_EXAMINED_LEN: usize = 320;

/// What the clipboard looked like — and **nothing about what it contained**.
///
/// Fieldless on purpose; see the module doc, guarantee 1.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ClipboardHint {
    /// The clipboard is exactly an e-mail address.
    Email,
    /// The clipboard is exactly an E.164 telephone number.
    Phone,
}

/// An e-mail address, anchored.
///
/// Deliberately the pragmatic pattern rather than RFC 5322: the question here is "did the user
/// just copy an address", not "is this deliverable". `logging.rs` uses the same shape for the
/// PII mask, and the two agreeing is worth more than either being exhaustive.
fn email_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| {
        Regex::new(r"\A[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\z")
            .expect("the e-mail pattern must compile")
    })
}

/// An E.164 telephone number, anchored.
///
/// E.164 is `+`, a country code that cannot start with `0`, and at most 15 digits in total. The
/// optional single space, dot or hyphen between digits is not part of E.164 — it is how people
/// actually copy numbers out of a web page, and rejecting `+90 555 000 00 00` while accepting
/// `+905550000000` would make the feature miss the common case. The separators are not stored
/// anywhere; they only have to be tolerated.
fn phone_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| {
        Regex::new(r"\A\+[1-9](?:[ .\-]?[0-9]){7,14}\z").expect("the phone pattern must compile")
    })
}

/// Whether `text` is exactly one address or one number — and which.
///
/// The argument is borrowed and the return value carries none of it. That is the whole design:
/// everything after this call is working with a two-valued enum.
pub fn detect(text: &str) -> Option<ClipboardHint> {
    let candidate = text.trim();
    if candidate.is_empty() || candidate.len() > MAX_EXAMINED_LEN {
        return None;
    }

    if email_pattern().is_match(candidate) {
        Some(ClipboardHint::Email)
    } else if phone_pattern().is_match(candidate) {
        Some(ClipboardHint::Phone)
    } else {
        None
    }
}

/// A value that answers "has the clipboard changed since the last tick" and nothing else.
///
/// See the module doc, guarantee 3. `DefaultHasher` is not cryptographic and does not need to
/// be: this is a change detector, not a commitment — an adversary who could read this process's
/// memory would already have the clipboard itself.
fn fingerprint(text: &str) -> u64 {
    let mut hasher = DefaultHasher::new();
    text.hash(&mut hasher);
    hasher.finish()
}

/// The toast to raise for `hint`.
///
/// **The clipboard is not a parameter.** Both strings come from the app's own `desktop.json`
/// dictionaries (§0.6 — no hard-coded UI text), and the hint only chooses whether the toast is
/// raised at all; the same question is asked for an address and for a number, because the answer
/// the user gives is the same in both cases ("make this a lead").
pub fn notification_text(_hint: ClipboardHint, labels: &ClipboardLabels) -> (String, String) {
    (
        labels.add_as_lead_title.clone(),
        labels.add_as_lead_body.clone(),
    )
}

/// Whether the user has switched clipboard capture on (`DesktopSettings::clipboard_capture`).
///
/// Read on **every tick**, not captured when the loop starts: the toggle in the settings screen
/// has to take effect within a second, not after a restart. Missing state reads as `false` —
/// an opt-in that cannot be verified is off.
fn enabled<R: Runtime>(app: &AppHandle<R>) -> bool {
    app.try_state::<AppState>()
        .is_some_and(|state| state.engine.settings().clipboard_capture)
}

/// Start the poll loop. Called once from `.setup()`.
///
/// The loop always runs; what it does depends on [`enabled`]. A loop that were started and
/// stopped with the setting would need a handle, a mutex and a second source of truth for
/// "is capture on" — for a task that wakes once a second and, while the feature is off, does
/// nothing but read a boolean. It does **not** read the clipboard while capture is off, which
/// is the part that actually matters.
///
/// The first tick is consumed to seed the fingerprint: whatever was on the clipboard before the
/// app started is not something the user just copied, and greeting someone with a toast about a
/// three-day-old address would be wrong.
pub fn start<R: Runtime>(app: &AppHandle<R>) {
    let app = app.clone();

    tauri::async_runtime::spawn(async move {
        let mut ticker = tokio::time::interval(POLL_INTERVAL);
        let mut last: Option<u64> = None;

        loop {
            ticker.tick().await;

            if !enabled(&app) {
                // Nothing is read while the feature is off — not read and discarded, not read.
                // The fingerprint is dropped too, so switching capture back on re-seeds instead
                // of firing about something copied while it was off.
                last = None;
                continue;
            }

            // `read_text` errors on an empty clipboard and on one holding a non-text flavour
            // (an image, a file list); both are the normal case, not a problem to report. The
            // error is discarded without logging: `arboard`'s message can quote the clipboard
            // format and there is no reason to put it anywhere.
            let Ok(text) = app.clipboard().read_text() else {
                continue;
            };

            let current = fingerprint(&text);
            let changed = last != Some(current);
            last = Some(current);
            if !changed {
                continue;
            }

            let Some(hint) = detect(&text) else {
                continue;
            };
            // `text` is not referenced again anywhere below this line.
            drop(text);

            let (title, body) = notification_text(hint, crate::tray::clipboard_labels(&app));
            if let Err(error) = app
                .notification()
                .builder()
                .title(title)
                .body(body)
                .show()
            {
                tracing::warn!(%error, "could not show the clipboard capture notification");
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use syncra_sync::DesktopSettings;

    // --- K10: opt-in, and off by default ------------------------------------------------------

    /// `SYNCDESKTOP.md` §6.4 / K10: clipboard capture is opt-in and **default off**.
    ///
    /// Locked against the engine's own `Default`, because that is the value a fresh install and
    /// a settings row written before the field existed both get. Flip it and this test is the
    /// thing that goes red.
    #[test]
    fn clipboard_capture_is_off_by_default() {
        assert!(
            !DesktopSettings::default().clipboard_capture,
            "clipboard capture must be opt-in (K10)"
        );
    }

    /// Nothing in the shell turns it on. The only writer is `storage::update_settings`, i.e. a
    /// deliberate user action in the settings screen.
    ///
    /// The needle is assembled with `concat!` for the same reason as in
    /// [`no_log_or_file_write_can_reach_the_clipboard_text`]: this test reads the file it is
    /// written in, so a plain literal would match itself.
    #[test]
    fn nothing_in_the_shell_enables_capture_on_its_own() {
        let source = include_str!("clipboard.rs");
        let assignment = concat!("clipboard_capture", " = true");
        assert!(
            !source.contains(assignment),
            "this module must never switch the opt-in on"
        );
    }

    // --- detection ---------------------------------------------------------------------------

    #[test]
    fn an_email_address_is_detected() {
        for sample in [
            "ada@example.com",
            "  ada@example.com  ",
            "ada.lovelace+crm@sub.example.co.uk",
            "a_b-c%d@example.io",
        ] {
            assert_eq!(detect(sample), Some(ClipboardHint::Email), "{sample}");
        }
    }

    #[test]
    fn an_e164_number_is_detected() {
        for sample in [
            "+905550000000",
            "+90 555 000 00 00",
            "+1-202-555-0143",
            "+442071838750",
        ] {
            assert_eq!(detect(sample), Some(ClipboardHint::Phone), "{sample}");
        }
    }

    /// The anchoring decision, tested as a decision: text that CONTAINS an address is not a
    /// match. A toast on every copied paragraph is how this feature gets switched off.
    #[test]
    fn text_that_merely_contains_a_match_is_not_a_match() {
        for sample in [
            "write to ada@example.com about the quote",
            "Ada Lovelace <ada@example.com>",
            "call +905550000000 tomorrow",
        ] {
            assert_eq!(detect(sample), None, "{sample}");
        }
    }

    #[test]
    fn ordinary_clipboard_content_is_not_a_match() {
        for sample in [
            "",
            "   ",
            "hello",
            "https://example.com",
            "@example.com",
            "ada@",
            "555-0143",
            "0905550000000",
            "+0905550000",
            "+12",
            "+9051234567890123456",
        ] {
            assert_eq!(detect(sample), None, "{sample}");
        }
    }

    /// A large clipboard is not examined at all — no regex walk, and nothing carried forward.
    #[test]
    fn an_oversized_clipboard_is_not_examined() {
        let long = format!("{}@example.com", "a".repeat(MAX_EXAMINED_LEN));
        assert!(long.len() > MAX_EXAMINED_LEN);
        assert_eq!(detect(&long), None);
    }

    // --- §9 item 6: the content never leaves this module -------------------------------------

    /// The toast is the dictionary's two sentences and nothing else.
    ///
    /// The samples are chosen so a leak would be unmistakable: if any part of the clipboard
    /// reached the toast, one of these needles would appear in it.
    #[test]
    fn the_notification_cannot_carry_clipboard_content() {
        let labels = ClipboardLabels {
            add_as_lead_title: "Syncra".to_string(),
            add_as_lead_body: "Add as lead?".to_string(),
        };

        for (sample, hint) in [
            ("nsa-secret@example.com", ClipboardHint::Email),
            ("+905551234567", ClipboardHint::Phone),
        ] {
            assert_eq!(detect(sample), Some(hint), "sanity: {sample}");

            let (title, body) = notification_text(hint, &labels);
            assert_eq!(title, labels.add_as_lead_title);
            assert_eq!(body, labels.add_as_lead_body);

            for needle in ["nsa-secret", "example.com", "9055512", "@"] {
                assert!(
                    !title.contains(needle) && !body.contains(needle),
                    "`{needle}` leaked from the clipboard into the toast"
                );
            }
        }
    }

    /// The fingerprint the loop keeps is a number, and a different clipboard is a different
    /// number — i.e. it does the one job it exists for without holding the text.
    #[test]
    fn the_change_detector_keeps_a_number_not_the_text() {
        assert_eq!(fingerprint("ada@example.com"), fingerprint("ada@example.com"));
        assert_ne!(fingerprint("ada@example.com"), fingerprint("bob@example.com"));
    }

    /// **§9 item 6, mechanically.** No log macro and no filesystem call in this module may take
    /// the clipboard.
    ///
    /// A source-level assertion because the property is "this line does not exist", which no
    /// behavioural test can demonstrate — running the loop and finding an empty log proves only
    /// that the sample did not trip a branch.
    ///
    /// The needles are built with `concat!` rather than written as literals: this test reads the
    /// file it is written in, so a literal `"tracing::"` here would match itself and the check
    /// would pass for the wrong reason.
    #[test]
    fn no_log_or_file_write_can_reach_the_clipboard_text() {
        let source = include_str!("clipboard.rs");
        let log_macro = concat!("tracing", "::");

        // Every filesystem write path — none of them belongs in this module at all.
        for forbidden in [
            concat!("fs", "::write"),
            concat!("File", "::create"),
            concat!("OpenOptions", "::new"),
            concat!("write", "_all"),
            concat!("std::fs", "::"),
        ] {
            assert!(
                !source.contains(forbidden),
                "`{forbidden}` must not appear in the clipboard module (§9 item 6)"
            );
        }

        // Every log call, and none of them may mention the clipboard binding.
        let log_lines: Vec<&str> = source
            .lines()
            .filter(|line| line.contains(log_macro))
            .filter(|line| !line.trim_start().starts_with("//"))
            .filter(|line| !line.trim_start().starts_with("concat!"))
            .collect();

        assert!(
            !log_lines.is_empty(),
            "the scanner found no log call at all — it has stopped checking anything"
        );

        for line in log_lines {
            for needle in ["text", "candidate", "clipboard()"] {
                assert!(
                    !line.contains(needle),
                    "a log line may not carry the clipboard: {line}"
                );
            }
        }
    }
}
