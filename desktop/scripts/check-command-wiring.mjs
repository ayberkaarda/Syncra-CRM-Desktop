#!/usr/bin/env node
// Name-integrity check for the Tauri command surface (`SYNCDESKTOP.md` §6.2).
//
// A Tauri command name is a STRING that has to be spelled identically in three places nothing
// links together:
//
//   1. the Rust `#[tauri::command] fn` — `generate_handler!` registers a command under the
//      FUNCTION name, not the module path, so `commands::storage::stats` is invoked as
//      `stats`;
//   2. the `invoke('...')` / `invokeCommand<T>('...')` literal in `desktop/src/**`;
//   3. the contract in `SYNCDESKTOP.md` §6.2, which is what every other document quotes.
//
// `tsc` cannot see any of this (the argument is `string`), `cargo` cannot see the TS side, and
// the failure mode is the worst kind: `invoke('stats')` against a command registered as
// `storage_stats` rejects at RUNTIME, on one screen, with a message no test asserts on. That
// is ledger entry O5 — `SYNCDESKTOP.md` §6.2 named the command `storage_stats` while both the
// Rust fn and the UI said `stats`, and nothing in the repo compared them.
//
// So this script reads three sources and cross-references them:
//
//   1. `desktop/src-tauri/src/commands/*.rs`  — the `#[tauri::command]` functions
//   2. `desktop/src-tauri/src/lib.rs`         — the `generate_handler![...]` registration
//   3. `desktop/src/**/*.ts(x)`               — the invoke call sites
//
// against the `CONTRACT` table below, transcribed from `SYNCDESKTOP.md` §6.2.
//
// Run: `npm run check:commands` (from `desktop/`).

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const DESKTOP = join(HERE, '..')

const COMMANDS_DIR = join(DESKTOP, 'src-tauri/src/commands')
const LIB_RS = join(DESKTOP, 'src-tauri/src/lib.rs')
const DESKTOP_SRC = join(DESKTOP, 'src')

/**
 * The command surface as `SYNCDESKTOP.md` §6.2 defines it, module by module.
 *
 * Transcribed from the spec line, NOT derived from the code — deriving it from the code would
 * make the whole comparison circular. The values are the names a command is REGISTERED and
 * INVOKED under, which is why `storage`'s first entry reads `storage_stats`: §6.2 writes the
 * surface in `module::fn` form, and the O5 ledger entry fixes the resolved wire name of
 * `storage::stats` as `storage_stats` (bare `stats` says nothing about what it counts, and the
 * spec text is the side that wins). Everywhere else the module-qualified spelling and the wire
 * name coincide, because the fn name is already unambiguous.
 *
 * `handle_realtime` and `bootstrap` are in `sync` on the authority of the "ŞARTNAME
 * DÜZELTMESİ" note under §6.2 (KARAR A11 / defter U15), which adds both to the list.
 */
const CONTRACT = {
  auth: ['login', 'session', 'restore', 'logout', 'list_devices', 'revoke_device'],
  data: ['query', 'mutate', 'search'],
  sync: [
    'sync_now',
    'status',
    'conflicts',
    'resolve_conflict',
    'download_archive',
    'bootstrap',
    'handle_realtime',
  ],
  storage: ['storage_stats', 'update_settings', 'storage_settings', 'clear_local'],
  files: ['cache_quote_pdf', 'open_cached', 'attach_from_paths', 'screenshot_to_ticket'],
  os: ['set_badge', 'register_hotkey', 'set_autostart', 'notify'],
}

/**
 * Contract commands that are deliberately NOT delivered yet, with the phase that owns them.
 * Anything in §6.2 that is missing from `generate_handler!` and NOT listed here fails the run.
 *
 * This list is the one place where "not built yet" is distinguishable from "silently dropped",
 * so an entry has to name the phase; removing a command from the shell without removing it
 * from §6.2 has to land here as a deliberate edit rather than pass unnoticed.
 */
const DEFERRED_COMMANDS = {
  cache_quote_pdf: 'files::* — F5 scope (commands/mod.rs says so in as many words)',
  open_cached: 'files::* — F5 scope',
  attach_from_paths: 'files::* — F5 scope (§6.4 drag-drop)',
  screenshot_to_ticket: 'files::* — F5 scope (§6.4 screenshot)',
  set_badge: 'os::* — F5 scope (§6.4 badge)',
  register_hotkey: 'os::* — F5 scope (§6.4 global hotkey)',
  set_autostart: 'os::* — F5 scope (§6.4 autostart)',
  notify: 'os::* — F5 scope (§6.4 native notification)',
}

