#!/usr/bin/env node
// Symmetry check between the error-code allowlist and the error dictionary (open item O55).
//
// `errorMessage()` in `desktop/src/ui/errors.ts` resolves a server/engine `code` string to
// `t(\`desktop:errors.${code}\`)`, but only after checking the code against a hand-transcribed
// `KNOWN_ERROR_CODES` set — a code the set does not know falls through to `desktop:errors.unknown`
// instead. Because the key is built dynamically (`errors.${code}`), neither `i18n:check` (which
// walks statically-referenced keys) nor `i18n:dead-keys` (which matches on the `errors.*` prefix
// and treats the whole namespace as "in use") can see whether the set and the dictionary actually
// agree on which codes exist. Finding B7 (O48) was exactly this: `errors.INVALID_MUTATION` was
// added to all four locale dictionaries but never to `KNOWN_ERROR_CODES`, so every server refusal
// carrying that code rendered as "An unknown error occurred." — a key that existed, had a
// translation, and was still dead.
//
// This script closes that gap by comparing two sources in both directions:
//
//   1. `desktop/src/ui/errors.ts`                    — the `KNOWN_ERROR_CODES` allowlist
//   2. `frontend/src/i18n/locales/tr/desktop.json`    — the `errors` dictionary (tr as reference;
//                                                        `i18n:check` already guarantees the other
//                                                        three locales carry the same key set, so
//                                                        checking all four here would be redundant)
//
// Run: `node scripts/check-error-codes.mjs` (from `desktop/`).

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const DESKTOP = join(HERE, '..')
const REPO = join(DESKTOP, '..')

const ERRORS_TS = join(DESKTOP, 'src/ui/errors.ts')
const DICTIONARY_JSON = join(REPO, 'frontend/src/i18n/locales/tr/desktop.json')

const problems = []
const notes = []

function fail(message) {
  problems.push(message)
}

function read(path) {
  return readFileSync(path, 'utf8')
}

// ------------------------------------------------------------------------------------------------
// 1. KNOWN_ERROR_CODES, from errors.ts
// ------------------------------------------------------------------------------------------------

/**
 * Pulls the code list out of `const KNOWN_ERROR_CODES = new Set<string>([ ... ])`.
 *
 * The declaration block MUST be found — if the source shape changes (renamed constant, no
 * longer a `new Set<string>([...])` literal), this throws instead of quietly returning an empty
 * set. An empty set would make every dictionary code look "dead" and the check would fail loud
 * in the wrong direction on every run, which is its own kind of silent-wrong-answer: the exact
 * failure mode this script exists to avoid reproducing (a controller that answered wrong without
 * saying so).
 */
function parseKnownErrorCodes(source) {
  const block = source.match(/const KNOWN_ERROR_CODES\s*=\s*new Set<string>\(\[([\s\S]*?)\]\)/)
  if (!block) {
    throw new Error(
      'KNOWN_ERROR_CODES: declaration `const KNOWN_ERROR_CODES = new Set<string>([...])` not ' +
        'found in errors.ts — the source shape changed, update the parser rather than trust an ' +
        'empty result',
    )
  }

  // The block interleaves `'CODE',` entries with `//` explanatory comments, and at least one of
  // those comments contains an apostrophe ("the server's own refusal codes"). A single regex
  // pass over the raw block would misparse that apostrophe as a quote delimiter. Comment lines
  // are dropped first so only actual entries are left to match against.
  const codeLines = block[1]
    .split('\n')
    .filter((line) => !line.trim().startsWith('//'))
    .join('\n')

  const codes = [...codeLines.matchAll(/'([A-Z][A-Z0-9_]*)'/g)].map((m) => m[1])
  if (codes.length === 0) {
    throw new Error('KNOWN_ERROR_CODES: block found but no codes extracted — check the pattern')
  }
  return codes
}

const knownCodes = parseKnownErrorCodes(read(ERRORS_TS))

// ------------------------------------------------------------------------------------------------
// 2. The `errors` dictionary, from tr/desktop.json
// ------------------------------------------------------------------------------------------------

/**
 * Pulls the code-shaped keys out of the `errors` object in the tr dictionary.
 *
 * Every real error code is SCREAMING_SNAKE_CASE (`AUTH_REQUIRED`, `INVALID_MUTATION`, ...) —
 * that is the convention `commands/*.rs` and `SyncError::Server{code}` both emit. `unknown` and
 * `httpStatus` are the two non-code entries under `errors`: `unknown` is the fallback sentence
 * `errorMessage()` returns when a code is NOT in `KNOWN_ERROR_CODES`, and `httpStatus` is a
 * templated sentence keyed by the `HTTP_(\d{3})` regex in errors.ts, not by set membership. Both
 * are legitimately camelCase/lowercase and neither is a code, so the SCREAMING_SNAKE_CASE
 * pattern below excludes them structurally instead of by a hard-coded exception list — a future
 * `errors.SOMETHING_ELSE_ALSO_NOT_A_CODE` would still (correctly) be treated as a code and would
 * have to earn its way into `KNOWN_ERROR_CODES` like any other.
 */
function parseDictionaryCodes(source) {
  let parsed
  try {
    parsed = JSON.parse(source)
  } catch (error) {
    throw new Error(`desktop.json: failed to parse as JSON — ${error.message}`)
  }
  const errors = parsed.errors
  if (!errors || typeof errors !== 'object' || Array.isArray(errors)) {
    throw new Error('desktop.json: no `errors` object found at the top level')
  }
  const codes = Object.keys(errors).filter((key) => /^[A-Z][A-Z0-9_]*$/.test(key))
  if (codes.length === 0) {
    throw new Error('desktop.json: `errors` object found but no SCREAMING_SNAKE_CASE keys in it')
  }
  return codes
}

const dictionaryCodes = parseDictionaryCodes(read(DICTIONARY_JSON))

// ------------------------------------------------------------------------------------------------
// 3. Symmetry, both directions
// ------------------------------------------------------------------------------------------------

const knownSet = new Set(knownCodes)
const dictionarySet = new Set(dictionaryCodes)

for (const code of dictionaryCodes) {
  if (!knownSet.has(code)) {
    fail(
      `errors.${code}: in the tr dictionary, missing from KNOWN_ERROR_CODES (desktop/src/ui/errors.ts) ` +
        `— dead key, this is the exact class of finding B7 caught (O48)`,
    )
  }
}
for (const code of knownCodes) {
  if (!dictionarySet.has(code)) {
    fail(
      `KNOWN_ERROR_CODES has '${code}', missing from errors.${code} in the tr dictionary ` +
        `— errorMessage() will resolve this code to desktop:errors.unknown instead of a real sentence`,
    )
  }
}

// ------------------------------------------------------------------------------------------------
// Report
// ------------------------------------------------------------------------------------------------

notes.push(`KNOWN_ERROR_CODES (errors.ts)     : ${knownCodes.length} codes`)
notes.push(`errors.* SCREAMING_SNAKE (tr.json) : ${dictionaryCodes.length} codes`)
notes.push(`symmetric                          : ${problems.length === 0 ? knownCodes.length : 'no'}`)

console.log('error code symmetry check')
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
