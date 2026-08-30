#!/usr/bin/env node
/**
 * Faz 14 / Iz D — anahtar-parite denetleyicisi (docs/PHASE-INTL.md §1.7).
 *
 * "Sessizce bozulan" sinifina karsi bir araç: i18next, eksik bir anahtari fallback dile
 * (tr) sessizce duşurur — hata gorunmez, yalniz metin yanlis dilde cikar (ya da hic
 * cevrilmemis TR metin baska bir dilde ekranda kalir). Bu script IKI ayri kontrol yapar:
 *
 *  1) DIL-DILE PARITE: tr/en/de/fr sozluklerinin anahtar kumelerini KARSILASTIRIR ve
 *     herhangi bir dilde eksik/fazla anahtar varsa hangi dilde hangi anahtarin eksik
 *     oldugunu okunur sekilde basar.
 *  2) TERS YON (kod -> sozluk): `src/**` icindeki `useTranslation(...)` / `t(...)` /
 *     `i18n.t(...)` / `<Trans i18nKey="...">` kullanimlarini statik olarak tarar ve
 *     KODDA KULLANILAN ama fallback dilde (tr) TANIMLI OLMAYAN anahtarlari yakalar. Bu,
 *     (1)'in KORLUGU oldugu bir sinif: bir namespace HICBIR dilde yoksa (orn. kod
 *     `useTranslation('contacts')` kullaniyor ama `locales/<dil>/contacts.json` hic yok),
 *     dil-dile karsilastirma "hepsi ayni (bos)" gordugu icin bunu YAKALAYAMAZ —
 *     `missingKeyHandler` (`src/i18n/index.ts`) dev/test'te throw eder ve ekran çöker.
 *
 * Iki kontrol de ayni komutta kosar; herhangi biri basarisiz olursa cikis kodu 1'dir.
 *
 * Kapsam: yalnizca frontend/src/i18n/locales/{tr,en,de,fr}/*.json (namespace bazli) VE
 * frontend/src/**\/*.{ts,tsx} (ters yon taramasi icin okuma-amaçli).
 * Backend `lang/*.php` paritesi icin ayrica `backend/tests/Feature/LocalizationParityTest.php`
 * (PHPUnit) yazildi — gerekce icin bkz. o dosyanin dogblock'u ve bu gorevin raporu.
 *
 * i18next CLDR cogul suffix'leri (`key_one`/`key_other`/... — bkz. docs/PHASE-INTL.md §1.1):
 * Turkce/Ingilizce/Almanca "one"+"other", Fransizca 0 VE 1 icin "one", geri kalani "other"
 * kullanir (CLDR kategorileri dile gore degisir). Bu yuzden SUFFIX'Lİ anahtarlar icin katı
 * esitlik YANLIS olur — bir dilde `_one`/`_other` varken baska bir dilde ek olarak `_many`
 * gibi bir kategori olabilir, bu HATA degildir. Kural: bir cogul anahtarin TABANI (suffix
 * cikarilmis hali, orn. `column.aria`) her dilde bulunmali; hangi suffix'lerin var oldugu
 * o dilin CLDR kategorilerine serbest birakilir. Duz (cogul olmayan) anahtarlar icin taban =
 * anahtarin kendisi, yani onlar icin fiilen katı esitlik uygulanmis olur.
 *
 * Bagimlilik YOK — saf Node (fs/path/url). package.json'a yeni paket EKLENMEDI.
 */

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const SRC_DIR = join(SCRIPT_DIR, '..', 'src');
const LOCALES_DIR = join(SRC_DIR, 'i18n', 'locales');
const LOCALES = ['tr', 'en', 'de', 'fr'];
const FALLBACK_LOCALE = 'tr';

// CLDR cogul kategorileri (i18next suffix'leri). Bkz. docs/PHASE-INTL.md §1.1.
const PLURAL_SUFFIX_RE = /_(zero|one|two|few|many|other)$/;

/** Bir namespace JSON dosyasini "a.b.c" -> leaf yol kumesine duzlestirir. */
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

/** Cogul suffix'ini soyar: "board.column.aria_one" -> "board.column.aria". */
function baseKey(path) {
  const segments = path.split('.');
  const last = segments.pop();
  const stripped = last.replace(PLURAL_SUFFIX_RE, '');
  segments.push(stripped);
  return segments.join('.');
}

function listNamespaces() {
  const namespaces = new Set();
  for (const locale of LOCALES) {
    const dir = join(LOCALES_DIR, locale);
    if (!existsSync(dir)) continue;
    for (const entry of readdirSync(dir)) {
      if (entry.endsWith('.json')) namespaces.add(entry.slice(0, -'.json'.length));
    }
  }
  return [...namespaces].sort();
}

