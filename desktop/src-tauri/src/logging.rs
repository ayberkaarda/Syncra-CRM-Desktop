//! Log plugin wiring: release log level and PII masking (`SYNCDESKTOP.md` §9/9).
//!
//! ## The problem this closes
//!
//! `tauri_plugin_log::Builder::default()` (`lib.rs`, registered unconfigured before this
//! module existed) ships two gaps:
//!
//! * **No level filter** — its documented default is [`log::LevelFilter::Trace`]. Every crate
//!   in the dependency graph that logs through the `log` facade writes straight to
//!   `%LOCALAPPDATA%\com.syncra.desktop\logs\Syncra.log` at every level, in every build,
//!   forever. This is not hypothetical: `keyring`'s Windows/Secret Service backends log the
//!   *entry name* (service + account, not the secret value) at `Debug`, and that line was
//!   found on disk in a real build before this change.
//! * **No formatter that guarantees redaction** — the default formatter is
//!   `"{timestamp}[{target}][{level}] {message}"` with `message` passed through untouched.
//!   Nothing stops a future `tracing::debug!("{:?}", payload)` (or a third-party crate) from
//!   writing an email or phone number straight to disk.
//!
//! [`level_for_build`] and [`masking_format`] close both, and both are wired onto the *same*
//! `Builder` in `lib.rs` before any target (stdout, the log-dir file, the webview relay) is
//! attached, so every sink gets the filtered, masked stream — nothing downstream can opt out.
//!
//! ## Why a bare `tracing::warn!` reaches this pipeline at all
//!
//! This crate never installs a `tracing::Subscriber` — there is no `tracing-subscriber` or
//! `tracing-log` anywhere in `Cargo.lock`. Without one, `tracing`'s event macros are pure
//! no-ops *unless* the `log` feature is enabled on the `tracing` dependency (`Cargo.toml`),
//! which makes `tracing` emit a [`log::Record`] for every event when no subscriber is active.
//! That is what makes `events.rs`'s two `tracing::warn!` calls observable anywhere, and it is
//! also what routes them — and any future `tracing::*!` call in this crate or `syncra-sync` —
//! through the exact same masked `log` pipeline as everything else, rather than a second,
//! unfiltered path.

use std::fmt::Arguments;
use std::sync::LazyLock;

use log::{LevelFilter, Record};
use regex::Regex;
use time::macros::format_description;
use time::OffsetDateTime;

/// `Debug` in a dev build, `Info` in a release build.
///
/// Release is restricted to `Info` and above so operational events (login, sync summaries,
/// warnings, errors) still land on disk, but `Debug`/`Trace` chatter from this app's own code
/// and from every dependency that logs through the `log` facade does not. Dev builds keep
/// `Debug` rather than the plugin's own `Trace` default: the `tracing` -> `log` bridge (see
/// module docs) emits span enter/exit records at `Trace` under the `tracing::span` target,
/// which is pure noise here and would dominate the file during local development.
pub fn level_for_build() -> LevelFilter {
    if cfg!(debug_assertions) {
        LevelFilter::Debug
    } else {
        LevelFilter::Info
    }
}

/// Matches an email address (case-insensitive local part / domain).
static EMAIL: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"(?i)[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}").expect("static email regex"));

/// Matches an E.164 phone number: a mandatory `+`, then 8-15 digits, first digit non-zero
/// (ITU-T E.164 §6: max 15 digits total including the country code, no leading zero).
///
/// The leading `+` is required on purpose, not optional. Digit runs of that length are common
/// and entirely innocent in this app's own log lines — outbox counts (`3/50`), byte totals,
/// database cursors — so matching bare digits without the `+` would turn this into a
/// false-positive generator rather than a privacy control.
static PHONE_E164: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"\+[1-9]\d{7,14}").expect("static phone regex"));

