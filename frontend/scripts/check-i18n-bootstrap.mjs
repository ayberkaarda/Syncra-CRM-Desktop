#!/usr/bin/env node
/**
 * Regresyon kilidi — "Ingilizce sectim, sayfayi yeniledim, arayuz Turkce" hatasi.
 *
 * HATA NEYDI (kok neden): `src/i18n/index.ts` acilista `i18n.init({ lng })`e
 * localStorage'daki secili dili (`en`/`de`/`fr`) veriyordu, ama `resources` YALNIZCA
 * eager `tr` paketini tasiyordu. `en/de/fr` sozlukleri `import.meta.glob` ile LAZY'dir
 * ve SADECE `setLocale()` icindeki `ensureBundlesLoaded()` tarafindan indirilir.
 * Tam sayfa yenilemede `setLocale()` HIC cagrilmaz -> `i18n.language === 'en'` ama
 * `en` sozlugu bellekte YOK -> i18next sessizce `fallbackLng: 'tr'`e duser.
 * Belirti: dil secici "English" gosterirken tum metin Turkce basar. Cokme yok, hata
 * yok, konsolda iz yok — "sessizce bozulan" sinifindan, elle fark edilmesi cok zor.
 *
 * BU BETIK IKI KATMANDA KILITLER:
 *
 *   1) STATIK KONTROL — `i18n/index.ts` kaynaginda acilis yolu hala "init'e verilen
 *      dilin paketini yukluyor" mu; `main.tsx` ilk boyayi bu hazirlik sozu cozulene
 *      kadar geciktiriyor mu; `tr` hala eager, digerleri hala lazy mi; korunmasi
 *      istenen kararlar (missingKeyHandler throw, applyUserLocale'in "elle secim
 *      sunucuyu ezer" davranisi, dis imzalar) yerinde mi.
 *
 *   2) DAVRANISSAL KONTROL — `i18n/index.ts`in GERCEK kaynagi calistirilir. Vite'a
 *      ozgu iki sey (`import.meta.glob`, `import.meta.env`) sahte karsiliklariyla
 *      degistirilir, `react-i18next` yerine kucuk bir 3rdParty stub konur; `i18next`
 *      GERCEK paketten gelir. Sahte glob, `tr` icin { probe.greeting: "Merhaba" },
 *      `en/de/fr` icin { probe.greeting: "Hello"/"Hallo"/"Bonjour" } dondurur ve LAZY
 *      yukleyiciler kasitli olarak bir makro-goreve geciktirilir. Boylece hatanin
 *      imzasi olculebilir hale gelir: acilis yolu bozuksa `language === 'en'` iken
 *      `t('common:probe.greeting') === 'Merhaba'` (fallback) cikar; dogruysa 'Hello'.
 *
 *      Senaryolar (her biri AYRI bir Node alt surecinde — i18next singleton oldugu
 *      icin ayni surecte iki kez `init()` edilemez):
 *        en / de / fr : ready beklenince o dilin paketi YUKLU ve metin O DILDE olmali;
 *                       ready'den ONCE paket henuz YOK olmali (yani bekleme gercek).
 *        tr           : lazy yukleyici HIC cagrilmamali ve ready ANINDA cozulmeli
 *                       (eager `tr` ag beklemesine sokulmuyor).
 *        de + hata    : lazy chunk reddedilirse uygulama COKMEMELI; konsola uyari
 *                       basip acikca `tr`ye dusmeli (dil secici de yalan soylememeli).
 *
 * Bagimlilik YOK — saf Node. Vitest/Jest bu projede KURULU DEGIL ve kurulmadi;
 * check-i18n-parity.mjs / check-money-currency-symbols.mjs ile ayni desen.
 */

import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url))
const FRONTEND_DIR = join(SCRIPT_DIR, '..')
const I18N_TS_PATH = join(FRONTEND_DIR, 'src', 'i18n', 'index.ts')
const MAIN_TSX_PATH = join(FRONTEND_DIR, 'src', 'main.tsx')

/**
 * Probe dosyalari OS gecici klasorune yazilir — proje agacina hicbir sey birakilmaz
 * (node_modules altina da YAZILAMAZ: Node oradaki dosyalarda tip silmeyi reddediyor).
 * Gecici klasorden `import ... from 'i18next'` cozulemeyecegi icin bu tek bare import
 * asagida `import.meta.resolve` ile bulunan MUTLAK dosya URL'sine cevrilir — yani
 * probe yine GERCEK i18next paketini kullanir.
 */