function loadNamespace(locale, namespace) {
  const path = join(LOCALES_DIR, locale, `${namespace}.json`);
  if (!existsSync(path)) return { exists: false, baseKeys: null, error: null };

  let raw;
  try {
    raw = readFileSync(path, 'utf8');
  } catch (err) {
    return { exists: true, baseKeys: null, error: `okunamadi: ${err.message}` };
  }

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (err) {
    return { exists: true, baseKeys: null, error: `gecersiz JSON: ${err.message}` };
  }

  const raw_keys = flattenKeys(parsed);
  const baseKeys = new Set([...raw_keys].map(baseKey));
  return { exists: true, baseKeys, error: null };
}

/**
 * KONTROL 1 — dil-dile parite. `problems` dizisine okunur satirlar ekler, tarama
 * ozetini dondurur ({ totalNamespaces, totalKeys }).
 */
function checkCrossLocaleParity(problems) {
  const namespaces = listNamespaces();
  let totalNamespaces = 0;
  let totalKeys = 0;

  for (const namespace of namespaces) {
    totalNamespaces += 1;
    const perLocale = {};

    for (const locale of LOCALES) {
      perLocale[locale] = loadNamespace(locale, namespace);
    }

    for (const locale of LOCALES) {
      const result = perLocale[locale];
      if (!result.exists) {
        problems.push(`[${locale}] ${namespace}.json: DOSYA YOK`);
      } else if (result.error) {
        problems.push(`[${locale}] ${namespace}.json: ${result.error}`);
      }
    }

    // Referans kume: dosyasi gecerli olan tum locale'lerin taban-anahtar BIRLESIMI.
    // (Boylece bir anahtar yalnizca TEK bir dilde varsa, o "fazla" anahtar diger
    // dillerde otomatik olarak "eksik" diye raporlanir — ayri bir "fazla anahtar"
    // gecisine gerek kalmadan iki yonlu simetrik tutarsizlik yakalanmis olur.)
    const validLocales = LOCALES.filter((l) => perLocale[l].exists && !perLocale[l].error);
    const union = new Set();
    for (const locale of validLocales) {
      for (const key of perLocale[locale].baseKeys) union.add(key);
    }
    totalKeys += union.size;

    for (const locale of validLocales) {
      const own = perLocale[locale].baseKeys;
      const missing = [...union].filter((key) => !own.has(key)).sort();
      for (const key of missing) {
        problems.push(`[${locale}] ${namespace}.json: eksik anahtar '${key}'`);
      }
    }
  }

  return { totalNamespaces, totalKeys };
}

// ---------------------------------------------------------------------------
// KONTROL 2 — TERS YON: kodda kullanilan ama fallback dilde (tr) tanimli olmayan
// anahtarlar. Bagimsiz bir mini-tarayici — tsc/babel YOK, saf regex/string tarama.
// Kapsam kasitli DAR tutuldu (bkz. asagidaki desenler); amac tam bir JS parser
// yazmak degil, bu kod tabaninin GERCEKTE kullandigi birkac sabit deseni yakalamak.
// ---------------------------------------------------------------------------

/** `src/**\/*.{ts,tsx}` dosyalarini recursive toplar (locales JSON'lari zaten .json, elenir). */
function collectSourceFiles(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const st = statSync(full);
    if (st.isDirectory()) {
      collectSourceFiles(full, out);
      continue;
    }
    if (/\.(tsx?|jsx?)$/.test(entry) && !entry.endsWith('.d.ts')) out.push(full);
  }
  return out;
}

/**
 * `content[start]` bir tirnak/backtick karakteri oldugunda, kapanan (kacissiz) esini
 * bulup ham icerigi dondurur. Kacisli tirnaklari (`\'`, `\"`, `` \` ``) atlar.
 */
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
    if (ch === quote) {
      i += 1;
      break;
    }
    raw += ch;
    i += 1;
  }
  return { value: raw, end: i };
}

function lineNumberAt(content, index) {
  let line = 1;
  for (let i = 0; i < index; i += 1) {
    if (content[i] === '\n') line += 1;
  }
  return line;
}

