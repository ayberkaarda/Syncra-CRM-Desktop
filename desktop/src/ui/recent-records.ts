// The Windows JumpList's "last 5 records" — the webview half (`SYNCDESKTOP.md` §6.4, defter O85).
//
// Rust owns the list itself: the five-entry cap, the `syncra://<entity>/<id>` url each entry
// launches with, the plaintext store, the COM calls and the logout/wipe clean-up all live in
// `src-tauri/src/jump_list.rs`. This module owns the two things only the webview can know:
//
//   1. **which record the window is showing**, because the shell chrome is mounted outside
//      `RouterProvider` (KARAR A27) and `window.location` is the only reading available; and
//   2. **what that record is called**, because resolving a name needs the mirror row and the
//      i18n dictionaries, and neither is reachable from Rust — the same division
//      `commands::os::notify` already draws for notification text.
//
// ## The route table is written by hand, and derivation is FORBIDDEN
//
// Third table under the same rule as `deeplink-routes.ts` and `record-context.ts`, and it is
// the INVERSE of the first one: `deeplink-routes.ts` maps an entity to the route a link opens,
// this maps a route segment back to the entity a visit should be recorded as. Two of the eight
// break any naive de-pluralisation —
//
//   * `/companies/:id` -> `company`, not `companie`
//   * `/chat/:id`      -> `conversation`, not `chat`
//
// — and `task` is absent on purpose, not by oversight: `frontend/src/router.tsx` declares no
// `tasks/:id` at all (tasks are edited in a drawer over the list), so there is no task detail
// route a user can be on and therefore nothing to record. That is the same fact
// `deeplink-routes.ts` records from the other direction, where `syncra://task/42` opens the
// task list.
//
// Keeping the two tables separate rather than deriving one from the other is deliberate: they
// disagree about `task` for a real reason, and a derived inverse would either invent
// `/tasks/:id` or drop a route that exists.
import type { EntityName, LocalRow } from '../platform/data/engine'

import type { DeepLinkEntity } from '../bridge/deeplink-routes'
import { recordLabel } from './record-label'
import type { Translate } from './useT'

/** The record a `record_opened` call is about. */
export interface RecentTarget {
  entity: DeepLinkEntity
  /** The id exactly as it appeared in the path — a string, never re-parsed into a number. */
  id: string
}

/**
 * Route segment -> the entity a visit to `/<segment>/<id>` should be recorded as.
 *
 * Transcribed from `frontend/src/router.tsx`. Seven entries for the eight §6.4 entities; see
 * the module header for why `task` is not one of them.
 */
const RECENT_ROUTES: Record<string, DeepLinkEntity> = {
  deals: 'deal', // router.tsx: `deals/:id` — NOT `deals/list`, which is a sibling
  leads: 'lead', // router.tsx: `leads/:id`
  contacts: 'contact', // router.tsx: `contacts/:id`
  companies: 'company', // router.tsx: `companies/:id` — NOT `companys`
  tickets: 'ticket', // router.tsx: `tickets/:id`
  quotes: 'quote', // router.tsx: `quotes/:id` — NOT `quotes/new`, NOT `quotes/:id/edit`
  chat: 'conversation', // router.tsx: `chat/:conversationId`
}

/**
 * §6.4's id shape. The same pattern `record-context.ts` uses and the same one
 * `deep_link::parse_deep_link` enforces on the other side of the IPC — restated here because
 * this is what stops `/deals/list` and `/quotes/new` from being read as record ids.
 */
const ID_PATTERN = /^[0-9]{1,12}$/

/**
 * The record the current path is about, or `null` on every other route.
 *
 * Exactly two segments, the second all digits: `/deals/42` matches, `/deals`, `/deals/list`,
 * `/quotes/new` and `/deals/42/anything` do not. A trailing slash is tolerated because
 * `''` segments are dropped, which is how `record-context.ts` behaves too.
 *
 * `Object.hasOwn` rather than a truthiness test on the lookup, for the reason
 * `deeplink-routes.ts` measured: a plain index signature is a prototype-chain lookup, so
 * `/constructor/1` would resolve to `Object` and `/toString/1` to a function. The path comes
 * from `window.location`, which anything that can navigate this window controls.
 */
export function recentTargetOf(pathname: string): RecentTarget | null {
  const segments = pathname.split('/').filter((segment) => segment !== '')
  if (segments.length !== 2) return null
  if (!ID_PATTERN.test(segments[1])) return null
  if (!Object.hasOwn(RECENT_ROUTES, segments[0])) return null
  return { entity: RECENT_ROUTES[segments[0]], id: segments[1] }
}

/**
 * Which mirror table a target's row lives in.
 *
 * The seven entity names §6.4 uses are already the singular `EntityName` values the engine
 * takes (`deal`, `company`, `conversation`, …), so this is an assertion that the two
 * vocabularies coincide rather than a translation — but it is written as a function so the one
 * place that relies on that fact is visible, and so `tsc` checks it.
 */
export function entityTableOf(entity: DeepLinkEntity): EntityName {
  return entity
}

/**
 * The title one jump-list entry should show.
 *
 * `row` is the mirror row, or `null` when the mirror does not have it (a record opened straight
 * from a deep link before the first sync, or one outside the retention window). The fallback is
 * an i18n string with the entity's own label and the id — "Fırsat #29" — because a jump-list
 * entry has to say something, and §0.6 forbids this file from inventing that sentence itself.
 *
 * The empty-string case is `recordLabel`'s contract, not an accident: a row whose name columns
 * are all blank gets the same treatment as no row at all, since an entry labelled `''` would
 * render as a nameless line the user cannot tell apart from the next one.
 */
export function recentTitleOf(
  t: Translate,
  target: RecentTarget,
  row: LocalRow | null,
): string {
  const label = row === null ? '' : recordLabel(row)
  if (label !== '') return label
  return t('desktop:jumpList.fallbackTitle', {
    entity: t(`desktop:entities.${target.entity}`),
    id: target.id,
  })
}
