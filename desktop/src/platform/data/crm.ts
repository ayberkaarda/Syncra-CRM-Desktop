// `DataSource` implementations for the four core CRM domains: deals, contacts, companies,
// leads.
//
// Reads run against the local mirror through a `NamedQuery`; writes go to `mutate()` and land
// in the outbox. The exceptions are `leads.convert` and `leads.checkDuplicates`, both of which
// go to `platform.http` — see their doc comments.
import type { CompaniesListResponse, TimelineListResponse } from '@/features/companies/api/companiesApi'
import type { DealsListResponse, DealPayload } from '@/features/deals/api/dealsApi'
import type { ContactsListResponse } from '@/features/contacts/api/contactsApi'
import type { LeadPayload } from '@/features/leads/api/leadsApi'
import type {
  CompaniesSource,
  ContactsSource,
  DealsSource,
  LeadsSource,
  WithSyncState,
} from '@/platform/types'
import type { TimelineItem } from '@/components/shared/Timeline'
import { recordSyncState } from '@/components/shared/recordSyncState'
import type { Company, ContactSummary } from '@/features/companies/types'
import type { Contact } from '@/features/contacts/types'
import type {
  ConvertLeadResult,
  CustomField as LeadCustomField,
  DuplicateCandidate,
  Lead,
} from '@/features/leads/types'
import type { CustomFieldDef as ContactCustomFieldDef } from '@/features/contacts/types'
import type {
  BoardColumn,
  BoardFilters,
  BoardResponse,
  Deal,
  DealCard,
  DealStatus,
  PipelineStage,
} from '@/features/deals/types'

import { http } from '../http'
import {
  bool,
  countRows,
  listPage,
  MAX_PAGE,
  num,
  pagination,
  rowId,
  runQuery,
  str,
  text,
  toInt,
  type LocalRow,
  type NamedQuery,
} from './engine'
import { loadCounts, loadRefs, loadRefsByIds, EMPTY_REFS } from './refs'
import {
  activityTimelineItem,
  fullNameRef,
  mapCompany,
  mapContact,
  mapContactSummary,
  mapDeal,
  mapLead,
  mapPipelineStage,
  nameRef,
  tagList,
  taskTimelineItem,
} from './mappers'
import {
  createRow,
  deleteRow,
  readBack,
  runAction,
  updateRow,
  type WritePayload,
} from './writes'

// ------------------------------------------------------------------------------------------------
// Shared reference loading
// ------------------------------------------------------------------------------------------------

async function dealRefs(rows: LocalRow[]) {
  const [companies, contacts, users, stages, tags] = await Promise.all([
    loadRefs('company', rows, ['company_id', 'company_client_id']),
    loadRefs('contact', rows, ['contact_id', 'contact_client_id']),
    loadRefs('user', rows, ['owner_id', 'owner_client_id']),
    loadRefs('pipeline_stage', rows, ['pipeline_stage_id', 'pipeline_stage_client_id']),
    loadTagRefs(rows),
  ])
  return { companies, contacts, users, stages, tags }
}

/** Resolve every tag id embedded in a page of rows (protocol §1.4 — there is no join table). */
export async function loadTagRefs(rows: LocalRow[]) {
  const ids = new Set<number>()
  for (const row of rows) {
    if (!Array.isArray(row.tags)) continue
    for (const raw of row.tags) {
      const id = typeof raw === 'number' ? raw : Number(raw)
      if (Number.isFinite(id)) ids.add(id)
    }
  }
  return ids.size === 0 ? EMPTY_REFS : loadRefsByIds('tag', [...ids])
}

// ------------------------------------------------------------------------------------------------
// Timelines
//
// The REST timeline is a server-side union of the record's activities and tasks. There is no
// union query in the whitelist and there does not need to be: the two lists are read
// separately and merged here, which keeps the SQL simple and the merge rule visible.
// ------------------------------------------------------------------------------------------------

/** Page size the record timelines use; the REST endpoint pages the same way. */
const TIMELINE_PER_PAGE = 20

