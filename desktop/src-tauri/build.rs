use std::env;

// Build-input guard for the two values this crate freezes at compile time, plus the usual
// `tauri_build::build()`.
//
// THE FAILURE THIS PREVENTS (open items O4 / O27)
// `desktop/scripts/tauri.mjs` is the only place that derives, from `frontend/.env`:
//   * `SYNCRA_API_URL`  — read by `src/state.rs` through `option_env!`, i.e. the API base baked
//                          into the binary;
//   * `app.security.csp` — passed to the CLI as a `--config` overlay, which the CLI forwards to
//                          this build script as `TAURI_CONFIG`.
// Until now that wrapper was a convention. `npx tauri build` (or `cargo build --release`)
// skipped it and produced a perfectly ordinary, signable package that pointed at
// `http://localhost:8000` — the `option_env!` fallback plus the placeholder policy in
// `tauri.conf.json` — with no warning of any kind. A silently wrong artifact is worse than a
// failed build, so a release build without those inputs is now a hard compile error.
//
// SCOPE: release profile only. `cargo test`/`cargo clippy` (`desktop-ci.yml`'s `desktop-rust`
// job) and `tauri build --debug` run in the debug profile and are untouched by the panic; a
// debug build that skipped the wrapper only gets a `cargo:warning`.
//
// This guard deliberately does NOT reject a localhost value. Release-profile builds against
// localhost are legitimate — `desktop-ci.yml`'s `desktop-release-smoke` job is exactly that.
// Refusing a loopback host is a RELEASE-PIPELINE decision and lives in
// `desktop/scripts/check-release-host.mjs`, which only `desktop-release.yml` runs.
fn main() {
    // `state.rs` bakes the API base in with `option_env!("SYNCRA_API_URL")`. Cargo does not
    // track `option_env!` inputs on its own, so without this line a rebuild after changing
    // `frontend/.env` (which `scripts/tauri.mjs` derives the value from) silently keeps the
    // URL compiled into the previous artifact — the build looks fresh and talks to the old
    // host. Open item O27.
    println!("cargo:rerun-if-env-changed=SYNCRA_API_URL");

    // `PROFILE` is `release` for release-like profiles and `debug` otherwise (cargo build-script
    // contract). `debug_assertions` is not available to a build script, so this is the profile
    // signal to use here.
    if env::var("PROFILE").as_deref() == Ok("release") {
        guard_release_inputs();
    } else if env::var_os("SYNCRA_API_URL").is_none() {
        println!(
            "cargo:warning=SYNCRA_API_URL is unset: this build falls back to the built-in \
             http://localhost:8000/api/ and to the deny-everything placeholder CSP in \
             tauri.conf.json (the packaged webview would load nothing). Build from `desktop/` \
             with `npm run dev` or `npm run tauri -- build ...` instead of calling cargo/tauri \
             directly."
        );
    }

    tauri_build::build();
}

const WRAPPER_HINT: &str = "\
Build the desktop app through its wrapper:

    cd desktop && npm run tauri -- build [--no-bundle] --features custom-protocol

`desktop/scripts/tauri.mjs` derives the API base URL and the Content-Security-Policy from
`frontend/.env` (VITE_API_URL, VITE_REVERB_SCHEME/HOST/PORT) and injects both into the build.
Calling `npx tauri build` or `cargo build --release` directly skips that step, which used to
produce a package pointing at localhost with no error at all (open items O4 / O27).";

fn guard_release_inputs() {
    let api_url = env::var("SYNCRA_API_URL").unwrap_or_default();
    let api_url = api_url.trim();

    if api_url.is_empty() {
        panic!(
            "\n\n\
             RELEASE BUILD REFUSED: SYNCRA_API_URL is not set.\n\
             Without it `src/state.rs` falls back to `http://localhost:8000/api/`, and that\n\
             fallback would be frozen into the shipped binary.\n\n\
             {WRAPPER_HINT}\n"
        );
    }

    let Some(origin) = origin_of(api_url) else {
        panic!(
            "\n\n\
             RELEASE BUILD REFUSED: SYNCRA_API_URL = {api_url:?} is not an absolute http(s) URL.\n\
             `src/state.rs` parses this value with `Url::parse` and panics at runtime on a bad\n\
             one, i.e. the artifact would build fine and then die on first launch.\n\n\
             {WRAPPER_HINT}\n"
        );
    };

    // The CLI hands the `--config` overlay to build scripts as `TAURI_CONFIG` (this is the
    // documented channel `tauri_build::try_build` itself reads to merge it). Its absence means
    // no overlay was passed, i.e. the committed placeholder CSP in `tauri.conf.json` is what
    // would be compiled into the app.
    let overlay = env::var("TAURI_CONFIG").unwrap_or_default();

    if overlay.trim().is_empty() {
        panic!(
            "\n\n\
             RELEASE BUILD REFUSED: no Tauri config overlay was passed (TAURI_CONFIG is unset),\n\
             so `src-tauri/tauri.conf.json`'s placeholder `app.security.csp` — which denies\n\
             every source — would be the shipped policy. The packaged webview would render a\n\
             blank window.\n\n\
             {WRAPPER_HINT}\n"
        );
    }

    if !overlay.contains("\"csp\"") {
        panic!(
            "\n\n\
             RELEASE BUILD REFUSED: the Tauri config overlay carries no `app.security.csp`, so\n\
             the placeholder policy in `src-tauri/tauri.conf.json` would be the shipped one.\n\n\
             overlay: {overlay}\n\n\
             {WRAPPER_HINT}\n"
        );
    }

    // The generated CSP's `connect-src`/`img-src` are built from the SAME `VITE_API_URL` that
    // produced `SYNCRA_API_URL`. If the origin is missing from the policy, the two inputs came
    // from different places — the app would be allowed to talk to one host and compiled to call
    // another, and every request would be blocked silently in the packaged webview.
    if !overlay.contains(&origin) {
        panic!(
            "\n\n\
             RELEASE BUILD REFUSED: the Content-Security-Policy does not allow the API origin\n\
             this binary is being compiled against.\n\n\
               SYNCRA_API_URL origin : {origin}\n\
               config overlay        : {overlay}\n\n\
             The CSP and the baked-in URL must come from one `frontend/.env`; they do not here,\n\
             so every API call would be blocked by the policy with no visible error.\n\n\
             {WRAPPER_HINT}\n"
        );
    }
}

/// `https://crm.example.com/api/` -> `https://crm.example.com`. `std`-only on purpose: this
/// build script has exactly one build-dependency (`tauri-build`) and adding a URL parser to the
/// build graph for one string split is not worth it.
fn origin_of(url: &str) -> Option<String> {
    let (scheme, rest) = url.split_once("://")?;
    if scheme != "http" && scheme != "https" {
        return None;
    }
    let authority = rest.split(['/', '?', '#']).next().unwrap_or_default();
    if authority.is_empty() {
        return None;
    }
    Some(format!("{scheme}://{authority}"))
}
