#!/usr/bin/env node
/**
 * Dead-key report — the direction `i18n:check` cannot see.
 *
 * `scripts/check-i18n-parity.mjs` runs two checks, and both point the same way: language to
 * language (does every locale have the key the others have) and code to dictionary (does the
 * dictionary have the key the code uses). Neither can answer the opposite question — is there
 * a key in the dictionary that NO code ever asks for — and that gap has already produced a
 * defect: ledger O20, where four `desktop.json` files gained `errors.INVALID_CREDENTIALS` and
 * `errors.PENDING_MUTATIONS` in all four languages while nothing in the shell ever resolved
 * either code. Four translations, four reviews, zero call sites, and a green gate.
 *
 * This script reads the `tr` dictionaries (the fallback locale, i.e. the one guaranteed to be
 * complete — parity proves the other three match it) and every `.ts`/`.tsx` source under
 * `frontend/src` and `desktop/src`, and lists the keys nothing consumes.
 *
 * ## Why this is NOT in the regression gate
 *
 * It is a heuristic, and it is honest about that. `t(someVar)` cannot be resolved without a
 * type checker, template keys are matched by PREFIX (`` t(`desktop:errors.${code}`) `` marks
 * everything under `desktop:errors.` as used, including keys that no code path can actually
 * reach), and a key can legitimately exist ahead of the screen that will use it. So the
 * default exit code is 0: this prints a list a human reads. `--strict` makes it exit 1, for
 * the case where someone wants to hold a specific number.
 *
 * Painting the gate red with a heuristic is how a gate stops being trusted; a gate nobody
 * trusts is worse than no gate.
 *
 * ## Plural suffixes
 *
 * `discardWarning_one` / `discardWarning_other` are ONE key as far as call sites go — the code
 * writes `t('...discardWarning', { count })` and i18next picks the suffix. So dictionary keys
 * are compared on their suffix-stripped base, exactly as `check-i18n-parity.mjs` does.
 *
 * Run: `npm run i18n:dead-keys` (from `frontend/`), `-- --strict` to fail on a finding.
 * No dependencies — pure Node.
 */

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const FRONTEND_SRC = join(SCRIPT_DIR, '..', 'src');
const DESKTOP_SRC = join(SCRIPT_DIR, '..', '..', 'desktop', 'src');
const LOCALES_DIR = join(FRONTEND_SRC, 'i18n', 'locales');
const REFERENCE_LOCALE = 'tr';
const DEFAULT_NS = 'common';

const STRICT = process.argv.slice(2).includes('--strict');

// CLDR plural suffixes (i18next). Same list as check-i18n-parity.mjs.
const PLURAL_SUFFIX_RE = /_(zero|one|two|few|many|other)$/;

/**
 * Source roots. `desktop/src` is included because the desktop shell reaches the app's own
 * i18next singleton through `desktop/src/ui/useT.ts` (KARAR A28) — its `t('desktop:...')`
 * calls are the ONLY consumers of the `desktop` namespace, and a scan that stopped at
 * `frontend/src` would report every key in it as dead.
 */
const SOURCE_ROOTS = [
  { label: 'frontend/src', dir: FRONTEND_SRC },
  { label: 'desktop/src', dir: DESKTOP_SRC },
];

/**
 * `namespace:prefix` pairs whose real consumer this script structurally cannot see, because it
 * only scans `.ts`/`.tsx` — treated exactly like a matched template prefix (`isUsed()` below),
 * so a key here is never reported as dead.
 *
 * This is a liveness OWNERSHIP TRANSFER, not a blind spot: each entry names, in its own comment,
 * the thing that actually keeps it honest — if that key stops being used there, THAT check goes
 * red, not this one silently. An allowlist entry with no such real check backing it would be a
 * blind spot; this is the narrow exception where one already exists elsewhere.
 */
