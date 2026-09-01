#!/usr/bin/env node
// Tauri CLI wrapper — builds the Content-Security-Policy from the SAME env file the frontend
// bundle is built from, then hands the rest of the command line to the real CLI untouched.
//
// WHY THIS EXISTS (`docs/DESKTOP-ARCHITECTURE.md` §E.5.2 ACIK 1). `tauri.conf.json` used to
// carry `http://localhost:8000` and `ws://localhost:8080` hard-coded inside the PRODUCTION
// `app.security.csp`. KARAR D-3 makes the API host a build-time constant (the frontend gets it
// from `VITE_API_URL`), so a packaged build against any other host would ship a CSP that blocks
// its own API — with no error message, because a CSP violation in a packaged webview is silent
// unless devtools are open. Deriving both from one source removes the possibility.
//
// O4 (closed by this pass): being the ONLY correct way to build was previously a convention.
// `tauri.conf.json`'s committed CSP is now a deny-everything placeholder and
// `src-tauri/build.rs` panics on a release build that did not come through here, so a direct
// `npx tauri build` can no longer produce a quietly localhost-configured package.
//
// The `tauri` script name is a contract with `desktop-ci.yml`, which calls
// `npm run tauri -- build --debug` (§E.5.2 ACIK 3) — hence a wrapper rather than a differently
// named script.
import { createRequire } from 'node:module'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'

// Resolution of every derived value lives in `build-env.mjs` so that
// `check-release-host.mjs` gates the exact values this file injects — see that module's header.
import { buildCsp, desktopRoot, resolveApiBase, resolveEnv } from './build-env.mjs'

const require = createRequire(import.meta.url)

const args = process.argv.slice(2)

// `--config` lives on the subcommands, not on the root command, so it is only appended for the
// ones that consume a config. `npm run tauri -- --version` or `... info` must stay untouched.
const CONFIG_AWARE = new Set(['dev', 'build', 'bundle', 'android', 'ios'])
const finalArgs = [...args]

if (CONFIG_AWARE.has(args[0])) {
  const env = resolveEnv()
  const overlay = { app: { security: { csp: buildCsp(env) } } }
  const outFile = resolve(desktopRoot, '.tauri/tauri.conf.generated.json')
  mkdirSync(dirname(outFile), { recursive: true })
  writeFileSync(outFile, `${JSON.stringify(overlay, null, 2)}\n`, 'utf8')
  // Merged onto `src-tauri/tauri.conf.json`; only `app.security.csp` is replaced. The CLI also
  // forwards this overlay to the build script as `TAURI_CONFIG`, which is what `build.rs`
  // inspects to prove the placeholder policy was actually replaced.
  finalArgs.push('--config', outFile)
  console.log(`[tauri] CSP from ../frontend/.env -> ${outFile}`)

  // Open item O27. `src-tauri/src/state.rs` reads the API base through
  // `option_env!("SYNCRA_API_URL")` and otherwise falls back to a hard-coded
  // `http://localhost:8000/api/`. Until now nothing set that variable, so the CSP (built
  // above from `VITE_API_URL`) and the host the engine actually talks to were two
  // independent truths: a build could ship a CSP for one origin and requests to another,
  // and no test would go red because the mismatch lives in the build input, not the code.
  // Deriving both from one value makes them wrong together and visibly, instead of
  // silently apart. An explicit SYNCRA_API_URL in the real process env still wins, which
  // is how CI and one-off builds override the host.
  if (!process.env.SYNCRA_API_URL) {
    process.env.SYNCRA_API_URL = resolveApiBase(env)
    console.log(`[tauri] SYNCRA_API_URL -> ${process.env.SYNCRA_API_URL}`)
  } else {
    console.log(`[tauri] SYNCRA_API_URL (from process env) -> ${process.env.SYNCRA_API_URL}`)
  }
}

const cli = require('@tauri-apps/cli')
cli.run(finalArgs, 'npm run tauri').catch((error) => {
  console.error(error.message ?? error)
  process.exit(1)
})
