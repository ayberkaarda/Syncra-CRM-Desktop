//! Global hotkey and the quick-capture window (`SYNCDESKTOP.md` §6.4, F5-3).
//!
//! §6.4: *"Global hotkey (default `CmdOrCtrl+Shift+Space`, configurable, conflict detection):
//! `quick-capture` window (always-on-top, 480×360, frameless), 4 types
//! (lead/note/task/activity), works offline (`mutate`)."*
//!
//! Three things in this module are worth reading before changing it.
//!
//! ## 1. Conflict detection is "register the new one first", not "unregister then hope"
//!
//! A global shortcut is a **process-wide OS claim**: on Windows `RegisterHotKey` refuses a
//! combination another application already owns, and the same is true through
//! `global-hotkey`'s macOS and X11 backends. That refusal is the only signal there is — there
//! is no API anywhere that answers "who owns Ctrl+Shift+Space".
//!
//! So [`apply_hotkey`] registers the **new** shortcut before releasing the old one. The
//! obvious order (unregister, then register) loses the working shortcut whenever the new one
//! is taken: the user ends up with neither, and the app has to explain a state it created. In
//! this order a conflict is a plain refusal — the previous hotkey is untouched and the caller
//! gets a `{code, message}` error rather than silence. §6.4's "conflict detection" is exactly
//! that: the failure must not be swallowed.
//!
//! ## 2. The accelerator must carry a modifier
//!
//! `global-hotkey` happily parses a bare `Space` or `F` and will then claim that key
//! **system-wide** — every other application stops receiving it. That is not a shortcut, it is
//! a keyboard hijack, and it is unrecoverable from inside the app that caused it (the user
//! cannot type into the settings field that would undo it). [`parse_accelerator`] refuses it.
//!
//! ## 3. Where the configured accelerator is stored: nowhere, on this side
//!
//! `syncra_sync::DesktopSettings` is the engine's persisted settings row and its API is frozen
//! (`SYNCDESKTOP.md` §5.2) — it has no hotkey field and this crate cannot add one. The shell
//! therefore does not persist the choice at all: `.setup()` claims [`DEFAULT_HOTKEY`], and the
//! webview re-applies the user's override through the `register_hotkey` command on boot,
//! keeping it in `localStorage` next to the language override it already keeps there
//! (`desktop/src/ui/hotkey.ts`). One source of truth, in the layer that already owns
//! per-install preferences.

use std::sync::{Mutex, OnceLock};

use tauri::{AppHandle, Manager, Runtime, WebviewUrl, WebviewWindowBuilder};
use tauri_plugin_global_shortcut::{GlobalShortcutExt, Shortcut, ShortcutState};

use crate::commands::{CommandError, CommandResult};

/// Label of the quick-capture window. Must match the entry in
/// `capabilities/default.json` -> `windows`, or the window's own `invoke` calls are denied.
pub const WINDOW_LABEL: &str = "quick-capture";

/// The page the window loads, relative to `frontendDist` — a second Vite entry
/// (`desktop/quick-capture.html`), not a route of the main app: the main entry boots the whole
/// CRM (router, query client, realtime bridge) and none of that belongs in a 480×360 popup.
const WINDOW_URL: &str = "quick-capture.html";

/// §6.4, verbatim.
pub const DEFAULT_HOTKEY: &str = "CmdOrCtrl+Shift+Space";

/// §6.4, verbatim: 480×360.
const WIDTH: f64 = 480.0;
const HEIGHT: f64 = 360.0;

/// The shortcut this process currently holds, if any.
///
/// A module-level slot rather than managed state because the hotkey is a **process-wide** OS
/// claim, not an app-scoped resource: there is exactly one of it, it outlives every window,
/// and it has to be readable from the command handler, from `.setup()` and from the shortcut
/// handler itself.
fn claimed() -> &'static Mutex<Option<Shortcut>> {
    static CLAIMED: OnceLock<Mutex<Option<Shortcut>>> = OnceLock::new();
    CLAIMED.get_or_init(|| Mutex::new(None))
}

// ------------------------------------------------------------------------------------------------
// Accelerator parsing
// ------------------------------------------------------------------------------------------------

