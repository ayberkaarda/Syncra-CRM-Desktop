//! System tray icon, menu and background lifecycle (`SYNCDESKTOP.md` §6.4, F5-1).
//!
//! §6.4 asks for one tray icon whose picture reflects `online / offline / syncing / conflict`
//! and a five item menu — **Open, Sync now, Quick capture, Pause sync, Quit** — plus the
//! close-to-tray window default (D-8, already in [`crate::run`]).
//!
//! Three things in this module are worth reading before changing it.
//!
//! ## 1. Quit is the only way this process ever reaches `RunEvent::Exit`
//!
//! D-8 makes every `CloseRequested` a `prevent_close()` + `hide()`, and nothing else calls
//! [`tauri::AppHandle::exit`]. Before this module existed the app could only be killed, and a
//! killed process emits no `Exit`: `tauri-plugin-window-state` writes `.window-state.json`
//! **only** from its own `RunEvent::Exit` hook (`tauri-plugin-window-state-2.4.1/src/lib.rs`
//! line 503), the sync scheduler was never stopped, and the SQLCipher connection was never
//! checkpointed, so every run left a `-wal`/`-shm` pair behind. The `Quit` item is what closes
//! all three, through the teardown in [`crate::run`].
//!
//! ## 2. The labels are the app's own i18n dictionary, not strings typed here
//!
//! §0.6 forbids hard-coded UI text, and Rust cannot reach the running i18next instance (that
//! singleton lives in `frontend/src/i18n`, and `desktop/src/ui/useT.ts` documents why a second
//! one is not an option). The webview cannot push the labels down either — the tray has to
//! exist before the first frame renders, and it has to keep working while the window is
//! hidden, which is precisely when no webview is running.
//!
//! So the four `desktop.json` dictionaries are embedded with [`include_str!`] and the `tray`
//! section is read out of them at runtime. The dictionary stays the single source of truth: a
//! renamed key is a `cargo test` failure here ([`self::tests::every_language_parses`]), and a
//! moved file is a compile error. See [`pick_language`] for which of the four is used.
//!
//! ## 3. The status icon is drawn, not shipped
//!
//! Four PNG variants of the app icon would be four binary files nobody can diff. Instead the
//! bundled 32×32 icon is decoded once and a status dot is composited into its corner
//! ([`with_status_dot`]), which makes the whole thing a pure function over pixels and
//! therefore testable without a window server.

use std::sync::{Arc, Mutex, OnceLock};

use serde::Deserialize;
use syncra_sync::SyncStatus;
use tauri::image::Image;
use tauri::menu::{Menu, MenuEvent, MenuItem, PredefinedMenuItem};
use tauri::tray::{TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Listener, Manager, Runtime};

use crate::events::ENGINE_EVENT;
use crate::state::AppState;

/// Id the tray icon is registered under, so [`tauri::Manager::tray_by_id`] can find it again
/// when the engine reports a new status.
pub const TRAY_ID: &str = "syncra-tray";

const ID_OPEN: &str = "tray.open";
const ID_SYNC_NOW: &str = "tray.sync_now";
const ID_QUICK_CAPTURE: &str = "tray.quick_capture";
const ID_PAUSE: &str = "tray.pause_sync";
const ID_QUIT: &str = "tray.quit";

// ------------------------------------------------------------------------------------------------
// Labels
// ------------------------------------------------------------------------------------------------

/// The four `desktop` namespace dictionaries, embedded at compile time.
///
/// The order is the resolution order of nothing — [`pick_language`] matches by tag — but the
/// first entry doubles as the documented fallback, see [`FALLBACK_LANGUAGE`].
const LOCALES: [(&str, &str); 4] = [
    (
        "tr",
        include_str!("../../../frontend/src/i18n/locales/tr/desktop.json"),
    ),
    (
        "en",
        include_str!("../../../frontend/src/i18n/locales/en/desktop.json"),
    ),
    (
        "de",
        include_str!("../../../frontend/src/i18n/locales/de/desktop.json"),
    ),
    (
        "fr",
        include_str!("../../../frontend/src/i18n/locales/fr/desktop.json"),
    ),
];

/// Same fallback locale the app's i18next instance uses (`frontend/src/i18n/index.ts`), so a
/// user whose language the tray cannot resolve sees the tray in the language the rest of the
/// app would fall back to as well.
const FALLBACK_LANGUAGE: &str = "tr";

