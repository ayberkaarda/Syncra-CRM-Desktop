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
// ## Second assert: the AppUserModelID (F7, defter O85)
//
// The same string has a second job since the Windows JumpList landed. Windows files a jump list
// by AppUserModelID, and it shows a custom list only when the AUMID the PROCESS declares matches
// the AUMID stamped on the SHORTCUT the user launched from. Three places therefore have to hold
// one value:
//
//   1. `tauri.conf.json`'s `identifier`      — what NSIS writes onto `Syncra.lnk` as `${BUNDLEID}`
//   2. `src-tauri/src/jump_list.rs`'s
//      `APP_USER_MODEL_ID`                   — what `SetCurrentProcessExplicitAppUserModelID` declares
//   3. EXPECTED_IDENTIFIER below             — the reviewed, pinned value
//
// A mismatch between 1 and 2 fails EXACTLY like the storage-key drift this script was written
// for: nothing crashes, every COM call returns `S_OK`, and the user right-clicks the taskbar to
// find an empty menu. `jump_list.rs`'s own `the_aumid_is_the_bundle_identifier` test holds 1
// against 2 from the Rust side; this holds both against 3, so changing the config and the
// constant together — the change that would pass a same-file test — still has to be declared
// here.
//
// Run: `npm run check:identifier` (from `desktop/`).

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const DESKTOP = join(HERE, '..')

const TAURI_CONF = join(DESKTOP, 'src-tauri/tauri.conf.json')
const JUMP_LIST_RS = join(DESKTOP, 'src-tauri/src/jump_list.rs')

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
// 3. Assert the AppUserModelID constant is the same string
// ------------------------------------------------------------------------------------------------

// Matched against the SOURCE rather than against a compiled artefact on purpose: this check runs
// in the Node lane, where no Rust toolchain is assumed, and the value is a literal in a `const`
// declaration that `cargo` would not compare to anything anyway.
const AUMID_DECLARATION = /pub const APP_USER_MODEL_ID:\s*&str\s*=\s*"([^"]*)"\s*;/

let jumpListSource
try {
  jumpListSource = read(JUMP_LIST_RS)
} catch (error) {
  fail(
    `src-tauri/src/jump_list.rs: could not read the file (${error.message}) — the AUMID this ` +
      'process declares cannot be compared to the bundle identifier the installer stamps',
  )
  jumpListSource = null
}

const aumid = jumpListSource === null ? undefined : jumpListSource.match(AUMID_DECLARATION)?.[1]

if (jumpListSource !== null && aumid === undefined) {
  fail(
    "src-tauri/src/jump_list.rs: no `pub const APP_USER_MODEL_ID: &str = \"...\";` declaration " +
      'found. Either the constant was renamed or this check went blind — a blind check here ' +
      'means an AUMID/identifier mismatch ships as an empty JumpList with no error anywhere.',
  )
} else if (aumid !== undefined && aumid !== EXPECTED_IDENTIFIER) {
  fail(
    `src-tauri/src/jump_list.rs: APP_USER_MODEL_ID is '${aumid}' but the bundle identifier is ` +
      `'${EXPECTED_IDENTIFIER}'. Windows files a JumpList by AppUserModelID and shows a custom ` +
      'list only when the process and the Start-menu shortcut declare the SAME one; the ' +
      'installer stamps the bundle identifier onto the shortcut, so a different constant here ' +
      'commits the list where no shortcut can find it — every COM call succeeds and the menu is ' +
      'empty.',
  )
} else if (aumid !== undefined) {
  notes.push(`jump_list APP_USER_MODEL_ID  : '${aumid}' (matches the bundle identifier)`)
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