const PROBE_DIR = join(tmpdir(), 'syncra-i18n-bootstrap-probe')
const I18NEXT_URL = import.meta.resolve('i18next')

let failures = 0

function fail(message) {
  failures += 1
  console.error(`  [HATA] ${message}`)
}

function ok(message) {
  console.log(`  OK: ${message}`)
}

/** Bir fonksiyon govdesini basit derinlik sayaciyla cikarir (money betigiyle ayni yaklasim). */
function extractFunctionBody(source, functionName) {
  const start = source.search(new RegExp(`function\\s+${functionName}\\s*\\(`))
  if (start === -1) return null
  const open = source.indexOf('{', start)
  if (open === -1) return null
  let depth = 0
  for (let i = open; i < source.length; i += 1) {
    if (source[i] === '{') depth += 1
    else if (source[i] === '}') {
      depth -= 1
      if (depth === 0) return source.slice(open, i + 1)
    }
  }
  return null
}

const i18nSource = readFileSync(I18N_TS_PATH, 'utf8')
const mainSource = readFileSync(MAIN_TSX_PATH, 'utf8')

// ---------------------------------------------------------------------------
// 1) STATIK KONTROL
// ---------------------------------------------------------------------------
console.log('== 1) Statik kontrol: acilis yolu kaynak duzeyinde ==')

// 1a) Acilis dili TEK bir const'a cozulmus mu? (init ile bootstrap ayni degeri kullanmali)
const initialLocaleMatch = i18nSource.match(/const\s+([A-Za-z_$][\w$]*)\s*=\s*resolveInitialLocale\(\)/)
let initialLocaleName = null
if (!initialLocaleMatch) {
  fail(
    'i18n/index.ts icinde `const <x> = resolveInitialLocale()` bulunamadi. Acilis dili bir ' +
      'degiskene alinmazsa init ile acilis yuklemesi ayni dili kullandigini kanitlayamaz.'
  )
} else {
  initialLocaleName = initialLocaleMatch[1]
  ok(`acilis dili tek bir const'a cozulmus: \`${initialLocaleName}\``)
}

// 1b) init() bu const ile cagriliyor mu?
if (initialLocaleName) {
  if (!new RegExp(`\\blng:\\s*${initialLocaleName}\\b`).test(i18nSource)) {
    fail(`i18n.init() icinde \`lng: ${initialLocaleName}\` bulunamadi.`)
  } else {
    ok(`i18n.init() acilis diliyle kuruluyor: \`lng: ${initialLocaleName}\``)
  }
}

// 1c) Disa acilan bir hazirlik sozu var mi ve bootstrap fonksiyonundan mi geliyor?
const readyMatch = i18nSource.match(
  /export\s+const\s+([A-Za-z_$][\w$]*)\s*:\s*Promise<void>\s*=\s*([A-Za-z_$][\w$]*)\s*\(\s*\)/
)
let readyName = null
let bootstrapName = null
if (!readyMatch) {
  fail(
    'i18n/index.ts bir `export const <x>: Promise<void> = <bootstrap>()` acilis sozu ' +
      'DISA ACMIYOR. main.tsx ilk boyayi neyi bekleyerek geciktirecek?'
  )
} else {
  readyName = readyMatch[1]
  bootstrapName = readyMatch[2]
  ok(`acilis hazirlik sozu disa aciliyor: \`${readyName}\` (kaynak: \`${bootstrapName}()\`)`)
}

