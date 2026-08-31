#!/usr/bin/env node
// Mapping-integrity check for the desktop realtime bridge (KARAR A11).
//
// The end-to-end path cannot be exercised here: there is no Reverb server in CI and no backend
// on `:8000`, so nothing proves the bridge works by running it. What CAN be proved is that the
// bridge is *wired*, and every way this particular bridge breaks is a silent one:
//
//   * a socket frame that refreshes the query cache directly still LOOKS correct online — it
//     only fails offline, weeks later, as "the desktop shows data the mirror never had";
//   * a channel the web adds and the desktop never hears about produces no error at all, just a
//     table that stops updating;
//   * `invoke('handle_realtimee')` rejects into a `.catch` and the app keeps working, minus
//     realtime, until someone notices the mirror is always a minute behind.
//
// So this script cross-references four sources:
//
//   1. `frontend/src/**`                          — the channels and events the WEB really uses
//   2. `desktop/src/bridge/realtime.ts`           — the hand-written channel/event -> entity map
//   3. `desktop/src-tauri/src/{lib.rs,commands/}` — the command registration
//   4. `crates/syncra-sync/src/types.rs`          — the `Entity` vocabulary
//
// Run: `npm run check:data` (from `desktop/`), or this file directly.

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const DESKTOP = join(HERE, '..')
const REPO = join(DESKTOP, '..')

const FRONTEND_SRC = join(REPO, 'frontend/src')
const DESKTOP_SRC = join(DESKTOP, 'src')
const REALTIME_TS = join(DESKTOP_SRC, 'bridge/realtime.ts')
const EVENTS_TS = join(DESKTOP_SRC, 'bridge/events.ts')
const LIB_RS = join(DESKTOP, 'src-tauri/src/lib.rs')
const COMMANDS_DIR = join(DESKTOP, 'src-tauri/src/commands')
const TYPES_RS = join(DESKTOP, 'crates/syncra-sync/src/types.rs')

/**
 * Call sites whose channel name is a runtime argument rather than a name this file can resolve.
 * Each one is a generic wrapper, not a subscription: allowing them by path keeps the resolver
 * strict everywhere else, where an unresolved name means the check went blind.
 */
const DYNAMIC_CHANNEL_CALLERS = {
  'platform/web.ts':
    'the web `RealtimeAdapter.channel(name)` wrapper — the name is the caller\'s, and the desktop counterpart is `bridge/realtime.ts:realtimeChannel`',
}

/**
 * The single sanctioned `invalidateQueries` site in `desktop/src`: the ENGINE's side of the
 * bridge, fed by `EngineEvent::TablesChanged`. Everything else in the desktop tree — and the
 * realtime path above all — must reach the cache through it, never around it.
 */
const INVALIDATION_OWNER = 'bridge/events.ts'

/**
 * Escape hatch for the KARAR A11 presence exception, should a desktop-side presence surface ever
 * need one. A line carrying this marker may call `invalidateQueries` outside the owner above.
 * Nothing uses it today, and that is the expected state — presence is handled entirely by the
 * shared `frontend/src/hooks/usePresence.ts`.
 */
const PRESENCE_EXCEPTION_MARKER = 'A11-EXCEPTION: presence'

const problems = []
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

const sourceFiles = (dir) => walk(dir).filter((path) => /\.tsx?$/.test(path))

// ------------------------------------------------------------------------------------------------
// 1. What the WEB subscribes to
// ------------------------------------------------------------------------------------------------

/** `` `user.${userId}` `` -> `user.{id}`: the identity of a channel family, not one channel. */
function normaliseTemplate(body) {
  return body.replace(/\$\{[^}]*\}/g, '{id}')
}

/**
 * Resolve a channel-name expression to its literal shape.
 *
 * Handles the four forms the web actually uses: a literal, a template, a `const` in the same
 * file holding either, and a same-file helper that returns a template
 * (`conversationChannel.ts:conversationChannelName`). Anything else returns `null`, which is a
 * failure rather than a skip — a resolver that quietly gives up would make this whole check
 * vacuous exactly where the naming is most dynamic.
 */
