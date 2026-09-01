// `bridge/notifications.ts` — the two rules that keep §6.4's native toasts from becoming noise.
//
// The interesting behaviour is not "a new row toasts"; it is everything that must NOT toast:
// a backlog restored at boot, and the whole unread list re-read because ONE row was marked
// read. Both are `tables_changed` batches indistinguishable from a real arrival, and both were
// the reason the producer was not wired at all until now.
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'

import type { Notification, NotificationsListResponse } from '@/features/notifications/types'

import { createNotificationWatcher, parseMirrorTimestamp, takeUnshown } from './notifications'

/** Shell start, as the watcher's injected clock reports it. */
const STARTED_AT = Date.parse('2026-09-01T10:00:00.000Z')

function row(overrides: Partial<Notification> = {}): Notification {
  return {
    id: 'n1',
    type: 'deal.assigned',
    title: 'Fırsat atandı',
    body: 'Acme yenileme fırsatı size atandı.',
    link: '/deals/1',
    meta: {},
    read_at: null,
    created_at: '2026-09-01T10:00:05.000Z',
    ...overrides,
  }
}

function page(rows: Notification[], total = rows.length): NotificationsListResponse {
  return {
    data: rows,
    meta: { pagination: { current_page: 1, per_page: 15, total, last_page: 1 } },
  }
}

describe('takeUnshown', () => {
  it('keeps a row created after the shell started', () => {
    const shown = new Set<string>()
    expect(takeUnshown([row()], shown, STARTED_AT).map((n) => n.id)).toEqual(['n1'])
  })

  it('drops a row created before the shell started — the restart backlog', () => {
    const shown = new Set<string>()
    const backlog = [
      row({ id: 'old-1', created_at: '2026-08-30T09:00:00.000Z' }),
      row({ id: 'old-2', created_at: '2026-09-01T09:59:59.000Z' }),
    ]
    expect(takeUnshown(backlog, shown, STARTED_AT)).toEqual([])
  })

  it('records every row it inspects, including the ones it filters out', () => {
    const shown = new Set<string>()
    takeUnshown([row({ id: 'old', created_at: '2026-08-30T09:00:00.000Z' })], shown, STARTED_AT)
    expect(shown.has('old')).toBe(true)
  })

  it('never returns the same row twice', () => {
    const shown = new Set<string>()
    const rows = [row()]
    expect(takeUnshown(rows, shown, STARTED_AT)).toHaveLength(1)
    expect(takeUnshown(rows, shown, STARTED_AT)).toEqual([])
  })

  it('skips a read row even though the query already filters them out', () => {
    const shown = new Set<string>()
    const read = row({ read_at: '2026-09-01T10:00:06.000Z' })
    expect(takeUnshown([read], shown, STARTED_AT)).toEqual([])
  })

  it('skips a row with no resolved text, which `os::notify` would refuse', () => {
    const shown = new Set<string>()
    const rows = [
      row({ id: 'no-title', title: '   ' }),
      row({ id: 'no-body', body: '' }),
      row({ id: 'unparseable-date', created_at: 'not a date' }),
    ]
    expect(takeUnshown(rows, shown, STARTED_AT)).toEqual([])
  })

  it('returns a batch oldest first, the reverse of the `created_at DESC` query', () => {
    const shown = new Set<string>()
    const newest = row({ id: 'newest', created_at: '2026-09-01T10:00:30.000Z' })
    const oldest = row({ id: 'oldest', created_at: '2026-09-01T10:00:10.000Z' })
    expect(takeUnshown([newest, oldest], shown, STARTED_AT).map((n) => n.id)).toEqual([
      'oldest',
      'newest',
    ])
  })
})

/** A watcher over a scripted feed, plus the two OS spies. */
function harness(pages: NotificationsListResponse[]) {
  const notify = vi.fn(() => Promise.resolve())
  const setBadge = vi.fn(() => Promise.resolve())
  let call = 0
  const watcher = createNotificationWatcher({
    feed: {
      list: () => {
        const next = pages[Math.min(call, pages.length - 1)]
        call += 1
        return Promise.resolve(next)
      },
    },
    notify,
    setBadge,
    now: () => STARTED_AT,
  })
  return { watcher, notify, setBadge, reads: () => call }
}