/// Only the slice of `desktop.json` this module reads. Unknown fields are ignored, which is
/// what keeps the other ~200 keys of the namespace out of this file.
#[derive(Debug, Clone, Deserialize)]
struct DesktopNamespace {
    tray: TrayLabels,
}

/// `desktop.tray` — the menu item labels and the four tooltips.
#[derive(Debug, Clone, Deserialize)]
struct TrayLabels {
    menu: MenuLabels,
    tooltip: TooltipLabels,
}

/// `desktop.tray.menu`.
#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
struct MenuLabels {
    open: String,
    sync_now: String,
    quick_capture: String,
    pause_sync: String,
    resume_sync: String,
    quit: String,
}

/// `desktop.tray.tooltip`.
#[derive(Debug, Clone, Deserialize)]
struct TooltipLabels {
    online: String,
    offline: String,
    syncing: String,
    conflict: String,
}

/// Every embedded dictionary, parsed once.
///
/// The panic is deliberate and cannot fire on a shipped build: the input is a compile-time
/// constant, and [`self::tests::every_language_parses`] runs this exact parse for all four
/// languages, so a dictionary that no longer has the shape the tray needs is a red test long
/// before it is a runtime failure.
fn all_labels() -> &'static [(&'static str, TrayLabels); 4] {
    static CACHE: OnceLock<[(&'static str, TrayLabels); 4]> = OnceLock::new();
    CACHE.get_or_init(|| LOCALES.map(|(language, raw)| (language, parse_labels(language, raw))))
}

fn parse_labels(language: &str, raw: &str) -> TrayLabels {
    match serde_json::from_str::<DesktopNamespace>(raw) {
        Ok(namespace) => namespace.tray,
        Err(error) => panic!(
            "frontend/src/i18n/locales/{language}/desktop.json: the `tray` section is not the \
             shape the tray menu reads ({error})"
        ),
    }
}

/// Labels for `language`, which must be one of [`LOCALES`]; anything else falls back.
fn labels_for(language: &str) -> &'static TrayLabels {
    let all = all_labels();
    all.iter()
        .find(|(candidate, _)| *candidate == language)
        .or_else(|| all.iter().find(|(candidate, _)| *candidate == FALLBACK_LANGUAGE))
        .map(|(_, labels)| labels)
        .expect("the fallback language is one of the embedded dictionaries")
}

/// The supported language a BCP-47 (or POSIX) tag resolves to, if any.
///
/// `de-DE`, `de_DE` and `DE` all resolve to `de`; `es-ES` resolves to nothing, because the app
/// has no Spanish dictionary to read.
fn supported(tag: &str) -> Option<&'static str> {
    let primary = tag
        .split(['-', '_', '.'])
        .next()
        .unwrap_or_default()
        .to_ascii_lowercase();
    LOCALES
        .iter()
        .map(|(language, _)| *language)
        .find(|language| *language == primary)
}

/// Which of the four dictionaries the tray speaks.
///
/// `session_locale` is the authoritative one: it is `users.locale` as the server sends it
/// (`UserResource`), the same value `applyUserLocale()` applies in the webview, and the engine
/// already caches it in the session document — so no new storage, no new command, and it
/// survives a restart because the session does.
///
/// `os_locale` (`tauri_plugin_os::locale()`) covers the window before a session exists: the
/// login screen, and the whole life of an app that has never been signed in.
///
/// **Known divergence, on purpose:** the webview lets a user override the language *in this
/// install* (`localStorage`, `frontend/src/i18n/index.ts`), and that choice wins over the
/// server's in the UI. Rust cannot read `localStorage` — it lives inside the WebView2/WebKitGTK
/// profile — so a user who overrides the language locally sees the tray in their account
/// language instead. Closing that gap needs a webview→shell push, i.e. a new command and a
/// `desktop/src` call site; both are outside F5-1's file scope and are recorded as an open
/// question rather than guessed at here.
fn pick_language(session_locale: Option<&str>, os_locale: Option<&str>) -> &'static str {
    session_locale
        .and_then(supported)
        .or_else(|| os_locale.and_then(supported))
        .unwrap_or(FALLBACK_LANGUAGE)
}

