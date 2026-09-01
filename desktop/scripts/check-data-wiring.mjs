#!/usr/bin/env node
// Mapping-integrity check for the desktop `DataSource`.
//
// `tsc` already proves that every method of the contract EXISTS with the right signature — the
// `const desktopData: DataSource = {...}` annotation cannot compile otherwise. What it cannot
// see is *where each method gets its data*, and that is the thing this project actually has to
// get right:
//
//   * an online-only action that reaches `mutate()` lands in the outbox, and the UI tells the
//     user a quote was sent when nothing left the machine (KARAR A15);
//   * a method that still throws `NOT_IMPLEMENTED` looks wired from the outside;
//   * a `NamedQuery` variant nothing calls is dead weight in a security-relevant whitelist;
//   * a method declared `query` that is supposed to consult the server too (the command
//     palette, SYNCDESKTOP.md §7.2) looks perfectly wired while half of it is missing — which
//     is why `hybrid` is a kind of its own here, asserted to reach BOTH paths.
//
// So this script reads four sources and cross-references them:
//
//   1. `frontend/src/platform/types.ts`         — the contract: which methods must exist
//   2. `desktop/src/platform/data/manifest.ts`  — the declared binding of each one
//   3. the four domain modules                  — what each method's body actually calls
//   4. `crates/syncra-sync/src/db/query.rs`     — the `NamedQuery` whitelist
//
// Run: `npm run check:data` (from `desktop/`).

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

// The realtime bridge's own integrity check (KARAR A11). Imported rather than chained in
// `package.json` so that running THIS file directly — which is what the regression gate in
// `docs/ENGINEERING-RULES.md` §2 does — still covers it. It runs its checks on import and hands back the two
// lists; `check:data` prints both reports and fails on either.
import { runRealtimeWiringCheck } from './check-realtime-wiring.mjs'

const HERE = dirname(fileURLToPath(import.meta.url))
const DESKTOP = join(HERE, '..')
const REPO = join(DESKTOP, '..')

const CONTRACT = join(REPO, 'frontend/src/platform/types.ts')
const MANIFEST = join(DESKTOP, 'src/platform/data/manifest.ts')
const INDEX = join(DESKTOP, 'src/platform/data/index.ts')
const QUERY_RS = join(DESKTOP, 'crates/syncra-sync/src/db/query.rs')
const DOMAIN_FILES = ['crm.ts', 'work.ts', 'comms.ts', 'catalog.ts'].map((name) =>
  join(DESKTOP, 'src/platform/data', name),
)

/**
 * `NamedQuery` variants no `DataSource` method uses, with the reason each one still belongs in
 * the whitelist. Anything not listed here and not referenced by the adapter fails the run.
 */
const RESERVED_QUERIES = {
  // `deals.board` reads through `deals_list`, one query per stage, NOT through this variant:
  // `DealsBoard` pins `status = 'open'` and carries none of the board filters (q / owner /
  // company / from / to), so it cannot reproduce `GET /api/deals/board`, which lists a stage's
  // won and lost cards in that stage's own column.
  deals_board: 'DealsBoard pins status = open and takes no board filters, so deals.board reads through deals_list per stage instead',
  pending_rows: 'the pending/conflict badges, F4 scope (SYNCDESKTOP.md §7.2)',
}

const problems = []
const notes = []

function fail(message) {
  problems.push(message)
}

function read(path) {
  return readFileSync(path, 'utf8')
}

// ------------------------------------------------------------------------------------------------
// 1. The contract: 16 domains, N methods each
// ------------------------------------------------------------------------------------------------

