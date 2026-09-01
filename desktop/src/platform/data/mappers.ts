// Mirror row -> feature DTO.
//
// `SYNCDESKTOP.md` K1 and §7.1 are the constraint this file exists to satisfy: **the feature
// DTOs do not change**. The web talks to Laravel API resources that embed relations and carry
// server-computed fields; the mirror stores flat rows and foreign keys. Every gap between the
// two is closed here, in one place, so no component and no hook has to know which platform it
// is running on.
//
// ## The three embedded tables (protocol §1.4 / §1.5 / §6.2 P13)
//
// `taggables`, `quote_items` and `custom_field_values` are NOT mirror tables. They arrive
// inside the owning row:
//
// * `tags` — an array of tag **server ids**; [`tagList`] resolves them against the `tags`
//   mirror, which is why every mapper that produces tags takes a tag index.
// * `quotes.items` — the full line-item array; [`mapQuote`] reads it straight out of the
//   payload and never queries a `quote_items` table, because there is none.
// * `custom_fields` — already `{key: value}`, i.e. already the DTO shape.
//
// ## What the mirror cannot reconstruct
//
// A handful of DTO fields are computed by the server from data that is deliberately outside
// the sync scope. They are listed at each mapper with the value used instead; none of them is
// silently guessed. The two rules behind those choices:
//
// * **Never re-derive a financial or SLA number.** `docs/QUOTE-FINANCIALS.md` and
//   `docs/SLA-DESIGN.md` are single sources; `quotes.calculate` is online-only precisely so
//   the arithmetic is not duplicated. Where the server value is missing, the field is `null`
//   (a countdown that does not render beats a countdown that lies).
// * **`can.*` stays permissive.** Row-level permissions are not mirrored. Returning `false`
//   would make the desktop read-only offline, which is the opposite of the point; the
//   authority is the server, and `SYNCDESKTOP.md` §8 / KARAR A14 keeps three independent
//   layers of defence behind it — the last of which is the push endpoint rejecting the write.
import type { Activity } from '@/features/activities/types'
import type { Attachment, ChatUser, Conversation, Message } from '@/features/chat/types'
import type { Company, ContactSummary } from '@/features/companies/types'
import type { Contact } from '@/features/contacts/types'
import type { Deal, DealStatus, PipelineStage } from '@/features/deals/types'
import type { Lead, LeadSource, LeadStatus } from '@/features/leads/types'
import { resolveNotificationText } from '@/features/notifications/notificationText'
import type { Notification, NotificationType } from '@/features/notifications/types'
import type { PriceList, PriceListItem } from '@/features/price-lists/types'
import type { Product } from '@/features/products/types'
import type { Quote, QuoteItem, QuoteStatus } from '@/features/quotes/types'
import type { SavedView, SavedViewModule, SavedViewQuery } from '@/features/saved-views/types'
import type { SearchResultItem, SearchResultType } from '@/features/search/types'
import type { Task, TaskableRef, TaskableType, TaskPriority, TaskStatus } from '@/features/tasks/types'
import type { Ticket, TicketPriority, TicketStatus } from '@/features/tickets/types'
import type { Role, User } from '@/features/users/types'
import type { TimelineItem } from '@/components/shared/Timeline'
import type { SyncState, WithSyncState } from '@/platform/types'

import {
  bool,
  customFields,
  embeddedItems,
  num,
  rowClientId,
  rowId,
  str,
  tagIds,
  text,
  toInt,
  toNumber,
  type LocalRow,
} from './engine'
import { EMPTY_REFS, type RefIndex } from './refs'
import { parseMirrorTimestamp } from './timestamps'

// ------------------------------------------------------------------------------------------------
// Shared shapes
// ------------------------------------------------------------------------------------------------

/**
 * The mirror row's own replication state (`SYNCDESKTOP.md` §5.3), carried into the DTO.
 *
 * This is the ONE field in this file that is not a reconstruction of something the server sent:
 * it is local truth, and it is the only way a shared list page can tell the user that the
 * company they edited on a plane is still sitting in the outbox. `SyncStateBadge` renders
 * nothing for `synced`/`undefined`, so a web DTO — which never gets this far — is unaffected.
 *
 * The value is validated rather than cast: `sync_state` is a `TEXT` column whose four legal
 * values are pinned by `CHECK(sync_state IN (...))` in `0001_init.sql`, and a row that somehow
 * carries anything else reports no state at all instead of a badge nobody can explain.
 */
function syncState(row: LocalRow): SyncState | undefined {
  const state = row.sync_state
  return state === 'synced' || state === 'pending' || state === 'conflict' || state === 'tombstone'
    ? state
    : undefined
}

/** `{id, name}` — owners, companies, creators, assignees. */
export function nameRef(row: LocalRow | null): { id: number; name: string } | null {
  if (!row) return null
  return { id: rowId(row), name: text(row.name) }
}

