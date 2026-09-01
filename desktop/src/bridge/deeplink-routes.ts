// `syncra://<entity>/<id>` -> a client route — `SYNCDESKTOP.md` §6.4, F5-4.
//
// Split out of `deeplink.ts` so this table can be tested at all: `frontend/src/router.tsx`
// calls `createBrowserRouter` at MODULE scope, which touches `document` on import, so any
// module that imports the router cannot be loaded by the Node-environment `vitest` run. The
// mapping is the part that can silently be wrong, so it lives on this side of that line.
//
// The Rust half (`src-tauri/src/deep_link.rs`) owns the security boundary: it parses the url
// against §6.4's `^[a-z]+/[0-9]{1,12}$` and the eight-name entity allowlist, refuses everything
// else without a word to the user, brings the main window forward, and emits the validated
// `{entity, id}` under the `deep-link` event. Nothing unvalidated ever reaches this file — the
// plugin's own `deep-link://new-url` event, which carries the RAW url, is deliberately not
// listened to anywhere in `desktop/src`.
//
// ## The route table is written by hand
//
// Same rule as `ENTITY_QUERY_KEYS` in `events.ts` (KARAR D-5): derivation is forbidden because
// it is wrong often enough to matter and fails silently when it is. Two of the eight entities
// break any naive `/${entity}s/${id}` guess:
//
//   * `company`      -> `/companies/:id`, not `/companys/:id`
//   * `conversation` -> `/chat/:conversationId`, not `/conversations/:id`
//
// and a third breaks the SHAPE, not just the spelling:
//
//   * `task` has NO detail route at all. `frontend/src/router.tsx` declares `tasks` and no
//     `tasks/:id` — tasks are edited in a drawer over the list, not on a page of their own.
//     A `syncra://task/42` link therefore opens the task LIST. That is a real loss of
//     precision and it is recorded here rather than papered over: routing to a
//     `/tasks/42` that does not exist would land on the `*` not-found route, which is worse.
/** The eight entities §6.4 lists. Mirrors `deep_link::ENTITIES`. */
export type DeepLinkEntity =
  | 'deal'
  | 'lead'
  | 'contact'
  | 'company'
  | 'ticket'
  | 'quote'
  | 'task'
  | 'conversation'

/** `deep_link::DeepLinkTarget` — already validated on the Rust side. */
export interface DeepLinkTarget {
  entity: DeepLinkEntity
  /** One to twelve ASCII digits, as text. Not re-parsed here: it is a path segment. */
  id: string
}

/**
 * Entity -> the route a link to one of its records opens.
 *
 * Transcribed from `frontend/src/router.tsx`, never derived. `task` is the deliberate
 * exception documented in the module header — it ignores the id because there is no route
 * that could use it.
 */
const ROUTES: Record<DeepLinkEntity, (id: string) => string> = {
  deal: (id) => `/deals/${id}`, // router.tsx: `deals/:id`
  lead: (id) => `/leads/${id}`, // router.tsx: `leads/:id`
  contact: (id) => `/contacts/${id}`, // router.tsx: `contacts/:id`
  company: (id) => `/companies/${id}`, // router.tsx: `companies/:id` — NOT `companys`
  ticket: (id) => `/tickets/${id}`, // router.tsx: `tickets/:id`
  quote: (id) => `/quotes/${id}`, // router.tsx: `quotes/:id`
  task: () => '/tasks', // router.tsx has NO `tasks/:id` — see the module header
  conversation: (id) => `/chat/${id}`, // router.tsx: `chat/:conversationId`
}

/**
 * The path `target` should navigate to, or `null` for an entity this build has no route for.
 *
 * `null` rather than a fallback to `/`: the Rust side has already rejected every entity outside
 * the eight, so reaching this branch means the two tables disagree — a defect worth leaving
 * visible (the app stays where it is) instead of hiding behind a navigation to the dashboard.
 */
export function routeForDeepLink(target: DeepLinkTarget): string | null {
  const route = ROUTES[target.entity]
  return route ? route(target.id) : null
}