function escapeRegExp(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Bir dosyayi tarar: `useTranslation(...)` baglamalarini cozer, sonra o dosyada
 * cagrilan `t(...)` / `tXxx(...)` / `i18n.t(...)` / `<Trans i18nKey="...">`
 * kullanimlarini STATIK anahtarlara ({ ns, key, line }) cevirir. Sablon
 * literali/degisken ile uretilen anahtarlar cozulemez — `dynamicCount` ile sayilir,
 * sessizce yutulmaz.
 */
function analyzeSourceFile(content) {
  // 1) `const { t } = useTranslation('ns')` / `const { t: alias } = useTranslation(['a','b'])`
  //    KAPSAM: bu kod tabaninda useTranslation HER ZAMAN yalniz `t` (gerekirse yeniden
  //    adlandirilmis) destructure eder, tek satirda yazilir (bkz. gorev taramasi) — bu yuzden
  //    ikinci bir secenek argumani (`{ keyPrefix }` vb.) veya coklu destructure YOK sayiliyor.
  const useTranslationRe = /const\s*\{\s*t(?:\s*:\s*(\w+))?\s*\}\s*=\s*useTranslation\(([^)]*)\)/g;
  /** @type {Map<string, string[]>} identifier -> namespace listesi (bildirim sirasiyla) */
  const identifierNs = new Map();
  let m;
  while ((m = useTranslationRe.exec(content))) {
    const alias = m[1] || 't';
    const argRaw = m[2].trim();
    let nsList;
    if (!argRaw) {
      nsList = ['common']; // defaultNS (bkz. i18n/index.ts)
    } else if (argRaw.startsWith('[')) {
      nsList = [...argRaw.matchAll(/'([^']*)'|"([^"]*)"/g)].map((mm) => mm[1] ?? mm[2]);
    } else {
      const sm = argRaw.match(/^['"]([^'"]*)['"]$/);
      nsList = sm ? [sm[1]] : ['common'];
    }
    if (nsList.length > 0) identifierNs.set(alias, nsList);
  }

  // Taramada aranacak cagri-kimlikleri: yerel olarak baglanmis tum aliaslar + her zaman `t`
  // (`t: TFunction` parametresi alan yardimci fonksiyonlar icin — bu dosyada yerel bir
  // useTranslation binding'i olmayabilir, ama `t(...)` yine de cagrilir; bkz. gorev raporu).
  const translationIds = new Set(identifierNs.keys());
  translationIds.add('t');

  const firstEntry = identifierNs.values().next();
  const filePrimaryNs = firstEntry.done ? 'common' : firstEntry.value[0];

  function primaryNsFor(id) {
    const list = identifierNs.get(id);
    return list ? list[0] : 'common';
  }

  const staticRefs = [];
  let dynamicCount = 0;

  for (const id of translationIds) {
    const callRe = new RegExp(`\\b${escapeRegExp(id)}\\(\\s*`, 'g');
    let cm;
    while ((cm = callRe.exec(content))) {
      const idx = cm.index + cm[0].length;
      const ch = content[idx];
      if (ch === "'" || ch === '"' || ch === '`') {
        const { value } = readQuotedLiteral(content, idx);
        if (ch === '`' && value.includes('${')) {
          // Sablon literali degisken icerior — statik cozulemez (orn. `t(\`chat:x.${type}\`)`).
          dynamicCount += 1;
          continue;
        }
        const colonIdx = value.indexOf(':');
        let ns, key;
        if (colonIdx > -1) {
          ns = value.slice(0, colonIdx);
          key = value.slice(colonIdx + 1);
        } else {
          ns = primaryNsFor(id);
          key = value;
        }
        if (!key) continue; // bos anahtar (`t('')` gibi) — anlamli degil, atla
        staticRefs.push({ ns, key, line: lineNumberAt(content, cm.index) });
      } else {
        // `t(someVar)`, `t(section.titleKey)` gibi degisken/ifade ile uretilen anahtar.
        dynamicCount += 1;
      }
    }
  }

  // 2) `<Trans i18nKey="ns:key" .../>` — bu kod tabaninda Trans HER ZAMAN kendini kapatir
  //    (`/>`), coklu satir attribute'lari destekler.
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
    const colonIdx = rawKey.indexOf(':');
    let ns, key;
    if (colonIdx > -1) {
      ns = rawKey.slice(0, colonIdx);
      key = rawKey.slice(colonIdx + 1);
    } else {
      key = rawKey;
      const tPropMatch = attrs.match(/\bt=\{(\w+)\}/);
      const nsPropMatch = attrs.match(/\bns=(?:\{)?['"]([^'"}]*)['"]/);
      if (tPropMatch && identifierNs.has(tPropMatch[1])) {
        ns = primaryNsFor(tPropMatch[1]);
      } else if (nsPropMatch) {
        ns = nsPropMatch[1];
      } else {
        ns = filePrimaryNs;
      }
    }
    staticRefs.push({ ns, key, line: lineNumberAt(content, tm.index) });
  }

  return { staticRefs, dynamicCount };
}

/**
 * KONTROL 2 — ters yon. `problems` dizisine okunur satirlar ekler, tarama ozetini
 * dondurur ({ scannedFiles, dynamicSkipped }).
 */
