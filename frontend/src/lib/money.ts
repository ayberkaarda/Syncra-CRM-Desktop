// Merkezi para/sayı biçimlendirme.
//
// NEDEN MERKEZİ: `Intl.NumberFormat('tr-TR', { style: 'currency', ... })` projede 7 farklı
// dosyada ayrı ayrı yazılmıştı ve üç farklı ondalık davranışı üretiyordu — aynı tutar hangi
// ekranda gösterildiğine göre farklı görünüyordu (`295.367 ₺` vs `295.366,56 ₺`). Bu dosya TEK
// doğruluk kaynağıdır; yeni bir para gösterimi ihtiyacı doğduğunda burada genişletilmeli, yeni
// bir yerel kopya AÇILMAMALI. (Faz 14'te `features/quotes/utils/money.ts` kopyası da buraya
// devredilip silindi — docs/PHASE-INTL.md §7.)
//
// FAZ 14 / §1.8 — İKİ EKSEN, İKİ AYRI KAVRAM:
//   • PARA BİRİMİ = VERİ ekseni. TRY tutulan bir fırsat Almanca arayüzde de TRY'dir; dil
//     değişimi kaydın para birimini DEĞİŞTİRMEZ.
//   • AYRAÇ/GRUPLAMA = DİL ekseni. Aynı tutar `1.234,56` (tr/de) veya `1,234.56` (en) basılır.
// Bu yüzden `currency` zorunlu ilk-sınıf parametre, `locale` ise opsiyoneldir ve verilmezse
// aktif arayüz dilinden okunur.
//
// Nerede hangisi kullanılmalı:
// - `formatMoney`        → varsayılan gösterim (tablolar, detay sayfaları, toplamlar). Her zaman
//                          2 ondalık basar; kuruş asla gizlenmez.
// - `formatMoneyCompact` → dar alanlar (Kanban kartları, dashboard KPI kutuları). 0 ondalık —
//                          bilinçli bir okunabilirlik tercihi, modülün sessiz kararı değil.
// - `formatNumber`       → para OLMAYAN sayılar (adet, stok, çalışan sayısı vb.).
// - `formatPercent`      → oran/yüzde değerleri (indirim %, KDV oranı).
import { getIntlLocale } from '../i18n'

const DEFAULT_CURRENCY = 'TRY'

/**
 * `formatMoney`/`formatMoneyCompact` seçenekleri (§1.8 — İz D ∩ İz E ORTAK İMZASI; dispatch
 * öncesi sabitlendi ki iki iş kolu aynı dosyayı tahmin yürüterek değiştirmesin).
 */
export type MoneyFormatOptions = {
  /** Kısa dil kodu (`tr`/`en`/`de`/`fr`). Verilmezse aktif arayüz dili kullanılır. */
  locale?: string
  /**
   * İz E — GÖRÜNTÜLEME para birimi (`users.preferred_currency`). Verilirse tutar `rate` ile
   * çevrilip bu para biriminde basılır; KAYDIN kendi para birimi değişmez, yalnızca gösterim.
   */
  displayCurrency?: string
  /** İz E — 1 `currency` = kaç `displayCurrency` (TCMB ForexBuying/Unit türevi). */
  rate?: number | string
}

/** Aynı biçimlendiriciyi tekrar tekrar kurmamak için önbellek; anahtar `locale|currency`.
 *  Locale de anahtara girer — dil değişince aynı para birimi FARKLI ayraçla basılmalı. */
const moneyFormatterCache = new Map<string, Intl.NumberFormat>()
const compactMoneyFormatterCache = new Map<string, Intl.NumberFormat>()
const numberFormatterCache = new Map<string, Intl.NumberFormat>()

