//! `os::*` — the OS-integration slice of the command surface (`SYNCDESKTOP.md` §6.2, §6.4).
//!
//! Three of the four `os::*` commands live here: [`notify`], [`set_badge`] and
//! [`set_autostart`]. `register_hotkey` is **deliberately absent** — the global shortcut it
//! registers has to be claimed at `.setup()` time (and released on conflict), which is a
//! different lifetime from a one-shot command call; F5-3 owns it together with the
//! quick-capture window. An empty stub here would have been a command the UI could call and
//! nothing would happen, which is worse than the command not existing.
//!
//! ## What this module does NOT own
//!
//! * **Plugin registration.** `tauri_plugin_notification`, `tauri_plugin_autostart` and
//!   `tauri_plugin_window_state` are all registered in `lib.rs` already. This module is the
//!   command surface over them, not their setup.
//! * **`window-state`.** It needs no code at all: the plugin restores on `on_window_ready`
//!   and saves on `RunEvent::Exit` by itself. See the phase report for the one real gap
//!   (nothing ever reaches `RunEvent::Exit` while D-8 prevents every close and no Quit item
//!   exists yet, so the state file is never written).
//! * **Deciding *when* to notify.** Detecting a new `notifications` row is the caller's job;
//!   the engine's `TablesChanged` stream already carries the signal and this module must not
//!   grow a second poller beside it.
//! * **Click-through routing** (`syncra://<entity>/<id>`). `tauri-plugin-notification`'s
//!   desktop `show()` has no click callback at all (it hands the toast to `notify-rust` and
//!   returns), so the routing cannot be attached here; F5-4 owns the deep-link end.
//!
//! ## Where the notification text comes from
//!
//! Nowhere in this file. [`NotificationInput`] carries the strings the caller read off the
//! `notifications` row, and [`notification_text`] returns them **verbatim** — the shell owns
//! no title, no body, no fallback label, and cannot: `notifications.data` is stored in key
//! mode (`{type, title_key, body_key, params, link, meta}`, see
//! `backend/app/Notifications/CrmNotification.php::toArray`), so rendering it needs the i18n
//! dictionaries, which live in `frontend/src/i18n/**` and are reachable only from the
//! webview. An unresolved payload is therefore **refused**, not labelled: substituting a
//! constant here is exactly the hard-coded UI string §0.6 forbids.

use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Manager, Runtime};
use tauri_plugin_autostart::ManagerExt;
use tauri_plugin_notification::NotificationExt;

use super::{CommandError, CommandResult};

/// Label of the shell's main window, as declared in `tauri.conf.json`.
///
/// The badge belongs to the taskbar/dock entry, which the main window owns; the
/// quick-capture window (F5-3) must never carry one.
const MAIN_WINDOW: &str = "main";

// ------------------------------------------------------------------------------------------------
// notify
// ------------------------------------------------------------------------------------------------

/// One notification to raise, as the caller read it off a `notifications` row.
///
/// `title` and `body` are already resolved text. They have to be: the row's `data` column is
/// written in key mode for every notification the CRM sends (`title_key`/`body_key`/`params`,
/// `CrmNotification::toArray`), deliberately so that a row read years later renders in the
/// reader's language rather than the sender's. Resolving those keys needs `i18next` and the
/// four `desktop`/`notifications` dictionaries, i.e. the webview — which is why this struct
/// takes text rather than the raw row.
#[derive(Debug, Clone, PartialEq, Eq, Deserialize, Serialize)]
pub struct NotificationInput {
    /// The row's rendered title.
    pub title: String,
    /// The row's rendered body.
    pub body: String,
}

/// The exact text handed to the OS.
///
/// A separate type from [`NotificationInput`] only so that the "what did we actually show"
/// question has something a test can assert against without opening a real toast.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct NotificationText {
    /// Shown as the toast summary.
    pub title: String,
    /// Shown as the toast body.
    pub body: String,
}