async function recordTimeline(
  morph: 'contact' | 'company',
  id: number,
  page: number,
): Promise<TimelineListResponse> {
  const activityQuery: NamedQuery = {
    query: 'activity_list',
    activityable_type: morph,
    activityable_id: id,
  }
  const taskQuery: NamedQuery = { query: 'task_list', taskable_type: morph, taskable_id: id }

  const [activityRows, taskRows] = await Promise.all([
    runQuery(activityQuery, { limit: MAX_PAGE }),
    runQuery(taskQuery, { limit: MAX_PAGE }),
  ])

  const users = await loadRefsByIds(
    'user',
    [
      ...activityRows.map((row) => Number(row.user_id)),
      ...taskRows.map((row) => Number(row.assigned_to)),
    ].filter((value) => Number.isFinite(value) && value > 0),
  )

  const items: TimelineItem[] = [
    ...activityRows.map((row) => activityTimelineItem(row, users)),
    ...taskRows.map((row) => taskTimelineItem(row, users)),
  ].sort((a, b) => b.occurred_at.localeCompare(a.occurred_at))

  const start = (Math.max(1, page) - 1) * TIMELINE_PER_PAGE
  return {
    data: items.slice(start, start + TIMELINE_PER_PAGE),
    meta: { pagination: pagination({ page, per_page: TIMELINE_PER_PAGE }, items.length) },
  }
}

// ------------------------------------------------------------------------------------------------
// Deals
// ------------------------------------------------------------------------------------------------

/**
 * `tag_ids` is the REST payload field; `tags` is the mirror column. Both are sent: the local
 * applier writes only real columns and the server reads only what its FormRequest validates,
 * so carrying both keeps the offline row and the pushed mutation in agreement without a
 * second payload shape.
 */
function withTagColumn(payload: WritePayload): WritePayload {
  if (!('tag_ids' in payload)) return payload
  return { ...payload, tags: payload.tag_ids }
}

/** Relations one page of board cards needs; the same set a page of deals needs. */
type BoardRefs = Awaited<ReturnType<typeof dealRefs>>

/**
 * `DealService::board()` hard-codes `'TRY'` as the board currency, and nothing in the sync
 * scope carries a per-board currency to read instead. Transcribed rather than guessed.
 */
const BOARD_CURRENCY = 'TRY'

/** `BoardDealRequest::DEFAULT_PER_STAGE`. */
const BOARD_PER_STAGE = 50

/**
 * The cards of one stage, filtered exactly as `DealRepository::applyFilters()` filters them
 * for `GET /api/deals/board`.
 *
 * **No `status` filter, on purpose.** The REST board lists a stage's cards whatever their
 * status, which is what puts a won card in the "Won" column; `NamedQuery::DealsBoard` pins
 * `status = 'open'` and carries none of these filters, so it cannot reproduce that response
 * and is not what this reads through.
 *
 * One known divergence: the server's `q` also matches the company name through a relation
 * (`orWhereHas('company')`), while the local `deals_list` matches `title` and `description`.
 * The mirror has no join to widen it with, and narrowing the local search to `title` alone
 * would drop hits the server returns.
 */
function boardCardsQuery(
  filters: BoardFilters,
  narrow: { stage_id?: number; status?: string } = {},
): NamedQuery {
  return {
    query: 'deals_list',
    q: filters.q || undefined,
    stage_id: narrow.stage_id,
    status: narrow.status,
    owner_id: filters.owner_id,
    company_id: filters.company_id,
    from: filters.from || undefined,
    to: filters.to || undefined,
  }
}

/**
 * `DealCardResource::isOverdue()` - a card is late only while it is still open.
 *
 * Compared at LOCAL midnight (`today()` on the server, `setHours(0,0,0,0)` here) so a card due
 * today never reads as overdue; `expected_close_date` is sliced to its date part because the
 * mirror keeps whatever the column held, which may be a full timestamp.
 */