/// [`pick_language`] applied to the live app: the cached session first, the OS second.
fn current_language<R: Runtime>(app: &AppHandle<R>) -> &'static str {
    let session_locale = app
        .try_state::<AppState>()
        .and_then(|state| state.engine.session())
        .and_then(|session| {
            session
                .user
                .get("locale")
                .and_then(|value| value.as_str())
                .map(str::to_owned)
        });

    pick_language(session_locale.as_deref(), tauri_plugin_os::locale().as_deref())
}

// ------------------------------------------------------------------------------------------------
// Status
// ------------------------------------------------------------------------------------------------

/// The four pictures §6.4 names.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum TrayStatus {
    Online,
    Offline,
    Syncing,
    Conflict,
}

impl TrayStatus {
    /// Collapse a [`SyncStatus`] onto one of the four pictures.
    ///
    /// Precedence is `offline > conflict > syncing > online`, and the interesting choice is
    /// conflict over syncing. Both can be true at once (a round runs every 60 seconds whether
    /// or not the Conflict Inbox is empty). `syncing` is transient and repaints itself a
    /// second later; a conflict sits there until a human resolves it. Ranking syncing higher
    /// would make the conflict badge blink out of existence on every round — an indicator that
    /// disappears while the condition persists is worse than no indicator.
    fn from_status(status: &SyncStatus) -> Self {
        if !status.online {
            Self::Offline
        } else if status.conflicts > 0 {
            Self::Conflict
        } else if status.syncing {
            Self::Syncing
        } else {
            Self::Online
        }
    }

    /// RGB of the corner dot. Not UI *text*, so §0.6 does not apply; these are the same hues
    /// the web app's connectivity chip uses (emerald / zinc / blue / red).
    fn dot(self) -> [u8; 3] {
        match self {
            Self::Online => [34, 197, 94],
            Self::Offline => [113, 113, 122],
            Self::Syncing => [59, 130, 246],
            Self::Conflict => [239, 68, 68],
        }
    }

    fn tooltip(self, labels: &TrayLabels) -> &str {
        match self {
            Self::Online => &labels.tooltip.online,
            Self::Offline => &labels.tooltip.offline,
            Self::Syncing => &labels.tooltip.syncing,
            Self::Conflict => &labels.tooltip.conflict,
        }
    }
}

// ------------------------------------------------------------------------------------------------
// Icon
// ------------------------------------------------------------------------------------------------

/// The bundled tray-sized app icon (`tauri.conf.json` ships the same file).
const BASE_ICON_PNG: &[u8] = include_bytes!("../icons/32x32.png");

/// The base icon, decoded once into RGBA.
fn base_icon() -> &'static (Vec<u8>, u32, u32) {
    static BASE: OnceLock<(Vec<u8>, u32, u32)> = OnceLock::new();
    BASE.get_or_init(|| {
        let image =
            Image::from_bytes(BASE_ICON_PNG).expect("icons/32x32.png must be a decodable PNG");
        (image.rgba().to_vec(), image.width(), image.height())
    })
}

/// Composite a status dot into the bottom-right corner of an RGBA buffer.
///
/// The dot is a filled disc in `dot`, ringed in opaque white so it stays visible against both
/// a light and a dark tray background. Pure and total: the output is always the same length as
/// the input, and a buffer whose length disagrees with `width * height * 4` is returned
/// untouched rather than panicking on an index (the caller's input comes from a decoder, not
/// from a literal).
fn with_status_dot(base: &[u8], width: u32, height: u32, dot: [u8; 3]) -> Vec<u8> {
    let mut out = base.to_vec();
    if width == 0 || height == 0 || out.len() != (width as usize * height as usize * 4) {
        return out;
    }

    // Diameter ≈ 44% of the icon: small enough that the badge stays inside the bottom-right
    // quadrant and leaves the glyph readable (asserted by
    // `tests::the_dot_is_local_to_the_corner`), large enough to read as a colour when Windows
    // draws the tray at 16 logical pixels.
    let radius = (width.min(height) as f32 * 0.22).max(3.0);
    let centre_x = width as f32 - radius - 1.0;
    let centre_y = height as f32 - radius - 1.0;

    for y in 0..height {
        for x in 0..width {
            let dx = x as f32 + 0.5 - centre_x;
            let dy = y as f32 + 0.5 - centre_y;
            let distance = (dx * dx + dy * dy).sqrt();
            if distance > radius {
                continue;
            }
            let index = (y as usize * width as usize + x as usize) * 4;
            let pixel = if distance <= radius - 1.5 {
                [dot[0], dot[1], dot[2], 255]
            } else {
                [255, 255, 255, 255]
            };
            out[index..index + 4].copy_from_slice(&pixel);
        }
    }

    out
}

