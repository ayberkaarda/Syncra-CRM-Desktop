// `EngineEvent` -> TanStack Query invalidation — `docs/DESKTOP-ARCHITECTURE.md` §3.6, KARAR A5.
//
// The engine emits `tables_changed` whenever a pull, a push result or a local mutation moves
// rows on disk. The shell's job is to tell the query cache which keys that invalidates.
//
// ## KARAR D-5 — the table is written by hand, and derivation is FORBIDDEN
//
// "Pluralise the entity name" is wrong for six of the twenty-two entities, and every one of
// those cases was verified in the F0 discovery. The counter-examples are not exotic:
//
// | Entity | Naive guess | Actual `*Keys` factory |
// |---|---|---|
// | search | `['search']` | `['global-search']` — `features/search/api/searchApi.ts:21-23` |
// | deal board | `['deals']` only | also `['deals','board']` — `features/deals/api/boardApi.ts:23-24` |
// | exchange rate | `['exchange-rates']` | `['exchange-rates','current']`; there is **no** `all` |
// | price list | `['price_lists']` | `['price-lists']` — a hyphen, not an underscore |
// | saved view | `['saved_views']` | `['saved-views']` |
// | custom field | `['custom_fields']` | `['custom-fields', <entityType>]` — two segments |
//
// A derived table would have silently invalidated nothing for those, which is the worst kind
// of failure: the data is stale, no error is raised, and the user re-reads a screen that never
// refreshes. So the table below is transcribed from the real factories, and each row names the
// file it came from.
//
// Prefix matching does the rest: TanStack Query treats a query key as a prefix, so `['deals']`
// invalidates `['deals','list',{...}]` and `['deals','detail',42]` alike. `TablesChanged` is
// table-granular anyway — there is no row-level event to be more precise with.
// `@tanstack/react-query` is a `frontend/package.json` dependency and is not resolvable from
// `desktop/` (KARAR A2 keeps the two dependency trees apart). The type comes from the shared
// singleton instead: a type-only import of the value, which erases completely at build time.
import type { queryClient } from '@/lib/queryClient'
import { listen, type UnlistenFn } from '@tauri-apps/api/event'

/** The query cache this bridge invalidates — the app's own `QueryClient`. */
type QueryClient = typeof queryClient

/** Tauri event name the Rust side forwards every engine event under (`src-tauri/src/events.rs`). */
export const ENGINE_EVENT = 'engine-event'

/** `syncra_sync::Entity` wire names. */
type EntityName = string

/** `syncra_sync::EngineEvent`, internally tagged on `type`. */
export type EngineEvent =
  | { type: 'tables_changed'; entities: EntityName[] }
  | { type: 'status_changed'; status: unknown }
  | { type: 'conflict_added'; id: string }
  | { type: 'storage_warning'; stats: unknown }
  | { type: 'auth_lost' }
  | { type: 'protocol_mismatch'; server: number }

/**
 * Entity -> the query keys its rows appear under.
 *
 * Transcribed from the `*Keys` factories, never derived (KARAR D-5). An entity mapped to `[]`
 * has no query key of its own: its rows reach the UI only inside another entity's DTO, and
 * invalidating the owner is what refreshes them.
 */
export const ENTITY_QUERY_KEYS: Record<EntityName, readonly (readonly unknown[])[]> = {
  // `dealsApi.ts:14-19` (list/detail) and `boardApi.ts:23-24` (the board is a SEPARATE factory).
  deal: [['deals'], ['deals', 'board']],
  // `companiesApi.ts:33-39`; `contactsApi.ts:51-54` and `dealsShared.ts:46` cache company
  // pickers under their own roots, which a company change also invalidates.
  company: [['companies'], ['company-options'], ['deals', 'company-options']],
  // `contactsApi.ts:36-41`; `dealsShared.ts:43-45` caches the per-company contact picker.
  contact: [['contacts'], ['companies', 'contacts'], ['deals', 'contact-options']],
  // `leadsApi.ts:22-26`.
  lead: [['leads']],
  // `tasksApi.ts:19-26` — list and calendar share the `['tasks']` root.
  task: [['tasks']],
  // `ticketsApi.ts:22-28` — `stats` is `['tickets','stats']`, under the same root.
  ticket: [['tickets']],
  // `quotesApi.ts:24-29`.
  quote: [['quotes']],
  // `activitiesApi.ts:10-15`; a record's timeline is rebuilt from activities and tasks, so a
  // change also invalidates the two timeline roots.
  activity: [['activities'], ['contacts', 'timeline'], ['companies', 'timeline']],
  // `features/chat/api.ts:23-33` — one root for conversations, messages, search and the badge.
  conversation: [['chat']],
  message: [['chat']],
  // Membership drives `unread_count` and `is_muted`, both of which live on the conversation DTO.
  conversation_user: [['chat']],
  // `features/notifications/hooks/useNotifications.ts:11-16`.
  notification: [['notifications']],
  // `companiesApi.ts:41-43`, `contactsApi.ts:43-45`, `leadsApi.ts:28`, `dealsShared.ts:41`,
  // `productsShared.ts:22` — five modules, one shared `['tags']` root.
  tag: [['tags']],
  // `boardApi.ts:28` — `['pipeline-stages']`, hyphenated, and the board redraws its columns.
  pipeline_stage: [['pipeline-stages'], ['deals', 'board'], ['settings', 'pipeline-stages']],
  // `customFieldsKeys` is `['custom-fields', <entityType>]` in every module
  // (`companiesApi.ts:45-47`, `contactsApi.ts:47-49`, `leadsApi.ts:29-31`,
  // `dealsShared.ts:42`, `productsShared.ts:23`). The prefix covers every entity type.
  custom_field: [['custom-fields'], ['settings', 'custom-fields']],
  // `productsApi.ts:17-24`.
  product: [['products']],
  // `priceListsApi.ts:19-26` — HYPHEN.
  price_list: [['price-lists']],
  // Items live under the same root (`['price-lists','items',id,page]`), and a price override
  // changes what `products.price` resolves to.
  price_list_item: [['price-lists'], ['products', 'price']],
  // `exchangeRatesApi.ts:14-16` — the factory has `current` and NO `all` field.
  exchange_rate: [['exchange-rates'], ['settings', 'exchange-rates']],
  // `savedViewsApi.ts:10-13` — HYPHEN.
  saved_view: [['saved-views']],
  // `settingsApi.ts:35-45`.
  setting: [['settings']],
  // `usersApi.ts:22-26`, plus the four picker roots the option lookups cache under:
  // `['user-options']` (`companiesApi.ts:49`, `contactsApi.ts:56`), `['tasks','user-options']`
  // (`tasksApi.ts:28`), `['deals','owner-options']` and `['leads','owner-options']`.
  user: [
    ['users'],
    ['user-options'],
    ['tasks', 'user-options'],
    ['deals', 'owner-options'],
    ['leads', 'owner-options'],
  ],
}