// NEDEN `currencyDisplay: 'narrowSymbol'`: `Intl.NumberFormat`'ın varsayılanı (`symbol`) CLDR
// gereği bir para birimini o locale'in KENDİ para biriminden ayırt etmek için UZUN biçim basar —
// bu "ayırt etme" bizim için gürültüdür, çünkü zaten `currency` parametresiyle hangi para birimi
// olduğunu biliyoruz. Ölçülen (Node ile doğrulandı) etkisi:
//   • en-GB: TRY → düz "TRY" (₺ değil!), USD → "US$" (kullanıcı raporundaki hata)
//   • de-DE: TRY → "1.234,56 TRY" (sonek olarak, sembol değil)
//   • fr-FR: USD → "1 234,56 $US", GBP → "1 234,56 £GB"
// `narrowSymbol` dört locale'de (tr/en/de/fr) dördü için de tek biçimli ₺/$/€/£ basar — TRY dahil,
// ki Türkiye merkezli bir CRM için asıl kritik olan budur. Aynı locale içinde iki farklı para
// birimi aynı ekranda YAN YANA basılmıyor (bkz. formatMoney kullanım yerleri), bu yüzden CLDR'nin
// önlemeye çalıştığı belirsizlik (ör. "$" hem USD hem CAD olabilir) burada pratikte oluşmuyor.
//
// GÜVENLİ GERİ DÜŞÜŞ: `narrowSymbol`, `Intl.NumberFormat`'a ES2020'de eklendi. Onu desteklemeyen
// (çok eski) bir motor `currencyDisplay: 'narrowSymbol'` ile RangeError fırlatır. Uygulama para
// basamadığı için ÇÖKMEMELİ — bu yüzden biçimlendirici KURULURKEN (her `format()` çağrısında değil,
// önbellek doldurulurken TEK SEFER) `narrowSymbol` denenir, RangeError'da sessizce varsayılan
// `symbol`'e düşülür. Düşülmüş sonuç da önbelleğe yazılır ki bir sonraki çağrı tekrar denemesin.
function getMoneyFormatter(intlLocale: string, currency: string): Intl.NumberFormat {
  const key = `${intlLocale}|${currency}`
  let formatter = moneyFormatterCache.get(key)
  if (!formatter) {
    const base: Intl.NumberFormatOptions = {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }
    try {
      formatter = new Intl.NumberFormat(intlLocale, { ...base, currencyDisplay: 'narrowSymbol' })
    } catch {
      formatter = new Intl.NumberFormat(intlLocale, base)
    }
    moneyFormatterCache.set(key, formatter)
  }
  return formatter
}

function getCompactMoneyFormatter(intlLocale: string, currency: string): Intl.NumberFormat {
  const key = `${intlLocale}|${currency}`
  let formatter = compactMoneyFormatterCache.get(key)
  if (!formatter) {
    const base: Intl.NumberFormatOptions = {
      style: 'currency',
      currency,
      maximumFractionDigits: 0,
    }
    try {
      formatter = new Intl.NumberFormat(intlLocale, { ...base, currencyDisplay: 'narrowSymbol' })
    } catch {
      formatter = new Intl.NumberFormat(intlLocale, base)
    }
    compactMoneyFormatterCache.set(key, formatter)
  }
  return formatter
}

function getNumberFormatter(intlLocale: string, fractionDigits: number): Intl.NumberFormat {
  const key = `${intlLocale}|${fractionDigits}`
  let formatter = numberFormatterCache.get(key)
  if (!formatter) {
    formatter = new Intl.NumberFormat(intlLocale, {
      minimumFractionDigits: fractionDigits > 0 ? undefined : 0,
      maximumFractionDigits: fractionDigits,
    })
    numberFormatterCache.set(key, formatter)
  }
  return formatter
}

/** `number | string | null | undefined` girdisini normalize eder. API'nin `decimal` alanları
 *  string olarak döner (ör. `"295366.56"`); boş/geçersiz girdi için `null` döner. */
function toFiniteNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined) return null
  if (typeof value === 'number') return Number.isFinite(value) ? value : null
  const trimmed = value.trim()
  if (trimmed === '') return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) ? parsed : null
}

/**
 * GÖRÜNTÜLEME DÖNÜŞÜMÜ — İZ D'NİN BIRAKTIĞI KANCA, İZ E'NİN DOLDURACAĞI YER.
 *
 * Şu anki hâli KASITLI OLARAK asgaridir: `rate` ile düz bir çarpım. İz E burada
 * (a) kur bayatlığı (>4 gün) etiketini, (b) `Unit` normalizasyonunu (TCMB `ForexBuying/Unit`),
 * (c) TRY↔TRY / kur yokluğu durumlarında "çevirme" davranışını netleştirecek — bkz. §2.1/§2.6.
 *
 * JS `number` çarpımı BURADA KABUL EDİLEBİLİR çünkü çıktı YALNIZCA GÖSTERİMDİR: otoriter para
 * matematiği (rapor toplamı, teklif hesabı, donmuş `base_amount`) sunucuda bcmath ile yapılır
 * (§2.3 — "float asla"). Bu fonksiyonun sonucu hiçbir zaman API'ye geri yazılmaz.
 */
