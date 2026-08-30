// Görüntüleme dönüşümü hesaplama — SAF fonksiyon, React'tan bağımsız (bkz. `useAmountConverter`
// bunu React Query + auth store'a bağlar). `lib/money.ts`in `formatMoney` imzasının beklediği
// `rate` ("1 currency = kaç displayCurrency") burada TÜRETİLİR; `/api/exchange-rates/current`
// yalnızca "1 yabancı para = kaç TRY" satırları döner (temel para birimi TRY örtüktür), iki
// yabancı para birimi arasında (ör. USD kaydı → EUR görüntüleme) TRY üzerinden çapraz kur
// hesaplanması gerekir — `ExchangeRateService::convert()`in FE tarafındaki GÖSTERİM-amaçlı
// eşdeğeri (otoriter değil; bkz. `money.ts` `applyDisplayConversion` dokümanı).
import type { ExchangeRateCurrentRow, ExchangeRatesCurrentResponse } from './types'

export type AmountConversion = {
  /** Gösterimde kullanılacak para birimi (kullanıcının `preferred_currency`'si). */
  displayCurrency: string
  /** Kaydın kendi para birimi zaten `displayCurrency` ile aynıysa `true` — dönüşüm YOK,
   *  hiçbir ikincil "orijinal tutar" notu gösterilmemeli (§2.4 "açıklanacak bir şey yoksa
   *  açıklama uydurulmaz" ilkesi — `RateInfoNote`teki `as_of === null` kısayoluyla aynı ruh). */
  sameCurrency: boolean
  /** `money.ts`e verilecek çapraz kur (1 kaynak = kaç `displayCurrency`); dönüşüm mümkün
   *  değilse `null`. */
  rate: number | null
  /** Kullanılan kur(lar)ın EN ESKİ tarihi (iki bacaktan biri temel para birimiyse o bacak
   *  tarihsiz sayılır — her zaman güncel kabul edilir). `rate === null` iken `null`. */
  rateDate: string | null
  /** İki bacaktan (varsa) HERHANGİ BİRİ bayatsa `true`. */
  isStale: boolean
  /** Bayat bacakların EN BÜYÜK gün sayısı. */
  daysStale: number
  /** `false`: para birimleri FARKLI ama gereken kur satır(lar)ı eksik — uydurma kur YOK,
   *  kayıt kendi para biriminde gösterilmeli + "çevrilemiyor" belirtilmeli. */
  canConvert: boolean
}

function sameCurrencyResult(displayCurrency: string): AmountConversion {
  return {
    displayCurrency,
    sameCurrency: true,
    rate: null,
    rateDate: null,
    isStale: false,
    daysStale: 0,
    canConvert: true,
  }
}

function unconvertibleResult(displayCurrency: string): AmountConversion {
  return {
    displayCurrency,
    sameCurrency: false,
    rate: null,
    rateDate: null,
    isStale: false,
    daysStale: 0,
    canConvert: false,
  }
}

type ResolvedLeg = { rateInTry: number; rateDate: string | null; isStale: boolean; daysStale: number }

function resolveLeg(
  currency: string,
  baseCurrency: string,
  rows: ExchangeRateCurrentRow[]
): ResolvedLeg | null {
  if (currency === baseCurrency) {
    // Temel para birimi (TRY): `exchange_rates`te satırı yoktur, rate=1 örtük, tarihsiz/asla bayat değil.
    return { rateInTry: 1, rateDate: null, isStale: false, daysStale: 0 }
  }

  const row = rows.find((r) => r.currency === currency)
  if (!row || row.rate === null || row.rate_date === null) return null

  const rateInTry = Number(row.rate)
  if (!Number.isFinite(rateInTry) || rateInTry <= 0) return null

  return { rateInTry, rateDate: row.rate_date, isStale: row.is_stale, daysStale: row.days_stale }
}

/**
 * `sourceCurrency` (kaydın kendi para birimi) → `displayCurrency` (kullanıcı tercihi) çapraz
 * kur ve bayatlık bilgisini hesaplar. `data` henüz yüklenmediyse (`undefined`) — aynı para
 * birimi kısayolu HARİÇ — dönüşüm yapılamaz sayılır (çağıran taraf bunu geçici bir yükleme
 * durumu olarak ayrıca ele almalı — bkz. `useAmountConverter`/`ConvertedAmount`).
 */
export function computeConversion(
  data: ExchangeRatesCurrentResponse | undefined,
  displayCurrency: string,
  sourceCurrency: string
): AmountConversion {
  if (sourceCurrency === displayCurrency) return sameCurrencyResult(displayCurrency)
  if (!data) return unconvertibleResult(displayCurrency)

  const baseCurrency = data.base_currency

  const sourceLeg = resolveLeg(sourceCurrency, baseCurrency, data.rates)
  const targetLeg = resolveLeg(displayCurrency, baseCurrency, data.rates)

  if (sourceLeg === null || targetLeg === null) return unconvertibleResult(displayCurrency)

  const rate = sourceLeg.rateInTry / targetLeg.rateInTry
  if (!Number.isFinite(rate) || rate <= 0) return unconvertibleResult(displayCurrency)

  const dated = [sourceLeg, targetLeg].filter((leg): leg is ResolvedLeg & { rateDate: string } => leg.rateDate !== null)
  const rateDate = dated.length > 0
    ? dated.reduce((oldest, leg) => (leg.rateDate < oldest ? leg.rateDate : oldest), dated[0].rateDate)
    : null
  const isStale = dated.some((leg) => leg.isStale)
  const daysStale = dated.reduce((max, leg) => Math.max(max, leg.daysStale), 0)

  return { displayCurrency, sameCurrency: false, rate, rateDate, isStale, daysStale, canConvert: true }
}
