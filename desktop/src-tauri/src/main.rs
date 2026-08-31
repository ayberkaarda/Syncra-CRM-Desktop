// Prevents an additional console window on Windows in release builds. Keep this exact
// attribute shape — it is the one `tauri-cli`'s templates and docs assume.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    syncra_desktop_lib::run();
}
