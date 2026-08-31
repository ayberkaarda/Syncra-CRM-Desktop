//! Syncra desktop shell — a thin Tauri 2 adapter over the [`syncra_sync`] engine.
//!
//! This turn (`SYNCDESKTOP.md` §10 F3, W2-b) wires the plugin set (§6.1), the command
//! surface (§6.2), capabilities and CSP (§6.3, `docs/DESKTOP-ARCHITECTURE.md` §5.5), and the
//! close-to-tray window default (D-8). It deliberately stops short of:
//!
//! * `files::*` / `os::*` commands (F5 scope),
//! * the tray icon itself (F5-1) and starting [`syncra_sync::SyncEngine::start_background_sync`]
//!   (that belongs with the tray/network-event triggers that give it something to react to),
//! * anything under `desktop/src/**` or `desktop/vite.desktop.config.ts` (a different strand
//!   is landing `frontend/src/platform`; W1 §E.3 keeps this turn off those files).

mod commands;
mod events;
mod logging;
mod state;

use tauri::{Manager, WindowEvent};

use state::AppState;

/// Build and run the Tauri application.
///
/// `#[cfg_attr(mobile, ...)]` is inert today (K11: no mobile target), kept only because it
/// is the standard `lib.rs` shape `tauri::generate_context!()` expects if mobile ever enters
/// scope.
#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let mut builder = tauri::Builder::default();

    // Must be the first plugin registered (`docs/DESKTOP-ARCHITECTURE.md` §5.1) — desktop
    // only; mobile is out of scope (K11) but the crate still gates on `cfg(desktop)` itself.
    #[cfg(desktop)]
    {
        builder = builder.plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            // A second launch surfaces the existing window instead of starting a second
            // instance. Deep-link argument handling (`syncra://...`) is F5-4 scope; the
            // deep-link plugin is registered below but not wired to this handler yet.
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.show();
                let _ = window.set_focus();
            }
        }));
    }

    // ⚠️ THE UPDATER MINE (`docs/DESKTOP-ARCHITECTURE.md` §E.5.3). `tauri-plugin-updater`'s
    // `Config` has a mandatory, default-less `pubkey: String`, read out of `plugins.updater` in
    // `tauri.conf.json`. That block does not exist yet — F7 owns the real minisign key and the
    // release manifest — so registering the plugin unconditionally makes `.setup()` fail and the
    // app never opens at all. That is what blocked `tauri dev` before this turn.
    //
    // Fix chosen: register it only where it can actually do something, i.e. NOT in a debug build.
    // The alternative (writing a throwaway pubkey into `tauri.conf.json`) was rejected: a fake
    // signing key committed to the repo is the kind of placeholder that survives to production.
    //
    // Consequence, recorded on purpose: a real `--release` build still needs `plugins.updater`
    // present. `tauri build --debug` (what CI runs) keeps `debug_assertions` on and is therefore
    // unaffected. F7's FIRST task is to add the block and delete this `cfg`.
    #[cfg(not(debug_assertions))]
    {
        builder = builder.plugin(tauri_plugin_updater::Builder::new().build());
    }

    builder
        .plugin(tauri_plugin_window_state::Builder::default().build())
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
        .plugin(
            tauri_plugin_log::Builder::new()
                .level(logging::level_for_build())
                .format(logging::masking_format)
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
            commands::storage::clear_local,
        ])
        .setup(|app| {
            let handle = app.handle().clone();
            let state = tauri::async_runtime::block_on(AppState::init(&handle))?;
            // Must start before the state is moved into the app: `TablesChanged` is what
            // keeps the UI's query cache honest, and an event emitted before the bridge
            // exists is simply lost (the channel only replays to live subscribers).
            events::forward_engine_events(handle.clone(), &state.engine);
            app.manage(state);

            // D-8: closing the main window minimizes to tray by default (the tray icon
            // itself is F5-1; until it exists this just hides the window — the shell comes
            // back via the `single-instance` second-instance handler above, or the OS
            // taskbar entry for the still-running process). Not yet backed by a setting —
            // that UI is F4/F5 scope.
            if let Some(window) = app.get_webview_window("main") {
                let hide_target = window.clone();
                window.on_window_event(move |event| {
                    if let WindowEvent::CloseRequested { api, .. } = event {
                        api.prevent_close();
                        let _ = hide_target.hide();
                    }
                });
            }

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running the Syncra desktop application");
}