function isOverdue(status: DealStatus, expectedCloseDate: string | null): boolean {
  if (status !== 'open' || !expectedCloseDate) return false
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const due = new Date(`${expectedCloseDate.slice(0, 10)}T00:00:00`)
  return Number.isFinite(due.getTime()) && due < today
}

/**
 * Mirror row -> `DealCardResource`.
 *
 * A card is NOT a trimmed `Deal`: it carries `pipeline_stage_id` as a bare id (not the nested
 * stage), drops `description`/`custom_fields`/`closed_at`, and exposes a two-ability `can`
 * rather than four. `mappers.ts` owns `mapDeal` for the detail shape; the card shape stays
 * here because the board is what this module serves.
 *
 * `can` is permissive for the same reason every other mapper's is (`mappers.ts` header):
 * row-level permissions are not mirrored, and the push endpoint is the authority.
 */
function boardCard(row: LocalRow, refs: BoardRefs): WithSyncState<DealCard> {
  const status = (text(row.status) || 'open') as DealStatus
  const expectedCloseDate = str(row.expected_close_date)
  const stageRow = refs.stages.resolve(row.pipeline_stage_id, row.pipeline_stage_client_id)
  return {
    id: rowId(row),
    title: text(row.title),
    amount: num(row.amount),
    currency: text(row.currency),
    // The stage the card is filed under. Resolved through the same index the detail mapper
    // uses, so a row that carries only the local reference still reports a real id.
    pipeline_stage_id: toInt(row.pipeline_stage_id) ?? (stageRow ? rowId(stageRow) : 0),
    // A fractional index, and a STRING on purpose (`features/deals/types.ts`).
    position: text(row.position),
    version: toInt(row.version) ?? 1,
    probability: toInt(row.probability) ?? null,
    expected_close_date: expectedCloseDate,
    status,
    company: nameRef(refs.companies.resolve(row.company_id, row.company_client_id)),
    contact: fullNameRef(refs.contacts.resolve(row.contact_id, row.contact_client_id)),
    owner: nameRef(refs.users.resolve(row.owner_id, row.owner_client_id)),
    tags: tagList(row, refs.tags),
    is_overdue: isOverdue(status, expectedCloseDate),
    can: { update: true, move: true },
    // Local truth, not a server field — same validated `row.sync_state` read `mappers.ts`
    // uses for every other DTO. That reader is private to `mappers.ts` (not exported), so this
    // reuses the identical, already-shared validator `recordSyncState` (`components/shared/`)
    // instead of duplicating the check; `recordSyncState` returns `null` for a missing/invalid
    // value where the field type wants `undefined`, hence the `?? undefined`.
    sync_state: recordSyncState(row) ?? undefined,
  }
}

/** The one read both stage callers issue; `{ limit: MAX_PAGE }` is applied at each call site. */
const STAGE_ROWS: NamedQuery = { query: 'pipeline_stages' }

/**
 * ACTIVE pipeline stages, in the order the mirror returned them.
 *
 * ONE filter/map serves two callers on purpose: the board's columns and `deals.stages()`. On
 * the server they are literally the same list — `GET /api/pipeline-stages` defaults
 * `include_inactive` to FALSE on the board route, and `DealService::board()` builds its columns
 * from that same active set — so a second, subtly different reading here is how a stage would
 * end up as a board column but not as an option in the stage filter above it. (The `runQuery`
 * call itself stays at each call site rather than moving in here: `check-data-wiring.mjs`
 * classifies a method by the reads its own body performs.)
 *
 * Ordering is the crate's: `NamedQuery::PipelineStages` declares `("position", Asc)` as its
 * default sort (`db/query.rs`), which is `PipelineStageService::list()`'s `orderBy('position')`.
 *
 * `mapPipelineStage` carries `name_key` (mirrored since migration `0002`). It must survive:
 * a NON-NULL `name_key` means the stage belongs to the core taxonomy and its heading is
 * rendered through `enums:pipelineStage.<name_key>` (`utils/stageLabel.ts`); dropping it would
 * print the raw stored name and un-translate every default column.
 */