/// Validate one payload and hand back the text to display.
///
/// Whitespace around a field is trimmed — that is normalisation, and the only transformation
/// applied. Nothing is added, replaced or defaulted.
///
/// An empty `title` or `body` is a refusal on purpose, for two reasons. It means the caller
/// failed to resolve the row (a key-mode payload that reached here unrendered), which is a
/// bug worth surfacing rather than a blank toast. And an empty `title` specifically would not
/// even stay empty: `tauri-plugin-notification`'s desktop `show()` substitutes
/// `config.product_name` for a missing title, so "let it through" would silently ship a toast
/// titled *Syncra* with no explanation of what happened — a constant label chosen by the
/// shell, which is the thing §0.6 forbids.
///
/// A field that is still an i18n **key** is refused for the same reason, and that case is not
/// hypothetical: `desktop/src/platform/data/mappers.ts::mapNotification` currently falls back
/// to `str(data.title) ?? str(data.title_key) ?? ''`, i.e. it hands the raw key through when
/// the row is in key mode — which is every CRM notification. Without this check the user
/// would get a toast reading `notifications.deal_assigned.title`.
pub fn notification_text(input: &NotificationInput) -> CommandResult<NotificationText> {
    let title = input.title.trim();
    let body = input.body.trim();

    if title.is_empty() {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            "notification title is empty — the row was not resolved before it reached notify()",
        ));
    }
    if body.is_empty() {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            "notification body is empty — the row was not resolved before it reached notify()",
        ));
    }
    if looks_like_an_unresolved_key(title) || looks_like_an_unresolved_key(body) {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            "notification text is still an i18n key — resolve title_key/body_key with the row's \
             params before calling notify()",
        ));
    }

    Ok(NotificationText {
        title: title.to_string(),
        body: body.to_string(),
    })
}

/// Whether `text` is one of the i18n keys `notifications.data` stores in key mode, rather
/// than rendered text.
///
/// Matched exactly, not heuristically: every key the backend writes is built by
/// `CrmNotification` as `notifications.<snake_case>.title` or `.body`
/// (`backend/app/Notifications/*.php`), so the shape is closed and a real sentence cannot
/// collide with it — no notification title is a single whitespace-free token beginning
/// `notifications.` and ending `.title`. A loose "looks like a dotted identifier" test was
/// rejected for exactly that reason: it would eventually refuse a legitimate body that
/// happened to be a bare hostname.
///
/// Written by hand rather than with `regex` (a dependency this crate already has) because the
/// grammar is three `str` splits and a compiled pattern would need a lazy static to be worth
/// anything.
fn looks_like_an_unresolved_key(text: &str) -> bool {
    let Some(rest) = text.strip_prefix("notifications.") else {
        return false;
    };
    let Some((name, leaf)) = rest.rsplit_once('.') else {
        return false;
    };

    matches!(leaf, "title" | "body")
        && !name.is_empty()
        && name
            .chars()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_')
}

/// Raise one native notification (`SYNCDESKTOP.md` §6.4).
///
/// The caller decides *whether* to call this; the engine's `TablesChanged` event for
/// `notification` is the signal it should be watching, and no polling loop belongs here.
///
/// Note that `show()` spawns the actual OS call onto Tauri's runtime and returns immediately,
/// so a resolved promise means "handed to the OS", not "displayed". There is no callback for
/// the click either (see the module doc), so the `syncra://` routing §6.4 asks for cannot be
/// attached from this command.
#[tauri::command]
pub fn notify<R: Runtime>(app: AppHandle<R>, notification: NotificationInput) -> CommandResult<()> {
    let text = notification_text(&notification)?;

    app.notification()
        .builder()
        .title(text.title)
        .body(text.body)
        .show()
        .map_err(|e| CommandError::new("OS_ERROR", format!("could not show notification: {e}")))
}

// ------------------------------------------------------------------------------------------------
// set_badge
// ------------------------------------------------------------------------------------------------