/// The tray picture for `status`.
fn status_image(status: TrayStatus) -> Image<'static> {
    let (rgba, width, height) = base_icon();
    Image::new_owned(
        with_status_dot(rgba, *width, *height, status.dot()),
        *width,
        *height,
    )
}

// ------------------------------------------------------------------------------------------------
// Menu handles
// ------------------------------------------------------------------------------------------------

/// The menu items the tray keeps a handle on, so a language change or a pause can relabel them
/// in place instead of rebuilding the menu (`set_menu` on Linux cannot replace a menu at all —
/// `TrayIconBuilder::menu`'s own platform note).
struct TrayItems<R: Runtime> {
    open: MenuItem<R>,
    sync_now: MenuItem<R>,
    quick_capture: MenuItem<R>,
    pause: MenuItem<R>,
    quit: MenuItem<R>,
}

// Hand-written rather than derived: `#[derive(Clone)]` on a struct generic over `R` would add
// an `R: Clone` bound, and `Runtime` implementations are not `Clone`. Every field is an `Arc`
// handle, so this is a refcount bump.
impl<R: Runtime> Clone for TrayItems<R> {
    fn clone(&self) -> Self {
        Self {
            open: self.open.clone(),
            sync_now: self.sync_now.clone(),
            quick_capture: self.quick_capture.clone(),
            pause: self.pause.clone(),
            quit: self.quit.clone(),
        }
    }
}

impl<R: Runtime> TrayItems<R> {
    /// Write `labels` onto every item. `paused` selects between the two spellings of the third
    /// item, which is one menu entry with two labels rather than two entries.
    fn apply_labels(&self, labels: &TrayLabels, paused: bool) {
        let pause_text = if paused {
            &labels.menu.resume_sync
        } else {
            &labels.menu.pause_sync
        };
        for (item, text) in [
            (&self.open, &labels.menu.open),
            (&self.sync_now, &labels.menu.sync_now),
            (&self.quick_capture, &labels.menu.quick_capture),
            (&self.pause, pause_text),
            (&self.quit, &labels.menu.quit),
        ] {
            if let Err(error) = item.set_text(text) {
                tracing::warn!(%error, "could not relabel a tray menu item");
            }
        }
    }
}

/// What the tray is currently showing, so an engine event that changes neither is a no-op
/// rather than a repaint. `TablesChanged` fires per pulled table; repainting the icon on each
/// one would be a tray flicker on every sync round.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
struct Painted {
    language: &'static str,
    status: TrayStatus,
    paused: bool,
}

// ------------------------------------------------------------------------------------------------
// Wiring
// ------------------------------------------------------------------------------------------------

/// Whether the background sync loop is currently stopped.
///
/// The scheduler slot **is** the pause flag: `Some` means the loop of
/// [`syncra_sync::SyncEngine::start_background_sync`] is running, `None` means it was stopped.
/// A second boolean would be a second source of truth that can disagree with the first.
fn is_paused<R: Runtime>(app: &AppHandle<R>) -> bool {
    app.try_state::<AppState>()
        .is_some_and(|state| state.scheduler.lock().is_ok_and(|slot| slot.is_none()))
}

fn status_snapshot<R: Runtime>(app: &AppHandle<R>) -> SyncStatus {
    app.try_state::<AppState>()
        .map(|state| state.engine.status())
        .unwrap_or_default()
}

/// Bring the main window back. Shared by the `Open` item, a left click on the icon, and — in
/// [`crate::run`] — the second-instance handler.
fn show_main_window<R: Runtime>(app: &AppHandle<R>) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
    }
}

