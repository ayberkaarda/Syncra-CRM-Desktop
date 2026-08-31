// Typed access to the `data::*` commands — `SYNCDESKTOP.md` §6.2, §5.2.
//
// This is the ONLY module in `desktop/src` that names a Tauri `data::*` command. Everything
// above it speaks in `NamedQuery` values and mirror rows; everything below it is Rust.
//
// Raw SQL never crosses this boundary in either direction (`SYNCDESKTOP.md` §5.2 —
// "Ham SQL UI'dan kabul edilmez — YASAK"): the UI names a query, the crate owns the
// statement, and every caller value is bound on the Rust side.
import { invokeCommand } from '../../bridge/invoke'

// ------------------------------------------------------------------------------------------------
// Wire types — the TypeScript face of `syncra_sync::{Entity, NamedQuery, QueryParams,
// LocalMutation, SearchHit}`. Field names are snake_case because serde serialises them that
// way; renaming them here would only move the mismatch somewhere harder to see.
// ------------------------------------------------------------------------------------------------

/** `syncra_sync::Entity` wire names. */
export type EntityName =
  | 'company'
  | 'contact'
  | 'lead'
  | 'deal'
  | 'task'
  | 'activity'
  | 'ticket'
  | 'quote'
  | 'conversation'
  | 'message'
  | 'conversation_user'
  | 'notification'
  | 'tag'
  | 'pipeline_stage'
  | 'custom_field'
  | 'product'
  | 'price_list'
  | 'price_list_item'
  | 'exchange_rate'
  | 'saved_view'
  | 'setting'
  | 'user'

/** `syncra_sync::db::query::CountScope`. */
export type CountScope =
  | 'company_contacts'
  | 'company_deals'
  | 'contact_deals'
  | 'contact_tickets'
  | 'price_list_items'

/** `syncra_sync::db::query::ReadFilter`. */
export type ReadFilter = 'unread' | 'read'

/**
 * `syncra_sync::db::query::NamedQuery`, internally tagged on `query`.
 *
 * Filters carry **server** ids (`owner_id`, `company_id`, …) because that is what the list
 * screens already hold; the two exceptions that carry local ids are called out in the crate's
 * module doc.
 */
export type NamedQuery =
  | { query: 'rows_by_server_ids'; entity: EntityName; ids: number[] }
  | { query: 'rows_by_client_ids'; entity: EntityName; client_ids: string[] }
  | { query: 'deals_board'; stage_client_ids: string[] }
  | {
      query: 'deals_list'
      q?: string
      status?: string
      stage_id?: number
      owner_id?: number
      company_id?: number
      contact_id?: number
      tag_id?: number
      amount_min?: number
      amount_max?: number
      from?: string
      to?: string
    }
  | {
      query: 'company_list'
      q?: string
      industry?: string
      owner_id?: number
      city?: string
      country?: string
      tag_id?: number
      from?: string
      to?: string
    }
  | {
      query: 'contact_list'
      q?: string
      company_id?: number
      owner_id?: number
      is_primary?: boolean
      city?: string
      tag_id?: number
      from?: string
      to?: string
    }
  | {
      query: 'lead_list'
      q?: string
      status?: string
      source?: string
      owner_id?: number
      score_min?: number
      score_max?: number
      tag_id?: number
      from?: string
      to?: string
    }
  | {
      query: 'task_list'
      q?: string
      status?: string
      priority?: string
      assigned_to?: number
      created_by?: number
      taskable_type?: string
      taskable_id?: number
      overdue?: boolean
      from?: string
      to?: string
    }
  | {
      query: 'activity_list'
      q?: string
      kind?: string
      user_id?: number
      activityable_type?: string
      activityable_id?: number
      from?: string
      to?: string
    }
  | {
      query: 'ticket_list'
      q?: string
      status?: string
      priority?: string
      assigned_to?: number
      company_id?: number
      contact_id?: number
      category?: string
      tag_id?: number
      sla_breached?: boolean
      from?: string
      to?: string
    }
  | { query: 'ticket_stats' }
  | {
      query: 'quote_list'
      q?: string
      status?: string
      deal_id?: number
      company_id?: number
      contact_id?: number
      expired?: boolean
      from?: string
      to?: string
    }
  | { query: 'quote_revision_family'; root_number: string }
  | { query: 'conversation_list'; kind?: string; q?: string }
  | { query: 'conversation_messages'; conversation_id: number; before_server_id?: number }
  | { query: 'conversation_membership'; user_id?: number; conversation_id?: number }
  | { query: 'notification_list'; read?: ReadFilter }
  | { query: 'pipeline_stages' }
  | {
      query: 'product_list'
      q?: string
      category?: string
      is_active?: boolean
      tag_id?: number
      price_min?: number
      price_max?: number
      in_stock?: boolean
    }
  | { query: 'product_categories' }
  | { query: 'price_list_list'; q?: string; is_active?: boolean; is_default?: boolean }
  | { query: 'price_list_item_list'; price_list_id?: number; product_id?: number }
  | { query: 'exchange_rate_list' }
  | { query: 'saved_view_list'; module?: string }
  | { query: 'setting_list' }
  | { query: 'tag_list'; q?: string }
  | { query: 'custom_field_list'; entity_type?: string }
  | { query: 'user_list'; q?: string; is_active?: boolean }
  | { query: 'related_counts'; scope: CountScope; parent_ids: number[] }
  | { query: 'pending_rows'; entity: EntityName }

