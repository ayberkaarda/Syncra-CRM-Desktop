// New `notifications` rows -> a native toast and a taskbar badge — `SYNCDESKTOP.md` §6.4 item 2
// ("`notifications` tablosundan (pull + realtime) yeni satır → native") and item 7 (badge).
//
// ## Why this hangs off `tables_changed` and not off a poller
//
// `commands::os` says it in its own module doc: "Deciding *when* to notify" is the caller's
// job, and "this module must not grow a second poller beside" the engine's event stream. Every
// path that can produce a notification row — the periodic pull, a Reverb frame translated by
// `bridge/realtime.ts` into `handle_realtime`, and a local `notification.read` mutation — ends
// in the engine emitting `TablesChanged`. That is the one signal, so this is the one reader.
//
// ## The two guards against a toast storm, and why BOTH are needed
//
// `tables_changed` says *the table moved*, not *what moved*. Re-reading the unread page on
// every such event would re-toast rows that are merely still unread, so:
//
//   1. **`shown`** — the ids this process has already raised a toast for, held for the lifetime
//      of the process. Without it, marking one notification read emits `tables_changed`, this
//      module re-reads, and every OTHER unread row toasts a second time.
//   2. **`startedAt` + a priming pass** — rows whose `created_at` predates the shell's start
//      never toast, and the FIRST read raises no toast at all (it only fills `shown` and sets
//      the badge). The timestamp alone is not enough: `created_at` is the SERVER's clock and
//      `startedAt` is the local one, so a machine whose clock runs slow would see a whole
//      restored backlog as "created after start" and open fifty toasts at once. The priming
//      pass makes that impossible regardless of clock skew; the timestamp then keeps a row
//      that was pulled late (but written long ago) quiet on the runs after it.
//
// The cost of the priming pass is the notification that lands in the window between process
// start and the first read — it is counted in the badge but not toasted. That is the safe
// direction, and the window is one command round trip wide.
//
// ## Failures are silent, deliberately
//
// `set_badge` is a no-op-shaped failure on a platform without a badge, and `notify` resolves as
// soon as the toast is *handed to* the OS (`tauri-plugin-notification`'s desktop `show()`
// swallows the outcome — `commands::os::notify` documents this). Neither result can honestly be
// reported to the user, so nothing here raises a toast, writes a banner or claims delivery. A
// rejection only means this cycle did nothing; the next `tables_changed` retries.
import type {
  Notification,
  NotificationsListResponse,
  NotificationsQuery,
} from '@/features/notifications/types'

import { notificationsSource } from '../platform/data/comms'
import { parseMirrorTimestamp } from '../platform/data/timestamps'
import { notify, setBadge, type NativeNotification } from '../ui/commands'

/**
 * Re-exported for `./notifications.test.ts`, which asserts this module's timestamp handling
 * directly. The parser itself now lives in `platform/data/timestamps.ts` — see that module's
 * header for why (it stopped having one caller when `ui/format.ts` and `platform/data/
 * mappers.ts` needed the exact same mirror-shape fix).
 */
export { parseMirrorTimestamp }

/** The `notification` wire name as `syncra_sync::Entity` serialises it. */
const NOTIFICATION_ENTITY = 'notification'

/** The read side this watcher needs — `platform/data/comms.ts`'s `notificationsSource`. */
export interface NotificationFeed {
  list(query: NotificationsQuery): Promise<NotificationsListResponse>
}

/** Everything the watcher touches that is not itself. Injected so the logic can be tested. */
export interface NotificationWatcherDeps {
  feed: NotificationFeed
  notify: (input: NativeNotification) => Promise<void>
  setBadge: (count: number) => Promise<void>
  /** Milliseconds since the epoch. Read exactly once, when the watcher is created. */
  now: () => number
}