function checkCodeToDictionary(problems) {
  const files = collectSourceFiles(SRC_DIR).filter((f) => !f.includes(`${join('i18n', 'locales')}`));

  /** @type {Map<string, Set<string>|null>} ns -> tr taban-anahtar kumesi (null = dosya yok) */
  const trKeysByNs = new Map();
  function trKeysFor(ns) {
    if (trKeysByNs.has(ns)) return trKeysByNs.get(ns);
    const result = loadNamespace(FALLBACK_LOCALE, ns);
    const value = result.exists && !result.error ? result.baseKeys : null;
    trKeysByNs.set(ns, value);
    return value;
  }

  /** @type {Map<string, { count: number, first: string }>} eksik namespace -> ornek konum */
  const missingNamespaces = new Map();
  /** @type {Map<string, { count: number, first: string }>} "ns:key" -> ornek konum */
  const missingKeys = new Map();
  let dynamicSkipped = 0;
  let scannedFiles = 0;

  for (const file of files) {
    const rel = relative(SRC_DIR, file).split('\\').join('/');
    const content = readFileSync(file, 'utf8');
    if (!/useTranslation\(|i18n\.t\(|<Trans\b|\bt\(/.test(content)) continue; // hizli elemek

    scannedFiles += 1;
    const { staticRefs, dynamicCount } = analyzeSourceFile(content);
    dynamicSkipped += dynamicCount;

    for (const { ns, key, line } of staticRefs) {
      const loc = `src/${rel}:${line}`;
      const trKeys = trKeysFor(ns);
      if (trKeys === null) {
        const existing = missingNamespaces.get(ns);
        if (existing) existing.count += 1;
        else missingNamespaces.set(ns, { count: 1, first: loc });
        continue;
      }
      if (!trKeys.has(baseKey(key))) {
        const id = `${ns}:${key}`;
        const existing = missingKeys.get(id);
        if (existing) existing.count += 1;
        else missingKeys.set(id, { count: 1, first: loc });
      }
    }
  }

  for (const [ns, info] of [...missingNamespaces.entries()].sort()) {
    problems.push(
      `[ters-yon] '${ns}' namespace'i kodda kullaniliyor ama sozluk dosyasi yok (locales/${FALLBACK_LOCALE}/${ns}.json) ` +
        `— ${info.count} kullanim, ilk: ${info.first}`
    );
  }
  for (const [id, info] of [...missingKeys.entries()].sort()) {
    problems.push(
      `[ters-yon] '${id}' kodda kullaniliyor ama locales/${FALLBACK_LOCALE}/ icinde tanimli degil ` +
        `— ${info.count} kullanim, ilk: ${info.first}`
    );
  }

  return { scannedFiles, dynamicSkipped };
}

function main() {
  if (!existsSync(LOCALES_DIR)) {
    console.error(`[i18n:check] Locale dizini bulunamadi: ${LOCALES_DIR}`);
    process.exit(1);
  }

  /** @type {string[]} */
  const problems = [];

  const { totalNamespaces, totalKeys } = checkCrossLocaleParity(problems);
  const { scannedFiles, dynamicSkipped } = checkCodeToDictionary(problems);

  console.log(
    `[i18n:check] Kontrol 1/2 (dil-dile parite): ${LOCALES.join('/')} — ${totalNamespaces} namespace, ~${totalKeys} anahtar tarandi.`
  );
  console.log(
    `[i18n:check] Kontrol 2/2 (kod -> sozluk, fallback=${FALLBACK_LOCALE}): ${scannedFiles} dosya tarandi, ` +
      `${dynamicSkipped} dinamik anahtar cozulemedi (atlandi, sessizce yutulmadi).\n`
  );

  if (problems.length === 0) {
    console.log('[i18n:check] OK — tum diller birebir anahtar paritesinde ve kod, tr sozlugunde tanimli olmayan hicbir anahtar kullanmiyor.');
    process.exit(0);
  }

  console.error(`[i18n:check] BASARISIZ — ${problems.length} parite sorunu bulundu:\n`);
  for (const problem of problems.sort()) {
    console.error(`  - ${problem}`);
  }
  console.error('\n[i18n:check] Cozum (dil-dile): eksik anahtari ilgili locales/<dil>/<namespace>.json dosyasina ekleyin.');
  console.error(
    '[i18n:check] Cozum (ters-yon, "[ters-yon]" etiketli satirlar): namespace dosyasi hic yoksa olusturun; ' +
      'anahtar yoksa locales/tr/<namespace>.json (ve idealde diger diller) icine ekleyin.'
  );
  console.error('[i18n:check] Not: `key_one`/`key_other` gibi CLDR cogul suffix\'leri istisna — yalniz anahtarin');
  console.error('TABANI (suffix\'siz hali) her dilde aranir, hangi suffix\'lerin bulunacagi dile gore serbesttir.');
  process.exit(1);
}

main();
