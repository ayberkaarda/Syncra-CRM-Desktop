// The mirror-timestamp fix where it actually shows: the SHARED components the notification list
// renders through.
//
// This suite exists because the first attempt at this fix was green and still wrong in the
// running app. `ui/format.ts::formatDateTime` was corrected and covered by `ui/format.test.ts`,
// whose header claimed it rendered "the notification list, activity feed, record headers" — it
// renders none of them: it has two callers, both desktop panels. The list formats through
// `@/features/notifications/components/notificationMeta.ts::formatRelativeTime`, and a
// notification body's `*_at` parameters through `@/lib/datetime.ts`. A row written 3 min 25 s
// earlier displayed as "3 hours ago" in the shipped shell while every test passed. These are the
// tests that would have caught that.
//
// **The timezone is pinned on purpose.** `Asia/Istanbul` (UTC+3, no DST) puts the buggy
// local-time reading and the correct UTC reading three hours apart on any machine, CI included.
// On a UTC host the two readings are the same number and this whole file would be a tautology.
//
// **The web bundle must not move.** `lib/datetime.ts` and `notificationMeta.ts` ship in the web
// build too, where values come from `toIso8601String()` and therefore always carry an offset.
// Every "…identical to the pre-fix `new Date(value)` reading" case below is the proof that the
// web rendering is bit-for-bit unchanged; they are not decorative.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'

import { formatRelativeTime } from '@/features/notifications/components/notificationMeta'
import { getIntlLocale } from '@/i18n'
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime'
import { parseMirrorTimestamp } from '@/lib/mirrorTime'

/** How the mirror stores a `DATETIME` pulled from MySQL: space separator, no zone, UTC instant. */
function mirrorStamp(epochMs: number): string {
  return new Date(epochMs).toISOString().replace('T', ' ').slice(0, 19)
}

/** How the WEB API serialises the same instant: `toIso8601String()`, offset always present. */
const WEB_VALUE = '2026-09-01T08:53:28+00:00'
/** The same instant as the mirror writes it. 08:53:28Z is 11:53 local in Istanbul. */
const MIRROR_VALUE = '2026-09-01 08:53:28'

const DATE_OPTS: Intl.DateTimeFormatOptions = { dateStyle: 'medium' }
const DATE_TIME_OPTS: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' }
const TIME_OPTS: Intl.DateTimeFormatOptions = { timeStyle: 'short' }

/** The expected rendering, built the way `lib/datetime.ts` builds it — same locale resolution. */
function render(options: Intl.DateTimeFormatOptions, value: Date | number): string {
  return new Intl.DateTimeFormat(getIntlLocale('en'), options).format(value)
}

const realTz = process.env.TZ

beforeAll(() => {
  process.env.TZ = 'Asia/Istanbul'
})
afterAll(() => {
  process.env.TZ = realTz
})

describe('the pinned timezone', () => {
  it('the host really is UTC+3 — otherwise nothing below proves anything', () => {
    // Guard on the pin itself. If `process.env.TZ` ever stops taking effect, this says so
    // instead of the rest of the file quietly degrading into a UTC-only tautology.
    expect(new Date(2026, 8, 1, 12, 0, 0).getTimezoneOffset()).toBe(-180)
  })

  it('the two readings of a zone-less mirror stamp really are three hours apart', () => {
    expect(parseMirrorTimestamp(MIRROR_VALUE) - Date.parse(MIRROR_VALUE)).toBe(3 * 60 * 60 * 1000)
  })
})

describe('formatRelativeTime — the notification list', () => {
  it('reads a mirror stamp written three minutes ago as MINUTES, not hours', () => {
    // The measured failure, reproduced exactly: a row three minutes old displayed "3 hours ago".
    const stamp = mirrorStamp(Date.now() - 3 * 60 * 1000)
    const formatter = new Intl.RelativeTimeFormat(getIntlLocale(), { numeric: 'auto' })

    expect(formatRelativeTime(stamp)).toBe(formatter.format(-3, 'minute'))
    expect(formatRelativeTime(stamp)).not.toBe(formatter.format(-3, 'hour'))
  })

  it('NEGATIVE CONTROL: the naive `new Date(iso)` reading of that same stamp says three HOURS', () => {
    // Not an assertion about the code under test — an assertion that the fixture above really
    // does separate the two readings, so the case above cannot pass for an accidental reason.
    const stamp = mirrorStamp(Date.now() - 3 * 60 * 1000)
    const buggySeconds = (new Date(stamp).getTime() - Date.now()) / 1000
    const formatter = new Intl.RelativeTimeFormat(getIntlLocale(), { numeric: 'auto' })

    expect(Math.abs(buggySeconds)).toBeGreaterThan(3600)
    expect(formatter.format(Math.round(buggySeconds / 3600), 'hour')).toBe(
      formatter.format(-3, 'hour')
    )
  })

  it('leaves an offset-carrying web value identical to the pre-fix `new Date(value)` reading', () => {
    const webStamp = new Date(Date.now() - 3 * 60 * 1000).toISOString()
    const formatter = new Intl.RelativeTimeFormat(getIntlLocale(), { numeric: 'auto' })

    expect(parseMirrorTimestamp(webStamp)).toBe(new Date(webStamp).getTime())
    expect(formatRelativeTime(webStamp)).toBe(formatter.format(-3, 'minute'))
  })

  it('still returns the RAW string for an unparseable value — contract unchanged', () => {
    // Not `'—'`: `NotificationList`/`NotificationsPage` have always shown the raw value here,
    // and this fix is not the place to change that.
    expect(formatRelativeTime('not a date')).toBe('not a date')
    expect(formatRelativeTime('')).toBe('')
  })
})

