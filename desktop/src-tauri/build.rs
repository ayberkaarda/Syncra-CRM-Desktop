fn main() {
    // `state.rs` bakes the API base in with `option_env!("SYNCRA_API_URL")`. Cargo does not
    // track `option_env!` inputs on its own, so without this line a rebuild after changing
    // `frontend/.env` (which `scripts/tauri.mjs` derives the value from) silently keeps the
    // URL compiled into the previous artifact — the build looks fresh and talks to the old
    // host. Open item O27.
    println!("cargo:rerun-if-env-changed=SYNCRA_API_URL");
    tauri_build::build();
}