/// Toggle the background sync loop.
///
/// **This is the shell's mechanism, and it is the only one available.** The engine has no
/// pause: `Inner::halted` is private and means "the server speaks a protocol this build does
/// not implement" — it is set only on a version mismatch and cleared only by a login, so
/// reusing it would make a user-requested pause indistinguishable from a broken build.
/// `syncra-sync`'s public API is frozen, so adding one is not on the table either.
///
/// What the engine *does* offer is exactly this: `lib.rs`'s own comment on the scheduler
/// handle says it is parked in the managed state to keep `SyncScheduler::stop` "reachable if a
/// later phase needs it (a 'pause sync' setting, a teardown path)". Both arrived at once.
///
/// Scope, stated plainly: this pauses the **background loop** — the 60 second timer, the
/// offline probe ramp, and the realtime/wake triggers that would otherwise start a round. It
/// does not disable the `sync_now` command, so an explicit "Sync now" (from this menu or from
/// the UI) still runs while paused. Pausing an automatic loop and refusing an explicit request
/// are different promises, and the menu only makes the first.
///
/// The caller repaints afterwards rather than reading a return value: the menu label, the
/// tooltip and the icon are all recomputed from the state this function just moved, so
/// [`refresh`] is the one place that decides what the tray shows.
fn toggle_pause<R: Runtime>(app: &AppHandle<R>) {
    let Some(state) = app.try_state::<AppState>() else {
        return;
    };
    let Ok(mut slot) = state.scheduler.lock() else {
        tracing::warn!("scheduler mutex poisoned; leaving the sync loop as it is");
        return;
    };

    match slot.take() {
        Some(scheduler) => {
            scheduler.stop();
            tracing::info!("background sync paused from the tray menu");
        }
        None => {
            // Same `block_on` as `.setup()`: `start_background_sync` calls `tokio::spawn`, and
            // a menu event handler runs on the main thread, outside any runtime context. The
            // closure never awaits, so this returns immediately.
            let scheduler =
                tauri::async_runtime::block_on(async { state.engine.start_background_sync() });
            *slot = Some(scheduler);
            tracing::info!("background sync resumed from the tray menu");
        }
    }
}

/// Repaint the icon, tooltip and labels if — and only if — something they depend on moved.
fn refresh<R: Runtime>(app: &AppHandle<R>, items: &TrayItems<R>, painted: &Mutex<Painted>) {
    let next = Painted {
        language: current_language(app),
        status: TrayStatus::from_status(&status_snapshot(app)),
        paused: is_paused(app),
    };

    let Ok(mut current) = painted.lock() else {
        return;
    };
    if *current == next {
        return;
    }

    let labels = labels_for(next.language);

    if current.status != next.status {
        if let Some(tray) = app.tray_by_id(TRAY_ID) {
            if let Err(error) = tray.set_icon(Some(status_image(next.status))) {
                tracing::warn!(%error, "could not update the tray icon");
            }
        }
    }
    if current.status != next.status || current.language != next.language {
        if let Some(tray) = app.tray_by_id(TRAY_ID) {
            if let Err(error) = tray.set_tooltip(Some(next.status.tooltip(labels))) {
                tracing::warn!(%error, "could not update the tray tooltip");
            }
        }
    }
    if current.language != next.language || current.paused != next.paused {
        items.apply_labels(labels, next.paused);
    }

    *current = next;
}