function parseContract(source) {
  const dataSource = source.match(/export interface DataSource \{([\s\S]*?)\n\}/)
  if (!dataSource) throw new Error('DataSource interface not found in the contract')

  const domains = []
  for (const line of dataSource[1].split('\n')) {
    const match = line.match(/^\s{2}(\w+):\s*(\w+)\s*$/)
    if (match) domains.push({ domain: match[1], iface: match[2] })
  }

  const methods = []
  for (const { domain, iface } of domains) {
    const block = source.match(new RegExp(`export interface ${iface} \\{([\\s\\S]*?)\\n\\}`))
    if (!block) throw new Error(`interface ${iface} not found in the contract`)
    for (const line of block[1].split('\n')) {
      const match = line.match(/^\s{2}(\w+)\s*\(/)
      if (match) methods.push(`${domain}.${match[1]}`)
    }
  }
  return { domains, methods }
}

const contract = parseContract(read(CONTRACT))

// ------------------------------------------------------------------------------------------------
// 2. The manifest
// ------------------------------------------------------------------------------------------------

function parseManifest(source) {
  const body = source.match(/export const DATA_METHOD_MANIFEST[^=]*= \{([\s\S]*?)\r?\n\}\r?\n/)
  if (!body) throw new Error('DATA_METHOD_MANIFEST not found')

  const bindings = new Map()
  // Entries are either one line or a wrapped object literal; both start with the quoted key.
  // The trailing newline is re-added because the capture stops before the closing brace, and
  // without it the LAST entry would be skipped — silently, which is the one thing this script
  // must not do.
  const entries = `${body[1].replace(/\r\n/g, '\n')}\n`
  const entryPattern = /'([\w.]+)':\s*\{([\s\S]*?)\},?\n/g
  let match
  while ((match = entryPattern.exec(entries)) !== null) {
    const [, name, fields] = match
    const kind = fields.match(/kind:\s*'(\w+)'/)?.[1]
    const via = fields.match(/via:\s*'([^']*)'/)?.[1]
    const reason = fields.match(/reason:\s*'([\w-]+)'/)?.[1]
    if (!kind || !via) throw new Error(`manifest entry ${name} has no kind/via`)
    bindings.set(name, { kind, via, reason })
  }

  // `readonly string[]` contains a `[`, so the list has to be found by its `= [` opener.
  const specEight = source.match(/export const SPEC_8_METHODS[\s\S]*?=\s*\[([\s\S]*?)\n\]/)
  if (!specEight) throw new Error('SPEC_8_METHODS not found')
  const online = [...specEight[1].matchAll(/'([\w.]+)'/g)].map((m) => m[1])

  return { bindings, online }
}

const { bindings, online } = parseManifest(read(MANIFEST))

// --- contract <-> manifest ----------------------------------------------------------------
for (const name of contract.methods) {
  if (!bindings.has(name)) fail(`${name}: declared in the contract, missing from the manifest`)
}
for (const name of bindings.keys()) {
  if (!contract.methods.includes(name)) fail(`${name}: in the manifest, not in the contract`)
}

// ------------------------------------------------------------------------------------------------
// 3. The implementations
// ------------------------------------------------------------------------------------------------

/** `domain` -> the `const <name>Source` that implements it, read off `index.ts`. */
function parseAssembly(source) {
  const body = source.match(/export const desktopData: DataSource = \{([\s\S]*?)\n\}/)
  if (!body) throw new Error('desktopData assembly not found in index.ts')
  const map = new Map()
  for (const line of body[1].split('\n')) {
    const match = line.match(/^\s{2}(\w+):\s*(\w+),\s*$/)
    if (match) map.set(match[1], match[2])
  }
  return map
}

const assembly = parseAssembly(read(INDEX))
for (const { domain } of contract.domains) {
  if (!assembly.has(domain)) fail(`${domain}: not assembled into desktopData`)
}

/**
 * Slice one `export const <objectName>: ... = { ... }` literal into `method -> body text`.
 *
 * The members are at two-space indent, so a member runs from its own line to the next
 * two-space `name:` line (or to the closing brace). Anything the extractor cannot find is
 * reported, never skipped — a silently missing body would make the whole check vacuous.
 */
function sliceMembers(source, objectName) {
  const start = source.indexOf(`export const ${objectName}`)
  if (start === -1) return null
  const open = source.indexOf('= {', start)
  if (open === -1) return null

  // Walk to the matching brace so a nested object cannot end the literal early.
  let depth = 0
  let end = -1
  for (let i = open + 2; i < source.length; i += 1) {
    const char = source[i]
    if (char === '{') depth += 1
    else if (char === '}') {
      depth -= 1
      if (depth === 0) {
        end = i
        break
      }
    }
  }
  if (end === -1) return null

  const lines = source.slice(open + 3, end).split('\n')
  const members = new Map()
  let current = null
  let buffer = []
  for (const line of lines) {
    const header = line.match(/^\s{2}(\w+):\s/)
    if (header) {
      if (current) members.set(current, buffer.join('\n'))
      current = header[1]
      buffer = [line]
    } else if (current) {
      buffer.push(line)
    }
  }
  if (current) members.set(current, buffer.join('\n'))
  return members
}

