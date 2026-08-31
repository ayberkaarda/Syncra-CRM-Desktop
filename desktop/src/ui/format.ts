// Locale-aware formatting for the desktop chrome.
//
// Everything here goes through `Intl.*` rather than through a dictionary key, for the reason
// `SYNCDESKTOP.md` §0.6 exists: "5 dakika" / "5 minutes" / "5 Minuten" is not UI copy, it is a
// number and a unit, and `Intl.NumberFormat(locale, {style: 'unit'})` already knows all four
// languages. Writing those strings into `desktop.json` would be four more translations to keep
// in sync for something the platform gets right for free.

/**
 * Buckets `formatElapsed` chooses between, coarsest last: the first bucket whose `ceiling` the
 * elapsed seconds fall under wins, and `perUnit` is how many seconds one of its units is.
 *
 * `perUnit` is carried explicitly rather than derived from `ceiling`: `ceiling / 60` happens to
 * be right for seconds and minutes and is WRONG for hours (86400/60 = 1440, so three hours
 * rendered as "7 hours"). Measured, not theorised.
 */
const ELAPSED_UNITS: readonly { ceiling: number; perUnit: number; unit: string }[] = [
  { ceiling: 60, perUnit: 1, unit: 'second' },
  { ceiling: 3600, perUnit: 60, unit: 'minute' },
  { ceiling: 86400, perUnit: 3600, unit: 'hour' },
]

/** Everything past the last bucket. */
const ELAPSED_FALLBACK = { perUnit: 86400, unit: 'day' }

/**
 * "5 dakika" — a bare duration, WITHOUT the "ago" suffix.
 *
 * `Intl.RelativeTimeFormat` is deliberately not used: it renders "5 dakika önce", and the only
 * caller is `desktop.sync.lastSynced` ("Son senkron: {{time}} önce"), which supplies the suffix
 * itself. Feeding a relative format into that slot would print the suffix twice in every
 * language.
 */
export function formatElapsed(locale: string, since: Date, now: Date = new Date()): string {
  const seconds = Math.max(0, Math.round((now.getTime() - since.getTime()) / 1000))

  const bucket = ELAPSED_UNITS.find((candidate) => seconds < candidate.ceiling) ?? ELAPSED_FALLBACK

  return new Intl.NumberFormat(locale, {
    style: 'unit',
    unit: bucket.unit,
    unitDisplay: 'long',
  }).format(Math.floor(seconds / bucket.perUnit))
}

const BYTES_PER_MB = 1024 * 1024

/** Bytes as megabytes with one decimal — the unit `desktop.storage.usage.value` already names. */
export function formatMegabytes(locale: string, bytes: number): string {
  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  }).format(bytes / BYTES_PER_MB)
}

/** Plain integer, grouped for the locale. */
export function formatCount(locale: string, value: number): string {
  return new Intl.NumberFormat(locale).format(value)
}

/** Short absolute timestamp; `null` for a value the server left empty or sent malformed. */
export function formatDateTime(locale: string, iso: string | null | undefined): string | null {
  if (!iso) return null
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return null
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}