/// Build the tray icon and its menu, and subscribe them to the engine's status.
///
/// Called from `.setup()` **after** `app.manage(state)`: the initial picture, tooltip and
/// language are all read out of [`AppState`].
pub fn init<R: Runtime>(app: &AppHandle<R>) -> tauri::Result<()> {
    let language = current_language(app);
    let labels = labels_for(language);
    let status = TrayStatus::from_status(&status_snapshot(app));

    let items = TrayItems {
        open: MenuItem::with_id(app, ID_OPEN, &labels.menu.open, true, None::<&str>)?,
        sync_now: MenuItem::with_id(app, ID_SYNC_NOW, &labels.menu.sync_now, true, None::<&str>)?,
        // DISABLED ON PURPOSE. The quick capture window is F5-3 and does not exist yet. The
        // item is present because §6.4 lists it and a menu that grows an entry later is a menu
        // whose shape moved under the user; it is disabled because the alternative — opening
        // some placeholder window — would be inventing a feature the spec assigns to another
        // phase. F5-3 enables it.
        quick_capture: MenuItem::with_id(
            app,
            ID_QUICK_CAPTURE,
            &labels.menu.quick_capture,
            false,
            None::<&str>,
        )?,
        pause: MenuItem::with_id(app, ID_PAUSE, &labels.menu.pause_sync, true, None::<&str>)?,
        quit: MenuItem::with_id(app, ID_QUIT, &labels.menu.quit, true, None::<&str>)?,
    };

    let before_pause = PredefinedMenuItem::separator(app)?;
    let before_quit = PredefinedMenuItem::separator(app)?;
    let menu = Menu::with_items(
        app,
        &[
            &items.open,
            &items.sync_now,
            &items.quick_capture,
            &before_pause,
            &items.pause,
            &before_quit,
            &items.quit,
        ],
    )?;

    let painted = Arc::new(Mutex::new(Painted {
        language,
        status,
        paused: is_paused(app),
    }));

    let menu_items = items.clone();
    let menu_painted = Arc::clone(&painted);

    TrayIconBuilder::with_id(TRAY_ID)
        .icon(status_image(status))
        .tooltip(status.tooltip(labels))
        .menu(&menu)
        // A left click opens the window (handled below); the menu is the right click, which is
        // the platform convention on Windows and Linux alike.
        .show_menu_on_left_click(false)
        .on_menu_event(move |app, event: MenuEvent| {
            match event.id().as_ref() {
                ID_OPEN => show_main_window(app),
                ID_SYNC_NOW => {
                    // A manual trigger of §5.5. Spawned rather than awaited: the menu handler
                    // runs on the main thread and a round takes seconds.
                    if let Some(state) = app.try_state::<AppState>() {
                        let engine = state.engine.clone();
                        tauri::async_runtime::spawn(async move {
                            if let Err(error) = engine.sync_now().await {
                                tracing::warn!(%error, "tray 'Sync now' round failed");
                            }
                        });
                    }
                }
                ID_QUICK_CAPTURE => {
                    // Unreachable while the item is disabled; kept so F5-3 has one place to
                    // wire the window rather than a silent `_ => {}` to discover.
                    tracing::debug!("tray quick capture is disabled until F5-3 delivers it");
                }
                ID_PAUSE => {
                    toggle_pause(app);
                    // The scheduler slot has already moved, so a plain refresh picks the new
                    // label up along with anything else that changed.
                    refresh(app, &menu_items, &menu_painted);
                }
                ID_QUIT => {
                    // The only `exit` in the app. `RunEvent::Exit` is what stops the scheduler,
                    // checkpoints the database and lets the window-state plugin write its file
                    // — see the teardown in `crate::run`.
                    tracing::info!("quitting from the tray menu");
                    app.exit(0);
                }
                other => tracing::debug!(id = other, "unhandled tray menu event"),
            }
        })
        .on_tray_icon_event(|tray, event| {
            if let TrayIconEvent::DoubleClick { .. } = event {
                show_main_window(tray.app_handle());
            }
        })
        .build(app)?;

    // The engine's status reaches the webview through `events::forward_engine_events`, which
    // owns the one and only `SyncEngine::subscribe()` receiver. The tray does NOT open a
    // second one: it listens on the Tauri event bus for what that bridge already emits, so
    // there is still exactly one subscriber to the broadcast channel and the tray cannot make
    // the webview lag. The payload is not parsed — every engine event is simply a reason to
    // re-read `SyncEngine::status()`, which is the same snapshot `StatusChanged` carries.
    let listener_items = items;
    app.listen(ENGINE_EVENT, {
        let app = app.clone();
        move |_event| refresh(&app, &listener_items, &painted)
    });

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    // --- labels -------------------------------------------------------------------------------

    /// Every embedded dictionary parses into the shape the menu reads.
    ///
    /// This is the test that turns a renamed or deleted i18n key into a red `cargo test`
    /// instead of a panic on someone's desktop: `all_labels()` runs the same parse the app
    /// runs, for all four languages, over the same compile-time constants.
    #[test]
    fn every_language_parses() {
        let all = all_labels();
        assert_eq!(all.len(), 4);
        for (language, labels) in all {
            for (field, value) in [
                ("menu.open", &labels.menu.open),
                ("menu.syncNow", &labels.menu.sync_now),
                ("menu.quickCapture", &labels.menu.quick_capture),
                ("menu.pauseSync", &labels.menu.pause_sync),
                ("menu.resumeSync", &labels.menu.resume_sync),
                ("menu.quit", &labels.menu.quit),
                ("tooltip.online", &labels.tooltip.online),
                ("tooltip.offline", &labels.tooltip.offline),
                ("tooltip.syncing", &labels.tooltip.syncing),
                ("tooltip.conflict", &labels.tooltip.conflict),
            ] {
                assert!(
                    !value.trim().is_empty(),
                    "desktop.tray.{field} is empty in {language}"
                );
            }
        }
    }

    /// The four languages really are four translations — a copy-paste that left one language
    /// holding another's text would pass `every_language_parses` and fail here.
    #[test]
    fn the_menu_is_actually_translated() {
        let quits: Vec<&str> = all_labels()
            .iter()
            .map(|(_, labels)| labels.menu.quit.as_str())
            .collect();
        let mut unique = quits.clone();
        unique.sort_unstable();
        unique.dedup();
        assert_eq!(unique.len(), quits.len(), "duplicate 'quit' labels: {quits:?}");
    }

    #[test]
    fn a_region_tag_resolves_to_its_language() {
        assert_eq!(pick_language(Some("de-DE"), None), "de");
        assert_eq!(pick_language(Some("fr_CA"), None), "fr");
        assert_eq!(pick_language(Some("EN"), None), "en");
        assert_eq!(pick_language(Some("tr-TR.UTF-8"), None), "tr");
    }

    /// The OS answers only when the session does not.
    #[test]
    fn the_session_locale_outranks_the_os() {
        assert_eq!(pick_language(Some("de"), Some("fr-FR")), "de");
        assert_eq!(pick_language(None, Some("fr-FR")), "fr");
    }

    /// A language the app has no dictionary for falls back rather than rendering keys.
    #[test]
    fn an_unsupported_language_falls_back() {
        assert_eq!(pick_language(Some("es-ES"), Some("ja-JP")), FALLBACK_LANGUAGE);
        assert_eq!(pick_language(None, None), FALLBACK_LANGUAGE);
        assert_eq!(pick_language(Some(""), None), FALLBACK_LANGUAGE);
    }

    #[test]
    fn labels_for_an_unknown_language_are_the_fallback_ones() {
        assert_eq!(
            labels_for("es").menu.quit,
            labels_for(FALLBACK_LANGUAGE).menu.quit
        );
    }

    // --- status -------------------------------------------------------------------------------

    fn status(online: bool, syncing: bool, conflicts: u32) -> SyncStatus {
        SyncStatus {
            online,
            syncing,
            conflicts,
            ..SyncStatus::default()
        }
    }

    #[test]
    fn offline_outranks_everything() {
        assert_eq!(
            TrayStatus::from_status(&status(false, true, 3)),
            TrayStatus::Offline
        );
    }

    /// The precedence choice documented on `from_status`: a conflict must not blink out of
    /// existence every time a round starts.
    #[test]
    fn a_conflict_outranks_a_round_in_flight() {
        assert_eq!(
            TrayStatus::from_status(&status(true, true, 1)),
            TrayStatus::Conflict
        );
        assert_eq!(
            TrayStatus::from_status(&status(true, true, 0)),
            TrayStatus::Syncing
        );
        assert_eq!(
            TrayStatus::from_status(&status(true, false, 0)),
            TrayStatus::Online
        );
    }

    #[test]
    fn every_status_has_its_own_colour() {
        let dots = [
            TrayStatus::Online.dot(),
            TrayStatus::Offline.dot(),
            TrayStatus::Syncing.dot(),
            TrayStatus::Conflict.dot(),
        ];
        let mut unique = dots.to_vec();
        unique.sort_unstable();
        unique.dedup();
        assert_eq!(unique.len(), 4, "two statuses share a dot colour: {dots:?}");
    }

    // --- icon ---------------------------------------------------------------------------------

    /// The shipped icon decodes, and the four pictures really are four different buffers.
    #[test]
    fn each_status_paints_a_distinct_icon() {
        let (rgba, width, height) = base_icon();
        assert_eq!(rgba.len(), *width as usize * *height as usize * 4);

        let painted: Vec<Vec<u8>> = [
            TrayStatus::Online,
            TrayStatus::Offline,
            TrayStatus::Syncing,
            TrayStatus::Conflict,
        ]
        .into_iter()
        .map(|status| with_status_dot(rgba, *width, *height, status.dot()))
        .collect();

        for buffer in &painted {
            assert_eq!(buffer.len(), rgba.len(), "the dot resized the icon");
            assert_ne!(buffer, rgba, "the dot did not change any pixel");
        }
        for (a, b) in [(0, 1), (0, 2), (0, 3), (1, 2), (1, 3), (2, 3)] {
            assert_ne!(painted[a], painted[b], "two statuses paint the same icon");
        }
    }

    /// The dot lands in the bottom-right corner and leaves the rest of the glyph alone.
    #[test]
    fn the_dot_is_local_to_the_corner() {
        let (rgba, width, height) = base_icon();
        let dot = TrayStatus::Conflict.dot();
        let painted = with_status_dot(rgba, *width, *height, dot);

        let radius = ((*width).min(*height) as f32 * 0.22).max(3.0);
        let centre_x = (*width as f32 - radius - 1.0) as u32;
        let centre_y = (*height as f32 - radius - 1.0) as u32;
        let centre = (centre_y as usize * *width as usize + centre_x as usize) * 4;
        assert_eq!(
            &painted[centre..centre + 4],
            &[dot[0], dot[1], dot[2], 255],
            "the centre of the dot is not the status colour"
        );

        // Top-left quadrant is untouched.
        for y in 0..*height / 2 {
            for x in 0..*width / 2 {
                let index = (y as usize * *width as usize + x as usize) * 4;
                assert_eq!(
                    painted[index..index + 4],
                    rgba[index..index + 4],
                    "the dot bled into the top-left quadrant at ({x}, {y})"
                );
            }
        }
    }

    /// A buffer whose length disagrees with its dimensions is returned untouched instead of
    /// panicking on an index.
    #[test]
    fn a_malformed_buffer_is_returned_untouched() {
        let base = vec![1u8, 2, 3, 4];
        assert_eq!(with_status_dot(&base, 8, 8, [0, 0, 0]), base);
        assert_eq!(with_status_dot(&base, 0, 0, [0, 0, 0]), base);
    }

    // --- lib.rs source assertions ---------------------------------------------------------------

    /// `StateFlags::VISIBLE` must stay OUT of the window-state plugin's flag set.
    ///
    /// `StateFlags::default()` is `all()`, so the plugin would record `visible` alongside the
    /// geometry. D-8 hides the window instead of closing it, so at `RunEvent::Exit` — the only
    /// moment the plugin writes — `visible` is *false* essentially every time, and
    /// `restore_state` skips its `show()` on the next launch. That is harmless only because
    /// `tauri.conf.json` still says `"visible": true`; the day that flips, the app opens to
    /// nothing at all, with no error anywhere.
    ///
    /// There is no way to assert this behaviourally without a window server and two process
    /// launches, so the source is locked instead — the same idiom as
    /// `commands::os::tests::autostart_is_opt_in`.
    #[test]
    fn window_state_does_not_persist_visibility() {
        let lib = include_str!("lib.rs");
        assert!(
            lib.contains("!StateFlags::VISIBLE"),
            "lib.rs must remove StateFlags::VISIBLE from the window-state plugin's flags: with \
             it, D-8's hidden window is saved as visible:false and the next launch never calls \
             show()"
        );
    }

    /// The teardown of `RunEvent::Exit` is the only thing that flushes the WAL, stops the sync
    /// loop and lets the window-state plugin write its file — and it is reachable only because
    /// the tray's Quit calls `exit`. A `.run(context)` that takes no callback would silently
    /// drop all three.
    #[test]
    fn the_exit_callback_is_wired() {
        let lib = include_str!("lib.rs");
        assert!(
            lib.contains("RunEvent::Exit"),
            "lib.rs must handle RunEvent::Exit: it is where the scheduler is stopped and the \
             database is checkpointed"
        );
        assert!(
            lib.contains("engine.shutdown()"),
            "lib.rs's RunEvent::Exit must call SyncEngine::shutdown() — the WAL checkpoint and \
             the connection close live there"
        );
    }
}
