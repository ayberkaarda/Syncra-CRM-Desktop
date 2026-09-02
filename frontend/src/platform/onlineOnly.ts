// `SYNCDESKTOP.md` §8 — the online-only action vocabulary, and the probe that answers
// "can this action run right now?" without knowing which platform is running (KARAR A19).
//
// ## The three parts of §8, and which one this file is
//
// §8 asks for three things and the repo already had two of them:
//
//   1. the CONTRACT — `Platform.onlineOnly(action, fn)` (`types.ts`) and `OnlineOnlyError`;
//   2. the TEXT — `desktop:onlineOnly.*`, 17 leaves x 4 languages;
//   3. the BINDING — nothing called (1), so (2) was dead and no trigger was ever disabled.
//
// This module is the missing (3), on the shared side: the vocabulary that ties an action to
// its dictionary leaf, and `isActionOffline()`, which asks the ACTIVE platform whether the
// action is currently refused.
//
// ## Why there is no `isDesktop` branch here
//
// Exactly the `isRecordNotMirrored` / `SyncStateBadge` trade (`errors.ts`, `types.ts`): the
// question is put to the platform's own `onlineOnly`, and the answer is read off the SHAPE of
// what comes back.
//
//   * `platform/web.ts`'s `onlineOnly` is the identity — `(_action, fn) => fn()`. The probe's
//     `fn` returns `undefined`, so `isActionOffline()` is ALWAYS `false` on the web build, and
//     every trigger there keeps the enabled/disabled state it had. This is deliberately NOT
//     `navigator.onLine`: the web adapter's `connectivity.isOnline()` does read that flag, and
//     disabling the send button of a browser that briefly reported `offline` would be a
//     behaviour change in the web app that §8 never asked for.
//   * `desktop/src/platform/onlineOnly.ts` returns an `OnlineOnlyError` when the ENGINE reports
//     offline (not `navigator.onLine` — §3.5: a machine with a LAN but no route to the API is
//     the common failure here), so the desktop build disables the trigger and names the reason.
//
// A component therefore reads one boolean and never asks which platform it is on.
import type { OnlineOnlyError, Platform } from './types'

/**
 * The `SYNCDESKTOP.md` §8 list, spelled exactly as the `desktop:onlineOnly.*` dictionary
 * spells it — `<action>` is appended to `desktop:onlineOnly.` to get the tooltip key
 * (`useOnlineOnly.ts`), so these two must stay identical.
 *
 * Order and wording follow §8 itself: "leads.convert, leads.import, quotes.send, quotes.revise,
 * quotes.pdf (cache yoksa), quotes.calculate, settings.*, users.*, roles, reports.*,
 * dashboard.* (son cache), logs.*, exchange-rates manuel, attachments upload (kuyruk),
 * saved-views create/update, password change".
 *
 * Note where an action name is COARSER than the `DataSource` method that carries it: §8 says
 * "users.*", and the dictionary has one sentence for it, so all seven `users.*` methods and
 * `users.roles`'s sibling report `'users'` / `'roles'` rather than a per-method key. Inventing
 * `onlineOnly.users.setActive` would be a new key, and this strand does not write the
 * dictionary.
 */
export const ONLINE_ONLY_ACTIONS = [
  'leads.convert',
  'leads.import',
  'quotes.send',
  'quotes.revise',
  'quotes.pdf',
  'quotes.calculate',
  'settings',
  'users',
  'roles',
  'reports',
  'dashboard',
  'logs',
  'exchangeRates',
  'attachments.upload',
  'savedViews.create',
  'savedViews.update',
  'password.change',
] as const

/**
 * One §8 action. A union rather than `string` on purpose: a typo in a call site would otherwise
 * resolve to a missing i18n key, and `frontend/src/i18n/index.ts`'s `missingKeyHandler` THROWS
 * in dev when the fallback language has no such key — a white screen instead of a tooltip.
 */
export type OnlineOnlyAction = (typeof ONLINE_ONLY_ACTIONS)[number]

/** The probe's payload. Never used — only its absence/presence in the return value is read. */
const PROBE = () => undefined

/**
 * True when `error` is the §8 refusal (`OnlineOnlyError`, `code: 'ONLINE_ONLY'`).
 *
 * Shape check, not a class check — the error crosses the `Platform` contract as a plain object
 * and may also arrive as a rejected promise from the desktop data layer.
 */
export function isOnlineOnlyError(error: unknown): error is OnlineOnlyError {
  return (
    typeof error === 'object' &&
    error !== null &&
    'code' in error &&
    (error as { code: unknown }).code === 'ONLINE_ONLY'
  )
}

/** The `action` an {@link isOnlineOnlyError} carries, or `undefined` for any other failure. */
export function onlineOnlyActionOf(error: unknown): string | undefined {
  if (!isOnlineOnlyError(error)) return undefined
  const action = (error as { action?: unknown }).action
  return typeof action === 'string' ? action : undefined
}

/**
 * Would `action` be refused if it were triggered right now?
 *
 * Asks the platform rather than any connectivity flag, so the answer is the SAME decision the
 * defence layer will make when the action is actually invoked — `desktop/src/platform/data/*`
 * routes every §8 verb through the same `onlineOnly()`. A disabled button and a rejected call
 * cannot disagree, because there is only one predicate.
 */
export function isActionOffline(platform: Platform, action: OnlineOnlyAction): boolean {
  return isOnlineOnlyError(platform.onlineOnly(action, PROBE))
}