/** `syncra_sync::db::query::QueryParams`. */
export interface QueryParams {
  limit?: number
  offset?: number
  sort_by?: string
  sort_dir?: 'asc' | 'desc'
  include_tombstones?: boolean
  /** Ask for `[{ total }]` instead of the page. See the Rust field's doc comment. */
  count_only?: boolean
}

/** `syncra_sync::Op`. */
export type MutationOp = 'create' | 'update' | 'action' | 'delete'

/** `syncra_sync::LocalMutation`. */
export interface LocalMutation {
  entity: EntityName
  op: MutationOp
  action?: string
  client_id?: string
  changed_fields?: string[]
  payload?: unknown
}

/** `syncra_sync::SearchHit`. */
export interface SearchHit {
  entity: EntityName
  client_id: string
  title: string
  snippet: string
}

/** One mirror row, exactly as the crate marshalled it: column name -> JSON value. */
export type LocalRow = Record<string, unknown>

// ------------------------------------------------------------------------------------------------
// Commands
// ------------------------------------------------------------------------------------------------

/** Run a whitelisted query and return the page. */
export function runQuery(query: NamedQuery, params: QueryParams = {}): Promise<LocalRow[]> {
  return invokeCommand<LocalRow[]>('query', { query, params })
}

/**
 * Row count for the same filters, for `meta.pagination`.
 *
 * The count and the page are built from the *same* `NamedQuery` value, so a filter can never
 * apply to one and not the other.
 */
export async function countRows(query: NamedQuery): Promise<number> {
  const rows = await runQuery(query, { count_only: true })
  return toInt(rows[0]?.total) ?? 0
}

/** Queue a mutation: applied to the local mirror and pushed on the next sync round. */
export function mutate(mutation: LocalMutation): Promise<string> {
  return invokeCommand<string>('mutate', { mutation })
}

/** Local FTS. */
export function searchLocal(
  fts: string,
  entities: EntityName[],
  limit: number,
): Promise<SearchHit[]> {
  return invokeCommand<SearchHit[]>('search', { fts, entities, limit })
}

// ------------------------------------------------------------------------------------------------
// Identity
// ------------------------------------------------------------------------------------------------

/**
 * The `id` a feature DTO should carry for this row.
 *
 * A row created offline has no `server_id` yet, but every DTO types `id` as a number and the
 * router builds `/deals/:id` out of it. `-local_rowid` is a placeholder the engine round-trips
 * (`NamedQuery::RowsByServerIds` accepts negative ids), so a record created offline stays
 * openable until the push gives it a real id. It is meaningful **only inside one installation**
 * and only until then.
 */
