// `formatDateTime` — the absolute timestamp shown by the desktop's own panels.
//
// SCOPE, stated exactly because the previous version of this header did not: `ui/format.ts::
// formatDateTime` has TWO callers, `ui/panels/ConflictInbox.tsx` (conflict `created_at`) and
// `ui/panels/DevicesPanel.tsx` (device `last_used_at`/`created_at`). The notification list, the
// activity feed and record headers do NOT come through here — they are shared-app components
// that format via `@/features/notifications/components/notificationMeta.ts::formatRelativeTime`
// and `@/lib/datetime.ts`, covered by `platform/data/mirrorTime.test.ts`. The old header
// claimed those too, which is why the notification list kept rendering three hours early while
// this file was green.
//
// `formatDateTime` used to build `new Date(iso)` directly. Every mirror `*_at` column that
// reaches here can be a MySQL `DATETIME` in its own text form — space-separated, no zone
// (`"2026-09-01 07:58:01"`) — because `SyncPullService::fetchRows()` reads pulled tables
// through the raw query builder, never through Eloquent (`@/lib/mirrorTime` has the full
// account). The instant is UTC
// (`APP_TIMEZONE=UTC`), but nothing in the string says so, and ECMA-262 reads a zone-less
// date-time as LOCAL time — so on UTC+3 every such timestamp rendered three hours earlier than
// it actually was.
//
// **The timezone is pinned here on purpose.** On a UTC host the buggy and the correct reading
// are the same number, so a test that did not fix the zone would be green against the bug.
import { afterAll, beforeAll, describe, expect, it } from 'vitest'

import { formatDateTime } from './format'

const DATE_TIME_OPTS: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' }

describe('formatDateTime', () => {
  const realTz = process.env.TZ

  beforeAll(() => {
    process.env.TZ = 'Asia/Istanbul'
  })
  afterAll(() => {
    process.env.TZ = realTz
  })

  it('the host really is UTC+3 — otherwise the cases below prove nothing', () => {
    expect(new Date(2026, 8, 1, 12, 0, 0).getTimezoneOffset()).toBe(-180)
  })

  it('returns null for a nullish or empty value', () => {
    expect(formatDateTime('en', null)).toBeNull()
    expect(formatDateTime('en', undefined)).toBeNull()
    expect(formatDateTime('en', '')).toBeNull()
  })

  it('returns null for a malformed value', () => {
    expect(formatDateTime('en', 'not a date')).toBeNull()
  })

  it('formats a locally-created, zone-carrying stamp unchanged', () => {
    const expected = new Intl.DateTimeFormat('en', DATE_TIME_OPTS).format(
      new Date('2026-09-01T07:58:01.123Z')
    )
    expect(formatDateTime('en', '2026-09-01T07:58:01.123Z')).toBe(expected)
  })

  it('does not shift a date-only value (`expected_close_date`/`valid_until` are `date()` columns)', () => {
    const expected = new Intl.DateTimeFormat('en', DATE_TIME_OPTS).format(new Date('2026-09-05'))
    expect(formatDateTime('en', '2026-09-05')).toBe(expected)
  })

  it('renders a space-separated mirror stamp at the correct UTC instant, three hours later than a naive reading', () => {
    // 07:58:01Z is 10:58 local (Istanbul, UTC+3). The bug rendered 07:58 local instead.
    const correctInstant = Date.UTC(2026, 8, 1, 7, 58, 1)
    const expected = new Intl.DateTimeFormat('en', DATE_TIME_OPTS).format(correctInstant)

    expect(formatDateTime('en', '2026-09-01 07:58:01')).toBe(expected)
    expect(expected).toContain('10:58 AM')
  })

  // NEGATIVE CONTROL. `new Date(iso)` IS the old implementation: it reads the same space-form
  // stamp as LOCAL time and formats a DIFFERENT (three-hours-earlier) clock time. If the old
  // `new Date(iso)` line were ever restored, this assertion would fail — see the actual run
  // captured in the phase report.
  it('NEGATIVE CONTROL: the naive `new Date(iso)` reading formats a different, wrong local hour', () => {
    const buggy = new Intl.DateTimeFormat('en', DATE_TIME_OPTS).format(new Date('2026-09-01 07:58:01'))
    const actual = formatDateTime('en', '2026-09-01 07:58:01')

    expect(actual).not.toBe(buggy)
    expect(buggy).toContain('7:58 AM')
  })
})
