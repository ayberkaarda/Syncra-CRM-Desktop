//! Syncra desktop shell — a thin Tauri 2 adapter over the [`syncra_sync`] engine.
//!
//! This file wires the plugin set (§6.1), the command surface (§6.2), capabilities and CSP
//! (§6.3, `docs/DESKTOP-ARCHITECTURE.md` §5.5), the close-to-tray window default (D-8), the
//! tray icon (§6.4, `crate::tray`) and the `RunEvent::Exit` teardown ([`teardown`]).
//!
//! Still out of scope here: the OS network-event listener, and anything under
//! `desktop/src/**` or `desktop/vite.desktop.config.ts`.
//!
//! [`syncra_sync::SyncEngine::start_background_sync`] used to be on that list, deferred to
//! F5-1 alongside the tray. O46 moved it here: `SYNCDESKTOP.md` §5.5 opens its trigger list
//! with **"open"**, so the loop starting when the app starts is the specification itself, and
//! the F4 acceptance run proved what deferring it costs — the network was back for 79 seconds,
//! the app never noticed, and restarting it was the only way out. The tray and the OS network
//! event are still F5; they make the loop *faster*, not *possible*.

mod clipboard;
mod commands;
// F8/1 (KARAR K15): where the mirror and the blob cache live, and how that is changed.
mod data_dir;
mod deep_link;
mod events;
mod jump_list;
mod logging;
mod quick_capture;
mod state;
mod tray;
// No production code — `SYNCDESKTOP.md` §9 item 8's evidence: the plugin's signature-
// verification path cited file:line, plus tests that run this app's own `plugins.updater`
// block through the plugin's own `Config` deserializer (a keyless config is rejected).
mod updater;

use tauri::{AppHandle, Manager, RunEvent, Runtime, WindowEvent};
#[cfg(desktop)]
use tauri_plugin_deep_link::DeepLinkExt;
use tauri_plugin_window_state::StateFlags;

use state::AppState;