function applyDisplayConversion(value: number, options: MoneyFormatOptions): { value: number; currency: string } | null {
  if (!options.displayCurrency) return null
  const rate = toFiniteNumber(options.rate)
  // Kur yoksa dönüşüm YAPILMAZ: yanlış bir rakam basmaktansa kaydın kendi para biriminde
  // göstermek doğrudur (sessiz yanlış hesap = Faz 9 KDV sınıfı hata).
  if (rate === null || rate <= 0) return null
  return { value: value * rate, currency: options.displayCurrency }
}

/**
 * Varsayılan para gösterimi. Her zaman 2 ondalık (`295.366,56 ₺`) — kuruşu gizlemek tutar
 * okumasını belirsizleştirir. `amount` `null`/`undefined`/boş/`NaN` ise `'—'` döner; `0` gerçek
 * bir tutardır ve `0,00 ₺` basar.
 */
export function formatMoney(
  amount: number | string | null | undefined,
  currency: string = DEFAULT_CURRENCY,
  options: MoneyFormatOptions = {}
): string {
  const value = toFiniteNumber(amount)
  if (value === null) return '—'

  const intlLocale = getIntlLocale(options.locale)
  const converted = applyDisplayConversion(value, options)
  const finalValue = converted?.value ?? value
  const finalCurrency = converted?.currency ?? currency

  try {
    return getMoneyFormatter(intlLocale, finalCurrency).format(finalValue)
  } catch {
    // Tanınmayan bir para birimi kodu gelirse Intl fırlatır; tutar yine de gösterilmeli.
    return `${getNumberFormatter(intlLocale, 2).format(finalValue)} ${finalCurrency}`
  }
}

/**
 * Dar alanlar için kompakt para gösterimi (Kanban kartları, dashboard KPI kutuları): 0 ondalık
 * (`295.367 ₺`). `formatMoney` ile aynı null/NaN ve dönüşüm kuralları geçerlidir.
 */
export function formatMoneyCompact(
  amount: number | string | null | undefined,
  currency: string = DEFAULT_CURRENCY,
  options: MoneyFormatOptions = {}
): string {
  const value = toFiniteNumber(amount)
  if (value === null) return '—'

  const intlLocale = getIntlLocale(options.locale)
  const converted = applyDisplayConversion(value, options)
  const finalValue = converted?.value ?? value
  const finalCurrency = converted?.currency ?? currency

  try {
    return getCompactMoneyFormatter(intlLocale, finalCurrency).format(finalValue)
  } catch {
    return `${getNumberFormatter(intlLocale, 0).format(finalValue)} ${finalCurrency}`
  }
}

/**
 * Para OLMAYAN sayılar için (adet, stok, çalışan sayısı vb.). Varsayılan olarak ondalık basmaz.
 */
export function formatNumber(
  value: number | string | null | undefined,
  fractionDigits: number = 0,
  locale?: string
): string {
  const num = toFiniteNumber(value)
  if (num === null) return '—'
  return getNumberFormatter(getIntlLocale(locale), fractionDigits).format(num)
}

/**
 * Oran/yüzde gösterimi (indirim %, KDV oranı). `features/quotes/utils/money.ts`ten devralındı.
 *
 * `style: 'percent'` KULLANILMAZ: girdi zaten "yüzde puanı"dır (`20` = %20), oran (`0.2`)
 * değil — `percent` stili 100 ile çarpardı. İşaretin YERİ dile bağlıdır (`%20` tr · `20 %` fr),
 * bu yüzden `Intl.NumberFormat`ın kendi yüzde kalıbı yerine sayı biçimlendirip önek/sonek
 * eklemek yerine, dile duyarlı tek doğru yol olarak `percent` stilini oran girdisiyle
 * kullanıyoruz: değer 100'e bölünür, biçimlendirici işareti doğru yere koyar.
 */
export function formatPercent(value: number | string | null | undefined, locale?: string): string {
  const num = toFiniteNumber(value)
  if (num === null) return '—'
  return new Intl.NumberFormat(getIntlLocale(locale), {
    style: 'percent',
    maximumFractionDigits: 2,
  }).format(num / 100)
}