describe('lib/datetime — notification body params and every other shared call site', () => {
  it('formatDateTime renders a mirror stamp at the correct UTC instant', () => {
    expect(formatDateTime(MIRROR_VALUE, 'en')).toBe(
      render(DATE_TIME_OPTS, Date.UTC(2026, 8, 1, 8, 53, 28))
    )
    // 08:53:28Z is 11:53 local (Istanbul, UTC+3). The bug printed 08:53.
    expect(formatDateTime(MIRROR_VALUE, 'en')).toContain('11:53')
  })

  it('NEGATIVE CONTROL: the pre-fix `new Date(value)` reading prints a different, wrong hour', () => {
    const buggy = render(DATE_TIME_OPTS, new Date(MIRROR_VALUE))

    expect(buggy).toContain('08:53')
    expect(formatDateTime(MIRROR_VALUE, 'en')).not.toBe(buggy)
  })

  it('formatTime renders a mirror stamp at the correct local clock time', () => {
    expect(formatTime(MIRROR_VALUE, 'en')).toBe(render(TIME_OPTS, Date.UTC(2026, 8, 1, 8, 53, 28)))
    expect(formatTime(MIRROR_VALUE, 'en')).toContain('11:53')
  })

  it('formatDate renders a mirror stamp on the correct local day', () => {
    expect(formatDate(MIRROR_VALUE, 'en')).toBe(render(DATE_OPTS, Date.UTC(2026, 8, 1, 8, 53, 28)))
  })

  // ---- WEB REGRESSION GUARD --------------------------------------------------------------
  // The whole justification for touching a file the web bundle ships is that an offset-carrying
  // value takes the pass-through branch and reaches `Date.parse` untouched. These cases compare
  // against `new Date(WEB_VALUE)` — literally the pre-fix expression — so a change in that
  // branch fails here rather than in production.

  it('formatDateTime is bit-for-bit unchanged for an offset-carrying web value', () => {
    expect(formatDateTime(WEB_VALUE, 'en')).toBe(render(DATE_TIME_OPTS, new Date(WEB_VALUE)))
  })

  it('formatTime is bit-for-bit unchanged for an offset-carrying web value', () => {
    expect(formatTime(WEB_VALUE, 'en')).toBe(render(TIME_OPTS, new Date(WEB_VALUE)))
  })

  it('formatDate is bit-for-bit unchanged for an offset-carrying web value', () => {
    expect(formatDate(WEB_VALUE, 'en')).toBe(render(DATE_OPTS, new Date(WEB_VALUE)))
  })

  it('the mirror form and the web form of the SAME instant now render identically', () => {
    // The point of the whole change, in one line.
    expect(formatDateTime(MIRROR_VALUE, 'en')).toBe(formatDateTime(WEB_VALUE, 'en'))
  })

  // ---- CONTRACTS THAT MUST NOT MOVE -------------------------------------------------------

  it('still returns the em dash for nullish and empty values', () => {
    for (const fn of [formatDate, formatDateTime, formatTime]) {
      expect(fn(null, 'en')).toBe('—')
      expect(fn(undefined, 'en')).toBe('—')
      expect(fn('', 'en')).toBe('—')
    }
  })

  it('still returns the em dash for a malformed value', () => {
    for (const fn of [formatDate, formatDateTime, formatTime]) {
      expect(fn('not a date', 'en')).toBe('—')
    }
  })

  it('still accepts a `Date` and a number unchanged — neither goes through the parser', () => {
    const instant = Date.UTC(2026, 8, 1, 8, 53, 28)
    for (const [fn, opts] of [
      [formatDate, DATE_OPTS],
      [formatDateTime, DATE_TIME_OPTS],
      [formatTime, TIME_OPTS],
    ] as const) {
      expect(fn(new Date(instant), 'en')).toBe(render(opts, instant))
      expect(fn(instant, 'en')).toBe(render(opts, instant))
    }
  })

  it('does not shift a date-only value — `expected_close_date`/`valid_until` are `date()` columns', () => {
    expect(formatDate('2026-09-05', 'en')).toBe(render(DATE_OPTS, new Date('2026-09-05')))
  })
})