export function rowId(row: LocalRow): number {
  const serverId = toInt(row.server_id)
  if (serverId !== undefined && serverId > 0) return serverId
  const localRowid = toInt(row.local_rowid)
  return localRowid === undefined ? 0 : -localRowid
}

/** `notifications` is keyed by a UUID string (protocol §6.1 P12), not by a numeric id. */
export function rowClientId(row: LocalRow): string {
  return typeof row.client_id === 'string' ? row.client_id : ''
}

/** Fetch rows by the id a DTO exposes (server id, or `-local_rowid`). */
export async function rowsByIds(entity: EntityName, ids: number[]): Promise<LocalRow[]> {
  const unique = [...new Set(ids.filter((id) => Number.isFinite(id) && id !== 0))]
  if (unique.length === 0) return []
  return runQuery({ query: 'rows_by_server_ids', entity, ids: unique }, { limit: unique.length })
}

/** Fetch one row by the id a DTO exposes; `null` when the mirror does not have it. */
export async function rowById(entity: EntityName, id: number): Promise<LocalRow | null> {
  const rows = await rowsByIds(entity, [id])
  return rows[0] ?? null
}

/**
 * Resolve a DTO id back to the local `client_id` a mutation has to address.
 *
 * Every write goes through this: `LocalMutation.client_id` is the engine's stable identity,
 * while the UI only ever holds the numeric id from the DTO it rendered.
 */
export async function clientIdFor(entity: EntityName, id: number): Promise<string> {
  const row = await rowById(entity, id)
  const clientId = row ? rowClientId(row) : ''
  if (!clientId) {
    throw new MissingRowError(entity, id)
  }
  return clientId
}

/**
 * Raised when a write targets a row the local mirror does not have.
 *
 * Distinct from a generic failure on purpose: the cause is almost always "this record has not
 * been pulled yet", which is actionable (sync, or widen the retention window) in a way that a
 * bare error is not. The code is what `desktop.errors.<code>` renders.
 */
export class MissingRowError extends Error {
  readonly code = 'ROW_NOT_LOCAL'

  constructor(entity: EntityName, id: number) {
    super(`${entity}#${id} is not in the local mirror`)
    this.name = 'MissingRowError'
  }
}

// ------------------------------------------------------------------------------------------------
// Paging
// ------------------------------------------------------------------------------------------------

/** Page size used when a caller omits `per_page`; matches Laravel's `paginate()` default. */
export const DEFAULT_PER_PAGE = 15

/**
 * The largest page the crate will return (`syncra_sync::db::query::MAX_LIMIT`).
 *
 * Used by the reads that have no pager of their own — option pickers, a record's timeline,
 * a conversation's members — where "everything" is the honest request and the ceiling is the
 * engine's, not this layer's.
 */
export const MAX_PAGE = 500

/** The `?page&per_page&sort` triple every list screen sends. */
export interface PageQuery {
  page?: number
  per_page?: number
  sort?: string
}