function activeStagesOrdered(rows: LocalRow[]): PipelineStage[] {
  const stages: PipelineStage[] = []
  for (const row of rows) {
    if (!bool(row.is_active)) continue
    const stage = mapPipelineStage(row)
    if (stage) stages.push(stage)
  }
  return stages
}

export const dealsSource: DealsSource = {
  list: async (query): Promise<DealsListResponse> =>
    listPage(
      {
        query: 'deals_list',
        q: query.q,
        status: query.status,
        stage_id: query.stage_id,
        owner_id: query.owner_id,
        company_id: query.company_id,
        contact_id: query.contact_id,
        tag_id: query.tag_id,
        amount_min: query.amount_min,
        amount_max: query.amount_max,
        from: query.from,
        to: query.to,
      },
      query,
      async (rows) => {
        const refs = await dealRefs(rows)
        return rows.map((row) => mapDeal(row, refs))
      },
    ),

  get: async (id): Promise<Deal> => {
    const row = await readBack('deal', id)
    const refs = await dealRefs([row])
    return mapDeal(row, refs)
  },

  create: async (payload: DealPayload): Promise<Deal> => {
    const clientId = await createRow('deal', withTagColumn(payload as WritePayload))
    const row = await readBack('deal', clientId)
    return mapDeal(row, await dealRefs([row]))
  },

  update: async (id, payload): Promise<Deal> => {
    await updateRow('deal', id, withTagColumn(payload as WritePayload))
    const row = await readBack('deal', id)
    return mapDeal(row, await dealRefs([row]))
  },

  delete: (id) => deleteRow('deal', id),

  assign: async (id, ownerId): Promise<Deal> => {
    await runAction('deal', id, 'assign', { owner_id: ownerId })
    const row = await readBack('deal', id)
    return mapDeal(row, await dealRefs([row]))
  },

  /**
   * `GET /api/deals/board`, rebuilt from the mirror - F4's offline Kanban.
   *
   * The shape follows `DealService::board()` step for step, so no board component has to know
   * which platform produced the response:
   *
   * * columns = ACTIVE `pipeline_stages` in `position` order (`activeStagesOrdered()`);
   * * `deals` = that stage's cards in fractional-index order, capped at `per_stage`;
   * * `meta.count` / `meta.total_amount` = the stage's totals over EVERY matching card, not
   *   just the loaded page (`boardAggregates()`), which is why the count is its own
   *   `count_only` read;
   * * `meta.has_more` = more matching cards exist than were loaded;
   * * `meta.total_open_amount` = open cards across all stages, stage filter deliberately
   *   absent (`totalOpenAmount()`).
   *
   * References are resolved ONCE for the whole board rather than per column: `dealRefs` costs
   * five reads, and paying that per stage would make a six-column board thirty reads heavier
   * for exactly the same index.
   *
   * Known ceiling: a stage with more than `MAX_PAGE` matching cards contributes only those to
   * `total_amount` (`meta.count` stays exact - it is counted, not measured). The crate has no
   * aggregate query to sum with, and adding one is outside this change.
   */
  board: async (filters): Promise<BoardResponse> => {
    const perStage = Math.max(1, filters.per_stage ?? BOARD_PER_STAGE)
    const stages = activeStagesOrdered(await runQuery(STAGE_ROWS, { limit: MAX_PAGE }))

    const loaded = await Promise.all(
      stages.map(async (stage) => {
        const query = boardCardsQuery(filters, { stage_id: stage.id })
        const [rows, count] = await Promise.all([
          runQuery(query, { limit: MAX_PAGE, sort_by: 'position', sort_dir: 'asc' }),
          countRows(query),
        ])
        return { stage, rows, count }
      }),
    )

    const openRows = await runQuery(boardCardsQuery(filters, { status: 'open' }), {
      limit: MAX_PAGE,
    })
    const refs = await dealRefs(loaded.flatMap((column) => column.rows))

    const data: BoardColumn[] = loaded.map(({ stage, rows, count }) => ({
      stage,
      deals: rows.slice(0, perStage).map((row) => boardCard(row, refs)),
      meta: {
        count,
        total_amount: rows.reduce((sum, row) => sum + num(row.amount), 0),
        has_more: count > perStage,
      },
    }))

    return {
      data,
      meta: {
        currency: BOARD_CURRENCY,
        total_open_amount: openRows.reduce((sum, row) => sum + num(row.amount), 0),
      },
    }
  },

  /**
   * `PATCH /api/deals/{id}/move`, offline. NOTHING is sent here: the row moves in the mirror
   * and the mutation is queued as `deal.move`, which the next sync round pushes.
   *
   * ## The payload carries two names for one thing, deliberately
   *
   * `to_stage_id` is the WIRE field (KARAR P20, `MoveDealRequest:44`; the spec's older
   * `pipeline_stage_id` was wrong and was once copied into a crate fixture, which is why the
   * distinction is spelled out here rather than assumed). `pipeline_stage_id` is the MIRROR
   * COLUMN. `local::apply` writes only payload keys that are real columns, so `to_stage_id`
   * alone would leave the card in its old column offline; `MoveDealRequest` declares no
   * `pipeline_stage_id` rule, so the server drops it from `validated()` and the move still
   * runs through `DealMoveService` untouched. Same two-names-one-payload shape as
   * `withTagColumn` above, and for the same reason.
   *
   * `pipeline_stage_client_id` is deliberately left alone. It is the second half of the FK
   * pair (protocol §5.3) and goes stale for exactly as long as the move is pending, which
   * costs nothing here: every read path prefers the server id (`RefIndex.resolve`,
   * `deals_list`'s `stage_id`), and the pull that follows the push rewrites both halves.
   *
   * `position` is NOT sent and NOT written locally: `MoveDealRequest` marks it `prohibited`,
   * so a payload carrying it would be rejected with a 422 and the move would be lost. The
   * consequence is honest and bounded - offline this call owns the COLUMN a card is in, never
   * its rank inside that column; the fractional index is generated under a row lock on the
   * server and reaches the mirror on the pull that follows the push.
   *
   * ## Snap-back
   *
   * The board renders the mirror, so there is no second copy to unwind. If the server refuses
   * the move (`DEAL_VERSION_CONFLICT`, a policy denial, a validation refusal) the push marks
   * the row `conflict` and files it in the Conflict Inbox with the server's own row attached
   * (`MutationApplier::fromHttpResponse` puts `DealCardResource` in `server_row`). Settling it
   * - `Resolution::TakeServer`, the inbox's `desktop:conflicts.discard` - writes that row back
   * over the local one, restoring `pipeline_stage_id` and `position`, and emits
   * `TablesChanged{deal}`, which `bridge/events.ts` maps to `['deals','board']`. The board
   * refetches from the mirror and the card is back in its old column. There is no
   * board-specific rollback path, because a second one would be a second truth.
   */
  move: async (id, payload): Promise<DealCard> => {
    await runAction('deal', id, 'move', { ...payload, pipeline_stage_id: payload.to_stage_id })
    const row = await readBack('deal', id)
    return boardCard(row, await dealRefs([row]))
  },

  /**
   * `GET /api/pipeline-stages`, rebuilt from the mirror — the board's stage filter, the deal
   * form's stage select and the deals list's stage column, all offline.
   *
   * `pipeline_stages` is a read-only mirror table that is NOT windowed (`SYNCDESKTOP.md` §4.1,
   * K2), so the local copy is the whole set and this is the complete answer, not a recent
   * slice of one.
   */
  stages: async (): Promise<PipelineStage[]> =>
    activeStagesOrdered(await runQuery(STAGE_ROWS, { limit: MAX_PAGE })),

  /**
   * `GET /api/users?per_page=100`, rebuilt from the mirror — the board's owner filter, the
   * deal form's owner select and the assign-owner modal, all offline.
   *
   * The SHARED `userOptions()` helper, not a copy: `contacts.userOptions`,
   * `companies.userOptions` and `leads.ownerOptions` already route through it, and five
   * separate readings of the same `user_list` query is how one screen ends up offering an
   * owner another screen does not. `users` is a read-only, non-windowed projection
   * (`SYNCDESKTOP.md` §4.1, K2), so the local copy is the whole set.
   */
  ownerOptions: () => userOptions(),
}