/// Translate a caller's count into the argument [`tauri::Window::set_badge_count`] wants.
///
/// * `0` clears the badge (`None`) — the documented "no badge" value, and the one the unread
///   counter naturally reaches when the last notification is read.
/// * A negative count is refused rather than clamped to zero: it can only come from a
///   counter that went wrong upstream, and silently rendering that as "all read" would hide
///   the bug behind a plausible-looking badge.
/// * Anything positive passes through unchanged. No ceiling is imposed here — every platform
///   that supports the badge already renders large numbers its own way, and inventing a cap
///   would be a UI decision this layer has no business making.
pub fn badge_count(count: i64) -> CommandResult<Option<i64>> {
    if count < 0 {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            format!("badge count must not be negative (got {count})"),
        ));
    }
    if count == 0 {
        return Ok(None);
    }
    Ok(Some(count))
}

/// Set (or clear, with `0`) the taskbar/dock badge on the main window.
///
/// ⚠️ **Windows is a no-op.** `tauri::Window::set_badge_count` documents "Windows:
/// Unsupported, use `set_overlay_icon` instead", and `tauri-runtime-wry`'s
/// `WindowMessage::SetBadgeCount` arm compiles to nothing at all outside iOS/macOS/Linux —
/// the call still returns `Ok(())`. So on the project's primary platform this command
/// succeeds and shows nothing. That is reported rather than papered over: the Windows path
/// needs a rendered numbered overlay image, which is a design decision (and an image
/// dependency) this turn is not authorised to take.
#[tauri::command]
pub fn set_badge<R: Runtime>(app: AppHandle<R>, count: i64) -> CommandResult<()> {
    let value = badge_count(count)?;

    let window = app.get_webview_window(MAIN_WINDOW).ok_or_else(|| {
        CommandError::new("OS_ERROR", format!("no `{MAIN_WINDOW}` window to badge"))
    })?;

    #[cfg(windows)]
    tracing::debug!(
        count,
        "set_badge: taskbar badges are unsupported on Windows (set_overlay_icon is the \
         platform's equivalent) — the call is a no-op here"
    );

    window
        .set_badge_count(value)
        .map_err(|e| CommandError::new("OS_ERROR", format!("could not set the badge: {e}")))
}

// ------------------------------------------------------------------------------------------------
// set_autostart
// ------------------------------------------------------------------------------------------------

/// Whether the OS entry has to be touched to reach `desired` from `current`.
///
/// `None` means "already there, do nothing". Autostart is persisted by the OS itself (a
/// registry value on Windows, a launch agent on macOS, a `.desktop` file on Linux), so a
/// settings screen that saves on every keystroke would otherwise rewrite it on every save.
pub fn autostart_change(current: bool, desired: bool) -> Option<bool> {
    (current != desired).then_some(desired)
}