/** The pagination envelope every list DTO carries. */
export interface Pagination {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

/**
 * Translate `sort=-created_at` into the enum-constrained pair the crate accepts.
 *
 * An unknown column yields `undefined`, which makes the query fall back to its natural order
 * — the same forgiving behaviour the backend repositories have (`DealRepository::applySort`).
 */
export function sortParams(sort: string | undefined): Pick<QueryParams, 'sort_by' | 'sort_dir'> {
  if (!sort) return {}
  const desc = sort.startsWith('-')
  const column = desc ? sort.slice(1) : sort
  if (!column) return {}
  return { sort_by: column, sort_dir: desc ? 'desc' : 'asc' }
}

/** `QueryParams` for one page of a list screen. */
export function pageParams(query: PageQuery): QueryParams {
  const perPage = query.per_page ?? DEFAULT_PER_PAGE
  const page = Math.max(1, query.page ?? 1)
  return { limit: perPage, offset: (page - 1) * perPage, ...sortParams(query.sort) }
}

/** The `meta.pagination` object for a page whose filters matched `total` rows. */
export function pagination(query: PageQuery, total: number): Pagination {
  const perPage = query.per_page ?? DEFAULT_PER_PAGE
  return {
    current_page: Math.max(1, query.page ?? 1),
    per_page: perPage,
    total,
    last_page: Math.max(1, Math.ceil(total / perPage)),
  }
}

/**
 * Run one page and its count together and wrap the result in the list envelope.
 *
 * The two commands are issued concurrently: they are independent reads of the same snapshot,
 * and serialising them would double the latency of every list screen for nothing.
 */
export async function listPage<T>(
  query: NamedQuery,
  pageQuery: PageQuery,
  map: (rows: LocalRow[]) => Promise<T[]> | T[],
): Promise<{ data: T[]; meta: { pagination: Pagination } }> {
  const [rows, total] = await Promise.all([
    runQuery(query, pageParams(pageQuery)),
    countRows(query),
  ])
  return { data: await map(rows), meta: { pagination: pagination(pageQuery, total) } }
}

// ------------------------------------------------------------------------------------------------
// Scalar coercion
//
// SQLite stores the CRM's decimals as TEXT and its booleans as INTEGER, while the feature DTOs
// type them as `number` and `boolean`. These four helpers are the single place that gap is
// closed; scattering `Number(...)` through the mappers is how a `"0.00"` ends up rendering as
// `NaN` in one column and `0` in the next.
// ------------------------------------------------------------------------------------------------

/** JSON value -> number, or `undefined` when it is not numeric. */
export function toNumber(value: unknown): number | undefined {
  if (typeof value === 'number') return Number.isFinite(value) ? value : undefined
  if (typeof value === 'string' && value.trim() !== '') {
    const parsed = Number(value)
    return Number.isFinite(parsed) ? parsed : undefined
  }
  return undefined
}

/** JSON value -> integer, or `undefined`. */
export function toInt(value: unknown): number | undefined {
  const parsed = toNumber(value)
  return parsed === undefined ? undefined : Math.trunc(parsed)
}

/** JSON value -> number, defaulting to `0`. Decimal columns are never null in practice. */
export function num(value: unknown): number {
  return toNumber(value) ?? 0
}

/** JSON value -> boolean. SQLite has no boolean type; `1`/`0` and `"1"`/`"0"` all arrive. */
export function bool(value: unknown): boolean {
  if (typeof value === 'boolean') return value
  const parsed = toNumber(value)
  if (parsed !== undefined) return parsed !== 0
  return false
}

/** JSON value -> string, or `null`. Empty strings stay empty; only null/undefined become null. */
export function str(value: unknown): string | null {
  if (value === null || value === undefined) return null
  return typeof value === 'string' ? value : String(value)
}

/** JSON value -> string, defaulting to `''`. */
export function text(value: unknown): string {
  return str(value) ?? ''
}

/** An embedded `custom_fields` document (protocol §1.5) as the DTOs expect it. */
export function customFields(value: unknown): Record<string, string> {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {}
  const out: Record<string, string> = {}
  for (const [key, raw] of Object.entries(value as Record<string, unknown>)) {
    if (raw === null || raw === undefined) continue
    out[key] = typeof raw === 'string' ? raw : String(raw)
  }
  return out
}

/** An embedded `tags` document (protocol §1.4): the owning row carries tag **server ids**. */
export function tagIds(value: unknown): number[] {
  if (!Array.isArray(value)) return []
  return value.map((entry) => toInt(entry)).filter((id): id is number => id !== undefined)
}

/** An embedded `quotes.items` document (protocol §1.5). */
export function embeddedItems(value: unknown): LocalRow[] {
  if (!Array.isArray(value)) return []
  return value.filter((entry): entry is LocalRow => !!entry && typeof entry === 'object')
}

/** A fresh local identity for a row created offline. */
export function newClientId(): string {
  return crypto.randomUUID()
}
