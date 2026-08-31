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
} from '@/platform/types'
import type { TimelineItem } from '@/components/shared/Timeline'
import type { Company, ContactSummary } from '@/features/companies/types'
import type { Contact } from '@/features/contacts/types'
import type {
  ConvertLeadResult,
  CustomField as LeadCustomField,
  DuplicateCandidate,
  Lead,
} from '@/features/leads/types'
import type { CustomFieldDef as ContactCustomFieldDef } from '@/features/contacts/types'
import type { Deal } from '@/features/deals/types'

import { http } from '../http'
import {
  listPage,
  MAX_PAGE,
  pagination,
  rowId,
  runQuery,
  type LocalRow,
  type NamedQuery,
} from './engine'
import { loadCounts, loadRefs, loadRefsByIds, EMPTY_REFS } from './refs'
import {
  activityTimelineItem,
  mapCompany,
  mapContact,
  mapContactSummary,
  mapDeal,
  mapLead,
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