const EXTERNAL_CONSUMER_PREFIXES = [
  {
    ns: 'desktop',
    prefix: 'tray.',
    // Real owner of "is this key still used": `desktop/src-tauri/src/tray.rs` `include_str!`s
    // all four `desktop.json` files and reads `tray.*` through a bespoke serde-ish parser keyed
    // by Rust field names, not string literals — teaching THIS script to parse Rust for one
    // small, stable key family would be over-engineering for what it buys. Instead,
    // `tray.rs`'s `every_language_parses` test loads all four catalogues and asserts every
    // `tray.*` key `tray.rs` reads resolves in each one; delete or rename a key out from under
    // it and `cargo test` fails immediately. So this allowlist entry does not stop anyone from
    // finding out a `tray.*` key died — it points at the check that actually can.
  },
];

// ---------------------------------------------------------------------------
// Dictionary
// ---------------------------------------------------------------------------

/** Flatten a namespace JSON into `a.b.c` leaf paths. */
function flattenKeys(obj, prefix = '', out = new Set()) {
  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
      flattenKeys(value, path, out);
    } else {
      out.add(path);
    }
  }
  return out;
}

/** `board.column.aria_one` -> `board.column.aria`. */
function baseKey(path) {
  const segments = path.split('.');
  const last = segments.pop();
  segments.push(last.replace(PLURAL_SUFFIX_RE, ''));
  return segments.join('.');
}

/** `ns -> Set<base key>` for the reference locale. */
function loadReferenceDictionary() {
  const dir = join(LOCALES_DIR, REFERENCE_LOCALE);
  const dictionary = new Map();
  for (const entry of readdirSync(dir)) {
    if (!entry.endsWith('.json')) continue;
    const ns = entry.slice(0, -'.json'.length);
    const parsed = JSON.parse(readFileSync(join(dir, entry), 'utf8'));
    dictionary.set(ns, new Set([...flattenKeys(parsed)].map(baseKey)));
  }
  return dictionary;
}

// ---------------------------------------------------------------------------
// Source scan
// ---------------------------------------------------------------------------

function collectSourceFiles(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      collectSourceFiles(full, out);
      continue;
    }
    if (/\.(tsx?|jsx?)$/.test(entry) && !entry.endsWith('.d.ts')) out.push(full);
  }
  return out;
}

/** Read the quoted/backticked literal that starts at `content[start]`. */
function readQuotedLiteral(content, start) {
  const quote = content[start];
  let i = start + 1;
  let raw = '';
  while (i < content.length) {
    const ch = content[i];
    if (ch === '\\') {
      raw += ch + (content[i + 1] ?? '');
      i += 2;
      continue;
    }
    if (ch === quote) break;
    raw += ch;
    i += 1;
  }
  return raw;
}

