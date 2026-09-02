// Single source of truth for every build-time value the desktop package derives from the
// frontend's `.env`: the API base URL that gets baked into the binary (`SYNCRA_API_URL` ->
// `src-tauri/src/state.rs`' `option_env!`) and the Content-Security-Policy that gets merged
// into `src-tauri/tauri.conf.json` (`app.security.csp`).
//
// WHY IT IS A SEPARATE MODULE (open items O4 / O27). Two consumers need the SAME answer:
//   1. `scripts/tauri.mjs`      — injects the values into the real build.
//   2. `scripts/check-release-host.mjs` — refuses a release whose values point at a loopback
//                                          or link-local host.
// A checker that re-implemented the resolution would drift from the wrapper, and the first
// symptom of that drift is a green gate over a wrongly-configured package — exactly the class
// of silent failure both open items are about. One module, one answer.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const desktopRoot = fileURLToPath(new URL('..', import.meta.url))
export const frontendRoot = resolve(desktopRoot, '../frontend')

/** Fallback used when `VITE_API_URL` is absent/unparseable — mirrors `state.rs`' built-in. */
export const FALLBACK_API_ORIGIN = 'http://localhost:8000'

/** Minimal `.env` reader — `KEY=value`, `#` comments, optional surrounding quotes. */
export function readEnvFile(path) {
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
export function resolveEnv() {
  return {
    ...readEnvFile(resolve(frontendRoot, '.env')),
    ...readEnvFile(resolve(frontendRoot, '.env.local')),
    ...Object.fromEntries(
      Object.entries(process.env).filter(([key]) => key.startsWith('VITE_') && process.env[key]),
    ),
  }
}

/** `http://localhost:8000/api/` -> `http://localhost:8000`. A CSP source is an origin, not a URL. */
export function toOrigin(value, fallback) {
  try {
    return new URL(value).origin
  } catch {
    return fallback
  }
}

export function resolveApiOrigin(env) {
  return toOrigin(env.VITE_API_URL ?? '', FALLBACK_API_ORIGIN)
}

/** What `SYNCRA_API_URL` is set to — `state.rs` parses this as the API *base*, not an origin. */
export function resolveApiBase(env) {
  return `${resolveApiOrigin(env)}/api/`
}

export function resolveReverbOrigin(env) {
  const scheme = env.VITE_REVERB_SCHEME === 'https' ? 'wss' : 'ws'
  const host = env.VITE_REVERB_HOST || 'localhost'
  const port = env.VITE_REVERB_PORT || '8080'
  return `${scheme}://${host}:${port}`
}

export function buildCsp(env) {
  const apiOrigin = resolveApiOrigin(env)
  const reverbOrigin = resolveReverbOrigin(env)

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

// ---------------------------------------------------------------------------
// Loopback / link-local classification (open item O27)
// ---------------------------------------------------------------------------

/**
 * `ipc: http://ipc.localhost` is a Tauri-internal CSP source, not a backend host: it is how
 * `invoke()` reaches the Rust side and it is present in EVERY correct build, on every host.
 * Scanning the CSP for loopback tokens without excluding it would make the release gate
 * unconditionally red and therefore useless.
 */
export const CSP_INTERNAL_SOURCES = ['ipc:', 'http://ipc.localhost']

/**
 * Is this hostname a loopback, "same machine", or link-local (mDNS) name — i.e. a host that
 * can only ever mean "the developer's own box" and must never be baked into a published
 * package? `hostname` is expected WITHOUT brackets for IPv6 (what `URL.hostname` returns is
 * bracketed, so callers strip them via `hostReason`).
 */
export function loopbackReason(hostname) {
  const host = String(hostname ?? '')
    .trim()
    .toLowerCase()
    .replace(/^\[|\]$/g, '')

  if (!host) return 'empty hostname'
  if (host === 'localhost' || host.endsWith('.localhost')) return 'localhost'
  if (/^127(\.\d{1,3}){3}$/.test(host)) return 'IPv4 loopback (127.0.0.0/8)'
  if (host === '0.0.0.0') return 'unspecified IPv4 address (0.0.0.0)'
  if (host === '::1' || host === '::' || host === '0:0:0:0:0:0:0:1') return 'IPv6 loopback (::1)'
  if (host.endsWith('.local')) return 'mDNS/link-local name (.local)'
  return null
}

/** Same test, given a full URL/origin string. Returns `{host, reason}` or `null`. */
export function hostReason(urlLike) {
  let hostname
  try {
    hostname = new URL(urlLike).hostname
  } catch {
    return { host: String(urlLike), reason: 'not a parseable absolute URL' }
  }
  const reason = loopbackReason(hostname)
  return reason ? { host: hostname, reason } : null
}

/**
 * Everything a release gate needs to decide, computed from one env snapshot:
 * the values that will be injected, plus every reason this configuration is not shippable.
 */
export function inspectReleaseTarget(env = resolveEnv()) {
  const apiOrigin = resolveApiOrigin(env)
  const reverbOrigin = resolveReverbOrigin(env)
  const csp = buildCsp(env)

  const violations = []

  if (!env.VITE_API_URL) {
    violations.push({
      field: 'VITE_API_URL',
      value: '(unset)',
      reason: `unset — the build would fall back to ${FALLBACK_API_ORIGIN}`,
    })
  } else {
    const bad = hostReason(env.VITE_API_URL)
    if (bad) violations.push({ field: 'VITE_API_URL', value: env.VITE_API_URL, reason: bad.reason })
  }

  const reverbBad = hostReason(reverbOrigin)
  if (reverbBad) {
    violations.push({
      field: 'VITE_REVERB_HOST',
      value: env.VITE_REVERB_HOST ? reverbOrigin : `${reverbOrigin} (VITE_REVERB_HOST unset)`,
      reason: reverbBad.reason,
    })
  }

  // Belt and braces: `buildCsp` may grow new sources later. Anything in the emitted policy
  // that is not a Tauri-internal source and still names a loopback host is a violation, even
  // if it did not come from one of the two variables checked above.
  for (const token of csp.split(/[;\s]+/).filter(Boolean)) {
    if (!token.includes('://')) continue
    if (CSP_INTERNAL_SOURCES.includes(token)) continue
    const bad = hostReason(token)
    if (bad && !violations.some((v) => v.value.includes(bad.host))) {
      violations.push({ field: 'csp source', value: token, reason: bad.reason })
    }
  }

  return { apiOrigin, apiBase: resolveApiBase(env), reverbOrigin, csp, violations }
}