function resolveChannelExpression(expression, source, depth = 0) {
  if (depth > 4) return null
  // A `const CHANNEL_NAME = 'deals' // -> private-deals` declaration is the norm in these hooks,
  // so the trailing comment is cut before anything else is attempted.
  const text = expression.split('//')[0].trim().replace(/[,;]$/, '')

  const literal = text.match(/^['"]([^'"]*)['"]$/)
  if (literal) return literal[1]

  const template = text.match(/^`([^`]*)`$/)
  if (template) return normaliseTemplate(template[1])

  const identifier = text.match(/^(\w+)$/)
  if (identifier) {
    const declaration = source.match(new RegExp(`\\bconst ${identifier[1]}\\s*=\\s*([^\\n]+)`))
    return declaration ? resolveChannelExpression(declaration[1], source, depth + 1) : null
  }

  const call = text.match(/^(\w+)\(/)
  if (call) {
    const body = source.match(new RegExp(`function ${call[1]}\\b[^{]*\\{([\\s\\S]*?)\\n\\}`))
    const returned = body?.[1].match(/return\s+([^\n]+)/)
    return returned ? resolveChannelExpression(returned[1], source, depth + 1) : null
  }

  return null
}

const CHANNEL_CALL = /(?:\becho|getEcho\(\)\??)\s*\??\.(private|join|channel)\(\s*([^)]+?)\s*\)/g
const PREFIX = { private: 'private-', join: 'presence-', channel: '' }

/** Every Echo event name a hook listens for. Broadcast aliases always start with a dot. */
const EVENT_LITERAL = /'(\.[a-z][a-z0-9]*(?:\.[a-z0-9]+)+)'/g

const webChannels = new Map() // channel identity -> [file:line, ...]
const webEvents = new Map() // event name -> [file:line, ...]

function lineOf(source, index) {
  return source.slice(0, index).split('\n').length
}

for (const path of sourceFiles(FRONTEND_SRC)) {
  const source = read(path)
  // Only files that actually talk to Echo. `lib/echo.ts` itself owns the connection, not a
  // subscription, so it is excluded — it would otherwise resolve nothing and fail the resolver.
  if (!/from\s+['"][^'"]*lib\/echo['"]/.test(source)) continue
  const rel = relative(FRONTEND_SRC, path).replace(/\\/g, '/')

  for (const match of source.matchAll(CHANNEL_CALL)) {
    const [, kind, expression] = match
    const where = `${rel}:${lineOf(source, match.index)}`
    const name = resolveChannelExpression(expression, source)
    if (name === null) {
      if (!(rel in DYNAMIC_CHANNEL_CALLERS)) {
        fail(`${where}: channel name \`${expression}\` could not be resolved — the check cannot see what this subscribes to`)
      }
      continue
    }
    const identity = `${PREFIX[kind]}${name}`
    const seen = webChannels.get(identity)
    if (seen) seen.push(where)
    else webChannels.set(identity, [where])
  }

  for (const match of source.matchAll(EVENT_LITERAL)) {
    const where = `${rel}:${lineOf(source, match.index)}`
    const seen = webEvents.get(match[1])
    if (seen) seen.push(where)
    else webEvents.set(match[1], [where])
  }
}

if (webChannels.size === 0) fail('no Echo channels found in frontend/src — the scanner is broken, not the app')
if (webEvents.size === 0) fail('no Echo events found in frontend/src — the scanner is broken, not the app')

// ------------------------------------------------------------------------------------------------
// 2. What the DESKTOP maps
// ------------------------------------------------------------------------------------------------

const realtimeSource = read(REALTIME_TS)