/** `{id, full_name}` — contacts, wherever the API embeds them. */
export function fullNameRef(row: LocalRow | null): { id: number; full_name: string } | null {
  if (!row) return null
  return { id: rowId(row), full_name: fullName(row) }
}

/** `{id, title}` — deals, wherever the API embeds them. */
export function titleRef(row: LocalRow | null): { id: number; title: string } | null {
  if (!row) return null
  return { id: rowId(row), title: text(row.title) }
}

/** The server's `full_name` accessor: first and last name, single-spaced. */
export function fullName(row: LocalRow): string {
  return [text(row.first_name), text(row.last_name)].filter(Boolean).join(' ')
}

/** Resolve an embedded `tags: [server ids]` document against the tag mirror. */
export function tagList(row: LocalRow, tags: RefIndex): { id: number; name: string; color: string }[] {
  return tagIds(row.tags)
    .map((id) => tags.byId(id))
    .filter((tag): tag is LocalRow => tag !== null)
    .map((tag) => ({ id: rowId(tag), name: text(tag.name), color: text(tag.color) }))
}

/**
 * Short morph name (`deal`, `contact`, …) from whatever the column holds.
 *
 * The database stores the fully-qualified class name — protocol §1.4 records that
 * `Relation::enforceMorphMap()` is never called — while the DTOs carry the short name the
 * backend's `MorphTargets` whitelist uses. A value that is already short passes through, so
 * this keeps working if the server ever starts sending short names.
 */
export function shortMorph(value: unknown): string | null {
  const raw = str(value)
  if (!raw) return null
  const tail = raw.split('\\').pop() ?? raw
  return tail.toLowerCase()
}

/**
 * `true` when a mirror timestamp is in the past.
 *
 * Reads through {@link parseMirrorTimestamp}, not `Date.parse` directly: `tasks.due_at` and
 * `tickets.sla_due_at` are `dateTime()` migration columns, so a pulled row holds the same
 * space-separated, zone-less `DATETIME` text `parseMirrorTimestamp`'s header describes — UTC
 * with nothing in the string saying so. `Date.parse` would read it as local time and shift
 * `is_overdue` by the host's UTC offset right at the deadline boundary. Date-only columns
 * (`expected_close_date`, `valid_until`) are unaffected: `parseMirrorTimestamp` only rewrites
 * strings that carry a time part, so those fall through to the same `Date.parse` reading they
 * always had — already correct, since ECMA-262 treats a bare date as UTC midnight.
 */
function isPast(value: unknown): boolean {
  const raw = str(value)
  if (!raw) return false
  const at = parseMirrorTimestamp(raw)
  return Number.isFinite(at) && at < Date.now()
}

// ------------------------------------------------------------------------------------------------
// Deals
// ------------------------------------------------------------------------------------------------

/** Relations one page of deals needs resolved. */
export interface DealRefs {
  companies: RefIndex
  contacts: RefIndex
  users: RefIndex
  stages: RefIndex
  tags: RefIndex
}

/**
 * `PipelineStageResource`.
 *
 * `name_key` is mirrored (migration `0002_ticket_sla_fields.sql` added the column; defter O7
 * follow-up). `null` means "customer-created stage, print `name` as-is" (`features/deals/
 * types.ts`) — a built-in taxonomy stage carries its key (e.g. `"qualified"`) and the UI
 * renders `enums:pipelineStage.<name_key>` for it, exactly like the web client.
 */
export function mapPipelineStage(row: LocalRow | null): PipelineStage | null {
  if (!row) return null
  return {
    id: rowId(row),
    name: text(row.name),
    name_key: str(row.name_key),
    slug: text(row.slug),
    position: num(row.position),
    probability: num(row.probability),
    color: str(row.color),
    is_won: bool(row.is_won),
    is_lost: bool(row.is_lost),
    is_active: bool(row.is_active),
  }
}

/**
 * `DealResource`.
 *
 * Not reconstructable: `related` (the C3 panel is loaded per-permission by the controller;
 * `undefined` is its documented "not loaded" state).
 */
export function mapDeal(row: LocalRow, refs: DealRefs): WithSyncState<Deal> {
  const status = (text(row.status) || 'open') as DealStatus
  return {
    id: rowId(row),
    title: text(row.title),
    description: str(row.description),
    amount: num(row.amount),
    currency: text(row.currency),
    // A fractional index, and a STRING on purpose (`features/deals/types.ts`).
    position: text(row.position),
    version: toInt(row.version) ?? 1,
    probability: toInt(row.probability) ?? null,
    expected_close_date: str(row.expected_close_date),
    status,
    lost_reason: str(row.lost_reason),
    won_reason: str(row.won_reason),
    closed_at: str(row.closed_at),
    pipeline_stage: mapPipelineStage(refs.stages.resolve(row.pipeline_stage_id, row.pipeline_stage_client_id)),
    company: nameRef(refs.companies.resolve(row.company_id, row.company_client_id)),
    contact: fullNameRef(refs.contacts.resolve(row.contact_id, row.contact_client_id)),
    owner: nameRef(refs.users.resolve(row.owner_id, row.owner_client_id)),
    tags: tagList(row, refs.tags).map((tag) => ({ id: tag.id, name: tag.name, color: tag.color })),
    custom_fields: customFields(row.custom_fields),
    // Server-derived. Offline there is no server value to defer to, and reporting `false`
    // would hide every overdue deal; the rule itself is unambiguous.
    is_overdue: status === 'open' && isPast(row.expected_close_date),
    created_at: str(row.created_at),
    updated_at: str(row.updated_at),
    can: { update: true, move: true, delete: true, assign: true },
    // Local truth, not a server field — see `syncState` above.
    sync_state: syncState(row),
  }
}