/**
 * Query keys that no `Entity` maps to, listed so the omission is a decision rather than an
 * oversight.
 *
 * `['global-search']` (`searchApi.ts:21-23`) is deliberately absent: the desktop palette reads
 * the local FTS index, which is rebuilt by the same triggers that write the rows, so it is
 * already fresh — and invalidating it on every `tables_changed` would re-run a search the user
 * may have moved on from. `['dashboard']`, `['reports']` and `['logs']` are online-only
 * surfaces (`SYNCDESKTOP.md` §8) and are not fed by the mirror at all.
 */
export const UNMAPPED_QUERY_KEYS: readonly string[] = [
  'global-search',
  'dashboard',
  'reports',
  'logs',
]

/** Every query key one `tables_changed` batch invalidates, de-duplicated. */
export function keysForEntities(entities: readonly EntityName[]): (readonly unknown[])[] {
  const seen = new Set<string>()
  const keys: (readonly unknown[])[] = []
  for (const entity of entities) {
    for (const key of ENTITY_QUERY_KEYS[entity] ?? []) {
      const id = JSON.stringify(key)
      if (seen.has(id)) continue
      seen.add(id)
      keys.push(key)
    }
  }
  return keys
}

/** Handlers for the engine events this bridge does not own. */
export interface EngineEventHandlers {
  /**
   * The same batch the invalidation above consumed, for readers that need more than a cache
   * refresh — `bridge/notifications.ts` is the first: a moved `notification` table is what
   * §6.4 turns into a native toast and a taskbar badge.
   *
   * Called AFTER the invalidation, and never instead of it: this is an extra consumer of the
   * event, not a replacement for the cache mapping. It is deliberately not a `Promise`-typed
   * hook — the subscription must not be able to fall behind on an awaited handler, so a
   * handler that does asynchronous work owns its own queueing (as the notification watcher
   * does).
   */
  onTablesChanged?: (entities: readonly EntityName[]) => void
  onStatusChanged?: (status: unknown) => void
  onConflictAdded?: (id: string) => void
  onStorageWarning?: (stats: unknown) => void
  onAuthLost?: () => void
  onProtocolMismatch?: (serverVersion: number) => void
}

/**
 * Apply one engine event to the query cache.
 *
 * Exported separately from the subscription so the mapping can be exercised without a Tauri
 * runtime.
 */
export function applyEngineEvent(
  queryClient: QueryClient,
  event: EngineEvent,
  handlers: EngineEventHandlers = {},
): void {
  switch (event.type) {
    case 'tables_changed':
      for (const queryKey of keysForEntities(event.entities)) {
        void queryClient.invalidateQueries({ queryKey })
      }
      handlers.onTablesChanged?.(event.entities)
      return
    case 'status_changed':
      handlers.onStatusChanged?.(event.status)
      return
    case 'conflict_added':
      handlers.onConflictAdded?.(event.id)
      return
    case 'storage_warning':
      handlers.onStorageWarning?.(event.stats)
      return
    case 'auth_lost':
      handlers.onAuthLost?.()
      return
    case 'protocol_mismatch':
      handlers.onProtocolMismatch?.(event.server)
  }
}

/**
 * Subscribe to the engine's event stream. Returns the unsubscribe handle.
 *
 * One listener, not one per variant: the discriminator travels inside the payload, and two
 * listeners would let a `status_changed` overtake the `tables_changed` that produced it.
 */
export function subscribeToEngineEvents(
  queryClient: QueryClient,
  handlers: EngineEventHandlers = {},
): Promise<UnlistenFn> {
  return listen<EngineEvent>(ENGINE_EVENT, (event) => {
    applyEngineEvent(queryClient, event.payload, handlers)
  })
}