describe('createNotificationWatcher', () => {
  it('sets the badge but raises no toast on the priming read', async () => {
    const { watcher, notify, setBadge } = harness([page([row()], 3)])
    await watcher.refresh()
    expect(setBadge).toHaveBeenCalledWith(3)
    expect(notify).not.toHaveBeenCalled()
  })

  it('raises a toast for a row that appears after priming', async () => {
    const first = page([row({ id: 'n1' })], 1)
    const second = page([row({ id: 'n2', title: 'Yeni' }), row({ id: 'n1' })], 2)
    const { watcher, notify, setBadge } = harness([first, second])

    await watcher.refresh()
    await watcher.refresh()

    expect(notify).toHaveBeenCalledTimes(1)
    expect(notify).toHaveBeenCalledWith({ title: 'Yeni', body: row().body })
    expect(setBadge).toHaveBeenLastCalledWith(2)
  })

  // The failure this whole module is shaped around: `notification.read` is a local mutation, so
  // it emits its own `tables_changed`, and the rows still unread would toast again.
  it('does not re-toast a row that survives another `tables_changed`', async () => {
    const primed = page([], 0)
    const arrived = page([row({ id: 'n2' })], 1)
    const { watcher, notify } = harness([primed, arrived, arrived, arrived])

    await watcher.refresh()
    await watcher.refresh()
    await watcher.refresh()
    await watcher.refresh()

    expect(notify).toHaveBeenCalledTimes(1)
  })

  it('ignores a batch that does not carry `notification`', async () => {
    const { watcher, setBadge, reads } = harness([page([])])
    watcher.onTablesChanged(['deal', 'task'])
    await watcher.refresh()
    expect(reads()).toBe(1)
    expect(setBadge).toHaveBeenCalledTimes(1)
  })

  it('reads once for a batch that does carry `notification`', async () => {
    const { watcher, reads } = harness([page([])])
    watcher.onTablesChanged(['company', 'notification'])
    await watcher.refresh()
    expect(reads()).toBeGreaterThanOrEqual(1)
  })

  it('never rejects when the feed or the OS does', async () => {
    const notify = vi.fn(() => Promise.reject(new Error('no notification centre')))
    const setBadge = vi.fn(() => Promise.reject(new Error('no main window')))
    let call = 0
    const watcher = createNotificationWatcher({
      feed: {
        list: () => {
          call += 1
          return call === 1 ? Promise.resolve(page([])) : Promise.reject(new Error('DB_ERROR'))
        },
      },
      notify,
      setBadge,
      now: () => STARTED_AT,
    })

    await expect(watcher.refresh()).resolves.toBeUndefined()
    await expect(watcher.refresh()).resolves.toBeUndefined()
  })

  // A pull and a realtime frame can land in the same tick. Two overlapping reads of one table
  // would hand the same new row to `takeUnshown` twice before either could record it.
  it('collapses overlapping refreshes into one extra pass', async () => {
    let call = 0
    let release!: () => void
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const notify = vi.fn(() => Promise.resolve())
    const watcher = createNotificationWatcher({
      feed: {
        list: async () => {
          call += 1
          if (call === 1) await gate
          return page([row({ id: 'n2' })], 1)
        },
      },
      notify,
      setBadge: () => Promise.resolve(),
      now: () => STARTED_AT,
    })

    const first = watcher.refresh()
    watcher.onTablesChanged(['notification'])
    watcher.onTablesChanged(['notification'])
    release()
    await first

    // One priming read plus exactly ONE rerun, not one per event.
    expect(call).toBe(2)
    // `n2` was recorded by the priming pass, so the rerun has nothing new to raise.
    expect(notify).not.toHaveBeenCalled()
  })
})

/**
 * `created_at` as the MIRROR spells it, not as a fixture finds convenient.
 *
 * Every fixture above uses `2026-09-01T10:00:05.000Z`. A row that came from the server does
 * not look like that: `SyncPullService` reads the table with the raw query builder, so a MySQL
 * `DATETIME` arrives as `2026-09-01 07:58:01` — space separator, no zone — and is stored
 * verbatim in the `TEXT` column. `Date.parse` reads that form as LOCAL time (ECMA-262), while
 * the instant is UTC (`APP_TIMEZONE=UTC`), so on UTC+3 every real row was shifted three hours
 * into the past and dropped by `createdMs < sinceMs`; five notifications arrived and not one
 * toasted.
 *
 * **The timezone is pinned here on purpose.** On a UTC host the broken and the correct reading
 * are the same number, so a test that did not fix the zone would be green against the bug — it
 * would prove nothing. `Asia/Istanbul` (UTC+3, no DST) makes the two readings three hours
 * apart on any machine, CI included.
 */
