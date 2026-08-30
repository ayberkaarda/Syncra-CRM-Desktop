#!/usr/bin/env node
/**
 * Regresyon kilidi — "US$/TRY sembol hatasi" (bkz. money.ts basindaki NEDEN yorumu).
 *
 * BAGLAM: `Intl.NumberFormat`'in varsayilan `currencyDisplay: 'symbol'` davranisi CLDR
 * geregi bir parayi locale'in KENDI para biriminden ayirt etmek icin UZUN bicim basar
 * (ornegin en-GB'de TRY -> duz "TRY", USD -> "US$"; fr-FR'de USD -> "$US", GBP -> "£GB").
 * `frontend/src/lib/money.ts` bunu `currencyDisplay: 'narrowSymbol'` + eski motorlar icin
 * `try/catch` geri dususuyle duzeltti. Bu script IKI seyi ayri ayri kilitler:
 *
 *   1) STATIK KONTROL: money.ts kaynagi hala `narrowSymbol` + try/catch geri dususu
 *      iceriyor mu? (Birisi "gereksiz" diye kaldirirsa burada YAKALANIR — asagidaki
 *      davranissal kontrol GERCEK Intl calistigi icin bunu tek basina YAKALAYAMAZ,
 *      cunku money.ts'in KENDISI import EDILMIYOR; bkz. asagidaki not.)
 *   2) DAVRANISSAL KONTROL: money.ts'teki formatlayici kurulumuyla BIREBIR ayni secenek
 *      nesnesiyle gercek `Intl.NumberFormat` calistirilir (Node ve tarayici ayni V8/ICU
 *      altyapisini paylasir) ve dort locale x dort para biriminin HEPSININ narrow/kisa
 *      sembol bastigi (₺/$/€/£), UZUN bicim (TRY/US$/$US/£GB) BASMADIGI dogrulanir.
 *      Ayrica narrowSymbol'u DESTEKLEMEYEN bir motoru simule ederek (gecersiz bir
 *      `currencyDisplay` degeriyle RangeError zorlanarak) money.ts'teki try/catch
 *      GERI DUSUS deseninin gercekten calistigi (cokme yerine `symbol`'e duşmesi)
 *      dogrulanir.
 *
 * NEDEN money.ts DOGRUDAN IMPORT EDILMIYOR: dosya `../i18n`'i import ediyor, o da
 * Vite'a ozgu `import.meta.glob`/`import.meta.env` kullaniyor — duz Node'da (bundler
 * olmadan) calismaz. Bu yuzden (2) money.ts'in KURULUM MANTIGINI (ayni secenek
 * anahtarlari, ayni try/catch sirasi) yeniden olusturup GERCEK Intl motoruyla calistirir;
 * (1)'deki statik kontrol de money.ts kaynaginin bu mantigi hala tasidigini ayrica
 * dogrular — ikisi birlikte "kod hala dogru sekli kuruyor" VE "bu sekil dogru sonucu
 * uretiyor" garantilerini kapatir.
 *
 * Bagimlilik YOK — saf Node (fs/path/url), check-i18n-parity.mjs ile ayni desen.
 * Vitest/Jest bu projede KURULU DEGIL; yeni test altyapisi kurmak yerine bu betik
 * `npm run` scripti olarak eklendi (bkz. package.json `test:money-currency`).
 */

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url))
const MONEY_TS_PATH = join(SCRIPT_DIR, '..', 'src', 'lib', 'money.ts')

const LOCALES = { 'tr-TR': 'tr', 'en-GB': 'en', 'de-DE': 'de', 'fr-FR': 'fr' }
const CURRENCIES = ['TRY', 'USD', 'EUR', 'GBP']

/** Her para birimi icin beklenen "dar" sembol — dort locale'in DORDUNDE de ayni olmali. */
const EXPECTED_NARROW_SYMBOL = { TRY: '₺', USD: '$', EUR: '€', GBP: '£' }

/** Regresyonun asil belirtisi: bu uzun/karisik bicimlerin HICBIRI artik basilmamali. */
const FORBIDDEN_SUBSTRINGS = ['TRY', 'US$', '$US', '£GB', 'GB£']

let failures = 0
const rows = []

function fail(message) {
  failures += 1
  console.error(`  [HATA] ${message}`)
}

// ---------------------------------------------------------------------------
// 1) STATIK KONTROL — money.ts kaynagi hala narrowSymbol + try/catch iceriyor mu?
// ---------------------------------------------------------------------------
console.log('== 1) Statik kontrol: money.ts kaynagi ==')
const moneySource = readFileSync(MONEY_TS_PATH, 'utf8')

const narrowSymbolCount = (moneySource.match(/currencyDisplay:\s*'narrowSymbol'/g) ?? []).length
if (narrowSymbolCount < 2) {
  fail(
    `money.ts icinde currencyDisplay: 'narrowSymbol' beklenen 2 (getMoneyFormatter + ` +
      `getCompactMoneyFormatter) yerine ${narrowSymbolCount} kez bulundu.`
  )
} else {
  console.log(`  OK: currencyDisplay: 'narrowSymbol' ${narrowSymbolCount} yerde bulundu.`)
}

