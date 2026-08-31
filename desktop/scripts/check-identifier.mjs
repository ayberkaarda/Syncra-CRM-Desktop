#!/usr/bin/env node
// Identity-stability check for `desktop/src-tauri/tauri.conf.json`'s `identifier` field
// (`SYNCDESKTOP.md` §0.2 hook family — this is the config-drift analogue of `check-commands`).
//
// `identifier` is not cosmetic: it is the STORAGE KEY the OS uses to place per-user data on
// disk.
//
//   * on Linux, WebKitGTK keeps `localStorage` under `~/.local/share/<identifier>/localstorage/`
//     (measured and confirmed against a real build — this is not a guess from the Tauri docs);
//   * on Windows, the WebView2 user-data folder Tauri provisions is keyed off the same field.
//
// If `identifier` changes between releases, every existing install starts reading and writing a
// DIFFERENT directory. Nothing crashes, nothing throws, no test goes red — the user's theme,
// locale and sidebar preferences (and anything else `localStorage` holds) just silently vanish,
// because the app is now looking at an empty folder that happens to sit next to the real one.
// The failure is invisible at the exact layer every other check in this repo inspects (compiled
// code, wired call sites); it lives in a config file's VALUE, which is why it needs its own gate
// instead of piggybacking on `check:commands` or `cargo check`.
//
// So this script does one thing: assert the field is present, non-empty, and byte-for-byte equal
// to the value pinned below. A deliberate change to `identifier` is a real, user-facing decision
// (it deletes every existing user's local settings) and has to be made on purpose — this script
// forces that decision to show up as an explicit edit to EXPECTED_IDENTIFIER, not a drive-by
// rename.
//
// Run: `npm run check:identifier` (from `desktop/`).

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const DESKTOP = join(HERE, '..')

const TAURI_CONF = join(DESKTOP, 'src-tauri/tauri.conf.json')

// Pinned from the value actually in `tauri.conf.json` at the time this check was written —
// transcribed, not invented. Changing this line IS the "I meant to do this" signal; anyone
// bumping `identifier` in the config has to bump it here too, in the same review.
const EXPECTED_IDENTIFIER = 'com.syncra.desktop'

const problems = []
const notes = []

function fail(message) {
  problems.push(message)
}

function read(path) {
  return readFileSync(path, 'utf8')
}

// ------------------------------------------------------------------------------------------------
// 1. Read and parse tauri.conf.json
// ------------------------------------------------------------------------------------------------

let config
try {
  config = JSON.parse(read(TAURI_CONF))
} catch (error) {
  fail(`src-tauri/tauri.conf.json: could not read/parse the file (${error.message})`)
  config = null
}

// ------------------------------------------------------------------------------------------------
// 2. Assert identifier is present, non-empty, and unchanged
// ------------------------------------------------------------------------------------------------

const actual = config && typeof config === 'object' ? config.identifier : undefined

if (actual === undefined) {
  fail(
    "src-tauri/tauri.conf.json: 'identifier' field is missing — this is the per-user storage " +
      "key (WebKitGTK localStorage path on Linux, WebView2 profile on Windows); its absence " +
      'is not a neutral default, it changes where user data lives',
  )
} else if (actual === '') {
  fail(
    "src-tauri/tauri.conf.json: 'identifier' is empty — every existing install would resolve " +
      'to a different storage location and silently lose theme/locale/sidebar preferences',
  )
} else if (actual !== EXPECTED_IDENTIFIER) {
  fail(
    `src-tauri/tauri.conf.json: 'identifier' changed from the pinned value '${EXPECTED_IDENTIFIER}' ` +
      `to '${actual}'. This deletes every existing user's local settings on their next launch ` +
      '(new identifier = new storage directory, the old one is simply orphaned). If this is a ' +
      'deliberate, reviewed version decision, update EXPECTED_IDENTIFIER in this script in the ' +
      'same change.',
  )
} else {
  notes.push(`identifier                  : '${actual}' (matches pinned value)`)
}

// ------------------------------------------------------------------------------------------------
// Report
// ------------------------------------------------------------------------------------------------

console.log('identifier check')
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