/** Slice one `export const <name>: ... = [ ... ]` array of object literals into records. */
function parseTable(source, name) {
  const opener = source.match(new RegExp(`export const ${name}[^=]*=\\s*\\[`))
  if (!opener) throw new Error(`${name} not found in bridge/realtime.ts`)

  const start = opener.index + opener[0].length - 1
  let depth = 0
  let end = -1
  for (let i = start; i < source.length; i += 1) {
    const char = source[i]
    if (char === '[') depth += 1
    else if (char === ']') {
      depth -= 1
      if (depth === 0) {
        end = i
        break
      }
    }
  }
  if (end === -1) throw new Error(`${name}: unterminated array literal`)

  const records = []
  // Comments are stripped first: a `{id}` inside a `//` line would otherwise open a phantom
  // object and the parser would report a record that does not exist.
  const body = source
    .slice(start + 1, end)
    .split('\n')
    .filter((line) => !/^\s*(\/\/|\*|\/\*)/.test(line))
    .join('\n')
  let objectDepth = 0
  let buffer = ''
  for (const char of body) {
    if (char === '{') objectDepth += 1
    if (objectDepth > 0) buffer += char
    if (char === '}') {
      objectDepth -= 1
      if (objectDepth === 0) {
        records.push(buffer)
        buffer = ''
      }
    }
  }

  return records.map((record) => {
    const fields = {}
    for (const field of record.matchAll(/(\w+):\s*'((?:[^'\\]|\\.)*)'/g)) {
      fields[field[1]] = field[2].replace(/\\'/g, "'")
    }
    const entities = record.match(/entities:\s*\[([^\]]*)\]/)
    if (entities) {
      fields.entities = [...entities[1].matchAll(/'([^']+)'/g)].map((m) => m[1])
    }
    return fields
  })
}

const channels = parseTable(realtimeSource, 'REALTIME_CHANNELS')
const bindings = parseTable(realtimeSource, 'REALTIME_BINDINGS')
const unmapped = parseTable(realtimeSource, 'UNMAPPED_CHANNELS')
const unrouted = parseTable(realtimeSource, 'UNROUTED_EVENTS')

const mappedChannels = new Set(channels.map((spec) => spec.channel))
const unmappedChannels = new Set(unmapped.map((entry) => entry.channel))

// --- every web channel is accounted for, one way or the other ---------------------------------
for (const [identity, sites] of webChannels) {
  const isMapped = mappedChannels.has(identity)
  const isUnmapped = unmappedChannels.has(identity)
  if (!isMapped && !isUnmapped) {
    fail(
      `${identity}: the web subscribes to it (${sites.join(', ')}) but the desktop neither routes it ` +
        'to the engine (REALTIME_CHANNELS) nor declares it UNMAPPED_CHANNELS with a reason',
    )
  }
  if (isMapped && isUnmapped) {
    fail(`${identity}: listed in BOTH REALTIME_CHANNELS and UNMAPPED_CHANNELS — pick one`)
  }
}

for (const identity of [...mappedChannels, ...unmappedChannels]) {
  if (!webChannels.has(identity)) {
    fail(`${identity}: mapped in bridge/realtime.ts, but nothing in frontend/src subscribes to it`)
  }
}

for (const entry of unmapped) {
  if (!entry.reason || entry.reason.length < 20) {
    fail(`${entry.channel}: UNMAPPED_CHANNELS entry needs a real reason, not a placeholder`)
  }
  if (!entry.source) fail(`${entry.channel}: UNMAPPED_CHANNELS entry names no frontend source`)
}

// --- the presence exception is explicit -------------------------------------------------------
const presenceChannels = [...webChannels.keys()].filter((identity) => identity.startsWith('presence-'))
for (const identity of presenceChannels) {
  if (!unmappedChannels.has(identity)) {
    fail(`${identity}: presence is the ONE KARAR A11 exception and must be declared in UNMAPPED_CHANNELS with its reason`)
  }
  if (mappedChannels.has(identity)) {
    fail(`${identity}: presence must not be routed to the engine (KARAR A11 exception) — it mirrors no table`)
  }
}
if (presenceChannels.length === 0) {
  fail('no presence channel found in frontend/src — the KARAR A11 exception could not be verified')
}

// --- every web event is routed or explicitly not ----------------------------------------------
const boundEvents = new Set(bindings.map((binding) => binding.event))
const unroutedEvents = new Set(unrouted.map((entry) => entry.event))

for (const [event, sites] of webEvents) {
  if (!boundEvents.has(event) && !unroutedEvents.has(event)) {
    fail(
      `${event}: the web listens for it (${sites.join(', ')}) but bridge/realtime.ts neither maps it to ` +
        'entities (REALTIME_BINDINGS) nor declares it UNROUTED_EVENTS with a reason',
    )
  }
  if (boundEvents.has(event) && unroutedEvents.has(event)) {
    fail(`${event}: listed in BOTH REALTIME_BINDINGS and UNROUTED_EVENTS — pick one`)
  }
}