// ------------------------------------------------------------------------------------------------
// Contacts
// ------------------------------------------------------------------------------------------------

async function contactRefs(rows: LocalRow[], withCounts: boolean) {
  const ids = rows.map(rowId).filter((id) => id > 0)
  const [companies, users, tags, dealCounts, ticketCounts] = await Promise.all([
    loadRefs('company', rows, ['company_id', 'company_client_id']),
    loadRefs('user', rows, ['owner_id', 'owner_client_id']),
    loadTagRefs(rows),
    withCounts ? loadCounts('contact_deals', ids) : new Map<number, number>(),
    withCounts ? loadCounts('contact_tickets', ids) : new Map<number, number>(),
  ])
  return { companies, users, tags, dealCounts, ticketCounts }
}

export const contactsSource: ContactsSource = {
  list: async (query): Promise<ContactsListResponse> =>
    listPage(
      {
        query: 'contact_list',
        q: query.q,
        company_id: query.company_id,
        owner_id: query.owner_id,
        is_primary: query.is_primary,
        city: query.city,
        tag_id: query.tag_id,
        from: query.from,
        to: query.to,
      },
      query,
      async (rows) => {
        const refs = await contactRefs(rows, true)
        return rows.map((row) => mapContact(row, refs))
      },
    ),

  get: async (id): Promise<Contact> => {
    const row = await readBack('contact', id)
    return mapContact(row, await contactRefs([row], true))
  },

  timeline: (id, page) => recordTimeline('contact', id, page),

  create: async (payload): Promise<Contact> => {
    const clientId = await createRow('contact', withTagColumn(payload as WritePayload))
    const row = await readBack('contact', clientId)
    return mapContact(row, await contactRefs([row], false))
  },

  update: async (id, payload): Promise<Contact> => {
    await updateRow('contact', id, withTagColumn(payload as WritePayload))
    const row = await readBack('contact', id)
    return mapContact(row, await contactRefs([row], false))
  },

  delete: (id) => deleteRow('contact', id),

  tags: async () => {
    const rows = await runQuery({ query: 'tag_list' }, { limit: MAX_PAGE })
    return rows.map((row) => ({ id: rowId(row), name: String(row.name ?? ''), color: String(row.color ?? '') }))
  },

  customFields: () => customFieldDefs('contacts'),

  companyOptions: async (search) => {
    const rows = await runQuery({ query: 'company_list', q: search }, { limit: 20 })
    return rows.map((row) => ({ id: rowId(row), name: String(row.name ?? '') }))
  },

  allCompanyOptions: async () => {
    const rows = await runQuery({ query: 'company_list' }, { limit: 100 })
    return rows.map((row) => ({ id: rowId(row), name: String(row.name ?? '') }))
  },

  userOptions: () => userOptions(),
}