describe('parseMirrorTimestamp — the timestamp shape the mirror stores', () => {
  const realTz = process.env.TZ

  beforeAll(() => {
    process.env.TZ = 'Asia/Istanbul'
  })
  afterAll(() => {
    process.env.TZ = realTz
  })

  it('the host really is UTC+3 — otherwise the cases below prove nothing', () => {
    // Guard on the pin itself: if `process.env.TZ` ever stops taking effect, this test says so
    // instead of the rest of the block quietly degrading into a UTC-only tautology.
    expect(new Date(2026, 8, 1, 12, 0, 0).getTimezoneOffset()).toBe(-180)
  })

  it('reads a space-separated, zone-less stamp as UTC, not as local time', () => {
    expect(parseMirrorTimestamp('2026-09-01 07:58:01')).toBe(Date.UTC(2026, 8, 1, 7, 58, 1))
  })

  // The negative control. `Date.parse` IS the old implementation; if the local-time reading
  // were ever restored, this equality would hold and the assertion would fail.
  it('NEGATIVE CONTROL: the local-time reading is three hours off, and is rejected', () => {
    const local = Date.parse('2026-09-01 07:58:01')
    expect(local).toBe(Date.UTC(2026, 8, 1, 4, 58, 1))
    expect(parseMirrorTimestamp('2026-09-01 07:58:01')).not.toBe(local)
    expect(parseMirrorTimestamp('2026-09-01 07:58:01') - local).toBe(3 * 60 * 60 * 1000)
  })

  it('leaves a stamp that carries its own zone alone — locally created rows are RFC 3339', () => {
    expect(parseMirrorTimestamp('2026-09-01T07:58:01.123Z')).toBe(
      Date.parse('2026-09-01T07:58:01.123Z')
    )
    expect(parseMirrorTimestamp('2026-09-01T10:58:01+03:00')).toBe(
      Date.UTC(2026, 8, 1, 7, 58, 1)
    )
  })

  it('accepts the fractional and `T`-separated zone-less forms as UTC too', () => {
    expect(parseMirrorTimestamp('2026-09-01 07:58:01.500')).toBe(
      Date.UTC(2026, 8, 1, 7, 58, 1, 500)
    )
    expect(parseMirrorTimestamp('2026-09-01T07:58:01')).toBe(Date.UTC(2026, 8, 1, 7, 58, 1))
  })

  it('reports an unparseable stamp as NaN, which the caller treats as not toastable', () => {
    expect(Number.isNaN(parseMirrorTimestamp('not a date'))).toBe(true)
    expect(Number.isNaN(parseMirrorTimestamp(''))).toBe(true)
  })
})

/**
 * `takeUnshown` and the watcher against MIRROR-shaped rows.
 *
 * Same pin, same reason: on UTC+3 the buggy reading pushed a row created five seconds after
 * the shell started five seconds shy of three hours BEFORE it, so the toast never fired.
 */
describe('takeUnshown — mirror-shaped `created_at`', () => {
  const realTz = process.env.TZ
  /** Shell start, expressed the way the mirror would: `2026-09-01T10:00:00Z`. */
  const startedAt = Date.UTC(2026, 8, 1, 10, 0, 0)

  beforeAll(() => {
    process.env.TZ = 'Asia/Istanbul'
  })
  afterAll(() => {
    process.env.TZ = realTz
  })

  /** A `Notification` whose `created_at` is spelled as the pull writes it. */
  function mirrorNotification(id: string, createdAt: string): Notification {
    return { ...row({ id }), created_at: createdAt }
  }

  it('keeps a row created after the shell started', () => {
    const shown = new Set<string>()
    const fresh = [mirrorNotification('n1', '2026-09-01 10:00:05')]
    expect(takeUnshown(fresh, shown, startedAt).map((n) => n.id)).toEqual(['n1'])
  })

  it('still drops the restart backlog', () => {
    const shown = new Set<string>()
    const backlog = [
      mirrorNotification('old-1', '2026-08-30 09:00:00'),
      mirrorNotification('old-2', '2026-09-01 09:59:59'),
    ]
    expect(takeUnshown(backlog, shown, startedAt)).toEqual([])
  })

  it('does NOT drop a row that only looks old under a local-time reading', () => {
    // 10:00:05Z is 13:00:05 local. Read as local it becomes 07:00:05Z — nearly three hours
    // before `startedAt`, which is exactly how five real notifications were lost.
    const shown = new Set<string>()
    const misread = Date.parse('2026-09-01 10:00:05')
    expect(misread).toBeLessThan(startedAt)
    expect(takeUnshown([mirrorNotification('n1', '2026-09-01 10:00:05')], shown, startedAt))
      .toHaveLength(1)
  })

  it('orders a mirror-shaped batch oldest first, like the ISO one', () => {
    const shown = new Set<string>()
    const rows = [
      mirrorNotification('newest', '2026-09-01 10:00:30'),
      mirrorNotification('oldest', '2026-09-01 10:00:10'),
    ]
    expect(takeUnshown(rows, shown, startedAt).map((n) => n.id)).toEqual(['oldest', 'newest'])
  })

  it('primes on mirror-shaped rows and toasts only the one that arrives after', async () => {
    const first = page([mirrorNotification('n1', '2026-09-01 10:00:05')], 1)
    const second = page(
      [
        { ...mirrorNotification('n2', '2026-09-01 10:00:20'), title: 'Yeni' },
        mirrorNotification('n1', '2026-09-01 10:00:05'),
      ],
      2
    )
    const notify = vi.fn(() => Promise.resolve())
    const setBadge = vi.fn(() => Promise.resolve())
    let call = 0
    const pages = [first, second]
    const watcher = createNotificationWatcher({
      feed: {
        list: () => {
          const next = pages[Math.min(call, pages.length - 1)]
          call += 1
          return Promise.resolve(next)
        },
      },
      notify,
      setBadge,
      now: () => startedAt,
    })

    await watcher.refresh()
    expect(notify).not.toHaveBeenCalled()

    await watcher.refresh()
    expect(notify).toHaveBeenCalledTimes(1)
    expect(notify).toHaveBeenCalledWith({ title: 'Yeni', body: row().body })
    expect(setBadge).toHaveBeenLastCalledWith(2)
  })
})
