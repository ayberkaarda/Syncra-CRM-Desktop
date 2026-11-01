#!/usr/bin/env node
// Release-host gate (open item O27) — refuses to let a PUBLISHED package be built against a
// host that only exists on the machine that built it, or that it would reach in clear text.
//
// WHAT IT ACTUALLY GUARDS
// Two build-time constants are derived from `frontend/.env` and frozen into the artifact:
//   * `SYNCRA_API_URL`      -> `src-tauri/src/state.rs`' `option_env!`, i.e. the base URL every
//                              HTTP call the desktop app makes is resolved against;
//   * `app.security.csp`    -> the packaged webview's Content-Security-Policy.
// Both are decided at build time and cannot be changed afterwards by a user, a setting or a
// server. If the release runner had no production `.env` (today `desktop-release.yml` seeds
// `.env.example`, which is localhost), the shipped installer talks to `http://localhost:8000`
// on the END USER's machine. Nothing crashes at build time, no test goes red, the bundle is
// signed and published; the failure surfaces only as "the app opens and nothing loads", on
// somebody else's computer. This script converts that into a build failure.
//
// WHY IT IS NOT PART OF `scripts/tauri.mjs`
// Building against localhost is legitimate and routine — `npm run dev`, `desktop-ci.yml`'s
// `tauri build --debug` matrix, and the `desktop-release-smoke` release-profile job all do it
// on purpose. A gate wired into the wrapper would fail every one of them. Only the RELEASE
// path (`.github/workflows/desktop-release.yml`) is entitled to demand a real host, so only
// that workflow runs this script.
//
// Run: `npm run check:release-host` (from `desktop/`).
// Exit 0 = the resolved API/Reverb hosts are publishable. Exit 1 = they are not.
//
// CLEAR TEXT (security review): every non-loopback origin baked into a release must be https
// (API, img-src) or wss (Reverb). An http:// or ws:// origin would send the device token and
// the user's data unencrypted, and nothing at run time can upgrade a CSP or an API base that
// was frozen at build time. Loopback hosts are refused here anyway, and development builds,
// which never run this script, keep using them over http/ws.

import { inspectReleaseTarget, resolveEnv } from './build-env.mjs'

const env = resolveEnv()
const { apiOrigin, apiBase, reverbOrigin, csp, violations } = inspectReleaseTarget(env)

console.log('release host check')
console.log('-'.repeat(78))
console.log(`VITE_API_URL       : ${env.VITE_API_URL ?? '(unset)'}`)
console.log(`VITE_REVERB_SCHEME : ${env.VITE_REVERB_SCHEME ?? '(unset -> ws)'}`)
console.log(`VITE_REVERB_HOST   : ${env.VITE_REVERB_HOST ?? '(unset -> localhost)'}`)
console.log(`VITE_REVERB_PORT   : ${env.VITE_REVERB_PORT ?? '(unset -> 8080)'}`)
console.log('-'.repeat(78))
console.log(`api origin (CSP)   : ${apiOrigin}`)
console.log(`SYNCRA_API_URL     : ${apiBase}   <- baked into the binary`)
console.log(`reverb origin (CSP): ${reverbOrigin}`)
console.log(`csp                : ${csp}`)
console.log('-'.repeat(78))

if (violations.length > 0) {
  console.error('')
  console.error(`FAILED — ${violations.length} value(s) may not be shipped in a release:`)
  for (const { field, value, reason } of violations) {
    console.error(`  - ${field} = ${value}  (${reason})`)
  }
  console.error('')
  console.error(
    'These values are frozen into the artifact at build time: the installer would ship a\n' +
      'binary whose API base and CSP point at the build machine itself (it would fail\n' +
      'silently for every user who installs it), or at a host reached without TLS (every\n' +
      'token and record would cross the network in clear text).\n' +
      '\n' +
      'Fix: give the release job a real production `frontend/.env` before it builds —\n' +
      '`.github/workflows/desktop-release.yml` reads it from `secrets.DESKTOP_RELEASE_ENV`\n' +
      'and falls back to the localhost `frontend/.env.example` when that secret is missing,\n' +
      'which is the situation this failure normally means. The contract is unchanged\n' +
      '(KARAR D-2/D-3): VITE_API_URL plus VITE_REVERB_SCHEME/HOST/PORT, with https for the\n' +
      'API and VITE_REVERB_SCHEME=https (which selects wss) for Reverb.',
  )
  process.exit(1)
}

console.log('OK — no loopback, link-local or clear-text (http/ws) host would be baked into this build.')