// ------------------------------------------------------------------------------------------------
// Companies
// ------------------------------------------------------------------------------------------------

async function companyRefs(rows: LocalRow[], withCounts: boolean, primaryContacts?: Map<number, LocalRow>) {
  const ids = rows.map(rowId).filter((id) => id > 0)
  const [users, tags, contactCounts, dealCounts] = await Promise.all([
    loadRefs('user', rows, ['owner_id', 'owner_client_id']),
    loadTagRefs(rows),
    withCounts ? loadCounts('company_contacts', ids) : new Map<number, number>(),
    withCounts ? loadCounts('company_deals', ids) : new Map<number, number>(),
  ])
  return { users, tags, contactCounts, dealCounts, primaryContacts }
}

export const companiesSource: CompaniesSource = {
  list: async (query): Promise<CompaniesListResponse> =>
    listPage(
      {
        query: 'company_list',
        q: query.q,
        industry: query.industry,
        owner_id: query.owner_id,
        city: query.city,
        country: query.country,
        tag_id: query.tag_id,
        from: query.from,
        to: query.to,
      },
      query,
      async (rows) => {
        const refs = await companyRefs(rows, true)
        return rows.map((row) => mapCompany(row, refs))
      },
    ),

  get: async (id): Promise<Company> => {
    const row = await readBack('company', id)
    // The detail path can afford the one extra query the list path cannot (see `mapCompany`).
    const primaryRows = await runQuery(
      { query: 'contact_list', company_id: id, is_primary: true },
      { limit: 1 },
    )
    const primaryContacts = new Map<number, LocalRow>()
    if (primaryRows[0]) primaryContacts.set(id, primaryRows[0])
    return mapCompany(row, await companyRefs([row], true, primaryContacts))
  },

  timeline: (id, page) => recordTimeline('company', id, page),

  contacts: async (id): Promise<ContactSummary[]> => {
    const rows = await runQuery({ query: 'contact_list', company_id: id }, { limit: MAX_PAGE })
    const summaries = rows.map(mapContactSummary)
    // The REST endpoint hoists the primary contact to the top; the list screens rely on it.
    return summaries.sort((a, b) => Number(b.is_primary) - Number(a.is_primary))
  },

  create: async (payload): Promise<Company> => {
    const clientId = await createRow('company', withTagColumn(payload as WritePayload))
    const row = await readBack('company', clientId)
    return mapCompany(row, await companyRefs([row], false))
  },

  update: async (id, payload): Promise<Company> => {
    await updateRow('company', id, withTagColumn(payload as WritePayload))
    const row = await readBack('company', id)
    return mapCompany(row, await companyRefs([row], false))
  },

  delete: (id) => deleteRow('company', id),

  tags: async () => {
    const rows = await runQuery({ query: 'tag_list' }, { limit: MAX_PAGE })
    return rows.map((row) => ({ id: rowId(row), name: String(row.name ?? ''), color: String(row.color ?? '') }))
  },

  customFields: () => customFieldDefs('companies'),

  userOptions: () => userOptions(),
}

