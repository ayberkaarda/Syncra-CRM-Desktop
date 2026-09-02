// Which record the window is currently showing — the missing half of `files::*`.
//
// Both file features §6.4 asks for need a record: a drop has to land on a deal or a ticket
// (`AttachTarget::Record`), and a screenshot has to land on a ticket. The shell chrome is
// mounted OUTSIDE `RouterProvider` (KARAR A27, `DesktopPanel.tsx`), so `useParams` is not
// available to it and the location is the only thing left to read the record off.
//
// ## The table is written by hand, and derivation is FORBIDDEN
//
// Same rule as `ENTITY_QUERY_KEYS` (KARAR D-5) and `deeplink-routes.ts`, for the same reason:
// `frontend/src/router.tsx` is not derivable. `deals/list` is a real sibling of `deals/:id`,
// and `quotes/new` and `quotes/:id/edit` sit beside `quotes/:id` — a `/(\w+)\/(.+)/` guess
// would read "list" as a deal id and open a drop target on a list screen. The id pattern is
// therefore digits-only and the segment count is exact.
//
// The ids are SERVER ids, which is correct and worth stating: every `files::*` command posts
// to the API, so a record that has never been pushed has no id the server would accept. A row
// created offline is not addressable here, and that is a property of the endpoints, not a gap
// in this table.
import type { RecordKind } from './files'

/** The record the current route is about, when it is one the file commands can address. */
export interface RecordContext {
  kind: RecordKind
  id: number
}

/** Route segment -> the `RecordKind` it carries. Transcribed from `router.tsx`. */
const RECORD_ROUTES: Record<string, RecordKind> = {
  deals: 'deal', // router.tsx: `deals/:id` — NOT `deals/list`, which is a sibling route
  tickets: 'ticket', // router.tsx: `tickets/:id`
}

/**
 * `/deals/12` and `/tickets/12` split into `['deals','12']`; anything with a different segment
 * count is a different route. Digits only, so `deals/list` and `quotes/new` cannot match.
 */
function detailSegments(pathname: string): [string, number] | null {
  const segments = pathname.split('/').filter((segment) => segment !== '')
  if (segments.length !== 2) return null
  if (!/^[0-9]{1,12}$/.test(segments[1])) return null
  return [segments[0], Number(segments[1])]
}

/** The deal or ticket the window is showing, or `null` on every other route. */
export function recordContextOf(pathname: string): RecordContext | null {
  const detail = detailSegments(pathname)
  if (detail === null) return null
  const kind = RECORD_ROUTES[detail[0]]
  return kind ? { kind, id: detail[1] } : null
}

/** The quote the window is showing (`quotes/:id` only — not `new`, not `:id/edit`). */
export function quoteIdOf(pathname: string): number | null {
  const detail = detailSegments(pathname)
  return detail !== null && detail[0] === 'quotes' ? detail[1] : null
}