/**
 * Commands the shell registers that §6.2 does not list. Each entry states why the command
 * exists; an undeclared one fails, because "the spec does not know about it" is exactly how a
 * command surface drifts away from the document every other document quotes.
 */
// Empty on purpose. `auth::session` lived here until 2026-08-31: it was registered and used
// but absent from §6.2, so this exception kept the check honest without silently passing it.
// The spec has since been corrected (SYNCDESKTOP.md §13, "session eksikti, stats yanlıştı"),
// so the exception is gone and `session` is verified like every other command. An entry here
// is a documented gap, never a way to quiet the check — leaving a stale one would mean the
// check stops caring if the spec later drops that command again.
const UNDOCUMENTED_COMMANDS = {}

/**
 * Files whose `invoke` argument is a parameter rather than a name this script can resolve.
 * Allowing them by path keeps the resolver strict everywhere else, where an unresolved name
 * means the check went blind on a real call site.
 */
const WRAPPER_FILES = {
  'bridge/invoke.ts':
    'the generic `invokeCommand(command, args)` wrapper — the name is the caller\'s',
}

const problems = []
const warnings = []
const notes = []

function fail(message) {
  problems.push(message)
}

function read(path) {
  return readFileSync(path, 'utf8')
}

function walk(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) walk(path, out)
    else out.push(path)
  }
  return out
}

function lineOf(source, index) {
  return source.slice(0, index).split('\n').length
}

/**
 * Blank out comments so a name that only APPEARS in prose is not counted as a call site.
 *
 * `platform/auth.ts` and `platform/desktop.ts` both document the commands they wrap with
 * `invoke('login')`-shaped comments, and `bridge/realtime.ts` draws the whole bridge in ASCII
 * — a scanner that cannot tell prose from code is a scanner nobody can write documentation
 * against. Same idiom as `check-realtime-wiring.mjs`: whole-line comments — including the
 * asterisk continuation lines of a block comment — are dropped, and a trailing `//` is cut.
 * Lines are blanked rather than removed so line numbers survive.
 */
function stripComments(source) {
  return source
    .split('\n')
    .map((line) => (/^\s*(\/\/|\*|\/\*)/.test(line) ? '' : line.split('//')[0]))
    .join('\n')
}

// ------------------------------------------------------------------------------------------------
// 1. Rust: the `#[tauri::command]` functions
// ------------------------------------------------------------------------------------------------

/**
 * Every `#[tauri::command]` fn, keyed `module::fn`.
 *
 * The registered wire name is the FUNCTION name. The attribute's `rename_all = "..."` renames
 * command ARGUMENTS, not the command, so it is ignored here; an explicit `name = "..."` (if it
 * is ever used) does rename the command and is honoured.
 */
const rustCommands = new Map()

for (const path of walk(COMMANDS_DIR).filter((p) => p.endsWith('.rs'))) {
  const module = path.replace(/\\/g, '/').split('/').pop().replace(/\.rs$/, '')
  if (module === 'mod') continue
  const source = read(path)
  for (const match of source.matchAll(/#\[tauri::command(\([^)]*\))?\][\s\S]*?\bfn\s+(\w+)/g)) {
    const [, attribute, fnName] = match
    const renamed = attribute?.match(/\bname\s*=\s*"([^"]+)"/)?.[1]
    rustCommands.set(`${module}::${fnName}`, {
      module,
      fn: fnName,
      wire: renamed ?? fnName,
      where: `src-tauri/src/commands/${module}.rs:${lineOf(source, match.index)}`,
    })
  }
}

if (rustCommands.size === 0) {
  fail('no #[tauri::command] function found in src-tauri/src/commands — the scanner is broken, not the app')
}

// ------------------------------------------------------------------------------------------------
// 2. Rust: the `generate_handler![...]` registration
// ------------------------------------------------------------------------------------------------

const handlerBlock = read(LIB_RS).match(/generate_handler!\[([\s\S]*?)\]/)
if (!handlerBlock) fail('src-tauri/src/lib.rs: generate_handler![...] not found')

const handlerEntries = handlerBlock
  ? [...handlerBlock[1].matchAll(/commands::(\w+)::(\w+)/g)].map((m) => `${m[1]}::${m[2]}`)
  : []