/// The log-dir timestamp format `tauri-plugin-log`'s own default uses, reproduced here because
/// [`masking_format`] replaces that default outright (a `Builder::format` closure receives
/// the fully-rendered message and nothing else — there is no "run the default formatter, then
/// mask" hook to lean on).
const LOG_TIME_FORMAT: &[time::format_description::FormatItem<'_>] =
    format_description!("[[[year]-[month]-[day]][[[hour]:[minute]:[second]]");

/// Replace every email address and E.164 phone number in `text` with a fixed placeholder.
///
/// Fixed placeholders (`[email]`, `[phone]`), not "keep the original length": a masked span
/// whose length still reveals the original value's length is a weaker mask than it looks.
fn mask_pii(text: &str) -> String {
    let masked = EMAIL.replace_all(text, "[email]");
    PHONE_E164.replace_all(&masked, "[phone]").into_owned()
}

/// The `Builder::format` closure installed on the log plugin in `lib.rs`.
///
/// This is the single choke point every record passes through before `fern` hands it to any
/// target. Masking lives here — not at call sites — because a call site is exactly the thing
/// that gets forgotten (SYNCDESKTOP §9/9: "biri unutur").
pub fn masking_format(out: fern::FormatCallback, message: &Arguments, record: &Record) {
    let masked = mask_pii(&message.to_string());
    let timestamp = OffsetDateTime::now_utc()
        .format(LOG_TIME_FORMAT)
        .unwrap_or_default();
    out.finish(format_args!(
        "{timestamp}[{}][{}] {masked}",
        record.target(),
        record.level(),
    ));
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;
    use std::sync::{Arc, Mutex};

    #[test]
    fn mask_pii_redacts_email_and_e164_phone() {
        let input = "user john.doe@example.com called from +905551234567 about invoice #42";
        let masked = mask_pii(input);

        assert!(!masked.contains("john.doe@example.com"), "email leaked: {masked}");
        assert!(!masked.contains("+905551234567"), "phone leaked: {masked}");
        assert!(masked.contains("[email]"), "no email placeholder: {masked}");
        assert!(masked.contains("[phone]"), "no phone placeholder: {masked}");
        // Non-PII content is untouched.
        assert!(masked.contains("about invoice #42"));
    }

    #[test]
    fn mask_pii_leaves_plain_digit_runs_alone() {
        // No leading '+': must not be treated as a phone number (outbox counters, byte
        // totals, database cursors all look like this).
        let input = "outbox 12345678/50000000, cursor 20260831093000";
        assert_eq!(mask_pii(input), input);
    }

    /// A `Write` sink a test can read back after logging into it, shared with the boxed
    /// `log::Log` fern hands back (which owns its own clone).
    #[derive(Clone, Default)]
    struct SharedBuf(Arc<Mutex<Vec<u8>>>);

    impl Write for SharedBuf {
        fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
            self.0.lock().expect("buf mutex").extend_from_slice(buf);
            Ok(buf.len())
        }
        fn flush(&mut self) -> std::io::Result<()> {
            Ok(())
        }
    }

    /// Builds a `log::Log` wired exactly like the real one in `lib.rs`
    /// (`tauri_plugin_log::Builder::new().format(masking_format)...`), minus the Tauri
    /// `AppHandle`/target plumbing that isn't available in a unit test — the piece under test
    /// is the format closure, not `TargetKind::LogDir`'s file handling.
    fn dispatch_into(buf: SharedBuf) -> Box<dyn log::Log> {
        let (_max_level, log) = fern::Dispatch::new()
            .format(masking_format)
            .chain(fern::Output::writer(Box::new(buf), "\n"))
            .into_log();
        log
    }

    #[test]
    fn a_logged_record_carrying_pii_is_masked_on_the_way_out() {
        let buf = SharedBuf::default();
        let logger = dispatch_into(buf.clone());

        let record = Record::builder()
            .args(format_args!(
                "device linked for jane@example.com, dial +14155552671 to confirm"
            ))
            .level(log::Level::Info)
            .target("syncra_desktop_lib::logging::tests")
            .build();
        logger.log(&record);

        let output = String::from_utf8(buf.0.lock().unwrap().clone()).expect("utf8 log output");
        println!("masked log line: {output}");

        assert!(!output.contains("jane@example.com"), "email reached disk: {output}");
        assert!(!output.contains("+14155552671"), "phone reached disk: {output}");
        assert!(output.contains("[email]"));
        assert!(output.contains("[phone]"));
    }

    #[test]
    fn level_for_build_matches_cfg_debug_assertions() {
        let expected = if cfg!(debug_assertions) {
            LevelFilter::Debug
        } else {
            LevelFilter::Info
        };
        assert_eq!(level_for_build(), expected);
    }
}