// 1d) ASIL KANIT: bootstrap govdesi, init'e verilen DILIN paketini yukluyor mu?
if (bootstrapName && initialLocaleName) {
  const body = extractFunctionBody(i18nSource, bootstrapName)
  if (!body) {
    fail(`\`${bootstrapName}\` fonksiyon govdesi okunamadi.`)
  } else {
    if (!new RegExp(`ensureBundlesLoaded\\s*\\(\\s*${initialLocaleName}\\s*\\)`).test(body)) {
      fail(
        `\`${bootstrapName}\` icinde \`ensureBundlesLoaded(${initialLocaleName})\` YOK. ` +
          'Tam da duzeltilen hata bu: secili dilin sozlugu ilk render oncesinde yuklenmiyor.'
      )
    } else {
      ok(`\`${bootstrapName}\` acilis dilinin paketini yukluyor: ensureBundlesLoaded(${initialLocaleName})`)
    }

    // `resolvedLanguage` tazelemesi: paket SONRADAN eklendigi icin i18next'in acilista
    // `tr`ye sabitledigi resolvedLanguage elle tazelenmezse getActiveLocale()/getIntlLocale()
    // yanlis kalir (metin Ingilizce, para/tarih tr-TR).
    if (!new RegExp(`changeLanguage\\s*\\(\\s*${initialLocaleName}\\s*\\)`).test(body)) {
      fail(
        `\`${bootstrapName}\` paketi yukledikten sonra \`changeLanguage(${initialLocaleName})\` ` +
          'cagirmiyor — i18next resolvedLanguage\'i acilista tr\'ye sabitler ve kendiliginden tazelemez.'
      )
    } else {
      ok('paket yuklendikten sonra changeLanguage ile resolvedLanguage tazeleniyor')
    }

    // Varsayilan dil icin ag beklemesi olmamali (erken donus)
    if (!new RegExp(`if\\s*\\(\\s*${initialLocaleName}\\s*===\\s*DEFAULT_LOCALE\\s*\\)\\s*return`).test(body)) {
      fail(
        `\`${bootstrapName}\` icinde \`if (${initialLocaleName} === DEFAULT_LOCALE) return\` erken ` +
          'donusu YOK. `tr` eager oldugu halde gereksiz bekletiliyor olabilir.'
      )
    } else {
      ok('varsayilan dil (tr) erken donuyor — eager paket ag beklemesine sokulmuyor')
    }

    // Yukleme basarisiz olursa: cokme yok, sessizlik de yok
    if (!/catch\s*\(/.test(body)) {
      fail(`\`${bootstrapName}\` icinde catch YOK — sozluk inmezse acilis reddedilir/uygulama acilmaz.`)
    } else if (!/console\.warn\s*\(/.test(body)) {
      fail(`\`${bootstrapName}\` hata durumunda console.warn BASMIYOR — sessiz bozulmaya geri donus.`)
    } else if (!/changeLanguage\s*\(\s*DEFAULT_LOCALE\s*\)/.test(body)) {
      fail(
        `\`${bootstrapName}\` hata durumunda \`changeLanguage(DEFAULT_LOCALE)\` cagirmiyor — ` +
          'metin Turkce basarken i18n.language yabanci dilde kalir (dil secici yalan soyler).'
      )
    } else {
      ok('yukleme hatasi: catch + console.warn + acikca DEFAULT_LOCALE\'e dusme — cokme yok, sessizlik yok')
    }
  }
}

// 1e) main.tsx ilk boyayi hazirlik sozune bagliyor mu?
if (readyName) {
  if (!new RegExp(`import\\s*\\{[^}]*\\b${readyName}\\b[^}]*\\}\\s*from\\s*'\\./i18n'`).test(mainSource)) {
    fail(`main.tsx \`${readyName}\` sembolunu './i18n'den import ETMIYOR.`)
  } else {
    ok(`main.tsx acilis sozunu import ediyor: \`${readyName}\``)
  }

  const gateIndex = Math.max(
    mainSource.indexOf(`${readyName}.then(`),
    mainSource.indexOf(`await ${readyName}`)
  )
  const renderIndex = mainSource.indexOf('createRoot(')

  if (renderIndex === -1) {
    fail('main.tsx icinde createRoot( bulunamadi.')
  } else if (gateIndex === -1) {
    fail(
      `main.tsx \`${readyName}\`i BEKLEMIYOR (ne \`.then(\` ne \`await\`). Ilk render sozluk ` +
        'inmeden yapilir -> yanlis dil / flash geri gelir.'
    )
  } else if (gateIndex > renderIndex) {
    fail('main.tsx once render edip SONRA acilis sozunu bekliyor — sira ters, flash geri gelir.')
  } else {
    ok('main.tsx ilk boyayi acilis sozu cozulene kadar geciktiriyor (render, kapinin ARDINDA)')
  }

  // Kapinin disinda kacak bir render olmasin: satir basinda `createRoot(` = ust duzey cagri
  if (/^\s*createRoot\s*\(/m.test(mainSource) && !/\.then\(|await/.test(mainSource)) {
    fail('main.tsx ust duzeyde kosulsuz createRoot( cagirıyor.')
  }
}

// 1f) Bundle-boyutu karari: tr eager, digerleri lazy
const eagerTrGlob = /import\.meta\.glob<[^(]*>\(\s*'\.\/locales\/tr\/\*\.json'\s*,\s*\{[^}]*eager:\s*true/s.test(
  i18nSource
)
if (!eagerTrGlob) {
  fail("`./locales/tr/*.json` globu `eager: true` DEGIL — baslangic bundle'i Turkceyi tasimiyor olabilir.")
} else {
  ok('tr sozlugu hala eager (baslangic bundle\'inda)')
}

const lazyGlobMatch = i18nSource.match(/import\.meta\.glob<[^(]*>\(\s*'\.\/locales\/\{en,de,fr\}\/\*\.json'\s*,\s*\{([^}]*)\}/s)
if (!lazyGlobMatch) {
  fail("`./locales/{en,de,fr}/*.json` globu bulunamadi.")
} else if (/eager:\s*true/.test(lazyGlobMatch[1])) {
  fail('en/de/fr globu eager yapilmis — bu, hatayi "cozer" ama baslangic bundle\'ini sisirir (karar bozuldu).')
} else {
  ok('en/de/fr sozlukleri hala lazy (ayri chunk)')
}

// 1g) Korunmasi istenen kararlar
const missingKeyBody = i18nSource.slice(i18nSource.indexOf('missingKeyHandler'))
if (!/missingKeyHandler/.test(i18nSource) || !/throw new Error\(/.test(missingKeyBody)) {
  fail('missingKeyHandler dev/test throw davranisi kaybolmus.')
} else {
  ok('missingKeyHandler dev/test\'te hala throw ediyor')
}

const applyBody = extractFunctionBody(i18nSource, 'applyUserLocale')
if (!applyBody || !/readStoredLocale\(\)\s*!==\s*null\s*\)\s*return/.test(applyBody)) {
  fail('applyUserLocale artik "elle secim sunucu tercihini ezer" korumasini tasimiyor.')
} else {
  ok('applyUserLocale: elle secilen dil sunucu tercihiyle EZILMIYOR')
}

for (const symbol of [
  'export const SUPPORTED_LOCALES',
  'export const DEFAULT_LOCALE',
  'export function getIntlLocale',
  'export async function setLocale',
  'export async function applyUserLocale',
  'export function getActiveLocale',
  'export default i18n',
]) {
  if (!i18nSource.includes(symbol)) fail(`Dis imza kaybolmus: \`${symbol}\``)
}
if (failures === 0) ok('dis imzalarin hepsi yerinde (180+ dosya bunlara bagli)')

// ---------------------------------------------------------------------------
// 2) DAVRANISSAL KONTROL — gercek index.ts kaynagi, sahte glob, gercek i18next
// ---------------------------------------------------------------------------
console.log('\n== 2) Davranissal kontrol: gercek i18n/index.ts calistiriliyor ==')

/** Vite'a ozgu parcalari sahtelerle degistir; geri kalan HER SEY dokunulmadan kalir. */
function buildProbeSource(source) {
  return source
    .replace(
      /import\s*\{\s*initReactI18next\s*\}\s*from\s*'react-i18next'/,
      "const initReactI18next = { type: '3rdParty', init: () => {} }"
    )
    .replace(/from\s*'i18next'/, `from ${JSON.stringify(I18NEXT_URL)}`)
    .replaceAll('import.meta.glob', '__probeGlob')
    .replaceAll('import.meta.env', '__probeEnv')
}

const PROBE_HEADER = `
// OTOMATIK URETILDI — scripts/check-i18n-bootstrap.mjs. Elle duzenlemeyin.
const __probeEnv = { DEV: false, MODE: 'production' }
const __probeGlobCalls = []
const __probeLazyCalls = []
const __probeWarnings = []

const __TR_BUNDLES = {
  './locales/tr/common.json': { probe: { greeting: 'Merhaba' } },
  './locales/tr/deals.json': { probe: { title: 'Firsatlar' } },
}
const __LAZY_BUNDLES = {
  './locales/en/common.json': { probe: { greeting: 'Hello' } },
  './locales/en/deals.json': { probe: { title: 'Deals' } },
  './locales/de/common.json': { probe: { greeting: 'Hallo' } },
  './locales/de/deals.json': { probe: { title: 'Deals' } },
  './locales/fr/common.json': { probe: { greeting: 'Bonjour' } },
  './locales/fr/deals.json': { probe: { title: 'Affaires' } },
}

/** Sahte import.meta.glob. Lazy yukleyiciler KASITLI olarak bir makro-goreve geciktirilir. */
function __probeGlob(pattern, options) {
  __probeGlobCalls.push({ pattern, eager: Boolean(options && options.eager) })
  if (options && options.eager) return { ...__TR_BUNDLES }
  const out = {}
  for (const [path, data] of Object.entries(__LAZY_BUNDLES)) {
    out[path] = () => {
      __probeLazyCalls.push(path)
      if (process.env.PROBE_FAIL === '1') {
        return Promise.reject(new Error('probe: chunk indirilemedi (ag hatasi taklidi)'))
      }
      return new Promise((resolve) => setTimeout(() => resolve(data), 25))
    }
  }
  return out
}

const __storage = new Map()
if (process.env.PROBE_STORED) __storage.set('syncra-locale', process.env.PROBE_STORED)
globalThis.window = {
  localStorage: {
    getItem: (k) => (__storage.has(k) ? __storage.get(k) : null),
    setItem: (k, v) => { __storage.set(k, v) },
  },
}
globalThis.document = { documentElement: { lang: '' } }
Object.defineProperty(globalThis, 'navigator', {
  value: { language: 'tr-TR' },
  configurable: true,
  writable: true,
})
console.warn = (...args) => { __probeWarnings.push(args.map(String).join(' ')) }
`

const PROBE_FOOTER = `
// --- olcum ---
const __expected = process.env.PROBE_STORED
const __bundleBefore = i18n.getResourceBundle(__expected, 'common')
const __t0 = Date.now()
await i18nReady
const __elapsed = Date.now() - __t0
process.stdout.write('PROBE_RESULT ' + JSON.stringify({
  stored: __expected,
  globCalls: __probeGlobCalls,
  lazyLoadCalls: __probeLazyCalls.length,
  bundleBeforeReady: Boolean(__bundleBefore),
  bundleAfterReady: Boolean(i18n.getResourceBundle(__expected, 'common')),
  language: i18n.language,
  activeLocale: getActiveLocale(),
  greeting: i18n.t('common:probe.greeting'),
  dealsTitle: i18n.t('deals:probe.title'),
  htmlLang: globalThis.document.documentElement.lang,
  warnings: __probeWarnings,
  elapsedMs: __elapsed,
}) + '\\n')
`

function runProbe(env) {
  const file = join(PROBE_DIR, `probe-${env.PROBE_STORED}-${env.PROBE_FAIL === '1' ? 'fail' : 'ok'}.mts`)
  writeFileSync(file, PROBE_HEADER + buildProbeSource(i18nSource) + PROBE_FOOTER, 'utf8')
  const res = spawnSync(process.execPath, [file], {
    cwd: FRONTEND_DIR,
    env: { ...process.env, ...env },
    encoding: 'utf8',
  })
  const line = (res.stdout || '').split('\n').find((l) => l.startsWith('PROBE_RESULT '))
  if (!line) {
    return { error: `alt surec sonuc uretmedi (exit=${res.status}).\n${res.stderr || res.stdout}` }
  }
  return JSON.parse(line.slice('PROBE_RESULT '.length))
}

const EXPECTED_GREETING = { tr: 'Merhaba', en: 'Hello', de: 'Hallo', fr: 'Bonjour' }
let behavioralRan = false

try {
  mkdirSync(PROBE_DIR, { recursive: true })

  // --- 2a) Varsayilan OLMAYAN diller: ready beklenince O DIL, oncesinde paket YOK ---
  console.log('\n  -- 2a) en/de/fr: acilisi bekle, dogru dil gelsin --')
  for (const locale of ['en', 'de', 'fr']) {
    const r = runProbe({ PROBE_STORED: locale, PROBE_FAIL: '0' })
    if (r.error) {
      fail(`[${locale}] ${r.error}`)
      continue
    }
    behavioralRan = true

    if (r.bundleBeforeReady) {
      fail(`[${locale}] paket ready'den ONCE de bellekteydi — bekleme yolu gercekten calismiyor olabilir.`)
    }
    if (!r.bundleAfterReady) {
      fail(`[${locale}] ready cozuldugu halde "${locale}" sozlugu bellekte YOK.`)
    }
    if (r.language !== locale || r.activeLocale !== locale) {
      fail(`[${locale}] dil ${r.language}/${r.activeLocale} olarak kaldi.`)
    }
    if (r.greeting !== EXPECTED_GREETING[locale]) {
      fail(
        `[${locale}] REGRESYON: metin "${EXPECTED_GREETING[locale]}" yerine "${r.greeting}" ` +
          `(dil ${r.language} iken fallback ${r.greeting === 'Merhaba' ? 'TURKCEYE DUSTU' : 'yanlis'}).`
      )
    }
    if (r.htmlLang !== locale) fail(`[${locale}] <html lang> "${r.htmlLang}" olmus.`)
    if (r.lazyLoadCalls === 0) fail(`[${locale}] hicbir lazy paket indirilmemis.`)

    console.log(
      `     ${locale}: ready ONCESI paket=${r.bundleBeforeReady} -> SONRASI paket=${r.bundleAfterReady}, ` +
        `dil=${r.language}, metin="${r.greeting}"/"${r.dealsTitle}", <html lang>=${r.htmlLang}, ` +
        `indirilen chunk=${r.lazyLoadCalls}, bekleme=${r.elapsedMs}ms`
    )
  }

  // --- 2b) tr: hic ag beklemesi olmamali ---
  console.log('\n  -- 2b) tr: eager, ek bekleme YOK --')
  const tr = runProbe({ PROBE_STORED: 'tr', PROBE_FAIL: '0' })
  if (tr.error) {
    fail(`[tr] ${tr.error}`)
  } else {
    behavioralRan = true
    if (tr.lazyLoadCalls !== 0) {
      fail(`[tr] lazy yukleyici ${tr.lazyLoadCalls} kez cagrilmis — Turkce ag beklemesine sokuluyor.`)
    }
    if (tr.elapsedMs >= 25) {
      fail(`[tr] acilis sozu ${tr.elapsedMs}ms bekletti (lazy gecikme 25ms) — aninda cozulmeli.`)
    }
    if (tr.greeting !== 'Merhaba' || tr.language !== 'tr') {
      fail(`[tr] dil=${tr.language}, metin="${tr.greeting}".`)
    }
    const eagerCall = tr.globCalls.find((c) => c.pattern.includes('/tr/'))
    if (!eagerCall || !eagerCall.eager) fail('[tr] tr globu eager cagrilmamis.')
    console.log(
      `     tr: lazy chunk=${tr.lazyLoadCalls}, bekleme=${tr.elapsedMs}ms, dil=${tr.language}, metin="${tr.greeting}"`
    )
  }

  // --- 2c) Ag hatasi: cokme yok, sessizlik yok, acikca tr ---
  console.log('\n  -- 2c) sozluk indirilemedi: cokme yok, uyari var, tr\'ye dusuyor --')
  const broken = runProbe({ PROBE_STORED: 'de', PROBE_FAIL: '1' })
  if (broken.error) {
    fail(`[de/hata] ${broken.error} — acilis sozu REDDEDILDI, uygulama hic acilmazdi.`)
  } else {
    behavioralRan = true
    if (broken.language !== 'tr' || broken.activeLocale !== 'tr') {
      fail(`[de/hata] dil "${broken.language}" olarak kaldi — metin Turkce basarken secici yalan soyler.`)
    }
    if (broken.greeting !== 'Merhaba') fail(`[de/hata] metin "${broken.greeting}".`)
    if (broken.warnings.length === 0) fail('[de/hata] konsola HIC uyari basilmamis — sessiz bozulma.')
    if (broken.htmlLang !== 'tr') fail(`[de/hata] <html lang>="${broken.htmlLang}".`)
    console.log(
      `     de+hata: ready cozuldu (${broken.elapsedMs}ms), dil=${broken.language}, metin="${broken.greeting}", ` +
        `<html lang>=${broken.htmlLang}`
    )
    console.log(`     uyari: ${broken.warnings[0] ?? '(yok)'}`)
  }
} finally {
  try {
    rmSync(PROBE_DIR, { recursive: true, force: true })
  } catch {
    // Gecici dosya temizligi kritik degil.
  }
}

if (!behavioralRan) {
  fail('Davranissal kontrollerin HICBIRI calisamadi — statik kontrol tek basina yeterli sayilmaz.')
}

console.log(
  '\n' +
    (failures === 0
      ? 'BASARILI — acilis yolu secili dilin sozlugunu ILK RENDER ONCESINDE yukluyor; ' +
        'tr ek bekleme gormuyor; yukleme hatasinda uygulama cokmeden tr\'ye dusuyor.'
      : `BASARISIZ — ${failures} hata.`)
)
process.exit(failures === 0 ? 0 : 1)
