// `parseMirrorTimestamp` as the DESKTOP shell reaches it — through `platform/data/timestamps.ts`,
// which is now a re-export of `@/lib/mirrorTime` (the parser moved to `frontend/src/lib` so the
// SHARED app could reach it too; that file's header has the reasoning). This path is what
// `bridge/notifications.ts` (toast timing), `ui/format.ts::formatDateTime` (the conflict-inbox
// and devices panels — NOT the notification list) and `platform/data/mappers.ts::isPast`
// (`is_overdue`/`is_expired`) import, so it stays covered on its own.
//
// The shared-app callers the notification list actually uses are covered by `mirrorTime.test.ts`.
//
// **The timezone is pinned here on purpose.** `Asia/Istanbul` (UTC+3, no DST) makes the buggy
// local-time reading and the correct UTC reading three hours apart on any machine, CI included.
// On a UTC host the two readings are the same number and a test that did not fix the zone would
// prove nothing — it would be green against the bug this module exists to close.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'

import { parseMirrorTimestamp } from './timestamps'

describe('parseMirrorTimestamp', () => {
  const realTz = process.env.TZ

  beforeAll(() => {
    process.env.TZ = 'Asia/Istanbul'
  })
  afterAll(() => {
    process.env.TZ = realTz
  })

  it('the host really is UTC+3 — otherwise the cases below prove nothing', () => {
    // Guard on the pin itself: if `process.env.TZ` ever stops taking effect, this test says so
    // instead of the rest of the file quietly degrading into a UTC-only tautology.
    expect(new Date(2026, 8, 1, 12, 0, 0).getTimezoneOffset()).toBe(-180)
  })

  it('reads a space-separated, zone-less stamp as UTC, not as local time', () => {
    expect(parseMirrorTimestamp('2026-09-01 07:58:01')).toBe(Date.UTC(2026, 8, 1, 7, 58, 1))
  })

  it('reads a `T`-separated, zone-less stamp as UTC too', () => {
    expect(parseMirrorTimestamp('2026-09-01T07:58:01')).toBe(Date.UTC(2026, 8, 1, 7, 58, 1))
  })

  it('reads a zone-less stamp with fractional seconds as UTC', () => {
    expect(parseMirrorTimestamp('2026-09-01 07:58:01.500')).toBe(
      Date.UTC(2026, 8, 1, 7, 58, 1, 500)
    )
  })

  it('leaves a stamp that already carries `Z` alone', () => {
    expect(parseMirrorTimestamp('2026-09-01T07:58:01.123Z')).toBe(
      Date.parse('2026-09-01T07:58:01.123Z')
    )
  })

  it('leaves a stamp that already carries a numeric offset alone', () => {
    expect(parseMirrorTimestamp('2026-09-01T10:58:01+03:00')).toBe(Date.UTC(2026, 8, 1, 7, 58, 1))
  })

  it('leaves a date-only value alone — `expected_close_date`/`valid_until` are `date()` columns', () => {
    // No time part at all, so ECMA-262 already reads this as UTC midnight. The naive-timestamp
    // pattern must not match it, or a correct value would be rewritten into a wrong one.
    expect(parseMirrorTimestamp('2026-09-05')).toBe(Date.parse('2026-09-05'))
    expect(parseMirrorTimestamp('2026-09-05')).toBe(Date.UTC(2026, 8, 5, 0, 0, 0))
  })

  it('reports an unparseable stamp as NaN', () => {
    expect(Number.isNaN(parseMirrorTimestamp('not a date'))).toBe(true)
    expect(Number.isNaN(parseMirrorTimestamp(''))).toBe(true)
  })

  // The negative control. `Date.parse`/`new Date(...)` IS the old, broken reading of the naive
  // form; if that reading were ever restored here, this equality would hold and the assertion
  // would fail — proving the test actually exercises the zone-supplying behaviour, not just the
  // regex matching.
  it('NEGATIVE CONTROL: the naive local-time reading is three hours off, and is rejected', () => {
    const local = Date.parse('2026-09-01 07:58:01')
    expect(local).toBe(Date.UTC(2026, 8, 1, 4, 58, 1))
    expect(parseMirrorTimestamp('2026-09-01 07:58:01')).not.toBe(local)
    expect(parseMirrorTimestamp('2026-09-01 07:58:01') - local).toBe(3 * 60 * 60 * 1000)
  })
})