/// Parse and vet one accelerator string.
///
/// Two failures, both `VALIDATION_ERROR` because both are the caller's input being wrong:
///
/// * it does not parse (`global-hotkey`'s own grammar — `Ctrl`, `Alt`, `Shift`, `Super`,
///   `CmdOrCtrl`, plus one key);
/// * it parses but carries **no modifier**. See the module doc: a bare key is a system-wide
///   keyboard hijack that the user cannot undo from inside this app.
pub fn parse_accelerator(accelerator: &str) -> CommandResult<Shortcut> {
    let trimmed = accelerator.trim();
    if trimmed.is_empty() {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            "the hotkey accelerator is empty",
        ));
    }

    let shortcut: Shortcut = trimmed.parse().map_err(|error| {
        CommandError::new(
            "VALIDATION_ERROR",
            format!("`{trimmed}` is not a valid accelerator: {error}"),
        )
    })?;

    if shortcut.mods.is_empty() {
        return Err(CommandError::new(
            "VALIDATION_ERROR",
            format!(
                "`{trimmed}` has no modifier — a bare key would be claimed system-wide and \
                 could not be typed anywhere else"
            ),
        ));
    }

    Ok(shortcut)
}

// ------------------------------------------------------------------------------------------------
// Registration
// ------------------------------------------------------------------------------------------------

/// Claim `accelerator` for the quick-capture window, releasing whatever was claimed before.
///
/// Idempotent: re-applying the shortcut already held is a no-op success, which matters because
/// the webview calls this on every boot with whatever it has stored.
///
/// On refusal the **previous** shortcut is still registered and working — see the module doc
/// for why the order is register-then-release.
pub fn apply_hotkey<R: Runtime>(app: &AppHandle<R>, accelerator: &str) -> CommandResult<()> {
    let shortcut = parse_accelerator(accelerator)?;

    let mut slot = claimed().lock().map_err(|_| {
        CommandError::new("OS_ERROR", "the global shortcut slot is poisoned")
    })?;

    if *slot == Some(shortcut) {
        return Ok(());
    }

    let manager = app.global_shortcut();
    manager
        .on_shortcut(shortcut, |app, _shortcut, event| {
            // A hotkey fires twice — once down, once up. Opening on both would toggle the
            // window's focus in the middle of the user's own keystroke.
            if event.state == ShortcutState::Pressed {
                open(app);
            }
        })
        .map_err(|error| {
            CommandError::new(
                "OS_ERROR",
                format!(
                    "`{accelerator}` could not be registered — another application is most \
                     likely already using it ({error})"
                ),
            )
        })?;

    if let Some(previous) = slot.replace(shortcut) {
        // Best effort: the new claim already succeeded, and failing to release the old one
        // leaves an extra shortcut registered, not a broken app.
        if let Err(error) = manager.unregister(previous) {
            tracing::warn!(%error, "could not release the previous quick-capture hotkey");
        }
    }

    tracing::info!(hotkey = %shortcut, "quick-capture hotkey registered");
    Ok(())
}

/// Claim [`DEFAULT_HOTKEY`] at startup.
///
/// A failure here is logged, not fatal: the default combination being taken by another
/// application is a completely ordinary situation, and refusing to start the app over it would
/// be absurd. The tray's "Quick capture" item opens the same window with no shortcut involved,
/// and the settings screen can apply a different accelerator — both of which need the app to
/// be running.
pub fn install_default<R: Runtime>(app: &AppHandle<R>) {
    if let Err(error) = apply_hotkey(app, DEFAULT_HOTKEY) {
        tracing::warn!(
            code = %error.code,
            message = %error.message,
            "the default quick-capture hotkey is unavailable; the tray menu still opens the window"
        );
    }
}

// ------------------------------------------------------------------------------------------------
// The window
// ------------------------------------------------------------------------------------------------