function escapeRegExp(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Split `'ns:key'` (or a bare `'key'`) into `{ ns, key }`, defaulting the namespace. */
function splitQualified(raw, fallbackNs) {
  const colon = raw.indexOf(':');
  if (colon > -1) return { ns: raw.slice(0, colon), key: raw.slice(colon + 1) };
  return { ns: fallbackNs, key: raw };
}

/**
 * Scan one file for translation lookups.
 *
 * Returns the static keys it resolved, the PREFIXES a template literal indexes into, and the
 * number of call sites whose key is a runtime value. Deliberately the same narrow set of
 * patterns `check-i18n-parity.mjs` recognises — `useTranslation` bindings, `t(...)` /
 * `alias(...)` / `i18n.t(...)`, and `<Trans i18nKey="...">` — plus the template branch, which
 * parity throws away and this script needs.
 */
function analyzeSourceFile(content) {
  const useTranslationRe = /const\s*\{\s*t(?:\s*:\s*(\w+))?\s*\}\s*=\s*useTranslation\(([^)]*)\)/g;
  /** @type {Map<string, string>} identifier -> primary namespace */
  const identifierNs = new Map();
  let m;
  while ((m = useTranslationRe.exec(content))) {
    const alias = m[1] || 't';
    const argRaw = m[2].trim();
    let nsList;
    if (!argRaw) {
      nsList = [DEFAULT_NS];
    } else if (argRaw.startsWith('[')) {
      nsList = [...argRaw.matchAll(/'([^']*)'|"([^"]*)"/g)].map((mm) => mm[1] ?? mm[2]);
    } else {
      const sm = argRaw.match(/^['"]([^'"]*)['"]$/);
      nsList = sm ? [sm[1]] : [DEFAULT_NS];
    }
    if (nsList.length > 0 && !identifierNs.has(alias)) identifierNs.set(alias, nsList[0]);
  }

  const translationIds = new Set(identifierNs.keys());
  translationIds.add('t');

  const filePrimaryNs = identifierNs.values().next().value ?? DEFAULT_NS;
  const primaryNsFor = (id) => identifierNs.get(id) ?? DEFAULT_NS;

  const staticRefs = [];
  const prefixRefs = [];
  let dynamicCount = 0;

  for (const id of translationIds) {
    const callRe = new RegExp(`\\b${escapeRegExp(id)}\\(\\s*`, 'g');
    let cm;
    while ((cm = callRe.exec(content))) {
      const idx = cm.index + cm[0].length;
      const ch = content[idx];
      if (ch !== "'" && ch !== '"' && ch !== '`') {
        // `t(someVar)`, `t(cond ? 'a' : 'b')`, `t(section.titleKey)` — not resolvable here.
        dynamicCount += 1;
        continue;
      }
      const value = readQuotedLiteral(content, idx);
      if (ch === '`' && value.includes('${')) {
        // A template key. Everything BEFORE the first interpolation is a real prefix into the
        // dictionary; matching on it is what stops `t(`enums:leadStatus.${status}`)` from
        // reporting every status label as dead.
        const literalHead = value.slice(0, value.indexOf('${'));
        const { ns, key } = splitQualified(literalHead, filePrimaryNs);
        if (!literalHead || literalHead.endsWith(':')) {
          // `t(`${ns}:...`)` or `t(`enums:${x}`)` — the namespace itself, or the whole key,
          // is computed. Nothing can be concluded about which keys are reachable.
          dynamicCount += 1;
          if (literalHead.endsWith(':')) prefixRefs.push({ ns, prefix: '' });
          continue;
        }
        prefixRefs.push({ ns, prefix: key });
        continue;
      }
      if (!value) continue;
      const { ns, key } = splitQualified(value, primaryNsFor(id));
      if (key) staticRefs.push({ ns, key });
    }
  }

  const transRe = /<Trans\b([\s\S]*?)\/>/g;
  let tm;
  while ((tm = transRe.exec(content))) {
    const attrs = tm[1];
    const keyMatch = attrs.match(/i18nKey\s*=\s*(['"])(.*?)\1/);
    if (!keyMatch) {
      if (/i18nKey\s*=\s*\{/.test(attrs)) dynamicCount += 1;
      continue;
    }
    const rawKey = keyMatch[2];
    if (!rawKey) continue;
    const colon = rawKey.indexOf(':');
    if (colon > -1) {
      staticRefs.push({ ns: rawKey.slice(0, colon), key: rawKey.slice(colon + 1) });
      continue;
    }
    const tProp = attrs.match(/\bt=\{(\w+)\}/);
    const nsProp = attrs.match(/\bns=(?:\{)?['"]([^'"}]*)['"]/);
    const ns = tProp && identifierNs.has(tProp[1]) ? primaryNsFor(tProp[1]) : nsProp?.[1] ?? filePrimaryNs;
    staticRefs.push({ ns, key: rawKey });
  }

  return { staticRefs, prefixRefs, dynamicCount };
}

/**
 * Second pass: fully-qualified key literals that are NOT written inside a `t(...)` call.
 *
 * This codebase routinely hoists keys into a table and resolves them later —
 * `{ value: 'all', labelKey: 'chat:conversationList.filters.all' }`, `SUBJECT_TYPE_KEYS`,
 * `PASSWORD_RULE_KEYS`, `DATE_RANGE_PRESETS` — and the call site is then `t(labelKey)`, which
 * the first pass can only count as one unresolved dynamic key. Without this pass every such
 * table reads as a hundred dead keys and the report is noise.
 *
 * The rule is deliberately narrow: a string or template literal whose text is
 * `<known namespace>:<something>`. Nothing else in this tree has that shape, so the pass adds
 * references without inventing them. Whole-line comments are dropped first — several modules
 * document their own key families in prose (`logs:enums.subjectType.*`), and a comment is not
 * a call site.
 *
 * Its cost is under-reporting, never over-reporting: a genuinely dead key that some literal
 * still mentions will not be listed. That is the right direction for a heuristic whose output
 * a human uses to decide what to delete.
 */
function collectQualifiedLiterals(content, namespaces) {
  const code = content
    .split('\n')
    .map((line) => (/^\s*(\/\/|\*|\/\*)/.test(line) ? '' : line))
    .join('\n');

  const staticRefs = [];
  const prefixRefs = [];
  for (const match of code.matchAll(/(['"`])([a-zA-Z][\w-]*):([^'"`\n]*)\1/g)) {
    const [, , ns, rest] = match;
    if (!namespaces.has(ns)) continue;
    const interpolation = rest.indexOf('${');
    if (interpolation === -1) {
      if (rest) staticRefs.push({ ns, key: rest });
      continue;
    }
    prefixRefs.push({ ns, prefix: rest.slice(0, interpolation) });
  }
  return { staticRefs, prefixRefs };
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main() {
  if (!existsSync(join(LOCALES_DIR, REFERENCE_LOCALE))) {
    console.error(`[i18n:dead-keys] Reference locale directory not found: ${join(LOCALES_DIR, REFERENCE_LOCALE)}`);
    process.exit(1);
  }

  const dictionary = loadReferenceDictionary();
  const namespaces = new Set(dictionary.keys());

  /** @type {Map<string, Set<string>>} ns -> used base keys */
  const usedKeys = new Map();
  /** @type {Map<string, Set<string>>} ns -> template prefixes */
  const usedPrefixes = new Map();
  let dynamicSkipped = 0;
  let scannedFiles = 0;

  const addTo = (map, ns, value) => {
    const bucket = map.get(ns);
    if (bucket) bucket.add(value);
    else map.set(ns, new Set([value]));
  };

  for (const root of SOURCE_ROOTS) {
    if (!existsSync(root.dir)) {
      console.error(`[i18n:dead-keys] Source root missing: ${root.dir}`);
      process.exit(1);
    }
    const files = collectSourceFiles(root.dir).filter((f) => !f.includes(join('i18n', 'locales')));
    for (const file of files) {
      const content = readFileSync(file, 'utf8');
      scannedFiles += 1;

      if (/useTranslation\(|i18n\.t\(|<Trans\b|\bt\(/.test(content)) {
        const { staticRefs, prefixRefs, dynamicCount } = analyzeSourceFile(content);
        dynamicSkipped += dynamicCount;
        for (const ref of staticRefs) addTo(usedKeys, ref.ns, baseKey(ref.key));
        for (const ref of prefixRefs) addTo(usedPrefixes, ref.ns, ref.prefix);
      }

      const hoisted = collectQualifiedLiterals(content, namespaces);
      for (const ref of hoisted.staticRefs) addTo(usedKeys, ref.ns, baseKey(ref.key));
      for (const ref of hoisted.prefixRefs) addTo(usedPrefixes, ref.ns, ref.prefix);
    }
  }

  // Seed the external-consumer allowlist as if it were a matched template prefix — same
  // shape (`ns -> Set<prefix>`), so `isUsed()` needs no separate code path for it.
  for (const { ns, prefix } of EXTERNAL_CONSUMER_PREFIXES) addTo(usedPrefixes, ns, prefix);

  /** A dictionary key counts as reachable if some call site names it, or indexes into it. */
  function isUsed(ns, key) {
    if (usedKeys.get(ns)?.has(key)) return true;
    const prefixes = usedPrefixes.get(ns);
    if (!prefixes) return false;
    for (const prefix of prefixes) {
      if (prefix === '' || key.startsWith(prefix)) return true;
    }
    return false;
  }

  /**
   * Reachable ONLY through a template prefix — no call site names this key.
   *
   * O102 is why this bucket exists. Sixteen `desktop:onlineOnly.*` keys were wired that round
   * and the report went 17 -> 0, which read as "all seventeen are live". Four of them
   * (`settings`, `reports`, `dashboard`, `logs`) had no call site at all: the one template,
   * `` t(`desktop:onlineOnly.${action}`) ``, marks the whole family used, so the four that
   * nothing ever passes are indistinguishable from the thirteen that something does.
   *
   * The prefix rule itself is correct and stays — dropping it would flood the report with false
   * positives from `notificationText.ts` and friends. What was wrong is that it made a family
   * DISAPPEAR from the output entirely. These keys are now counted and, on request, listed: not
   * dead, not proven live, and the report no longer implies the second.
   */
  function isPrefixOnly(ns, key) {
    if (usedKeys.get(ns)?.has(key)) return false;
    const prefixes = usedPrefixes.get(ns);
    if (!prefixes) return false;
    for (const prefix of prefixes) {
      if (prefix !== '' && key.startsWith(prefix)) return true;
    }
    return false;
  }

  const dead = new Map();
  const prefixOnly = new Map();
  let totalKeys = 0;
  for (const [ns, keys] of [...dictionary.entries()].sort()) {
    totalKeys += keys.size;
    const unreachable = [...keys].filter((key) => !isUsed(ns, key)).sort();
    if (unreachable.length > 0) dead.set(ns, unreachable);
    const viaPrefix = [...keys].filter((key) => isPrefixOnly(ns, key)).sort();
    if (viaPrefix.length > 0) prefixOnly.set(ns, viaPrefix);
  }
  const prefixOnlyTotal = [...prefixOnly.values()].reduce((sum, list) => sum + list.length, 0);

  const deadTotal = [...dead.values()].reduce((sum, list) => sum + list.length, 0);
  const wildcardNamespaces = [...usedPrefixes.entries()]
    .filter(([, prefixes]) => prefixes.has(''))
    .map(([ns]) => ns)
    .sort();

  console.log('i18n dead-key report');
  console.log('-'.repeat(52));
  console.log(`reference locale            : ${REFERENCE_LOCALE}`);
  console.log(`namespaces                  : ${dictionary.size}`);
  console.log(`dictionary keys (base)      : ${totalKeys}`);
  console.log(`source files scanned        : ${scannedFiles} (${SOURCE_ROOTS.map((r) => r.label).join(' + ')})`);
  console.log(`static keys referenced      : ${[...usedKeys.values()].reduce((s, set) => s + set.size, 0)}`);
  console.log(`template prefixes matched   : ${[...usedPrefixes.values()].reduce((s, set) => s + set.size, 0)}`);
  console.log(`  wholly dynamic namespaces : ${wildcardNamespaces.length} (${wildcardNamespaces.join(', ') || '-'})`);
  console.log(`dynamic keys unresolved     : ${dynamicSkipped} (skipped, not swallowed)`);
  console.log(`unreferenced keys           : ${deadTotal} in ${dead.size} namespace(s)`);
  console.log(`reached ONLY via a prefix   : ${prefixOnlyTotal} in ${prefixOnly.size} namespace(s) — counted live, not proven live`);
  if (process.argv.includes('--list-prefix-only') && prefixOnlyTotal > 0) {
    console.log('');
    for (const [ns, keys] of [...prefixOnly.entries()].sort()) {
      console.log(`${ns}.json — ${keys.length} (prefix-only)`);
      for (const key of keys) console.log(`  ~ ${ns}:${key}`);
    }
  }

  if (deadTotal > 0) {
    console.log('');
    for (const [ns, keys] of dead) {
      console.log(`${ns}.json — ${keys.length}`);
      for (const key of keys) console.log(`  - ${ns}:${key}`);
    }
    console.log('');
    console.log('A listed key is one no static call site names and no template prefix reaches.');
    console.log('It is a CANDIDATE, not a verdict: check it against the dynamic count above');
    console.log('before deleting anything — and delete from all four locales, never just tr.');
  }

  console.log('-'.repeat(52));

  if (STRICT && deadTotal > 0) {
    console.error(`FAILED (--strict) — ${deadTotal} unreferenced key(s)`);
    process.exit(1);
  }

  console.log(deadTotal === 0 ? 'OK — no unreferenced key found' : 'OK (report only; pass --strict to fail)');
}

main();