// ------------------------------------------------------------------------------------------------
// Leads
// ------------------------------------------------------------------------------------------------

async function leadRefs(rows: LocalRow[]) {
  const [users, tags] = await Promise.all([
    loadRefs('user', rows, ['owner_id', 'owner_client_id']),
    loadTagRefs(rows),
  ])
  return { users, tags }
}

export const leadsSource: LeadsSource = {
  list: async (query) =>
    listPage(
      {
        query: 'lead_list',
        q: query.q,
        status: query.status,
        source: query.source,
        owner_id: query.owner_id,
        score_min: query.score_min,
        score_max: query.score_max,
        tag_id: query.tag_id,
        from: query.from,
        to: query.to,
      },
      query,
      async (rows) => {
        const refs = await leadRefs(rows)
        return rows.map((row) => mapLead(row, refs))
      },
    ),

  get: async (id): Promise<Lead> => {
    const row = await readBack('lead', id)
    return mapLead(row, await leadRefs([row]))
  },

  create: async (payload: LeadPayload): Promise<Lead> => {
    const clientId = await createRow('lead', withTagColumn(payload as WritePayload))
    const row = await readBack('lead', clientId)
    return mapLead(row, await leadRefs([row]))
  },

  update: async (id, payload): Promise<Lead> => {
    await updateRow('lead', id, withTagColumn(payload as WritePayload))
    const row = await readBack('lead', id)
    return mapLead(row, await leadRefs([row]))
  },

  delete: (id) => deleteRow('lead', id),

  /**
   * ONLINE-ONLY. Duplicate scoring is `Services/Leads/DuplicateDetector` — a server algorithm
   * with its own weighting of email/phone/name matches. Approximating it locally would
   * produce a *different* answer under the same name, which is the failure mode
   * `docs/QUOTE-FINANCIALS.md` and `docs/SLA-DESIGN.md` both legislate against for their own
   * numbers. Not on the `SYNCDESKTOP.md` §8 list because §8 enumerates user-facing actions;
   * this is a read with no local equivalent.
   */
  checkDuplicates: (input) =>
    http.post<{ data: DuplicateCandidate[] }>('/api/leads/check-duplicates', input).then((body) => body.data),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8) — `lead.convert` is absent from the action whitelist. */
  convert: (id, payload) =>
    http.post<{ data: ConvertLeadResult }>(`/api/leads/${id}/convert`, payload).then((body) => body.data),

  assign: async (id, ownerId): Promise<Lead> => {
    await runAction('lead', id, 'assign', { owner_id: ownerId })
    const row = await readBack('lead', id)
    return mapLead(row, await leadRefs([row]))
  },

  tags: async () => {
    const rows = await runQuery({ query: 'tag_list' }, { limit: 100 })
    return rows.map((row) => ({ id: rowId(row), name: String(row.name ?? ''), color: (row.color as string) ?? null }))
  },

  /**
   * `tags` is a read-write sync entity at the head of the push order (`topo_level` 0)
   * precisely so a tag created offline exists before the record that references it.
   */
  createTag: async (payload) => {
    const clientId = await createRow('tag', { name: payload.name, color: payload.color ?? null })
    const row = await readBack('tag', clientId)
    return { id: rowId(row), name: String(row.name ?? ''), color: (row.color as string) ?? null }
  },

  customFields: (entityType) => customFieldRecords(entityType),

  ownerOptions: () => userOptions(),
}

