import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Desktop Vite config — `docs/DESKTOP-ARCHITECTURE.md` §4.
//
// `frontend/vite.config.ts` is NOT touched (KARAR A1): the web bundle keeps its 8-line config
// and its exact behaviour. This file is the desktop-only twin, and every option below is here
// for a reason recorded in §4.2 — none of them are defaults-with-extra-steps.

const desktopRoot = fileURLToPath(new URL('.', import.meta.url))
const frontendRoot = fileURLToPath(new URL('../frontend/', import.meta.url))
const frontendSrc = fileURLToPath(new URL('../frontend/src', import.meta.url))
const frontendPublic = fileURLToPath(new URL('../frontend/public', import.meta.url))

export default defineConfig({
  // Vite's `root` defaults to `process.cwd()`, not to the config file's directory. Pinning it
  // means `npm run build:desktop` produces the same `desktop/dist` no matter which directory
  // the command was launched from (Tauri runs `beforeBuildCommand` from the app dir, CI may
  // not).
  root: desktopRoot,

  // Same plugin set as `frontend/vite.config.ts`, in the same order.
  plugins: [react(), tailwindcss()],

  resolve: {
    // §4.2: lets the desktop entry reach the shared app. `frontend/src`'s own imports are all
    // relative, so this alias serves `desktop/src` only. Mirrored by `paths` in
    // `desktop/tsconfig.json`.
    alias: {
      '@': frontendSrc,
    },
    // The shell has its own `node_modules` (KARAR A1 keeps `@tauri-apps/*` out of
    // `frontend/package.json`), so `react` is installed twice on disk: once here, once under
    // `frontend/`. Node resolution would hand `desktop/src/main.desktop.tsx` the local copy
    // and `frontend/src/App.tsx` the other one — two React instances in one page, which
    // breaks every hook with the notoriously unhelpful "invalid hook call". `dedupe` forces
    // BOTH to resolve to the copy found from `root`, i.e. exactly one React in the bundle.
    dedupe: ['react', 'react-dom'],
  },

  // One source of truth for `favicon.png` / `apple-touch-icon.png` / `logo-mark.png`; they are
  // served (and copied into `dist/`) straight out of the web app's public dir rather than
  // duplicated here.
  publicDir: frontendPublic,

  // KARAR D-2: a single `.env`, the web one. A desktop-only `VITE_*` variable added there is
  // harmless to the web build (Vite ignores variables nobody reads), whereas two env files
  // drift apart silently — the most expensive failure class in this project.
  envDir: frontendRoot,

  // `TAURI_ENV_*` carries the target platform/arch the CLI injects; `VITE_` must stay in the
  // list — replacing it rather than extending it makes every `import.meta.env.VITE_*` read in
  // `frontend/src` (API URL, Reverb host/port/scheme) resolve to `undefined`.
  envPrefix: ['VITE_', 'TAURI_ENV_*'],

  // `tauri dev` interleaves the Rust compiler's output with Vite's. Vite clearing the screen
  // on restart would wipe rustc errors mid-read.
  clearScreen: false,

  server: {
    // `tauri.conf.json` -> `build.devUrl` is the fixed string `http://localhost:1420`; Tauri
    // does not probe for the real port. `strictPort` makes a taken port a loud failure
    // instead of Vite silently moving to 1421 and Tauri opening a blank window against 1420.
    port: 1420,
    strictPort: true,
    fs: {
      // The application source lives OUTSIDE this config's root (`../frontend/src`, plus
      // `../frontend/node_modules` for its dependencies). Vite refuses to serve outside the
      // root without this.
      allow: ['..'],
    },
    watch: {
      // Rust build output must not feed the HMR watcher.
      //
      // `**/target/**` is NOT optional and NOT a duplicate of the `src-tauri` entry. Removing
      // `desktop/.cargo/config.toml` (§E.5.2 ACIK 2) moved the workspace's `target/` from
      // `crates/syncra-sync/target` — outside this Vite root — to `desktop/target`, i.e. INSIDE
      // it. Chokidar then tries to watch `target/debug/deps/syncra_desktop_lib.dll` while the
      // running app holds it open, and node's watcher throws an unhandled `EBUSY` that kills the
      // dev server mid-`tauri dev` ("beforeDevCommand terminated with a non-zero status code").
      // Observed, not theorised.
      ignored: ['**/src-tauri/**', '**/target/**', '**/.tauri/**'],
    },
  },

  build: {
    // `tauri.conf.json` -> `build.frontendDist` is `../dist`, relative to `src-tauri/`.
    outDir: 'dist',

    // TWO entries, not one (F5-3). `quick-capture.html` is the page
    // `src-tauri/src/quick_capture.rs` opens as `WebviewUrl::App("quick-capture.html")`, and
    // it is a separate document rather than a route of the main app on purpose: the main
    // entry boots the router, the query client, the realtime bridge and the session restore
    // before it renders anything, and a 480×360 popup that has to appear the instant a hotkey
    // is pressed cannot wait for that. `quick_capture::tests::the_window_page_is_a_real_entry`
    // asserts this block still names the file — without it the popup opens blank in a bundled
    // build (dev works either way, which is exactly how this would ship broken).
    //
    // Naming `index.html` explicitly is required: the moment `input` is set, Vite stops
    // inferring the root `index.html` and would build ONLY the second page.
    rollupOptions: {
      input: {
        main: fileURLToPath(new URL('index.html', import.meta.url)),
        'quick-capture': fileURLToPath(new URL('quick-capture.html', import.meta.url)),
      },
    },
  },

  // `base` is deliberately left at its default `/`. Tauri's asset protocol strips the leading
  // slash and resolves against `frontendDist` (§4.3-1, read out of Tauri's `get_asset`), so
  // the root-absolute references already in `index.html` and in `Sidebar`/`LoginPage`/
  // `ChangePasswordPage` (`/logo-mark.png`) work unchanged. Setting `base: './'` here would
  // break them, not fix them.
  //
  // `define` is deliberately absent: KARAR D-1 / S4 — `__PLATFORM__` exists in no web build,
  // so the moment shared code reads it the web bundle breaks. Platform selection happens in
  // the entry file instead (KARAR A3).
})