// ------------------------------------------------------------------------------------------------
// Contacts / companies
// ------------------------------------------------------------------------------------------------

/** Relations one page of contacts needs resolved. */
export interface ContactRefs {
  companies: RefIndex
  users: RefIndex
  tags: RefIndex
  /** `{contact server id -> deal count}`; empty on list pages that do not carry counts. */
  dealCounts?: Map<number, number>
  /** `{contact server id -> ticket count}`. */
  ticketCounts?: Map<number, number>
}

/** `ContactResource`. `related` is the controller's per-permission C3 panel — not mirrored. */
export function mapContact(row: LocalRow, refs: ContactRefs): WithSyncState<Contact> {
  const id = rowId(row)
  return {
    id,
    first_name: text(row.first_name),
    last_name: text(row.last_name),
    full_name: fullName(row),
    email: str(row.email),
    phone: str(row.phone),
    mobile: str(row.mobile),
    position: str(row.position),
    is_primary: bool(row.is_primary),
    address: str(row.address),
    city: str(row.city),
    country: str(row.country),
    notes: str(row.notes),
    company: nameRef(refs.companies.resolve(row.company_id, row.company_client_id)),
    owner: nameRef(refs.users.resolve(row.owner_id, row.owner_client_id)),
    tags: tagList(row, refs.tags),
    custom_fields: customFields(row.custom_fields),
    deals_count: refs.dealCounts?.get(id) ?? 0,
    tickets_count: refs.ticketCounts?.get(id) ?? 0,
    created_at: text(row.created_at),
    updated_at: text(row.updated_at),
    // Local truth, not a server field — see `syncState` above.
    sync_state: syncState(row),
  }
}

/** The mini contact table a company detail page renders. */
export function mapContactSummary(row: LocalRow): ContactSummary {
  return {
    id: rowId(row),
    first_name: text(row.first_name),
    last_name: text(row.last_name),
    full_name: fullName(row),
    email: str(row.email),
    phone: str(row.phone),
    position: str(row.position),
    is_primary: bool(row.is_primary),
  }
}

/** Relations one page of companies needs resolved. */
export interface CompanyRefs {
  users: RefIndex
  tags: RefIndex
  contactCounts?: Map<number, number>
  dealCounts?: Map<number, number>
  /** `{company server id -> primary contact row}`; only the detail path fills this. */
  primaryContacts?: Map<number, LocalRow>
}

/**
 * `CompanyResource`.
 *
 * `primary_contact` needs a per-company lookup, so it is resolved on the detail path only;
 * on a list page it is `null` rather than 50 extra queries.
 */
export function mapCompany(row: LocalRow, refs: CompanyRefs): WithSyncState<Company> {
  const id = rowId(row)
  const primary = refs.primaryContacts?.get(id) ?? null
  return {
    id,
    name: text(row.name),
    email: str(row.email),
    phone: str(row.phone),
    website: str(row.website),
    industry: str(row.industry),
    address: str(row.address),
    city: str(row.city),
    country: str(row.country),
    employee_count: toInt(row.employee_count) ?? null,
    annual_revenue: row.annual_revenue === null || row.annual_revenue === undefined ? null : num(row.annual_revenue),
    notes: str(row.notes),
    owner: nameRef(refs.users.resolve(row.owner_id, row.owner_client_id)),
    tags: tagList(row, refs.tags),
    custom_fields: customFields(row.custom_fields),
    contacts_count: refs.contactCounts?.get(id) ?? 0,
    deals_count: refs.dealCounts?.get(id) ?? 0,
    primary_contact: primary
      ? { id: rowId(primary), full_name: fullName(primary), email: str(primary.email) }
      : null,
    created_at: text(row.created_at),
    updated_at: text(row.updated_at),
    // Local truth, not a server field — see `syncState` above.
    sync_state: syncState(row),
  }
}

// ------------------------------------------------------------------------------------------------
// Leads
// ------------------------------------------------------------------------------------------------

/** Relations one page of leads needs resolved. */
export interface LeadRefs {
  users: RefIndex
  tags: RefIndex
}