export interface NotificationWatcher {
  /**
   * Re-read the unread page: badge from the total, toasts for the rows this process has not
   * raised yet. Never rejects.
   */
  refresh(): Promise<void>
  /** `tables_changed` filter — does nothing unless `notification` is in the batch. */
  onTablesChanged(entities: readonly string[]): void
}

/**
 * The rows of one unread page that still deserve a toast, oldest first.
 *
 * **Mutates `shown`**: every row it inspects is recorded, including the ones it filters out.
 * That is the point — a row rejected for being too old must not become toastable later just
 * because the next `tables_changed` arrived after the clock moved.
 *
 * Returned oldest-first so a batch stacks in the order the events happened; the query is
 * `created_at DESC` (`NamedQuery::default_sort`), i.e. the opposite.
 */
export function takeUnshown(
  rows: readonly Notification[],
  shown: Set<string>,
  sinceMs: number,
): Notification[] {
  const fresh: Notification[] = []
  for (const row of rows) {
    if (shown.has(row.id)) continue
    shown.add(row.id)
    // Defensive: the query already filters `read_at IS NULL`, but this module must not toast a
    // row someone has read even if the filter is ever widened.
    if (row.read_at !== null) continue
    const createdMs = parseMirrorTimestamp(row.created_at)
    if (!Number.isFinite(createdMs) || createdMs < sinceMs) continue
    // `os::notification_text` rejects an empty title or body with `VALIDATION_ERROR`. Filtering
    // here keeps that rejection out of the log for the one case the shell can see coming.
    if (row.title.trim() === '' || row.body.trim() === '') continue
    fresh.push(row)
  }
  return fresh.reverse()
}

/** A watcher over `deps`. Nothing is read until the first {@link NotificationWatcher.refresh}. */
export function createNotificationWatcher(deps: NotificationWatcherDeps): NotificationWatcher {
  const startedAt = deps.now()
  const shown = new Set<string>()
  let primed = false

  /** Set while a read is in flight, so two events cannot interleave two reads of one table. */
  let inFlight: Promise<void> | null = null
  /** An event that arrived while a read was running — one more pass is owed, not one per event. */
  let rerun = false

  async function readOnce(): Promise<void> {
    const page = await deps.feed.list({ read: 'unread' })

    // The badge is the WHOLE unread count, not the page size: `meta.pagination.total` is what
    // `countRows` returned for the same filter, and page 1 holds only the newest 15.
    await deps.setBadge(page.meta.pagination.total).catch(() => undefined)

    const fresh = takeUnshown(page.data, shown, startedAt)
    if (!primed) {
      // First pass: `shown` is now filled and the badge is right. Nothing is raised — see the
      // module header on clock skew.
      primed = true
      return
    }

    for (const row of fresh) {
      await deps.notify({ title: row.title, body: row.body }).catch(() => undefined)
    }
  }

  function refresh(): Promise<void> {
    if (inFlight !== null) {
      rerun = true
      return inFlight
    }

    let settle!: () => void
    const gate = new Promise<void>((resolve) => {
      settle = resolve
    })
    inFlight = gate

    void (async () => {
      do {
        rerun = false
        try {
          await readOnce()
        } catch {
          // Silent by contract — see the module header.
        }
      } while (rerun)
      inFlight = null
      settle()
    })()

    return gate
  }

  return {
    refresh,
    onTablesChanged(entities) {
      if (!entities.includes(NOTIFICATION_ENTITY)) return
      void refresh()
    },
  }
}

/**
 * The process-wide watcher.
 *
 * A singleton because `shown` and `startedAt` are process state: a second instance would start
 * with an empty `shown` set and re-toast everything the first one already raised.
 */
let watcher: NotificationWatcher | null = null

/** The shell's watcher, created on first use. */
export function notificationWatcher(): NotificationWatcher {
  watcher ??= createNotificationWatcher({
    feed: notificationsSource,
    notify,
    setBadge,
    now: () => Date.now(),
  })
  return watcher
}