/// Turn launch-at-login on or off, and report back the state the OS actually holds.
///
/// **Opt-in (`SYNCDESKTOP.md` §6.4), and structurally so.** Registering
/// `tauri_plugin_autostart` does not enable anything — its `Builder::build` only builds an
/// `AutoLaunch` and `app.manage()`s it — so the only path to an enabled entry in this whole
/// crate is an explicit `set_autostart(true)` from the user. `autostart_is_opt_in` in the
/// tests below asserts that `lib.rs` never reaches for `autolaunch()`, which is the only way
/// that could change.
///
/// The return value is read back from the OS rather than echoing `enabled`, so a write that
/// was refused (a locked-down registry, a read-only launch agent directory) surfaces as a
/// disagreeing answer instead of a confident lie.
#[tauri::command]
pub fn set_autostart<R: Runtime>(app: AppHandle<R>, enabled: bool) -> CommandResult<bool> {
    let manager = app.autolaunch();

    let current = manager.is_enabled().map_err(|e| {
        CommandError::new(
            "OS_ERROR",
            format!("could not read the autostart entry: {e}"),
        )
    })?;

    if let Some(desired) = autostart_change(current, enabled) {
        let result = if desired {
            manager.enable()
        } else {
            manager.disable()
        };
        result.map_err(|e| {
            CommandError::new(
                "OS_ERROR",
                format!("could not write the autostart entry: {e}"),
            )
        })?;
    }

    manager.is_enabled().map_err(|e| {
        CommandError::new(
            "OS_ERROR",
            format!("could not read the autostart entry: {e}"),
        )
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn input(title: &str, body: &str) -> NotificationInput {
        NotificationInput {
            title: title.to_string(),
            body: body.to_string(),
        }
    }

    // --- notify -----------------------------------------------------------------------------

    /// The shell displays the row's own words and nothing else. Three unrelated fixtures,
    /// each shaped like a resolved `notifications.data` payload for a different
    /// `NotificationType`: whatever goes in comes out byte-identical, so no constant of this
    /// module's can be hiding in the output.
    #[test]
    fn notification_text_is_the_row_text_verbatim() {
        let rows = [
            (
                "Yeni fırsat atandı",
                "Acme Ltd — 120.000 ₺ fırsatı size atandı.",
            ),
            (
                "SLA warning",
                "Ticket #4021 breaches its first-response SLA in 30 minutes.",
            ),
            (
                "Angebot genehmigt",
                "Angebot Q-2026-0114 wurde vom Kunden angenommen.",
            ),
        ];

        for (title, body) in rows {
            let text = notification_text(&input(title, body)).expect("resolved row is accepted");
            assert_eq!(text.title, title);
            assert_eq!(text.body, body);
        }
    }

    /// Surrounding whitespace is normalised away, but nothing inside the strings is touched —
    /// normalisation, not rewriting.
    #[test]
    fn notification_text_only_trims() {
        let text = notification_text(&input("  Task reminder  ", "\n Call back Ada Lovelace \t"))
            .expect("padded row is accepted");
        assert_eq!(text.title, "Task reminder");
        assert_eq!(text.body, "Call back Ada Lovelace");
    }

    /// A payload that arrived unresolved is REFUSED, not given a default label. This is the
    /// hard-code guard: if this module ever grows a fallback title or body, this test is what
    /// turns red. (`tauri-plugin-notification` would otherwise title the toast with
    /// `product_name` on its own — a constant the user never asked for.)
    #[test]
    fn an_unresolved_notification_is_refused_not_labelled() {
        for (title, body) in [
            ("", "Acme Ltd — 120.000 ₺ fırsatı size atandı."),
            ("Yeni fırsat atandı", ""),
            ("   ", "   "),
        ] {
            let err = notification_text(&input(title, body)).expect_err("must be refused");
            assert_eq!(err.code, "VALIDATION_ERROR");
        }
    }

    /// A payload that arrived as the raw i18n key is refused too — the failure mode
    /// `mapNotification`'s `str(data.title) ?? str(data.title_key)` fallback produces today
    /// for every key-mode row, i.e. every CRM notification.
    #[test]
    fn an_unresolved_i18n_key_is_refused() {
        for (title, body) in [
            (
                "notifications.deal_assigned.title",
                "notifications.deal_assigned.body",
            ),
            ("Yeni fırsat atandı", "notifications.deal_assigned.body"),
            (
                "notifications.ticket_sla_warning.title",
                "Ticket #4021 breaches its first-response SLA in 30 minutes.",
            ),
        ] {
            let err = notification_text(&input(title, body)).expect_err("must be refused");
            assert_eq!(err.code, "VALIDATION_ERROR");
        }
    }

    /// The key check is exact, so real text that merely contains dots, digits or the word
    /// "notifications" still goes through. A guard that eats legitimate notifications would
    /// be worse than the bug it prevents.
    #[test]
    fn the_key_check_does_not_swallow_real_text() {
        for text in [
            "Angebot Q-2026-0114 wurde vom Kunden angenommen.",
            "notifications.deal_assigned.title is the key for this one.",
            "www.acme.com adresinden bir talep geldi.",
            "notifications.deal_assigned",
            "notifications.Deal_Assigned.title",
            "deal_assigned.title",
        ] {
            assert!(
                !looks_like_an_unresolved_key(text),
                "wrongly treated as an i18n key: {text}"
            );
        }
    }

    // --- set_badge --------------------------------------------------------------------------

    /// `0` is "no badge", not "a badge reading zero".
    #[test]
    fn zero_clears_the_badge() {
        assert_eq!(badge_count(0).expect("zero is valid"), None);
    }

    /// A positive count reaches the OS unchanged, with no ceiling of the shell's invention.
    #[test]
    fn a_positive_count_passes_through() {
        assert_eq!(badge_count(1).expect("1 is valid"), Some(1));
        assert_eq!(badge_count(7).expect("7 is valid"), Some(7));
        assert_eq!(badge_count(4_321).expect("4321 is valid"), Some(4_321));
        assert_eq!(
            badge_count(i64::MAX).expect("i64::MAX is valid"),
            Some(i64::MAX)
        );
    }

    /// A negative count is refused rather than clamped: clamping to zero would render a
    /// broken upstream counter as a plausible "all read".
    #[test]
    fn a_negative_count_is_refused() {
        for count in [-1, -42, i64::MIN] {
            let err = badge_count(count).expect_err("negative must be refused");
            assert_eq!(err.code, "VALIDATION_ERROR");
        }
    }

    // --- set_autostart ----------------------------------------------------------------------

    /// Autostart is opt-in (`SYNCDESKTOP.md` §6.4): nothing may enable it but an explicit
    /// user-driven `set_autostart(true)`.
    ///
    /// The plugin cannot do it on its own — `tauri_plugin_autostart::Builder::build` only
    /// constructs an `AutoLaunch` and `app.manage()`s it, and `AutoLaunchManager::enable` is
    /// reachable **only** through the `autolaunch()` extension method. So the one place the
    /// opt-in default could be violated is the shell's own startup path, and this asserts it
    /// does not touch that method. A future turn that wires autostart into `.setup()` will
    /// fail here, which is the point.
    #[test]
    fn autostart_is_opt_in() {
        let lib = include_str!("../lib.rs");
        assert!(
            !lib.contains("autolaunch"),
            "lib.rs reaches for autolaunch(): autostart is opt-in (SYNCDESKTOP.md §6.4) and \
             must be enabled only by an explicit set_autostart(true)"
        );
    }

    /// Already in the desired state -> no OS write at all.
    #[test]
    fn autostart_write_is_skipped_when_already_correct() {
        assert_eq!(autostart_change(false, false), None);
        assert_eq!(autostart_change(true, true), None);
    }

    /// A real change is reported as the value to write.
    #[test]
    fn autostart_change_reports_the_value_to_write() {
        assert_eq!(autostart_change(false, true), Some(true));
        assert_eq!(autostart_change(true, false), Some(false));
    }

    // --- integration scaffolding ------------------------------------------------------------

    /// The `#![allow(dead_code)]` at the top of this file is legitimate for exactly one
    /// window: the commands are written but `lib.rs` has not registered them yet, so nothing
    /// here is reachable from the crate root. The moment registration lands, that attribute
    /// stops covering a temporary state and starts hiding real dead code — so removing it is
    /// made part of the integration instead of a note somebody has to remember.
    ///
    /// The needle is split across `concat!` on purpose: this test's own source is part of the
    /// file it reads, and an intact literal would match itself forever.
    #[test]
    fn the_dead_code_scaffold_is_removed_once_the_commands_are_registered() {
        let registered = include_str!("../lib.rs").contains("commands::os::notify");
        // Whole-line comparison, not `contains`: the needle also appears in this test's own
        // doc comment and panic message, so a substring search matches itself forever and the
        // assertion can never go green. Only the real attribute occupies a line of its own.
        let needle = concat!("#![allow(dead_", "code)]");
        let scaffolded = include_str!("os.rs")
            .lines()
            .any(|line| line.trim() == needle);

        assert!(
            !(registered && scaffolded),
            "os::* is registered in generate_handler! now — delete the #![allow(dead_code)] \
             at the top of commands/os.rs; its items are reachable and real dead code in this \
             module would go unreported"
        );
    }
}