/** `LeadResource`. */
export function mapLead(row: LocalRow, refs: LeadRefs): WithSyncState<Lead> {
  return {
    id: rowId(row),
    first_name: text(row.first_name),
    last_name: text(row.last_name),
    full_name: fullName(row),
    email: str(row.email),
    phone: str(row.phone),
    company_name: str(row.company_name),
    position: str(row.position),
    source: (text(row.source) || 'other') as LeadSource,
    status: (text(row.status) || 'new') as LeadStatus,
    score: num(row.score),
    notes: str(row.notes),
    owner: nameRef(refs.users.resolve(row.owner_id, row.owner_client_id)),
    tags: tagList(row, refs.tags).map((tag) => ({ id: tag.id, name: tag.name, color: tag.color })),
    custom_fields: customFields(row.custom_fields),
    converted_at: str(row.converted_at),
    converted_contact_id: toInt(row.converted_contact_id) ?? null,
    converted_company_id: toInt(row.converted_company_id) ?? null,
    converted_deal_id: toInt(row.converted_deal_id) ?? null,
    created_at: text(row.created_at),
    updated_at: text(row.updated_at),
    can: { update: true, convert: true, delete: true, assign: true },
    // Local truth, not a server field — see `syncState` above.
    sync_state: syncState(row),
  }
}

// ------------------------------------------------------------------------------------------------
// Tasks / activities
// ------------------------------------------------------------------------------------------------

/** Related-record labels, keyed by short morph name. */
export type MorphRefs = Map<string, RefIndex>

/** The label a `taskable`/`activityable` badge shows, per related-record type. */
function morphLabel(row: LocalRow): string | null {
  return (
    str(row.title) ??
    str(row.name) ??
    str(row.subject) ??
    (row.first_name === undefined ? null : fullName(row)) ??
    str(row.quote_number)
  )
}

/** `{type, id, label}` for a polymorphic relation. */
export function morphRef(typeValue: unknown, idValue: unknown, morphs: MorphRefs): TaskableRef | null {
  const type = shortMorph(typeValue)
  const id = toInt(idValue)
  if (!type || id === undefined) return null
  const target = morphs.get(type)?.byId(id) ?? null
  return { type: type as TaskableType, id, label: target ? morphLabel(target) : null }
}

/** Relations one page of tasks needs resolved. */
export interface TaskRefs {
  users: RefIndex
  morphs: MorphRefs
}

/** `TaskResource`. */
export function mapTask(row: LocalRow, refs: TaskRefs): WithSyncState<Task> {
  const status = (text(row.status) || 'pending') as TaskStatus
  return {
    id: rowId(row),
    title: text(row.title),
    description: str(row.description),
    due_at: str(row.due_at),
    reminder_at: str(row.reminder_at),
    priority: (text(row.priority) || 'normal') as TaskPriority,
    status,
    completed_at: str(row.completed_at),
    // Server-derived (`features/tasks/types.ts`). Offline the server value does not exist,
    // and an overdue task that does not look overdue is worse than an approximation whose
    // rule — past due date, not finished — is the same one the server applies.
    is_overdue: status !== 'completed' && status !== 'cancelled' && isPast(row.due_at),
    assignee: nameRef(refs.users.resolve(row.assigned_to, row.assigned_to_client_id)),
    creator: nameRef(refs.users.resolve(row.created_by, row.created_by_client_id)),
    taskable: morphRef(row.taskable_type, row.taskable_id, refs.morphs),
    created_at: str(row.created_at),
    updated_at: str(row.updated_at),
    can: { update: true, complete: true, delete: true, assign: true },
    // Local truth, not a server field — see `syncState` above.
    sync_state: syncState(row),
  }
}

/** `ActivityResource`. */
export function mapActivity(row: LocalRow, refs: TaskRefs): WithSyncState<Activity> {
  return {
    id: rowId(row),
    type: (text(row.type) || 'note') as Activity['type'],
    subject: text(row.subject),
    body: str(row.body),
    occurred_at: str(row.occurred_at),
    duration_minutes: toInt(row.duration_minutes) ?? null,
    outcome: str(row.outcome),
    user: nameRef(refs.users.resolve(row.user_id, row.user_client_id)),
    activityable: morphRef(row.activityable_type, row.activityable_id, refs.morphs),
    created_at: str(row.created_at),
    updated_at: str(row.updated_at),
    can: { update: true, delete: true },
    // Local truth, not a server field — see `syncState` above.
    sync_state: syncState(row),
  }
}

/** An activity or task, rendered as a record-timeline entry. */
export function activityTimelineItem(row: LocalRow, users: RefIndex): TimelineItem {
  return {
    type: 'activity',
    id: rowId(row),
    title: text(row.subject),
    description: str(row.body),
    icon_hint: str(row.type),
    occurred_at: text(row.occurred_at) || text(row.created_at),
    user: nameRef(users.resolve(row.user_id, row.user_client_id)),
    meta: {},
  }
}