// ------------------------------------------------------------------------------------------------
// Shared lookups
// ------------------------------------------------------------------------------------------------

/**
 * `custom_fields.options` is a JSON array stored as text.
 *
 * The mirror keeps whatever the server sent; a value that will not parse yields "no options",
 * which renders a select with an empty list rather than throwing inside a list page.
 */
function fieldOptions(raw: unknown): string[] | null {
  if (Array.isArray(raw)) return raw.map(String)
  if (typeof raw !== 'string' || raw === '') return null
  try {
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed.map(String) : null
  } catch {
    return null
  }
}

/**
 * Custom field definitions, in the `CustomFieldDef` shape contacts and companies declare
 * (`label`, and `options?: string[]` — optional, never null).
 */
async function customFieldDefs(entityType: string): Promise<ContactCustomFieldDef[]> {
  const rows = await runQuery({ query: 'custom_field_list', entity_type: entityType }, { limit: MAX_PAGE })
  return rows.map((row) => {
    const options = fieldOptions(row.options)
    return {
      id: rowId(row),
      key: String(row.key ?? ''),
      label: String(row.name ?? ''),
      type: String(row.type ?? 'text'),
      ...(options === null ? {} : { options }),
    }
  })
}

/**
 * The same definitions in the richer `CustomField` shape leads declares (`name`,
 * `entity_type`, `is_required`, `position`, and `options: string[] | null`).
 */
async function customFieldRecords(entityType: string): Promise<LeadCustomField[]> {
  const rows = await runQuery({ query: 'custom_field_list', entity_type: entityType }, { limit: MAX_PAGE })
  return rows.map((row) => ({
    id: rowId(row),
    entity_type: String(row.entity_type ?? ''),
    name: String(row.name ?? ''),
    key: String(row.key ?? ''),
    type: (String(row.type ?? 'text') || 'text') as LeadCustomField['type'],
    options: fieldOptions(row.options),
    is_required: Boolean(row.is_required),
    position: Number(row.position ?? 0),
  }))
}

/** `{id, name}` options for owner/assignee pickers, from the `users` mirror projection. */
export async function userOptions() {
  const rows = await runQuery({ query: 'user_list', is_active: true }, { limit: MAX_PAGE })
  return rows.map((row) => ({ id: rowId(row), name: String(row.name ?? '') }))
}