/** wire name -> `module::fn` that owns it. */
const registered = new Map()

for (const entry of handlerEntries) {
  const command = rustCommands.get(entry)
  if (!command) {
    fail(`lib.rs registers commands::${entry}, which is not a #[tauri::command] fn`)
    continue
  }
  const clash = registered.get(command.wire)
  if (clash) {
    fail(`'${command.wire}': registered twice (${clash} and ${entry}) — Tauri resolves by name, one shadows the other`)
    continue
  }
  registered.set(command.wire, entry)
}

// A command that exists but is never handed to `generate_handler!` is defect U15: every
// `invoke` of it rejects at runtime while both the fn and the call site look correct.
for (const [key, command] of rustCommands) {
  if (!handlerEntries.includes(key)) {
    fail(`${command.where}: ${key} is a #[tauri::command] but is NOT in lib.rs's generate_handler![...] — every invoke of '${command.wire}' would reject`)
  }
}

// ------------------------------------------------------------------------------------------------
// 3. TypeScript: the invoke call sites
// ------------------------------------------------------------------------------------------------

const INVOKE_CALL = /(?<![\w$.])(invoke|invokeCommand|tauriInvoke)\s*(?:<[^()]*?>)?\s*\(/g

/** wire name -> [`file:line`, ...] */
const invoked = new Map()
let dynamicCalls = 0
let scannedFiles = 0

/**
 * The first argument of a call whose `(` sits at `openIndex`, resolved to a command name.
 * `{ name }` when it is a literal (or a same-file `const` holding one), `null` when the
 * expression is a runtime value this script cannot follow.
 */
function resolveCommandName(source, openIndex) {
  const tail = source.slice(openIndex + 1)

  const quoted = tail.match(/^\s*(['"])([^'"]*)\1/)
  if (quoted) return { name: quoted[2] }

  const template = tail.match(/^\s*`([^`]*)`/)
  if (template) return template[1].includes('${') ? null : { name: template[1] }

  const identifier = tail.match(/^\s*([A-Za-z_$][\w$]*)\s*[,)]/)
  if (identifier) {
    const declaration = source.match(
      new RegExp(`\\bconst\\s+${identifier[1]}\\s*(?::[^=\\n]+)?=\\s*(['"])([^'"]*)\\1`),
    )
    return declaration ? { name: declaration[2] } : null
  }

  return null
}

for (const path of walk(DESKTOP_SRC).filter((p) => /\.tsx?$/.test(p))) {
  const rel = relative(DESKTOP_SRC, path).replace(/\\/g, '/')
  const source = stripComments(read(path))
  if (!INVOKE_CALL.test(source)) continue
  INVOKE_CALL.lastIndex = 0
  scannedFiles += 1

  for (const match of source.matchAll(INVOKE_CALL)) {
    const openIndex = match.index + match[0].length - 1
    const resolved = resolveCommandName(source, openIndex)
    if (!resolved) {
      // The wrapper's own `tauriInvoke(command, args)` is the indirection, not a call site.
      if (!(rel in WRAPPER_FILES)) dynamicCalls += 1
      continue
    }
    const where = `${rel}:${lineOf(source, match.index)}`
    const seen = invoked.get(resolved.name)
    if (seen) seen.push(where)
    else invoked.set(resolved.name, [where])
  }
}

if (invoked.size === 0) {
  fail('no resolvable invoke() call found in desktop/src — the scanner is broken, not the app')
}

// ------------------------------------------------------------------------------------------------
// 4. The three sides against each other
// ------------------------------------------------------------------------------------------------

const contractNames = new Map()
for (const [module, names] of Object.entries(CONTRACT)) {
  for (const name of names) {
    if (contractNames.has(name)) {
      fail(`CONTRACT lists '${name}' twice (${contractNames.get(name)} and ${module})`)
      continue
    }
    contractNames.set(name, module)
  }
}

// --- TS -> Rust: every call reaches a registered command --------------------------------------
for (const [name, sites] of invoked) {
  if (registered.has(name)) continue
  const nearMiss = [...rustCommands.values()].find((command) => command.fn === name || command.wire === name)
  const hint = nearMiss
    ? ` (the closest Rust fn is ${nearMiss.module}::${nearMiss.fn}, registered as '${nearMiss.wire}')`
    : ''
  fail(`'${name}': invoked from ${sites.join(', ')} but no command is registered under that name${hint} — this rejects at runtime`)
}

// --- Rust -> contract: nothing undocumented ---------------------------------------------------
const undocumentedSeen = []
for (const [name, entry] of registered) {
  if (contractNames.has(name)) continue
  if (UNDOCUMENTED_COMMANDS[name]) {
    undocumentedSeen.push(name)
    continue
  }
  fail(`'${name}' (commands::${entry}): registered but absent from the SYNCDESKTOP.md §6.2 contract — document it or declare it in UNDOCUMENTED_COMMANDS with a reason`)
}
for (const name of Object.keys(UNDOCUMENTED_COMMANDS)) {
  if (!registered.has(name)) {
    fail(`UNDOCUMENTED_COMMANDS declares '${name}', which nothing registers — drop the entry`)
  }
  if (contractNames.has(name)) {
    fail(`UNDOCUMENTED_COMMANDS declares '${name}', but §6.2 does list it — drop the entry`)
  }
}

// --- contract -> Rust: nothing silently undelivered --------------------------------------------
const deferredSeen = []
for (const [name, module] of contractNames) {
  if (registered.has(name)) {
    if (DEFERRED_COMMANDS[name]) {
      fail(`'${name}': listed as deferred but the shell does register it — drop the DEFERRED_COMMANDS entry`)
    }
    const owner = registered.get(name).split('::')[0]
    if (owner !== module) {
      fail(`'${name}': §6.2 puts it in ${module}::, the shell registers it from ${owner}:: — one of the two is wrong`)
    }
    continue
  }
  if (DEFERRED_COMMANDS[name]) {
    deferredSeen.push(name)
    continue
  }
  fail(`'${name}': in the §6.2 contract (${module}::) but no command is registered under that name — deliver it, or declare it in DEFERRED_COMMANDS with the phase that owns it`)
}
for (const name of Object.keys(DEFERRED_COMMANDS)) {
  if (!contractNames.has(name)) {
    fail(`DEFERRED_COMMANDS lists '${name}', which is not a §6.2 contract command`)
  }
}

// --- Rust -> TS: dead commands are a warning, not a failure -----------------------------------
//
// Deliberately NOT a failure: a command can legitimately have no TS call site (invoked from
// another Rust path, or delivered one phase ahead of the screen that uses it). Silence would
// be wrong too, so the count and the names are always printed.
const deadCommands = [...registered.keys()].filter((name) => !invoked.has(name)).sort()
for (const name of deadCommands) {
  warnings.push(`'${name}' (commands::${registered.get(name)}): registered but nothing in desktop/src invokes it`)
}

// ------------------------------------------------------------------------------------------------
// Report
// ------------------------------------------------------------------------------------------------

const contractTotal = [...contractNames.keys()].length

notes.push(`#[tauri::command] fns       : ${rustCommands.size}`)
notes.push(`registered (generate_handler): ${registered.size}`)
notes.push(`§6.2 contract commands      : ${contractTotal} across ${Object.keys(CONTRACT).length} modules`)
notes.push(`  delivered                 : ${contractTotal - deferredSeen.length}`)
notes.push(`  DEFERRED (with reason)    : ${deferredSeen.length} (${deferredSeen.join(', ') || '-'})`)
notes.push(`UNDOCUMENTED (with reason)  : ${undocumentedSeen.length} (${undocumentedSeen.join(', ') || '-'})`)
notes.push(`TS files with invoke calls  : ${scannedFiles}`)
notes.push(`distinct names invoked      : ${invoked.size}`)
notes.push(`dead commands (warning)     : ${deadCommands.length} (${deadCommands.join(', ') || '-'})`)
// Printed even when it is 0: an unresolved call site is a place the check went blind, and a
// silently swallowed one is indistinguishable from a name that matches.
notes.push(`dynamic calls unresolved    : ${dynamicCalls} (skipped, not swallowed)`)

console.log('command wiring check')
console.log('-'.repeat(52))
for (const note of notes) console.log(note)

if (warnings.length > 0) {
  console.log('')
  console.log(`warnings — ${warnings.length}:`)
  for (const warning of warnings) console.log(`  ! ${warning}`)
}

if (problems.length > 0) {
  console.error('')
  console.error(`FAILED — ${problems.length} problem(s):`)
  for (const problem of problems) console.error(`  - ${problem}`)
  process.exit(1)
}

console.log('-'.repeat(52))
console.log('OK')