/** A task, rendered as a record-timeline entry. */
export function taskTimelineItem(row: LocalRow, users: RefIndex): TimelineItem {
  return {
    type: 'task',
    id: rowId(row),
    title: text(row.title),
    description: str(row.description),
    icon_hint: str(row.status),
    occurred_at: text(row.due_at) || text(row.created_at),
    user: nameRef(users.resolve(row.assigned_to, row.assigned_to_client_id)),
    meta: { status: text(row.status), priority: text(row.priority) },
  }
}

// ------------------------------------------------------------------------------------------------
// Tickets
// ------------------------------------------------------------------------------------------------

/** Relations one page of tickets needs resolved. */
export interface TicketRefs {
  companies: RefIndex
  contacts: RefIndex
  users: RefIndex
  tags: RefIndex
}

/**
 * `TicketResource`.
 *
 * **The derived SLA fields stay server-owned** (`docs/SLA-DESIGN.md`, KARAR A26): the server
 * is the single authority for `sla_remaining_seconds`, `sla_total_seconds`, `sla_target_hours`
 * and `sla_breached` — this mapper only reads what `SyncPullService::attachTicketSla()`
 * (`backend/app/Services/Sync/SyncPullService.php`) already computed and attached to the
 * `tickets` pull row, via the SAME `SlaService` methods `TicketResource` uses, and never
 * re-derives the arithmetic itself.
 *
 * ⚠️ **`null` vs `0` is not interchangeable here.** `sla_remaining_seconds` is `null` when the
 * SLA clock has stopped (resolved/closed ticket, or no `sla_due_at`) and `0`-or-negative when
 * it is still running but the deadline has arrived or passed. `toInt(...) ?? null` is used
 * instead of `num(...)` (which defaults to `0`) or a truthy/`??`-only check, precisely so a
 * `0` remaining-seconds row is NOT collapsed into "no SLA" — see the file header and KARAR A23.
 * `sla_total_seconds` and `sla_target_hours` are never `null` on the wire (`SlaService` always
 * falls back to the priority target), so they default to `0` like every other decimal field
 * this mapper handles; `sla_breached` is always a wire `bool`.
 *
 * `sla_paused` has no server field of its own on the pull row — `SyncPullTicketSlaTest` lists
 * only the four fields above — so it stays the locally-defined equivalent the type file
 * documents ("`status === 'pending'` ile eşdeğer"). `notes_count` has no mirrored source and is
 * `0`.
 */
export function mapTicket(row: LocalRow, refs: TicketRefs): WithSyncState<Ticket> {
  const status = (text(row.status) || 'open') as TicketStatus
  return {
    id: rowId(row),
    ticket_number: text(row.ticket_number),
    subject: text(row.subject),
    description: text(row.description),
    priority: (text(row.priority) || 'normal') as TicketPriority,
    status,
    category: str(row.category),

    sla_due_at: str(row.sla_due_at),
    // Server-computed, never null on the wire (`SlaService::totalSeconds()` /
    // `targetHoursForTicket()` always fall back to the priority target) — `0` only when the
    // pull row predates this field (KARAR A26) and the mirror has not re-pulled yet.
    sla_total_seconds: toInt(row.sla_total_seconds) ?? 0,
    // `undefined` (missing/pre-A26 row) also reads as "no value", same as an explicit `null` —
    // both fall through to `null`, never to `0`.
    sla_remaining_seconds: toInt(row.sla_remaining_seconds) ?? null,
    sla_paused: status === 'pending',
    sla_breached: bool(row.sla_breached),
    sla_paused_seconds: num(row.sla_paused_seconds),
    sla_target_hours: toNumber(row.sla_target_hours) ?? 0,

    first_response_at: str(row.first_response_at),
    resolved_at: str(row.resolved_at),
    closed_at: str(row.closed_at),

    notes_count: 0,

    contact: fullNameRef(refs.contacts.resolve(row.contact_id, row.contact_client_id)),
    company: nameRef(refs.companies.resolve(row.company_id, row.company_client_id)),
    assignee: nameRef(refs.users.resolve(row.assigned_to, row.assigned_to_client_id)),
    creator: nameRef(refs.users.resolve(row.created_by, row.created_by_client_id)),
    tags: tagList(row, refs.tags).map((tag) => ({ id: tag.id, name: tag.name, color: tag.color })),
    custom_fields: customFields(row.custom_fields),

    created_at: str(row.created_at),
    updated_at: str(row.updated_at),
    can: { update: true, status: true, delete: true, assign: true },
    // Local truth, not a server field — see `syncState` above.
    sync_state: syncState(row),
  }
}

// ------------------------------------------------------------------------------------------------
// Quotes
// ------------------------------------------------------------------------------------------------

/** Relations one page of quotes needs resolved. */
export interface QuoteRefs {
  deals: RefIndex
  companies: RefIndex
  contacts: RefIndex
  users: RefIndex
}

/**
 * One embedded line item (protocol §1.5 — there is no `quote_items` table, locally or in the
 * pull set).
 *
 * `line_total` and `line_gross` come from the payload the server computed. They are NOT
 * recomputed here: `docs/QUOTE-FINANCIALS.md` is the single source, which is exactly why
 * `quotes.calculate` is online-only (`SYNCDESKTOP.md` §8).
 */