function extractFunctionBody(source, functionName) {
  const start = source.indexOf(`function ${functionName}(`)
  if (start === -1) return null
  // Fonksiyonun kapanis suslu parantezini basit bir derinlik sayaciyla bul.
  let depth = 0
  let bodyStart = -1
  for (let i = start; i < source.length; i++) {
    if (source[i] === '{') {
      if (depth === 0) bodyStart = i
      depth++
    } else if (source[i] === '}') {
      depth--
      if (depth === 0) return source.slice(bodyStart, i + 1)
    }
  }
  return null
}

for (const fnName of ['getMoneyFormatter', 'getCompactMoneyFormatter']) {
  const body = extractFunctionBody(moneySource, fnName)
  if (!body) {
    fail(`${fnName} fonksiyonu money.ts icinde bulunamadi.`)
    continue
  }
  const hasTry = /\btry\s*\{/.test(body)
  const hasCatch = /\}\s*catch\b/.test(body)
  if (!hasTry || !hasCatch) {
    fail(`${fnName} icinde beklenen try/catch geri dususu bulunamadi (narrowSymbol RangeError korumasi).`)
  } else {
    console.log(`  OK: ${fnName} try/catch geri dususu icinde narrowSymbol kuruyor.`)
  }
}

// ---------------------------------------------------------------------------
// 2) DAVRANISSAL KONTROL — money.ts'teki KURULUM MANTIGININ AYNISI (narrowSymbol
//    dene, RangeError'da symbol'e dus) gercek Intl ile calistirilir.
// ---------------------------------------------------------------------------
console.log('\n== 2) Davranissal kontrol: gercek Intl.NumberFormat cikisi ==')

function buildFormatterLikeMoneyTs(intlLocale, currency, currencyDisplay) {
  const base = { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }
  try {
    return new Intl.NumberFormat(intlLocale, { ...base, currencyDisplay })
  } catch {
    return new Intl.NumberFormat(intlLocale, base)
  }
}

console.log('\n  locale   | ' + CURRENCIES.map((c) => c.padEnd(12)).join(''))
console.log('  ' + '-'.repeat(9 + CURRENCIES.length * 12))
for (const [intlTag, shortLocale] of Object.entries(LOCALES)) {
  const cells = []
  for (const currency of CURRENCIES) {
    const formatted = buildFormatterLikeMoneyTs(intlTag, currency, 'narrowSymbol').format(1234.56)
    cells.push(formatted)
    rows.push({ locale: intlTag, currency, formatted })

    const expectedSymbol = EXPECTED_NARROW_SYMBOL[currency]
    if (!formatted.includes(expectedSymbol)) {
      fail(`${intlTag}/${currency}: beklenen sembol "${expectedSymbol}" cikista yok -> "${formatted}"`)
    }
    for (const forbidden of FORBIDDEN_SUBSTRINGS) {
      if (formatted.includes(forbidden)) {
        fail(`${intlTag}/${currency}: yasakli uzun bicim "${forbidden}" cikista bulundu -> "${formatted}"`)
      }
    }
  }
  console.log(`  ${intlTag.padEnd(8)} | ${cells.map((c) => c.padEnd(12)).join('')}`)
}

if (failures === 0) {
  console.log('\n  OK: 4 locale x 4 para birimi (16 kombinasyon) hepsi narrow sembol basiyor, uzun bicim YOK.')
}

// ---------------------------------------------------------------------------
// 3) GERI DUSUS SIMULASYONU — narrowSymbol'u "desteklemeyen" bir motoru gecersiz bir
//    currencyDisplay degeriyle simule et; try/catch cokmeden symbol'e dusmeli.
// ---------------------------------------------------------------------------
console.log('\n== 3) Geri dusus simulasyonu (RangeError -> symbol) ==')
try {
  const fallbackFormatter = buildFormatterLikeMoneyTs('tr-TR', 'TRY', /** gecersiz deger */ 'not-a-real-display')
  const fallbackOutput = fallbackFormatter.format(1234.56)
  console.log(`  OK: gecersiz currencyDisplay RangeError firlatti, catch bloğu 'symbol'e dustu -> "${fallbackOutput}"`)
  if (!fallbackOutput.includes('₺')) {
    fail(`Geri dusus cikisinda beklenen ₺ yok -> "${fallbackOutput}"`)
  }
} catch (err) {
  fail(`Geri dusus BEKLENEN sekilde calismadi, hala cokuyor: ${err.message}`)
}

console.log('\n' + (failures === 0 ? `BASARILI — ${rows.length} kombinasyon dogrulandi, 0 hata.` : `BASARISIZ — ${failures} hata.`))
process.exit(failures === 0 ? 0 : 1)