const domainSources = DOMAIN_FILES.map(read).join('\n')

const MUTATE_CALLS = /\b(createRow|updateRow|updateRowByClientId|deleteRow|deleteRowByClientId|runAction|runActionByClientId|runUserScopedAction)\(/
const QUERY_CALLS = /\b(runQuery|listPage|countRows|searchLocal|rowsByIds|rowById|readBack|loadRefs|loadRefsByIds|loadCounts|loadTagRefs)\(/
const HTTP_CALLS = /\bhttp\s*\n?\s*\.(get|post|put|patch|delete)\b|\bhttp\.(get|post|put|patch|delete)\b/

/**
 * Helpers whose own bodies are the shared implementation of a member.
 *
 * A value may be one helper name or a list of them; the listed bodies are appended to the
 * member's own before it is classified. A list is NOT a way to make a thin member look wired:
 * every name on it has to be a real function in a domain module, and the union still has to
 * satisfy the member's declared `kind` (a `hybrid` still has to show BOTH a local read and an
 * `http` call, wherever the two live).
 */
const SHARED_HELPERS = {
  'contacts.timeline': 'recordTimeline',
  'companies.timeline': 'recordTimeline',
  'contacts.customFields': 'customFieldDefs',
  'companies.customFields': 'customFieldDefs',
  'contacts.userOptions': 'userOptions',
  'companies.userOptions': 'userOptions',
  'leads.ownerOptions': 'userOptions',
  'deals.ownerOptions': 'userOptions',
  'tickets.userOptions': 'userOptions',
  'leads.customFields': 'customFieldRecords',
  // The deal-form / ticket-form lookups (defter O42). Both domains read through the same three
  // helpers in `crm.ts`, so the helper body is what has to show the local read — the member
  // itself is one line.
  'deals.tags': 'formTagOptions',
  'tickets.tags': 'formTagOptions',
  'deals.customFields': 'customFieldRecords',
  'tickets.customFields': 'customFieldRecords',
  'deals.contactOptions': 'formContactOptions',
  'tickets.contactOptions': 'formContactOptions',
  'deals.companyOptions': 'formCompanyOptions',
  'tickets.companyOptions': 'formCompanyOptions',
  'chat.conversation': 'conversationById',
  'chat.renameConversation': 'conversationById',
  'chat.muteConversation': 'conversationById',
  'chat.markRead': 'cursorAck',
  'chat.markDelivered': 'cursorAck',
  // `search.query` is one line that composes the two halves; the halves themselves are where
  // the local read and the HTTP call live, and both have to be seen for the `hybrid`
  // classification below to mean anything.
  'search.query': ['unifiedSearch', 'localSearchGroups', 'serverSearchGroups'],
}

function helperBody(name) {
  const pattern = new RegExp(`(?:async )?function ${name}\\b[\\s\\S]*?\\n\\}`)
  const body = domainSources.match(pattern)?.[0]
  if (body === undefined) {
    // A helper that cannot be found would silently contribute an empty body, and the member
    // would then be judged on its own one line — which is exactly how a wrong classification
    // gets to look correct.
    fail(`SHARED_HELPERS names ${name}, which is not a function in any domain module`)
    return ''
  }
  return body
}

const memberIndex = new Map()
for (const [domain, objectName] of assembly) {
  let found = null
  for (const path of DOMAIN_FILES) {
    const members = sliceMembers(read(path), objectName)
    if (members) {
      found = members
      break
    }
  }
  if (!found) {
    fail(`${objectName}: implementation object literal not found in any domain module`)
    continue
  }
  for (const [method, body] of found) {
    memberIndex.set(`${domain}.${method}`, body)
  }
}

for (const [name, binding] of bindings) {
  let body = memberIndex.get(name)
  if (body === undefined) {
    fail(`${name}: no implementation body found (the extractor could not locate the member)`)
    continue
  }
  const helper = SHARED_HELPERS[name]
  for (const helperName of helper === undefined ? [] : [helper].flat()) {
    body += `\n${helperBody(helperName)}`
  }

  const usesMutate = MUTATE_CALLS.test(body)
  const usesQuery = QUERY_CALLS.test(body)
  const usesHttp = HTTP_CALLS.test(body)

  if (binding.kind === 'http') {
    if (!usesHttp) fail(`${name}: declared http, but its body never calls platform.http`)
    if (usesMutate) fail(`${name}: declared http, but its body calls a mutate helper (KARAR A15)`)
  } else if (binding.kind === 'mutate') {
    if (!usesMutate) fail(`${name}: declared mutate, but its body calls no mutate helper`)
    if (usesHttp) fail(`${name}: declared mutate, but its body calls platform.http`)
  } else if (binding.kind === 'query') {
    if (!usesQuery) fail(`${name}: declared query, but its body runs no local read`)
    if (usesHttp) fail(`${name}: declared query, but its body calls platform.http`)
    if (usesMutate) fail(`${name}: declared query, but its body writes`)
  } else if (binding.kind === 'hybrid') {
    // BOTH are required, and that is the whole point of the kind. `search.query` used to be
    // declared `query` while it only read the local index; the manifest was true and the
    // feature was missing (SYNCDESKTOP.md §7.2 asks for the two unified). Demanding both
    // halves here means the same gap cannot reopen without this script saying so.
    if (!usesQuery) fail(`${name}: declared hybrid, but its body runs no local read`)
    if (!usesHttp) fail(`${name}: declared hybrid, but its body never calls platform.http`)
    // A hybrid is a READ. Half of it goes straight to the network and cannot be queued, so a
    // write on this path would be reported as done while only one half of it happened.
    if (usesMutate) fail(`${name}: declared hybrid, but its body writes (a hybrid is read-only)`)
  } else {
    fail(`${name}: unknown kind ${binding.kind}`)
  }
}

// --- online-only (SYNCDESKTOP.md §8 / KARAR A15) --------------------------------------------
for (const name of online) {
  const binding = bindings.get(name)
  if (!binding) {
    fail(`${name}: listed as online-only but absent from the manifest`)
    continue
  }
  // Deliberately `!== 'http'`, not "reaches http": `hybrid` also touches the network, and an
  // §8 action bound that way would still have a local half that could report success the
  // server never granted. Only a plain `http` binding satisfies KARAR A15.
  if (binding.kind !== 'http') {
    fail(`${name}: online-only (§8) but bound to '${binding.kind}' — it must go to platform.http, never mutate()`)
  }
}

// --- nothing wired still refuses to run ------------------------------------------------------
const wiredSources = [...DOMAIN_FILES, join(DESKTOP, 'src/platform/desktop.ts')]
for (const path of wiredSources) {
  if (read(path).includes('NOT_IMPLEMENTED')) {
    fail(`${path}: still contains NOT_IMPLEMENTED`)
  }
}

// ------------------------------------------------------------------------------------------------
// 4. The NamedQuery whitelist — no dead variants
// ------------------------------------------------------------------------------------------------

function parseVariants(source) {
  const body = source.match(/pub enum NamedQuery \{([\s\S]*?)\n\}/)
  if (!body) throw new Error('NamedQuery enum not found')
  const variants = []
  for (const line of body[1].split('\n')) {
    const match = line.match(/^\s{4}([A-Z]\w*)\s*[,{]/)
    if (match) variants.push(match[1])
  }
  return variants
}

/** `DealsList` -> `deals_list`, matching `#[serde(rename_all = "snake_case")]`. */
function toSnake(name) {
  return name.replace(/([a-z0-9])([A-Z])/g, '$1_$2').toLowerCase()
}

const variants = parseVariants(read(QUERY_RS))
const adapterSources = [...DOMAIN_FILES, join(DESKTOP, 'src/platform/data/refs.ts'), join(DESKTOP, 'src/platform/data/writes.ts')]
  .map(read)
  .join('\n')

const usedVariants = []
const reservedSeen = []
for (const variant of variants) {
  const tag = toSnake(variant)
  const used = adapterSources.includes(`query: '${tag}'`)
  if (used) {
    usedVariants.push(tag)
    if (RESERVED_QUERIES[tag]) {
      fail(`NamedQuery::${variant}: listed as reserved but the adapter does use it — drop the reservation`)
    }
    continue
  }
  if (RESERVED_QUERIES[tag]) {
    reservedSeen.push(tag)
    continue
  }
  fail(`NamedQuery::${variant} (${tag}): dead — no DataSource method uses it, and it is not declared reserved`)
}

for (const tag of Object.keys(RESERVED_QUERIES)) {
  if (!variants.map(toSnake).includes(tag)) {
    fail(`RESERVED_QUERIES lists ${tag}, which is not a NamedQuery variant`)
  }
}

// ------------------------------------------------------------------------------------------------
// 5. The entity -> query key table covers every entity (KARAR D-5)
//
// The table is hand-written on purpose, which is exactly why it needs a guard: a new `Entity`
// variant would otherwise reach the shell with no invalidation at all, and the symptom would
// be a screen that never refreshes rather than an error.
// ------------------------------------------------------------------------------------------------

const TYPES_RS = join(DESKTOP, 'crates/syncra-sync/src/types.rs')
const EVENTS_TS = join(DESKTOP, 'src/bridge/events.ts')

function parseEntities(source) {
  const body = source.match(/pub enum Entity \{([\s\S]*?)\n\}/)
  if (!body) throw new Error('Entity enum not found')
  return body[1]
    .split('\n')
    .map((line) => line.match(/^\s{4}([A-Z]\w*),\s*$/))
    .filter(Boolean)
    .map((match) => toSnake(match[1]))
}

const entities = parseEntities(read(TYPES_RS))
const eventsSource = read(EVENTS_TS)
const keyTable = eventsSource.match(/export const ENTITY_QUERY_KEYS[^=]*= \{([\s\S]*?)\n\}/)
if (!keyTable) throw new Error('ENTITY_QUERY_KEYS not found')
const mappedEntities = [...keyTable[1].matchAll(/^\s{2}(\w+):/gm)].map((match) => match[1])

for (const entity of entities) {
  if (!mappedEntities.includes(entity)) {
    fail(`bridge/events.ts: Entity::${entity} has no query key mapping (KARAR D-5 — write it, do not derive it)`)
  }
}
for (const entity of mappedEntities) {
  if (!entities.includes(entity)) {
    fail(`bridge/events.ts: ENTITY_QUERY_KEYS maps '${entity}', which is not an Entity variant`)
  }
}

// ------------------------------------------------------------------------------------------------
// Report
// ------------------------------------------------------------------------------------------------

const byKind = { query: 0, mutate: 0, http: 0, hybrid: 0 }
for (const binding of bindings.values()) byKind[binding.kind] += 1

notes.push(`contract methods            : ${contract.methods.length} across ${contract.domains.length} domains`)
notes.push(`manifest entries            : ${bindings.size}`)
notes.push(`  (a) local read  [query]   : ${byKind.query}`)
notes.push(`  (b) local write [mutate]  : ${byKind.mutate}`)
notes.push(`  (c) online-only [http]    : ${byKind.http}`)
notes.push(`  (d) local+server [hybrid] : ${byKind.hybrid}`)
notes.push(`SYNCDESKTOP §8 methods      : ${online.length}, all bound to http`)
notes.push(`NamedQuery variants         : ${variants.length} (${usedVariants.length} used, ${reservedSeen.length} reserved)`)
notes.push(`reserved variants           : ${reservedSeen.join(', ') || '-'}`)
notes.push(`NOT_IMPLEMENTED occurrences : 0`)
notes.push(`Entity -> queryKey rows     : ${mappedEntities.length} / ${entities.length} entities mapped`)

const realtime = runRealtimeWiringCheck()

console.log('data wiring check')
console.log('-'.repeat(52))
for (const note of notes) console.log(note)

console.log('')
console.log('realtime wiring check')
console.log('-'.repeat(52))
for (const note of realtime.notes) console.log(note)

const allProblems = [...problems, ...realtime.problems]

if (allProblems.length > 0) {
  console.error('')
  console.error(`FAILED — ${allProblems.length} problem(s):`)
  for (const problem of allProblems) console.error(`  - ${problem}`)
  process.exit(1)
}

console.log('-'.repeat(52))
console.log('OK')