function mapQuoteItem(item: LocalRow, index: number): QuoteItem {
  return {
    id: rowId(item) || (toInt(item.id) ?? index + 1),
    product_id: toInt(item.product_id) ?? null,
    name: text(item.name),
    description: str(item.description),
    quantity: num(item.quantity),
    unit_price: num(item.unit_price),
    discount_percent: num(item.discount_percent),
    tax_rate: num(item.tax_rate),
    line_total: num(item.line_total),
    line_gross: num(item.line_gross),
    position: toInt(item.position) ?? index,
  }
}

/**
 * `QuoteResource`.
 *
 * `withItems` mirrors the REST contract: the list endpoint sends `items: null`, the detail
 * endpoint sends the array. `tax_breakdown` is always `null` — it is the calculator's output
 * and is not mirrored; reproducing it here would be the second implementation
 * `docs/QUOTE-FINANCIALS.md` forbids.
 */
export function mapQuote(row: LocalRow, refs: QuoteRefs, withItems: boolean): WithSyncState<Quote> {
  const items = embeddedItems(row.items)
  return {
    id: rowId(row),
    quote_number: text(row.quote_number),
    title: text(row.title),
    status: (text(row.status) || 'draft') as QuoteStatus,
    valid_until: str(row.valid_until),
    is_expired: isPast(row.valid_until),
    subtotal: num(row.subtotal),
    discount_type: (text(row.discount_type) || 'amount') as Quote['discount_type'],
    discount_value: num(row.discount_value),
    discount_amount: num(row.discount_amount),
    tax_amount: num(row.tax_amount),
    total: num(row.total),
    currency: text(row.currency),
    revision: toInt(row.revision) ?? 1,
    parent_quote_id: toInt(row.parent_quote_id) ?? null,
    notes: str(row.notes),
    terms: str(row.terms),
    sent_at: str(row.sent_at),
    accepted_at: str(row.accepted_at),
    rejected_at: str(row.rejected_at),
    deal: titleRef(refs.deals.resolve(row.deal_id, row.deal_client_id)),
    company: nameRef(refs.companies.resolve(row.company_id, row.company_client_id)),
    contact: fullNameRef(refs.contacts.resolve(row.contact_id, row.contact_client_id)),
    creator: nameRef(refs.users.resolve(row.created_by, row.created_by_client_id)),
    items: withItems ? items.map(mapQuoteItem) : null,
    tax_breakdown: null,
    items_count: items.length,
    created_at: text(row.created_at),
    updated_at: text(row.updated_at),
    // Local truth, not a server field — see `syncState` above.
    sync_state: syncState(row),
  }
}

// ------------------------------------------------------------------------------------------------
// Chat
// ------------------------------------------------------------------------------------------------

/** `ChatUser` — the projection §4.1 allows for `users`. */
export function chatUser(row: LocalRow | null): ChatUser | null {
  if (!row) return null
  return { id: rowId(row), name: text(row.name), email: text(row.email) }
}

/** Relations one page of conversations needs resolved. */
export interface ConversationRefs {
  users: RefIndex
  morphs: MorphRefs
  /** `conversation_user` rows for the conversations on this page. */
  membership: LocalRow[]
  /** The signed-in user's server id; `undefined` before the session is known. */
  sessionUserId?: number
}

/**
 * `ConversationResource`.
 *
 * Not reconstructable: `last_message_preview` (a per-conversation lookup of the newest
 * message body — `null` rather than one query per row). `display_name` is derived here the
 * way the server derives it: the explicit name, else the other party of a DM, else the
 * attached record's label.
 */
export function mapConversation(row: LocalRow, refs: ConversationRefs): Conversation {
  const conversationId = rowId(row)
  const members = refs.membership.filter((m) => toInt(m.conversation_id) === conversationId)
  const mine = members.find((m) => toInt(m.user_id) === refs.sessionUserId)
  const memberUsers = members
    .map((m) => chatUser(refs.users.resolve(m.user_id, m.user_client_id)))
    .filter((user): user is ChatUser => user !== null)
  const conversable = morphRef(row.conversable_type, row.conversable_id, refs.morphs)
  const others = memberUsers.filter((user) => user.id !== refs.sessionUserId)

  const name = str(row.name)
  const displayName = name ?? (others[0]?.name ?? conversable?.label ?? '')

  return {
    id: conversationId,
    type: (text(row.type) || 'group') as Conversation['type'],
    name,
    display_name: displayName,
    conversable: conversable ? { type: conversable.type, id: conversable.id, label: conversable.label ?? '' } : null,
    created_by: toInt(row.created_by) ?? null,
    last_message_at: str(row.last_message_at),
    last_message_preview: null,
    unread_count: mine ? num(mine.unread_count) : 0,
    is_muted: mine ? bool(mine.is_muted) : false,
    members: memberUsers,
  }
}

