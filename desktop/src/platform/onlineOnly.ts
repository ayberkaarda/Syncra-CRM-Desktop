// `SYNCDESKTOP.md` §8 on the desktop side: the refusal itself.
//
// `frontend/src/platform/onlineOnly.ts` owns the shared vocabulary and the probe; this module
// owns the VERDICT, because only this platform has one to give. It lives next to `status.ts`
// rather than inside `desktop.ts` so `platform/data/*` can reach it without closing the import
// cycle `desktop.ts -> ./data -> desktop.ts` (same split as `http.ts`).
//
// ## Two entry points, one predicate
//
//   * `onlineOnly()` is `Platform['onlineOnly']` — it RETURNS the `OnlineOnlyError` rather than
//     throwing, which is what lets the UI probe an action's availability without running it
//     (`isActionOffline`). That is the shape the contract fixed (`types.ts`, karar S9) and it
//     is not widened here.
//   * `requireOnline()` is the wrapper the data layer uses, and it THROWS the same error. A
//     `DataSource` method is declared `Promise<T>`; returning `Promise<T> | OnlineOnlyError`
//     from one would not type-check and, worse, would make the refusal something every caller
//     has to remember to inspect. Rejecting puts it on the path every caller already has.
//
// Both read `isEngineOnline()`, so defence layer 1 (the disabled button) and defence layer 2
// (the rejected call) cannot disagree.
import { isOnlineOnlyError, type OnlineOnlyAction } from '@/platform/onlineOnly'
import type { OnlineOnlyError } from '@/platform/types'

import { isEngineOnline } from './status'

/**
 * `SYNCDESKTOP.md` §8, defence layer 2: offline, `fn` is never called — the caller gets an
 * `OnlineOnlyError` whose `action` resolves the `desktop.onlineOnly.<action>` tooltip key (S9).
 *
 * `action` is typed `string` and not `OnlineOnlyAction` because this is the implementation of
 * `Platform['onlineOnly']`, whose signature the shared contract fixes. Every call site inside
 * this codebase goes through {@link requireOnline} or `useOnlineOnly`, both of which are typed
 * to the union.
 */
export function onlineOnly<T>(action: string, fn: () => T): T | OnlineOnlyError {
  if (!isEngineOnline()) {
    return {
      code: 'ONLINE_ONLY',
      action,
      message: `"${action}" requires a connection.`,
    }
  }
  return fn()
}

/**
 * The `platform/data/*` wrapper for a §8 verb: run it online, reject with `OnlineOnlyError`
 * offline.
 *
 * The point is that `fn` is NOT called offline. Without this the wrapped body would reach
 * `platform.http`, axios would fail with a transport error, and the user would be shown
 * `errors.HTTP_ERROR` / `errors.unknown` — a generic failure that names neither the action nor
 * the reason, which is precisely what §8 exists to replace.
 */
export function requireOnline<T>(action: OnlineOnlyAction, fn: () => Promise<T>): Promise<T> {
  const outcome = onlineOnly(action, fn)
  if (isOnlineOnlyError(outcome)) return Promise.reject(outcome)
  return outcome
}
