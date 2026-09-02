#!/usr/bin/env node
// Notification-catalogue drift check.
//
// The notification SENTENCES now live in two places that have to stay in lockstep by hand:
//
//   1. `backend/lang/<locale>/notifications.php`               — the source of truth, keyed
//      by notification type (`deal_assigned`, `task_assigned`, ...), each with a `title` and
//      one or more `body*`/`title*` variants, using Laravel `:param` placeholders.
//   2. `frontend/src/i18n/locales/<locale>/notifications.json` — the desktop-side mirror,
//      transcribed by hand with `:param` mechanically converted to i18next `{{param}}`.
//
// `frontend/src/features/notifications/notificationText.ts` resolves a notification's
// `title_key`/`body_key` (e.g. `notifications.deal_assigned.body_with_due`) against this
// JSON catalogue at read time. If (2) drifts from (1) — a key missing, an extra key nobody
// asked for, or a `{{param}}` name that no longer matches the `:param` the backend fills in —
// the desktop app either falls back to a generic label or renders a sentence with an empty
// hole, and nothing in `tsc`/`i18n:check` catches it (both only look at frontend/**;
// `i18n:check` compares locales to each other, not to the backend). This project has already
// shipped four separate instances of exactly this failure mode (wire dialect, `ApiErrorBody`,
// SLA columns, the action allowlist) — always the same shape: two sources, one silent drift.
//
// This script is that guard. It parses the backend PHP catalogue and the frontend JSON
// catalogue for each of the four locales and cross-checks, in both directions:
//
//   (a) backend has a key the frontend is missing  -> desktop cannot resolve it, falls back
//   (b) frontend has a key the backend never defines -> dead/invented key, drifts silently
//   (c) for every key present on both sides, the SET of parameter names must match
//       (`:deal` vs `{{deal}}`) — the most dangerous case, because the key resolves and text
//       renders, but the interpolation hole for the mismatched name stays empty.
//
// Run: `npm run i18n:notifications` (from `frontend/`).

import { readFileSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const FRONTEND = join(HERE, '..')
const REPO = join(FRONTEND, '..')

const LOCALES = ['tr', 'en', 'de', 'fr']

const backendPath = (locale) => join(REPO, `backend/lang/${locale}/notifications.php`)
const frontendPath = (locale) => join(FRONTEND, `src/i18n/locales/${locale}/notifications.json`)

/**
 * Top-level JSON keys that are UI chrome (breadcrumb text, tab labels, toasts, ...), not
 * backend-sourced notification message types. Message types are always the snake_case type
 * names the backend PHP catalogue declares (`deal_assigned`, `task_assigned`, ...); this list
 * is everything else that legitimately lives in this file and is NOT expected to have a
 * backend counterpart. If a new chrome namespace is ever added to notifications.json, add its
 * top-level key here too — otherwise this script reports it as an "invented" message key.
 */
const CHROME_KEYS = new Set(['breadcrumb', 'page', 'bell', 'list', 'relativeTime', 'toast'])

const problems = []
const notes = []

function fail(message) {
  problems.push(message)
}

function read(path) {
  return readFileSync(path, 'utf8')
}

// ------------------------------------------------------------------------------------------------
// Backend parser: `return [ 'type' => [ 'key' => 'value'|"value", ... ], ... ];`
//
// Deliberately paranoid: every step that could silently under-match cross-checks its own
// result against a cheap independent count of what SHOULD have been found, and throws
// (not `fail`s — throws, aborting the run) the moment the two disagree. A regex-based PHP
// array parser that goes quietly wrong is worse than no parser at all — see the module
// docblock above.
// ------------------------------------------------------------------------------------------------

/** Parses one `'key' => 'value'` or `'key' => "value"` block into `{ key -> unescaped value }`. */
function parseEntries(block, locale, type) {
  // Independent count of declared keys in this block (8-space indent), to catch any key the
  // value-regex below fails to consume.
  const declaredKeys = [...block.matchAll(/^ {8}'(\w+)'\s*=>/gm)].map((m) => m[1])

  const entries = {}
  // PHP allows either quote style per literal; this catalogue uses " when the text itself
  // contains an unescaped apostrophe (e.g. fr `deal_stage_changed.title`).
  const kvRe = /'(\w+)'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/g
  let km
  while ((km = kvRe.exec(block)) !== null) {
    const [, key, singleQuoted, doubleQuoted] = km
    const rawValue = singleQuoted !== undefined ? singleQuoted : doubleQuoted
    entries[key] = rawValue.replace(/\\(.)/g, '$1') // unescape \' \" \\
  }

  const missed = declaredKeys.filter((key) => !(key in entries))
  if (missed.length > 0) {
    throw new Error(
      `backend/lang/${locale}/notifications.php: type '${type}': found key line(s) [${missed.join(', ')}] the value-regex could not parse — fix the parser, do not report a partial catalogue`,
    )
  }
  if (declaredKeys.length === 0) {
    throw new Error(
      `backend/lang/${locale}/notifications.php: type '${type}': block parsed with zero keys — the source format changed, fix the parser`,
    )
  }
  return entries
}

function parsePhpCatalogue(locale) {
  const path = backendPath(locale)
  const source = read(path)

  const returnIdx = source.indexOf('return [')
  if (returnIdx === -1) {
    throw new Error(`backend/lang/${locale}/notifications.php: 'return [' not found — cannot parse PHP catalogue`)
  }
  const body = source.slice(returnIdx + 'return ['.length)

  // Independent count of declared top-level types, to catch any type block the main regex
  // fails to consume (e.g. a nesting depth or quoting variant it does not expect).
  const declaredTypes = [...body.matchAll(/^ {4}'(\w+)'\s*=>\s*\[/gm)].map((m) => m[1])
  if (declaredTypes.length === 0) {
    throw new Error(
      `backend/lang/${locale}/notifications.php: no top-level notification types found — parser assumptions are stale, fix the regex, do not silently return an empty catalogue`,
    )
  }

  const result = {}
  const typeRe = /'(\w+)'\s*=>\s*\[([\s\S]*?)\n {4}\],?\n/g
  let tm
  while ((tm = typeRe.exec(body)) !== null) {
    const [, type, block] = tm
    result[type] = parseEntries(block, locale, type)
  }

  const missedTypes = declaredTypes.filter((type) => !(type in result))
  if (missedTypes.length > 0) {
    throw new Error(
      `backend/lang/${locale}/notifications.php: failed to parse block(s) for type(s) [${missedTypes.join(', ')}] — regex did not match, fix it rather than silently skipping`,
    )
  }

  return result
}

// ------------------------------------------------------------------------------------------------
// Frontend loader
// ------------------------------------------------------------------------------------------------

function loadFrontendCatalogue(locale) {
  const path = frontendPath(locale)
  if (!existsSync(path)) {
    throw new Error(`frontend/src/i18n/locales/${locale}/notifications.json: file not found`)
  }
  let parsed
  try {
    parsed = JSON.parse(read(path))
  } catch (err) {
    throw new Error(`frontend/src/i18n/locales/${locale}/notifications.json: invalid JSON: ${err.message}`)
  }

  const messageTypes = {}
  for (const [topKey, value] of Object.entries(parsed)) {
    if (CHROME_KEYS.has(topKey)) continue
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
      fail(`[${locale}] notifications.json: top-level key '${topKey}' is not a chrome key and not an object — cannot be a message type`)
      continue
    }
    const leaves = {}
    for (const [key, text] of Object.entries(value)) {
      if (typeof text !== 'string') {
        fail(`[${locale}] notifications.json: '${topKey}.${key}' is not a string leaf`)
        continue
      }
      leaves[key] = text
    }
    messageTypes[topKey] = leaves
  }
  return messageTypes
}

// ------------------------------------------------------------------------------------------------
// Parameter extraction
// ------------------------------------------------------------------------------------------------

/** Laravel `:param` placeholders in a backend string. */
function backendParams(text) {
  return new Set([...text.matchAll(/:([A-Za-z_][A-Za-z0-9_]*)/g)].map((m) => m[1]))
}

/** i18next `{{param}}` placeholders in a frontend string. */
function frontendParams(text) {
  return new Set([...text.matchAll(/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/g)].map((m) => m[1]))
}

function setDiff(a, b) {
  return [...a].filter((x) => !b.has(x))
}

// ------------------------------------------------------------------------------------------------
// Cross-check, per locale, in both directions + parameter names
// ------------------------------------------------------------------------------------------------

let totalBackendKeys = 0
let totalFrontendKeys = 0
let totalCompared = 0

for (const locale of LOCALES) {
  const backend = parsePhpCatalogue(locale)
  const frontend = loadFrontendCatalogue(locale)

  const backendTypeCount = Object.keys(backend).length
  const backendKeyCount = Object.values(backend).reduce((n, entries) => n + Object.keys(entries).length, 0)
  const frontendKeyCount = Object.values(frontend).reduce((n, entries) => n + Object.keys(entries).length, 0)
  totalBackendKeys += backendKeyCount
  totalFrontendKeys += frontendKeyCount
  notes.push(
    `[${locale}] backend: ${backendTypeCount} types / ${backendKeyCount} keys — frontend: ${Object.keys(frontend).length} types / ${frontendKeyCount} keys`,
  )

  // (a) backend -> frontend: every backend key must exist on the frontend side.
  for (const [type, entries] of Object.entries(backend)) {
    for (const key of Object.keys(entries)) {
      if (frontend[type]?.[key] === undefined) {
        fail(`[${locale}] notifications.${type}.${key}: backend'de var, frontend/notifications.json'da yok (masaüstü çözemez)`)
      }
    }
  }

  // (b) frontend -> backend: every frontend message key must exist on the backend side.
  for (const [type, entries] of Object.entries(frontend)) {
    for (const key of Object.keys(entries)) {
      if (backend[type]?.[key] === undefined) {
        fail(`[${locale}] notifications.${type}.${key}: frontend/notifications.json'da var, backend'de yok (ölü/uydurma anahtar)`)
      }
    }
  }

  // (c) parameter-name parity for every key present on both sides.
  for (const [type, entries] of Object.entries(backend)) {
    for (const [key, backendText] of Object.entries(entries)) {
      const frontendText = frontend[type]?.[key]
      if (frontendText === undefined) continue // already reported by (a)

      totalCompared += 1
      const wantedParams = backendParams(backendText)
      const gotParams = frontendParams(frontendText)

      const missingInFrontend = setDiff(wantedParams, gotParams)
      const extraInFrontend = setDiff(gotParams, wantedParams)

      if (missingInFrontend.length > 0) {
        fail(
          `[${locale}] notifications.${type}.${key}: parametre adı sürüklenmesi — backend ':${missingInFrontend.join('\', \':')}' bekliyor, frontend'de '{{${missingInFrontend.join('}}\', \'{{')}}}' karşılığı yok`,
        )
      }
      if (extraInFrontend.length > 0) {
        fail(
          `[${locale}] notifications.${type}.${key}: parametre adı sürüklenmesi — frontend '{{${extraInFrontend.join('}}\', \'{{')}}}' kullanıyor, backend'de karşılığı yok`,
        )
      }
    }
  }
}

notes.push(`karşılaştırılan anahtar çiftleri (parametre kontrolü) : ${totalCompared}`)
notes.push(`toplam backend anahtarı (4 dil)                       : ${totalBackendKeys}`)
notes.push(`toplam frontend mesaj anahtarı (4 dil)                 : ${totalFrontendKeys}`)

// ------------------------------------------------------------------------------------------------
// Report
// ------------------------------------------------------------------------------------------------

console.log('notification catalogue drift check')
console.log('-'.repeat(52))
for (const note of notes) console.log(note)

if (problems.length > 0) {
  console.error('')
  console.error(`FAILED — ${problems.length} problem(s):`)
  for (const problem of problems) console.error(`  - ${problem}`)
  process.exit(1)
}

console.log('-'.repeat(52))
console.log('OK')