/// Build and run the Tauri application.
///
/// `#[cfg_attr(mobile, ...)]` is inert today (K11: no mobile target), kept only because it
/// is the standard `lib.rs` shape `tauri::generate_context!()` expects if mobile ever enters
/// scope.
#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    // FIRST, before any window, plugin or webview exists. Windows files a jump list by
    // AppUserModelID, the installer stamps `com.syncra.desktop` onto the Start-menu shortcut,
    // and this process declared nothing at all until F7 — so every list it committed would have
    // been filed under a system-derived id the shortcut does not share, and the menu would be
    // empty with every COM call returning `S_OK`. Microsoft's rule is that the id must be set
    // before the process shows any UI, which is why this is the first statement of `run` and
    // not a line inside `.setup()`. See `crate::jump_list` for the whole story.
    jump_list::set_process_aumid();

    let mut builder = tauri::Builder::default();

    // Must be the first plugin registered (`docs/DESKTOP-ARCHITECTURE.md` §5.1) — desktop
    // only; mobile is out of scope (K11) but the crate still gates on `cfg(desktop)` itself.
    #[cfg(desktop)]
    {
        builder = builder.plugin(tauri_plugin_single_instance::init(|app, args, _cwd| {
            // A second launch surfaces the existing window instead of starting a second
            // instance.
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.show();
                let _ = window.set_focus();
            }

            // §6.4: "forwarded to the existing window via single-instance". On Windows and
            // Linux a `syncra://` link does not reach a running process at all — the OS starts
            // a NEW one with the url as its only argument, and this handler is where that
            // second process's argv arrives. `handle_cli_arguments` checks the argument against
            // the schemes declared in `tauri.conf.json` and, on a match, fires the plugin's
            // `on_open_url` — which `crate::deep_link::install` has already subscribed to. So
            // the validation and the routing stay in one place instead of being written twice.
            //
            // macOS never gets here: it delivers the url as an `Open URL` Apple event to the
            // running instance, which the plugin turns into the same `on_open_url` call.
            app.deep_link().handle_cli_arguments(args.iter());
        }));
    }

    // THE UPDATER MINE, defused (`docs/DESKTOP-ARCHITECTURE.md` §E.5.3, open item O12).
    // `tauri-plugin-updater`'s `Config` has a mandatory, default-less `pubkey: String`, read out
    // of `plugins.updater` in `tauri.conf.json`. That block was missing until F7, so registering
    // the plugin made `.setup()` fail and the app never opened at all — measured, not predicted:
    // `PluginInitialization("updater", "invalid type: null, expected struct Config")`, exit 101.
    //
    // It used to be registered under `#[cfg(not(debug_assertions))]` so `tauri dev` could run at
    // all. That `cfg` is gone on purpose, and its absence is the point: CI builds with
    // `--debug`, which keeps `debug_assertions` on, so a `cfg(not(debug_assertions))` block is
    // never compiled there — the panic above could not have been caught by any CI job. The
    // registration is now unconditional, which means every build (debug, CI, release) exercises
    // the same plugin-init path, and `desktop-ci.yml`'s Windows release smoke exercises the same
    // binary shape we ship. Signing key: minisign `3E1D6B1F3C9F300F`, private half held only by
    // the repo owner and in `TAURI_SIGNING_PRIVATE_KEY(_PASSWORD)`, never in this tree.
    builder = builder.plugin(tauri_plugin_updater::Builder::new().build());

    builder
        // `StateFlags::default()` is `all()`, which includes `VISIBLE` — and that one is a
        // trap here. D-8 hides the main window instead of closing it, and the plugin writes
        // its file at exactly one moment, `RunEvent::Exit`; a user who closed to tray and then
        // quit therefore saves `visible: false`, and `restore_state` skips its `show()` on the
        // next launch. Today that is invisible because `tauri.conf.json` still declares
        // `"visible": true`, so the window is shown by the config regardless. The day that
        // flips — a window created hidden so it can be positioned first, say — the app opens
        // to nothing at all, with no error anywhere. Geometry is worth persisting; a
        // visibility bit that D-8 guarantees will read `false` is not.
        // Locked by `tray::tests::window_state_does_not_persist_visibility`.
        .plugin(
            tauri_plugin_window_state::Builder::default()
                .with_state_flags(StateFlags::all() & !StateFlags::VISIBLE)
                .build(),
        )
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_global_shortcut::Builder::new().build())
        .plugin(tauri_plugin_deep_link::init())
        .plugin(tauri_plugin_autostart::init(
            tauri_plugin_autostart::MacosLauncher::LaunchAgent,
            None,
        ))
        .plugin(tauri_plugin_clipboard_manager::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_fs::init())
        .plugin(tauri_plugin_os::init())
        .plugin(tauri_plugin_process::init())
        .plugin(tauri_plugin_shell::init())
        // Level and format are not the plugin's defaults (`Trace`, unmasked) — see
        // `logging.rs` (SYNCDESKTOP §9/9): release must not write DEBUG to disk, and no
        // record — ours or a dependency's — reaches any sink without the PII mask applied.
        //
        // Rotation is not the plugin's defaults either (O92): unconfigured, this plugin
        // rotates at `RotationStrategy::KeepOne` (`tauri-plugin-log` 2.9.0,
        // `DEFAULT_ROTATION_STRATEGY`) once `Syncra.log` crosses `DEFAULT_MAX_FILE_SIZE` —
        // 40,000 bytes. `KeepOne`'s `rotate()` calls `fs::remove_file` on the file that just
        // hit the cap, with no rename first — the lines in it are gone, not archived. At the
        // default ~40 KB threshold that firing routinely mid-measurement was exactly the
        // failure mode O92 caught: a `deep link rejected` line present on one run's disk and
        // silently rotated away on the next. `logging::LOG_MAX_FILE_SIZE_BYTES` and
        // `logging::LOG_ROTATION_STRATEGY` fix both factors — see `logging.rs` for the
        // reasoning behind each value.
        .plugin(
            tauri_plugin_log::Builder::new()
                .level(logging::level_for_build())
                .format(logging::masking_format)
                .max_file_size(logging::LOG_MAX_FILE_SIZE_BYTES)
                .rotation_strategy(logging::LOG_ROTATION_STRATEGY)
                .build(),
        )
        .invoke_handler(tauri::generate_handler![
            commands::auth::login,
            commands::auth::restore,
            commands::auth::logout,
            commands::auth::session,
            commands::auth::list_devices,
            commands::auth::revoke_device,
            commands::data::query,
            commands::data::mutate,
            commands::data::search,
            commands::sync::bootstrap,
            commands::sync::sync_now,
            commands::sync::status,
            commands::sync::conflicts,
            commands::sync::resolve_conflict,
            commands::sync::download_archive,
            commands::sync::handle_realtime,
            commands::storage::storage_stats,
            commands::storage::update_settings,
            commands::storage::storage_settings,
            commands::storage::clear_local,
            // F8/1 (KARAR K15): the mirror + cache root is a user choice, and moving it is a
            // whole procedure (close the engine, copy, verify, record, delete, reopen) rather
            // than a setting — see `storage::move_data_dir`'s doc comment. Written without the
            // `commands::` prefix on purpose: `check-command-wiring.mjs` reads registrations
            // out of this block with a `commands::<mod>::<fn>` regex, and a prose mention in
            // the fully-qualified form reads as a second registration of the same name.
            commands::storage::data_location,
            commands::storage::move_data_dir,
            // F5-5 / F5-8 (§6.4 drag-drop, PDF cache, screenshot). All file IO lives in Rust:
            // `Shell::open`'s Rust path passes no scope, so `open_cached` does its own
            // containment check rather than leaning on `shell:allow-open` (which only binds JS).
            commands::files::cache_quote_pdf,
            commands::files::open_cached,
            commands::files::attach_from_paths,
            commands::files::screenshot_to_ticket,
            // F5-2 / F5-7 (§6.4 notification, badge, autostart) and F5-3 (`register_hotkey`,
            // which completes the five `os::*` commands §6.2 lists — the shortcut itself is
            // claimed at setup time below, this command is how the user's choice replaces it).
            commands::os::notify,
            commands::os::set_badge,
            commands::os::set_autostart,
            commands::os::get_autostart,
            commands::os::register_hotkey,
            // NOT a §6.2 command — see its doc comment and `check-command-wiring.mjs`'s
            // `UNDOCUMENTED_COMMANDS` entry (defter C1).
            commands::os::set_tray_language,
            // §6.4 "JumpList: son 5 kayıt" (F7, defter O85). NOT a §6.2 command yet — see its
            // doc comment and `check-command-wiring.mjs`'s `UNDOCUMENTED_COMMANDS` entry.
            commands::os::record_opened,
        ])
        .setup(|app| {
            let handle = app.handle().clone();
            let state = tauri::async_runtime::block_on(AppState::init(&handle))?;
            // Must start before the state is moved into the app: `TablesChanged` is what
            // keeps the UI's query cache honest, and an event emitted before the bridge
            // exists is simply lost (the channel only replays to live subscribers).
            events::forward_engine_events(handle.clone(), &state.engine());

            // O46 B2 — "open" is the first trigger in `SYNCDESKTOP.md` §5.5. The loop syncs on
            // its 60 second timer while online and probes for the network while offline, which
            // is what lets the app come back on its own after an outage.
            //
            // Inside `block_on` because `start_background_sync` calls `tokio::spawn`, and
            // `.setup()` runs on the main thread, outside any runtime context; `block_on`
            // enters Tauri's global runtime, which is the same one the commands await on. The
            // closure itself does no awaiting, so this does not block startup.
            //
            // The handle goes into the managed state rather than being dropped on the floor.
            // Dropping it would not kill the loop — `SyncScheduler` wraps a `JoinHandle`, and
            // dropping one detaches the task — but a detached loop is a loop nothing can ever
            // stop or account for. Keeping it in the state ties its lifetime to the app's, on
            // purpose and visibly, and leaves `SyncScheduler::stop` reachable if a later phase
            // needs it (a "pause sync" setting, a teardown path).
            let scheduler =
                tauri::async_runtime::block_on(async { state.engine().start_background_sync() });
            *state
                .scheduler
                .lock()
                .expect("a freshly built AppState's scheduler mutex cannot be poisoned") =
                Some(scheduler);

            app.manage(state);

            // §6.4: the tray icon, its five item menu and the status picture. After
            // `app.manage`, because the initial icon, tooltip and language are all read out of
            // `AppState`.
            tray::init(&handle)?;

            // §6.4: claim `CmdOrCtrl+Shift+Space` for the quick-capture window (F5-3).
            //
            // Here rather than in a command because a global shortcut is a process-wide OS
            // claim with the app's own lifetime — a command that had to be invoked before the
            // hotkey worked would mean no hotkey until the webview had booted, which is
            // precisely the moment a user reaches for it least.
            //
            // Non-fatal by design (see `quick_capture::install_default`): the combination
            // being taken by another application is ordinary, and the tray's `Quick capture`
            // item opens the same window with no shortcut involved. The webview replaces this
            // claim with the user's own accelerator through `os::register_hotkey`.
            //
            // Safe inside `.setup()` despite the plugin's `run_on_main_thread` hop:
            // `tauri_runtime_wry::send_user_message` runs the task INLINE when it is already
            // on the main thread, which `.setup()` is — the event loop has not started yet, so
            // a queued task would have deadlocked on its own reply channel.
            quick_capture::install_default(&handle);

            // §6.4: `syncra://<entity>/<id>`. Subscribes to the plugin's `on_open_url` and
            // consumes the url this process was launched with, so a link clicked while the app
            // was closed is not lost. Every url is validated against §6.4's regex and the eight
            // entity names before anything reaches the webview — see `crate::deep_link`.
            #[cfg(desktop)]
            deep_link::install(&handle);

            // §6.4 item 6: clipboard capture. The loop is always spawned; it reads nothing
            // until `DesktopSettings::clipboard_capture` is switched on, which is off by
            // default (K10) and only ever written by the settings screen. See
            // `crate::clipboard` for how §9 item 6 ("the content is not written to disk or
            // log") is guaranteed structurally rather than by care.
            clipboard::start(&handle);

            // §6.4 "JumpList: son 5 kayıt" — restore the list this install last committed.
            //
            // The shell persists a jump list itself, so this is not what makes the menu survive
            // a restart; it is what makes it survive the things that silently drop it (a
            // `DeleteList` from a previous `clear`, an AUMID that only started being declared
            // this version, a `CustomDestinations` file the shell discarded). Rebuilding from
            // our own store on every launch means the menu converges on the truth instead of
            // waiting for the user to open a record.
            //
            // Best-effort and non-fatal: `.setup()` returning `Err` means the app does not
            // open, and no jump list is worth that. A fresh install has no stored category
            // label and `jump_list::rebuild` returns `Ok(())` without touching the shell — see
            // `RecentStore::category`.
            {
                let store = jump_list::load_recent(&jump_list::recent_path(
                    &app.state::<AppState>().root_dir(),
                ));
                if let Err(error) = jump_list::rebuild(&store) {
                    tracing::warn!(code = %error.code, message = %error.message, "jump list: the startup rebuild failed");
                }
            }

            // D-8 / §6.4 "Pencere kapatma → tray'e (ayar)" — a setting, and now actually one.
            //
            // `DesktopSettings::close_to_tray` is the flag (defaulting to `true`, which is the
            // behaviour every existing install already has). It is read **on every close**,
            // not captured here: the handler is installed once at startup and lives for the
            // process, so a snapshot taken now would mean the toggle only took effect after a
            // restart — which is exactly the kind of setting users conclude is broken.
            //
            // `false` means the close is allowed through. That does not quit the app by itself:
            // Tauri exits when the last window closes, and the quick-capture window may still
            // be alive, so the tray's `Quit` remains the one deliberate way out (and the one
            // path that reaches the `RunEvent::Exit` teardown).
            if let Some(window) = app.get_webview_window("main") {
                let hide_target = window.clone();
                let close_handle = handle.clone();
                window.on_window_event(move |event| {
                    if let WindowEvent::CloseRequested { api, .. } = event {
                        if close_to_tray(&close_handle) {
                            api.prevent_close();
                            let _ = hide_target.hide();
                        }
                    }
                });
            }

            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("error while building the Syncra desktop application")
        .run(|app, event| {
            if let RunEvent::Exit = event {
                teardown(app);
            }
        });
}

/// Whether closing the main window should hide it instead (`DesktopSettings::close_to_tray`).
///
/// Defaults to `true` when the settings cannot be read at all — a state that only exists if
/// `.setup()` failed before managing the engine. Hiding a window is recoverable from the tray;
/// letting it close when the user asked for the opposite is not.
fn close_to_tray<R: Runtime>(app: &AppHandle<R>) -> bool {
    app.try_state::<AppState>()
        .map(|state| state.engine().settings().close_to_tray)
        .unwrap_or(true)
}

/// Orderly shutdown, run once from `RunEvent::Exit`.
///
/// **Reaching this function at all is the point of the tray's Quit item.** D-8 turns every
/// `CloseRequested` into `prevent_close()` + `hide()`, so before F5-1 the only way to end the
/// process was to kill it — and a killed process emits no `Exit`. Three things were silently
/// skipped on every single run:
///
/// * `tauri-plugin-window-state` writes `.window-state.json` **only** from its own
///   `RunEvent::Exit` hook, so the file had never once been written on a developer machine;
/// * the background sync loop was never stopped;
/// * the SQLCipher connection was never checkpointed, leaving a `-wal`/`-shm` pair behind
///   after every run (measured: a 4.2 MB `-wal` next to a 659 KB database).
///
/// # Why `stop()` before `shutdown()`
///
/// They are the two halves of one teardown and `SyncEngine::shutdown`'s own doc comment fixes
/// the order. `SyncScheduler::stop` is the **task** half: it aborts the tokio task, which is
/// the only way to cut short a round currently awaiting an HTTP response, and it knows nothing
/// about the database. `SyncEngine::shutdown` is the **engine** half: it cannot abort that
/// task (the engine never owned the handle), so it raises the `stopping` flag the loop checks,
/// then checkpoints the WAL with `PRAGMA wal_checkpoint(TRUNCATE)` and closes the connection.
///
/// Stopping first means the checkpoint does not race a round that is still writing; doing it
/// the other way round would leave `shutdown` waiting for a loop that only leaves at its next
/// check point. Both are idempotent, and neither loses data — the outbox is durable.
fn teardown<R: Runtime>(app: &AppHandle<R>) {
    let Some(state) = app.try_state::<AppState>() else {
        // `.setup()` failed before the state was managed; there is nothing to tear down.
        return;
    };

    match state.scheduler.lock() {
        Ok(mut slot) => {
            // `None` here is not an error: "Pause sync" leaves the slot empty on purpose.
            if let Some(scheduler) = slot.take() {
                scheduler.stop();
            }
        }
        Err(error) => tracing::warn!(%error, "scheduler mutex poisoned; skipping the task half"),
    }

    // Bound rather than chained off `state.engine()` so the clone this shuts down is dropped
    // at the end of the function and not held for the rest of it — the same reason
    // `commands::storage::move_data_dir` scopes its own `shutdown` call.
    let engine = state.engine();
    if let Err(error) = engine.shutdown() {
        tracing::warn!(%error, "the sync engine did not shut down cleanly");
    }
}
