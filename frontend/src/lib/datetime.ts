// Merkezi tarih/saat biçimlendirme (Faz 14 / İz D — docs/PHASE-INTL.md §1.8).
//
// BORÇ VE KAPSAM: recon, 24 dosyada elle yazılmış `Intl.DateTimeFormat('tr-TR', ...)` saydı —
// `money.ts` öncesindeki para biçimlendirmesiyle aynı dağınıklık. Bu dosya o dizinin TEK
// doğruluk kaynağıdır. Faz 14'ün ÇEKİRDEK görevi yalnızca yardımcıyı KURAR ve birkaç çağrı
// yerini örnek olarak bağlar; kalan dosyaların devri (feature çevirileriyle birlikte) sonraki
// şeritlere aittir — o yüzden buradaki API, mevcut çağrıların kalıbını (`dateStyle: 'medium'`,
// `dateStyle+timeStyle`) bilerek birebir karşılar, taşıma mekanik olsun diye.
//
// AYRAÇ VE SIRA DİLE BAĞLIDIR (`24 Ağu 2026` tr · `24 Aug 2026` en · `24. Aug. 2026` de);
// SAAT DİLİMİ değildir — `Date` her zaman yerel saate çevirir.
//
// TIMEZONE NOTE (English, per SYNCDESKTOP §0.6). The web API always serialises timestamps with
// an explicit offset (`toIso8601String()` -> `2026-09-01T08:53:28+00:00`), so `new Date(value)`
// was correct for every value the WEB bundle passes here. The desktop shell renders the same
// components against the local SQLite mirror, whose `*_at` columns hold MySQL's own `DATETIME`
// text — space-separated and zone-less (`2026-09-01 08:53:28`) — which ECMA-262 reads as LOCAL
// time even though the instant is UTC. That is why parsing goes through `parseMirrorTimestamp`
// now: it supplies the missing `Z` for the zone-less form and passes everything else (offset-
// carrying values, date-only values, garbage) to `Date.parse` untouched, so the web rendering is
// bit-for-bit what it was. See `lib/mirrorTime.ts`.
import { getIntlLocale } from '../i18n'
import { parseMirrorTimestamp } from './mirrorTime'

const dateFormatterCache = new Map<string, Intl.DateTimeFormat>()

function getFormatter(intlLocale: string, options: Intl.DateTimeFormatOptions, cacheKey: string): Intl.DateTimeFormat {
  const key = `${intlLocale}|${cacheKey}`
  let formatter = dateFormatterCache.get(key)
  if (!formatter) {
    formatter = new Intl.DateTimeFormat(intlLocale, options)
    dateFormatterCache.set(key, formatter)
  }
  return formatter
}

/** Geçersiz/boş girdide `null` — `'—'` kararını çağıran fonksiyon verir. */
function toDate(value: string | number | Date | null | undefined): Date | null {
  if (value === null || value === undefined || value === '') return null
  // A `Date` is already an instant; a number is already epoch milliseconds. Only the STRING
  // form is ambiguous, and only that form goes through the mirror parser.
  const date =
    value instanceof Date
      ? value
      : new Date(typeof value === 'string' ? parseMirrorTimestamp(value) : value)
  return Number.isNaN(date.getTime()) ? null : date
}

/** `24 Ağu 2026` — tarih, saat yok. Geçersiz girdide girdinin kendisi değil `'—'` basar. */
export function formatDate(value: string | number | Date | null | undefined, locale?: string): string {
  const date = toDate(value)
  if (date === null) return '—'
  return getFormatter(getIntlLocale(locale), { dateStyle: 'medium' }, 'date-medium').format(date)
}

/** `24 Ağu 2026 14:35` — tarih + kısa saat. Zaman damgası gösteren her yerin varsayılanı. */
export function formatDateTime(value: string | number | Date | null | undefined, locale?: string): string {
  const date = toDate(value)
  if (date === null) return '—'
  return getFormatter(
    getIntlLocale(locale),
    { dateStyle: 'medium', timeStyle: 'short' },
    'datetime-medium-short'
  ).format(date)
}

/** `14:35` — yalnız saat (aynı gün içindeki sohbet/aktivite satırları gibi dar bağlamlar). */
export function formatTime(value: string | number | Date | null | undefined, locale?: string): string {
  const date = toDate(value)
  if (date === null) return '—'
  return getFormatter(getIntlLocale(locale), { timeStyle: 'short' }, 'time-short').format(date)
}
