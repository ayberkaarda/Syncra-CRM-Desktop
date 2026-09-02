// `SYNCDESKTOP.md` §8, the UI half: "cevrimdisinda devre disi + tooltip".
//
// `onlineOnly.ts` answers whether an action is currently refused; this file is the React
// binding for that answer plus the sentence that goes with it. It is the ONLY place that builds
// a `desktop:onlineOnly.*` key, which is what keeps the 17 dictionary leaves reachable from one
// template instead of 17 scattered literals.
//
// ## Web behaviour is unchanged, structurally
//
// `isActionOffline()` asks `platform.onlineOnly`, and the web adapter's is the identity
// (`platform/web.ts`) — so `offline` is always `false` in the web bundle, `title` is always
// `undefined`, and `t()` is never called with an `onlineOnly` key there. A page that spreads
// this guard onto a button renders exactly the button it rendered before. See `onlineOnly.ts`
// for why the probe is not `connectivity.isOnline()`.
import { useCallback, useSyncExternalStore } from 'react'
import { useTranslation } from 'react-i18next'

import { usePlatform } from './index'
import { isActionOffline, type OnlineOnlyAction } from './onlineOnly'

/** What a trigger needs in order to obey §8: whether to disable, and what to say if it does. */
export interface OnlineOnlyGuard {
  /** `true` when the action cannot run right now. Always `false` on the web build. */
  offline: boolean
  /**
   * The `title` for the trigger while it is disabled — the `desktop:onlineOnly.<action>`
   * sentence. `undefined` when the action is available, so a call site can write
   * `title={guard.title ?? existingTitle}` and leave its own tooltip alone.
   */
  title: string | undefined
}

/**
 * Live §8 state for one action.
 *
 * Re-reads on every connectivity transition the platform reports. The subscription is the
 * platform's own (`connectivity.subscribe`), not a second poller: `desktop.ts` documents that
 * there is exactly one place the engine's status can be observed from, and a second feed would
 * let the button and the connectivity bar disagree on screen.
 */
export function useOnlineOnly(action: OnlineOnlyAction): OnlineOnlyGuard {
  const platform = usePlatform()
  const { t } = useTranslation()

  const subscribe = useCallback(
    (notify: () => void) => platform.connectivity.subscribe(() => notify()),
    [platform],
  )
  // A boolean snapshot, so `useSyncExternalStore`'s identity check is a value comparison and
  // cannot loop — returning a fresh object here is the classic way to make it re-render forever.
  const snapshot = useCallback(() => isActionOffline(platform, action), [platform, action])

  const offline = useSyncExternalStore(subscribe, snapshot, snapshot)

  return {
    offline,
    title: offline ? t(`desktop:onlineOnly.${action}`) : undefined,
  }
}