for (const event of [...boundEvents, ...unroutedEvents]) {
  if (!webEvents.has(event)) {
    fail(`${event}: named in bridge/realtime.ts, but no frontend hook listens for it`)
  }
}

for (const entry of unrouted) {
  if (!entry.reason || entry.reason.length < 20) {
    fail(`${entry.event}: UNROUTED_EVENTS entry needs a real reason, not a placeholder`)
  }
}

// --- the tables themselves --------------------------------------------------------------------
function parseEntities(source) {
  const body = source.match(/pub enum Entity \{([\s\S]*?)\n\}/)
  if (!body) throw new Error('Entity enum not found')
  return body[1]
    .split('\n')
    .map((line) => line.match(/^\s{4}([A-Z]\w*),\s*$/))
    .filter(Boolean)
    .map((match) => match[1].replace(/([a-z0-9])([A-Z])/g, '$1_$2').toLowerCase())
}

const entityNames = new Set(parseEntities(read(TYPES_RS)))
const bindingChannels = new Set()

for (const binding of bindings) {
  bindingChannels.add(binding.channel)
  if (!mappedChannels.has(binding.channel)) {
    fail(`${binding.channel} / ${binding.event}: binding names a channel that is not in REALTIME_CHANNELS`)
  }
  if (!binding.event?.startsWith('.')) {
    // Laravel's `broadcastAs` aliases all start with a dot; a client whisper (`typing`) does not.
    // A whisper is peer-to-peer and never persisted, so routing one to the engine is always a bug.
    fail(`${binding.channel}: '${binding.event}' is not a broadcast alias (no leading dot) — whispers must not reach the engine`)
  }
  if (!binding.entities || binding.entities.length === 0) {
    fail(`${binding.channel} / ${binding.event}: routed to the engine but names no entity — use UNROUTED_EVENTS instead`)
  }
  for (const entity of binding.entities ?? []) {
    if (!entityNames.has(entity)) {
      fail(`${binding.channel} / ${binding.event}: '${entity}' is not a syncra_sync::Entity variant`)
    }
  }
  if (!binding.source) fail(`${binding.channel} / ${binding.event}: binding names no frontend source`)
  if (!binding.why) fail(`${binding.channel} / ${binding.event}: binding gives no reason for its entity set`)
}

for (const spec of channels) {
  if (!bindingChannels.has(spec.channel)) {
    fail(`${spec.channel}: routed to the engine but has no REALTIME_BINDINGS row — it would subscribe and forward nothing`)
  }
  if (!['always', 'user', 'attach'].includes(spec.mode)) {
    fail(`${spec.channel}: unknown subscribe mode '${spec.mode}'`)
  }
}

// ------------------------------------------------------------------------------------------------
// 3. The command exists on BOTH sides under the SAME name
// ------------------------------------------------------------------------------------------------

const commandName = realtimeSource.match(/export const HANDLE_REALTIME_COMMAND = '([^']+)'/)?.[1]
if (!commandName) {
  fail('bridge/realtime.ts: HANDLE_REALTIME_COMMAND constant not found')
} else if (!new RegExp(`invokeCommand<[^>]*>\\(\\s*HANDLE_REALTIME_COMMAND`).test(realtimeSource)) {
  fail(`bridge/realtime.ts: HANDLE_REALTIME_COMMAND is declared but never invoked — the bridge forwards nothing`)
}