/**
 * `MessageResource`.
 *
 * Not reconstructable: `attachment` — `attachments` is explicitly outside the sync scope
 * (protocol §1.3), so an attached file has no local row and the field is `null`. `tick` is
 * always `'sent'`: the mirror carries `last_read_message_id` but no delivered cursor, and
 * `TickState` is monotonic (`features/chat/types.ts`), so reporting the lowest state is the
 * only value that a later realtime event can correct without ever moving backwards.
 */
export function mapMessage(row: LocalRow, users: RefIndex): Message {
  const attachment: Attachment | null = null
  return {
    id: rowId(row),
    conversation_id: toInt(row.conversation_id) ?? 0,
    user: chatUser(users.resolve(row.user_id, row.user_client_id)),
    body: str(row.body),
    type: (text(row.type) || 'text') as Message['type'],
    attachment,
    edited_at: str(row.edited_at),
    deleted_at: str(row.deleted_at),
    created_at: text(row.created_at),
    tick: 'sent',
  }
}

// ------------------------------------------------------------------------------------------------
// Notifications
// ------------------------------------------------------------------------------------------------

/**
 * The `data` document of a `notifications` mirror row, as an object.
 *
 * **The mirror hands this over as a JSON string, not as an object.** `notifications.data` is a
 * `TEXT` column (`migrations/0001_init.sql`) holding exactly what the server sent —
 * `SyncPullService::renderNotificationText()` writes it back with `json_encode()`, and the
 * crate's `json_to_sql()` stores a JSON document as its text. On the way out, `row_to_json()`
 * re-parses only the columns listed in the entity's `embedded` set (`db/schema.rs`), and
 * `Entity::Notification` declares `NO_EMBEDDED` — `tags`/`custom_fields`/`items` are decoded,
 * `data` is not. Reading it as an object therefore yielded `{}` for every real row, i.e. an
 * empty `title` and `body` on every notification (and, downstream, a `takeUnshown` that
 * dropped all of them on the empty-text guard).
 *
 * `mapSavedView` has the same shape for `query_json` and parses it the same way.
 *
 * Both shapes are accepted: the string the mirror actually stores, and the object a fixture —
 * or a future `embedded` entry — may supply. A document that will not decode, or that decodes
 * to something other than an object, degrades to `{}`: one malformed row must not take the
 * whole notification list down with it.
 */