/// Show the quick-capture window, creating it on first use.
///
/// Created lazily rather than declared in `tauri.conf.json`: a window declared there is built
/// at startup, and a second webview process alive for the whole session — for a popup most
/// runs never open — is exactly the idle-RAM the F7 budget (< 150 MB) is measured against.
/// Afterwards it is hidden rather than destroyed, so the second press is instant.
pub fn open<R: Runtime>(app: &AppHandle<R>) {
    if let Some(window) = app.get_webview_window(WINDOW_LABEL) {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
        return;
    }

    let builder = WebviewWindowBuilder::new(app, WINDOW_LABEL, WebviewUrl::App(WINDOW_URL.into()))
        // The product name, not UI copy: a frameless window shows no title bar, and this is
        // only what the OS window manager labels the surface with (§0.6 is about text the user
        // reads in the app, and the app's own name is not a translatable string).
        .title(app.config().product_name.clone().unwrap_or_default())
        .inner_size(WIDTH, HEIGHT)
        .resizable(false)
        .decorations(false)
        .always_on_top(true)
        .center()
        .focused(true);

    // `skip_taskbar` is Windows/Linux only in Tauri 2; on macOS the equivalent is an
    // accessory-mode activation policy, which would change the whole app's behaviour and is
    // not worth it for one popup.
    #[cfg(any(windows, target_os = "linux"))]
    let builder = builder.skip_taskbar(true);

    if let Err(error) = builder.build() {
        tracing::warn!(%error, "could not open the quick-capture window");
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tauri_plugin_global_shortcut::{Code, Modifiers};

    /// The documented default really is the one §6.4 names, and it really parses.
    #[test]
    fn the_default_accelerator_is_the_one_the_spec_names() {
        assert_eq!(DEFAULT_HOTKEY, "CmdOrCtrl+Shift+Space");
        let shortcut = parse_accelerator(DEFAULT_HOTKEY).expect("the default must parse");
        assert_eq!(shortcut.key, Code::Space);
        assert!(shortcut.mods.contains(Modifiers::SHIFT));
    }

    /// `CmdOrCtrl` resolves per platform — `SUPER` on macOS, `CONTROL` everywhere else.
    #[test]
    fn cmd_or_ctrl_resolves_for_this_platform() {
        let shortcut = parse_accelerator(DEFAULT_HOTKEY).expect("parse");
        #[cfg(target_os = "macos")]
        assert!(shortcut.mods.contains(Modifiers::SUPER));
        #[cfg(not(target_os = "macos"))]
        assert!(shortcut.mods.contains(Modifiers::CONTROL));
    }

    /// A bare key is refused: claiming it would take that key away from every other
    /// application on the machine, and the user could not type it to undo the setting.
    #[test]
    fn a_modifierless_accelerator_is_refused() {
        for accelerator in ["Space", "F", "Enter", "F5"] {
            let error = parse_accelerator(accelerator)
                .expect_err(&format!("`{accelerator}` must be refused"));
            assert_eq!(error.code, "VALIDATION_ERROR");
            assert!(
                error.message.contains("no modifier"),
                "the refusal must say why: {}",
                error.message
            );
        }
    }

    #[test]
    fn an_unparseable_accelerator_is_refused() {
        for accelerator in ["Ctrl+", "Ctrl+Shift+NotAKey", "++", "Ctrl+A+B"] {
            let error = parse_accelerator(accelerator)
                .expect_err(&format!("`{accelerator}` must be refused"));
            assert_eq!(error.code, "VALIDATION_ERROR");
        }
    }

    #[test]
    fn an_empty_accelerator_is_refused() {
        for accelerator in ["", "   "] {
            let error = parse_accelerator(accelerator).expect_err("must be refused");
            assert_eq!(error.code, "VALIDATION_ERROR");
            assert!(error.message.contains("empty"));
        }
    }

    /// Surrounding whitespace is normalised, not rejected — a settings field trailing a space
    /// is not a different shortcut.
    #[test]
    fn whitespace_around_an_accelerator_is_trimmed() {
        assert_eq!(
            parse_accelerator("  Ctrl+Shift+K  ").expect("parse"),
            parse_accelerator("Ctrl+Shift+K").expect("parse"),
        );
    }

    /// Several spellings of the same combination are the same claim, which is what makes
    /// `apply_hotkey`'s idempotence check (`*slot == Some(shortcut)`) meaningful.
    #[test]
    fn equivalent_spellings_are_the_same_shortcut() {
        assert_eq!(
            parse_accelerator("Ctrl+Shift+K").expect("parse"),
            parse_accelerator("CONTROL+shift+k").expect("parse"),
        );
    }

    /// The window label is the one the capability file grants permissions to. A rename on one
    /// side only would leave the popup unable to call a single command, at runtime, with no
    /// compile error.
    #[test]
    fn the_window_label_is_in_the_capability_file() {
        let capability = include_str!("../capabilities/default.json");
        assert!(
            capability.contains(&format!("\"{WINDOW_LABEL}\"")),
            "capabilities/default.json must list the `{WINDOW_LABEL}` window"
        );
    }

    /// The page the window loads must exist as a real Vite entry, or the popup opens blank.
    #[test]
    fn the_window_page_is_a_real_entry() {
        let config = include_str!("../../vite.desktop.config.ts");
        assert!(
            config.contains(WINDOW_URL),
            "vite.desktop.config.ts must build `{WINDOW_URL}` as a second entry"
        );
    }
}