/** `#[tauri::command]`-annotated function names, per command module. */
const rustCommands = new Map()
for (const path of walk(COMMANDS_DIR).filter((p) => p.endsWith('.rs'))) {
  const module = path.replace(/\\/g, '/').split('/').pop().replace(/\.rs$/, '')
  const source = read(path)
  for (const match of source.matchAll(/#\[tauri::command\][\s\S]*?\bfn\s+(\w+)/g)) {
    rustCommands.set(`${module}::${match[1]}`, path)
  }
}

const handlerBlock = read(LIB_RS).match(/generate_handler!\[([\s\S]*?)\]/)
if (!handlerBlock) {
  fail('src-tauri/src/lib.rs: generate_handler![...] not found')
}
const registered = handlerBlock
  ? [...handlerBlock[1].matchAll(/commands::(\w+)::(\w+)/g)].map((m) => `${m[1]}::${m[2]}`)
  : []

for (const entry of registered) {
  if (!rustCommands.has(entry)) {
    fail(`lib.rs registers commands::${entry.replace('::', '::')}, which is not a #[tauri::command] fn`)
  }
}

if (commandName) {
  const rustMatches = [...rustCommands.keys()].filter((key) => key.endsWith(`::${commandName}`))
  if (rustMatches.length === 0) {
    fail(`'${commandName}': the TS bridge invokes it, but no #[tauri::command] fn by that name exists in src-tauri/src/commands/`)
  } else if (!rustMatches.some((key) => registered.includes(key))) {
    fail(
      `'${commandName}': the Rust fn exists (${rustMatches.join(', ')}) but is NOT in lib.rs's generate_handler![...] — ` +
        'every invoke would reject at runtime',
    )
  }
}

// ------------------------------------------------------------------------------------------------
// 4. Nothing on the desktop realtime path invalidates the cache itself (KARAR A11)
// ------------------------------------------------------------------------------------------------

let invalidationSites = 0
for (const path of sourceFiles(DESKTOP_SRC)) {
  const rel = relative(DESKTOP_SRC, path).replace(/\\/g, '/')
  const lines = read(path).split('\n')
  lines.forEach((line, index) => {
    // Comment lines and trailing comments are cut: this module's own header DESCRIBES the
    // forbidden call, and a check that cannot tell prose from code is a check nobody could
    // write documentation against.
    const code = /^\s*(\/\/|\*|\/\*)/.test(line) ? '' : line.split('//')[0]
    if (!code.includes('invalidateQueries')) return
    invalidationSites += 1
    if (rel === INVALIDATION_OWNER) return
    if (line.includes(PRESENCE_EXCEPTION_MARKER)) return
    fail(
      `${rel}:${index + 1}: invalidateQueries outside ${INVALIDATION_OWNER} — on the desktop a realtime frame ` +
        'goes to the engine and comes back as TablesChanged (KARAR A11); it never refreshes the cache itself',
    )
  })
}
if (invalidationSites === 0) {
  fail(`no invalidateQueries call found anywhere in desktop/src — ${INVALIDATION_OWNER} is the one site that must have one`)
}
if (!read(EVENTS_TS).includes('invalidateQueries')) {
  fail(`${INVALIDATION_OWNER}: the engine-driven bridge no longer invalidates anything`)
}

const REACT_QUERY_IMPORT = /from\s+['"](@tanstack\/react-query|@\/lib\/queryClient)['"]/
if (REACT_QUERY_IMPORT.test(realtimeSource) && !/^import type/m.test(realtimeSource.match(/^.*queryClient.*$/m) ?? '')) {
  fail('bridge/realtime.ts imports the query cache — the realtime path must not be able to touch it')
}

// ------------------------------------------------------------------------------------------------
// Report
// ------------------------------------------------------------------------------------------------

notes.push(`web channels discovered     : ${webChannels.size} (${[...webChannels.keys()].sort().join(', ')})`)
notes.push(`  routed to the engine      : ${mappedChannels.size}`)
notes.push(`  UNMAPPED (with reason)    : ${unmappedChannels.size} (${[...unmappedChannels].sort().join(', ')})`)
notes.push(`web events discovered       : ${webEvents.size}`)
notes.push(`  channel/event -> entities : ${bindings.length}`)
notes.push(`  UNROUTED (with reason)    : ${unrouted.length}`)
notes.push(`engine command              : ${commandName ?? '-'} (TS + Rust fn + generate_handler)`)
notes.push(`tauri commands registered   : ${registered.length}`)
notes.push(`invalidateQueries sites     : ${invalidationSites}, all in ${INVALIDATION_OWNER}`)

export function runRealtimeWiringCheck() {
  return { problems, notes }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  console.log('realtime wiring check')
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
}
