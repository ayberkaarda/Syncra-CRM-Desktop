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
// The `tauri` script name is a contract with `desktop-ci.yml`, which calls
// `npm run tauri -- build --debug` (§E.5.2 ACIK 3) — hence a wrapper rather than a differently
// named script.
import { createRequire } from 'node:module'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const desktopRoot = fileURLToPath(new URL('..', import.meta.url))

/** Minimal `.env` reader — `KEY=value`, `#` comments, optional surrounding quotes. */
function readEnvFile(path) {
  let raw
  try {
    raw = readFileSync(path, 'utf8')
  } catch {
    return {}
  }
  const out = {}
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) continue
    const eq = trimmed.indexOf('=')
    if (eq < 1) continue
    const key = trimmed.slice(0, eq).trim()
    const value = trimmed.slice(eq + 1).trim()
    out[key] = value.replace(/^(['"])(.*)\1$/, '$2')
  }
  return out
}

// KARAR D-2: one `.env`, the frontend's. Same precedence Vite uses — `.env` then `.env.local`,
// with a real process env variable winning over both (that is how CI overrides the host).
function resolveEnv() {
  const frontend = resolve(desktopRoot, '../frontend')
  return {
    ...readEnvFile(resolve(frontend, '.env')),
    ...readEnvFile(resolve(frontend, '.env.local')),
    ...Object.fromEntries(
      Object.entries(process.env).filter(([key]) => key.startsWith('VITE_') && process.env[key]),
    ),
  }
}

/** `http://localhost:8000/api/` -> `http://localhost:8000`. A CSP source is an origin, not a URL. */
function toOrigin(value, fallback) {
  try {
    return new URL(value).origin
  } catch {
    return fallback
  }
}

function buildCsp(env) {
  const apiOrigin = toOrigin(env.VITE_API_URL ?? '', 'http://localhost:8000')

  const scheme = env.VITE_REVERB_SCHEME === 'https' ? 'wss' : 'ws'
  const host = env.VITE_REVERB_HOST || 'localhost'
  const port = env.VITE_REVERB_PORT || '8080'
  const reverbOrigin = `${scheme}://${host}:${port}`

  // `ipc: http://ipc.localhost` is NOT optional (§5.5 duzeltme 1 / S1): Tauri 2 routes every
  // `invoke()` through `connect-src`, so without it the app opens and then every command fails.
  // `style-src-attr` is the S2 fix: once Tauri injects a nonce into `style-src`, the spec makes
  // the browser ignore `'unsafe-inline'` there, which breaks every library that writes an inline
  // `style=""` attribute — `style-src-attr` is not affected by the nonce.
  return [
    "default-src 'self'",
    `connect-src 'self' ipc: http://ipc.localhost ${apiOrigin} ${reverbOrigin}`,
    `img-src 'self' data: ${apiOrigin}`,
    "style-src 'self' 'unsafe-inline'",
    "style-src-attr 'unsafe-inline'",
    // Poppins is self-hosted through `@fontsource` (`frontend/src/index.css`), so no font CDN
    // origin is needed — `data:` covers the inlined faces (`docs/DESIGN-SYSTEM.md` §9).
    "font-src 'self' data:",
    "object-src 'none'",
    "frame-ancestors 'none'",
  ].join('; ')
}

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
  // Merged onto `src-tauri/tauri.conf.json`; only `app.security.csp` is replaced.
  finalArgs.push('--config', outFile)
  console.log(`[tauri] CSP from ../frontend/.env -> ${outFile}`)
}

const cli = require('@tauri-apps/cli')
cli.run(finalArgs, 'npm run tauri').catch((error) => {
  console.error(error.message ?? error)
  process.exit(1)
})