function notificationData(raw: unknown): Record<string, unknown> {
  let value = raw
  if (typeof value === 'string') {
    if (value === '') return {}
    try {
      value = JSON.parse(value) as unknown
    } catch {
      return {}
    }
  }
  return value && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/**
 * `NotificationResource`.
 *
 * `client_id` **is** the id (protocol §6.1 P12: `notifications.id` is already a UUID), which
 * is why this is the one mapper that does not call `rowId`.
 *
 * `title`/`body` used to fall back straight to the raw `title_key`/`body_key` string when
 * `data.title`/`data.body` were absent (defter O61 / B3) — every key-mode row (all 12 types as
 * of Phase 14 / Track D) showed something like `notifications.deal_assigned.title` in the
 * notification list instead of a sentence. `resolveNotificationText()`
 * (`@/features/notifications/notificationText`) fixes the resolution order — key + params first,
 * plain `title`/`body` only as the legacy fallback — and is the one place this logic lives; see
 * that module's docblock for why the desktop needs its own resolution step at all (the web never
 * does: `NotificationResource` resolves server-side) and for the current content gap (the
 * sentence catalogue itself is not yet in `frontend/src/i18n/**`, so an unresolved key degrades
 * to a generic translated label today rather than a raw key).
 */
export function mapNotification(row: LocalRow): Notification {
  const data = notificationData(row.data)
  const meta = data.meta && typeof data.meta === 'object' ? (data.meta as Record<string, unknown>) : {}
  const resolved = resolveNotificationText(data)
  return {
    id: rowClientId(row),
    type: (str(data.type) ?? '') as NotificationType,
    title: resolved.title,
    body: resolved.body,
    link: str(data.link) ?? '',
    meta,
    read_at: str(row.read_at),
    created_at: text(row.created_at),
  }
}

// ------------------------------------------------------------------------------------------------
// Catalogue and settings
// ------------------------------------------------------------------------------------------------

/** `ProductResource`. */
export function mapProduct(row: LocalRow, tags: RefIndex): Product {
  return {
    id: rowId(row),
    name: text(row.name),
    sku: str(row.sku),
    description: str(row.description),
    category: str(row.category),
    unit_price: num(row.unit_price),
    currency: text(row.currency),
    tax_rate: num(row.tax_rate),
    unit: text(row.unit),
    // `null` means "stock is not tracked" and is NOT the same as `0` — see the type file.
    stock_quantity: row.stock_quantity === null || row.stock_quantity === undefined ? null : num(row.stock_quantity),
    is_active: bool(row.is_active),
    tags: tagList(row, tags).map((tag) => ({ id: tag.id, name: tag.name, color: tag.color })),
    custom_fields: customFields(row.custom_fields),
    created_at: str(row.created_at),
    updated_at: str(row.updated_at),
  }
}

/** `PriceListResource`. */
export function mapPriceList(row: LocalRow, itemCounts: Map<number, number>): PriceList {
  const id = rowId(row)
  return {
    id,
    name: text(row.name),
    code: text(row.code),
    description: str(row.description),
    currency: text(row.currency),
    is_default: bool(row.is_default),
    is_active: bool(row.is_active),
    valid_from: str(row.valid_from),
    valid_until: str(row.valid_until),
    items_count: itemCounts.get(id) ?? 0,
    created_at: str(row.created_at),
    updated_at: str(row.updated_at),
  }
}

/** `PriceListItemResource`; `catalog_price` is the product's own `unit_price`. */
export function mapPriceListItem(row: LocalRow, products: RefIndex): PriceListItem {
  const product = products.resolve(row.product_id, row.product_client_id)
  return {
    product_id: toInt(row.product_id) ?? (product ? rowId(product) : 0),
    product_name: product ? text(product.name) : null,
    product_sku: product ? str(product.sku) : null,
    unit_price: num(row.unit_price),
    catalog_price: product ? num(product.unit_price) : null,
    created_at: str(row.created_at),
  }
}

/** `SavedViewResource`. `query_json` is stored as text and parsed back here. */
export function mapSavedView(row: LocalRow, users: RefIndex, sessionUserId?: number): SavedView {
  let query: SavedViewQuery = {}
  const raw = row.query_json
  if (typeof raw === 'string' && raw !== '') {
    try {
      query = JSON.parse(raw) as SavedViewQuery
    } catch {
      // A view whose snapshot cannot be parsed still has to list; it just applies nothing.
      query = {}
    }
  } else if (raw && typeof raw === 'object') {
    query = raw as SavedViewQuery
  }

  const owner = users.resolve(row.user_id, row.user_client_id)
  return {
    id: rowId(row),
    module: (text(row.module) || 'deals') as SavedViewModule,
    name: text(row.name),
    query_json: query,
    is_shared: bool(row.is_shared),
    // A convenience flag; the real authorisation decision stays on the server (403/404).
    is_mine: sessionUserId !== undefined && toInt(row.user_id) === sessionUserId,
    owner_name: owner ? text(owner.name) : null,
    created_at: text(row.created_at),
    updated_at: text(row.updated_at),
  }
}

/**
 * `UserResource`, from the `users` projection §4.1 pins to
 * `id, name, email, avatar_url, is_active, department`.
 *
 * `must_change_password`, `last_login_at` and `role` are outside that projection — storing
 * them would widen a deliberately narrow mirror ("başka kolon YASAK"), so they report their
 * neutral values. This matters little in practice: `users.*` is online-only
 * (`SYNCDESKTOP.md` §8), and this mapper only backs owner/assignee pickers.
 */
export function mapUser(row: LocalRow): User {
  return {
    id: rowId(row),
    name: text(row.name),
    email: text(row.email),
    department: str(row.department),
    is_active: bool(row.is_active),
    must_change_password: false,
    last_login_at: null,
    created_at: text(row.created_at),
    role: null,
  }
}

/** A role, for the pickers that only need `{id, name}`. Roles are not mirrored. */
export function emptyRoles(): Role[] {
  return []
}

// ------------------------------------------------------------------------------------------------
// Search
// ------------------------------------------------------------------------------------------------

/** `entity` wire name -> the group key `SearchResponse` uses. */
export const SEARCH_GROUPS: Record<string, { type: SearchResultType; group: string; path: string }> = {
  deal: { type: 'deal', group: 'deals', path: '/deals' },
  lead: { type: 'lead', group: 'leads', path: '/leads' },
  contact: { type: 'contact', group: 'contacts', path: '/contacts' },
  company: { type: 'company', group: 'companies', path: '/companies' },
  quote: { type: 'quote', group: 'quotes', path: '/quotes' },
  ticket: { type: 'ticket', group: 'tickets', path: '/tickets' },
}

/** One local FTS hit, in the shape the command palette renders. */
export function mapSearchResult(entity: string, row: LocalRow): SearchResultItem | null {
  const meta = SEARCH_GROUPS[entity]
  if (!meta) return null
  const id = rowId(row)
  const title =
    str(row.title) ?? str(row.name) ?? str(row.subject) ?? (row.first_name === undefined ? null : fullName(row))
  const subtitle =
    str(row.quote_number) ?? str(row.ticket_number) ?? str(row.email) ?? str(row.company_name) ?? str(row.status)
  return {
    type: meta.type,
    id,
    title: title ?? '',
    subtitle,
    // `/users` has no detail route, which is why `user` is absent from SEARCH_GROUPS.
    link: `${meta.path}/${id}`,
  }
}

/** An index with nothing in it, for mappers that take one but have no relation on this path. */
export const NO_REFS = EMPTY_REFS
