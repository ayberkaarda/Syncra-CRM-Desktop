// `sync_state` reader for record DTOs — the data half of `SyncStateBadge`.
//
// It sits in its own module for the same reason `tokenBadgeVariant.ts` does: a `.tsx` file that
// exports both a component and a plain function breaks React Fast Refresh for that file. The
// badge stays a component-only module and this stays a function-only one.
import type { SyncState } from '../../platform/types'

/**
 * The `sync_state` a record DTO carries, when it carries one.
 *
 * The argument is `unknown` on purpose. `sync_state` is a `WithSyncState<T>` field
 * (`platform/types.ts`) that the feature DTOs — `Deal`, `Contact`, … — do not declare, because
 * it is not a property of the domain record: it describes where the local COPY of that record
 * stands relative to the server, which only an offline-capable platform can answer. Declaring
 * it on every domain type would push a platform concern into all eighteen features; instead a
 * list page holds a plain `Deal` at the call site and this helper is the single place that
 * looks past that type.
 *
 * The value is validated, not asserted: the four legal states are pinned by
 * `CHECK(sync_state IN ('synced','pending','conflict','tombstone'))` in the mirror schema
 * (`desktop/crates/syncra-sync/migrations/0001_init.sql`), and anything else — including the
 * `undefined` every web DTO carries — reports `null`, which the badge renders as nothing.
 */
export function recordSyncState(record: unknown): SyncState | null {
  if (record === null || typeof record !== 'object') return null
  const state = (record as { sync_state?: unknown }).sync_state
  return state === 'pending' || state === 'conflict' || state === 'synced' || state === 'tombstone'
    ? state
    : null
}
