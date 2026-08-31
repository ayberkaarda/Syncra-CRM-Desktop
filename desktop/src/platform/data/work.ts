// `DataSource` implementations for tasks, tickets, quotes and activities.
//
// The one domain with online-only members is `quotes`: `send`, `revise`, `calculate` and the
// PDF are all on the `SYNCDESKTOP.md` §8 list and go to `platform.http`, never to `mutate()`.
// Queuing them would put the user in front of a "sent" quote that no customer received.
import type { Activity, ActivitiesListResponse } from '@/features/activities/types'
import type {
  Quote,
  QuoteCalculateResult,
  QuotesListResponse,
} from '@/features/quotes/types'
import type { Task, TasksCalendarResponse, TasksListResponse } from '@/features/tasks/types'
import type { Ticket, TicketsListResponse, TicketStats } from '@/features/tickets/types'
import type {
  ActivitiesSource,
  QuotesSource,
  TasksSource,
  TicketsSource,
} from '@/platform/types'

import { http } from '../http'
import { listPage, MAX_PAGE, num, runQuery, toInt, type EntityName, type LocalRow } from './engine'
import { loadRefs, loadRefsByIds, type RefIndex } from './refs'
import { loadTagRefs } from './crm'
import {
  mapActivity,
  mapQuote,
  mapTask,
  mapTicket,
  shortMorph,
  type MorphRefs,
} from './mappers'
import { createRow, deleteRow, readBack, runAction, updateRow, type WritePayload } from './writes'

// ------------------------------------------------------------------------------------------------
// Polymorphic relations
// ------------------------------------------------------------------------------------------------

/** The morph targets a `taskable`/`activityable` may point at, per the backend whitelist. */
const MORPH_ENTITIES: Record<string, EntityName> = {
  deal: 'deal',
  lead: 'lead',
  contact: 'contact',
  company: 'company',
  ticket: 'ticket',
}

/**
 * Resolve every related-record label a page needs, one query per distinct type.
 *
 * A page of tasks can point at five different tables, so this groups by type first rather
 * than issuing a lookup per row.
 */
async function loadMorphs(rows: LocalRow[], typeCol: string, idCol: string): Promise<MorphRefs> {
  const byType = new Map<string, Set<number>>()
  for (const row of rows) {
    const type = shortMorph(row[typeCol])
    const id = toInt(row[idCol])
    if (!type || id === undefined || id <= 0 || !MORPH_ENTITIES[type]) continue
    const bucket = byType.get(type) ?? new Set<number>()
    bucket.add(id)
    byType.set(type, bucket)
  }

  const entries = await Promise.all(
    [...byType].map(async ([type, ids]): Promise<[string, RefIndex]> => [
      type,
      await loadRefsByIds(MORPH_ENTITIES[type], [...ids]),
    ]),
  )
  return new Map(entries)
}

async function taskRefs(rows: LocalRow[]) {
  // Assignee and creator both point at `users`; one index resolves both.
  const [users, morphs] = await Promise.all([
    loadRefs(
      'user',
      rows,
      ['assigned_to', 'assigned_to_client_id'],
      ['created_by', 'created_by_client_id'],
    ),
    loadMorphs(rows, 'taskable_type', 'taskable_id'),
  ])
  return { users, morphs }
}

// ------------------------------------------------------------------------------------------------
// Tasks
// ------------------------------------------------------------------------------------------------

export const tasksSource: TasksSource = {
  list: async (query): Promise<TasksListResponse> =>
    listPage(
      {
        query: 'task_list',
        q: query.q,
        status: query.status,
        priority: query.priority,
        assigned_to: query.assigned_to,
        created_by: query.created_by,
        taskable_type: query.taskable_type,
        taskable_id: query.taskable_id,
        overdue: query.overdue,
        from: query.from,
        to: query.to,
      },
      query,
      async (rows) => {
        const refs = await taskRefs(rows)
        return rows.map((row) => mapTask(row, refs))
      },
    ),

  /** The calendar has no pager: it is bounded by `from`/`to`, and its meta says so. */
  calendar: async (query): Promise<TasksCalendarResponse> => {
    const rows = await runQuery(
      {
        query: 'task_list',
        assigned_to: query.assigned_to,
        status: query.status,
        priority: query.priority,
        from: query.from,
        to: query.to,
      },
      { limit: MAX_PAGE },
    )
    const refs = await taskRefs(rows)
    const data = rows.map((row) => mapTask(row, refs))
    return { data, meta: { from: query.from, to: query.to, count: data.length } }
  },

  get: async (id): Promise<Task> => {
    const row = await readBack('task', id)
    return mapTask(row, await taskRefs([row]))
  },

  create: async (payload): Promise<Task> => {
    const clientId = await createRow('task', payload as WritePayload)
    const row = await readBack('task', clientId)
    return mapTask(row, await taskRefs([row]))
  },

  update: async (id, payload): Promise<Task> => {
    await updateRow('task', id, payload as WritePayload)
    const row = await readBack('task', id)
    return mapTask(row, await taskRefs([row]))
  },

  delete: (id) => deleteRow('task', id),

  /**
   * `task.complete` is on the crate's action whitelist and has a local effect the applier
   * knows (`status = completed`, `completed_at = now`). Un-completing is a plain field
   * update: there is no `uncomplete` action on the wire.
   *
   * `CompleteTaskRequest` (`backend/app/Http/Requests/Tasks/CompleteTaskRequest.php`) requires
   * `completed` as a boolean — an empty payload is rejected server-side ("tamamlanma durumu
   * alanı zorunludur").
   */
  complete: async (id, completed): Promise<Task> => {
    if (completed) {
      await runAction('task', id, 'complete', { completed: true })
    } else {
      await updateRow('task', id, { status: 'pending', completed_at: null })
    }
    const row = await readBack('task', id)
    return mapTask(row, await taskRefs([row]))
  },

  assign: async (id, assignedTo): Promise<Task> => {
    await runAction('task', id, 'assign', { assigned_to: assignedTo })
    const row = await readBack('task', id)
    return mapTask(row, await taskRefs([row]))
  },

  userOptions: async () => {
    const rows = await runQuery({ query: 'user_list', is_active: true }, { limit: MAX_PAGE })
    return rows.map((row) => ({ id: toInt(row.server_id) ?? 0, name: String(row.name ?? '') }))
  },
}

// ------------------------------------------------------------------------------------------------
// Activities
// ------------------------------------------------------------------------------------------------

async function activityRefs(rows: LocalRow[]) {
  const [users, morphs] = await Promise.all([
    loadRefs('user', rows, ['user_id', 'user_client_id']),
    loadMorphs(rows, 'activityable_type', 'activityable_id'),
  ])
  return { users, morphs }
}

export const activitiesSource: ActivitiesSource = {
  list: async (query): Promise<ActivitiesListResponse> =>
    listPage(
      {
        query: 'activity_list',
        q: query.q,
        kind: query.type,
        user_id: query.user_id,
        activityable_type: query.activityable_type,
        activityable_id: query.activityable_id,
        from: query.from,
        to: query.to,
      },
      query,
      async (rows) => {
        const refs = await activityRefs(rows)
        return rows.map((row) => mapActivity(row, refs))
      },
    ),

  create: async (payload): Promise<Activity> => {
    const clientId = await createRow('activity', payload as WritePayload)
    const row = await readBack('activity', clientId)
    return mapActivity(row, await activityRefs([row]))
  },

  update: async (id, payload): Promise<Activity> => {
    await updateRow('activity', id, payload as WritePayload)
    const row = await readBack('activity', id)
    return mapActivity(row, await activityRefs([row]))
  },

  delete: (id) => deleteRow('activity', id),
}

// ------------------------------------------------------------------------------------------------
// Tickets
// ------------------------------------------------------------------------------------------------

async function ticketRefs(rows: LocalRow[]) {
  const [companies, contacts, users, tags] = await Promise.all([
    loadRefs('company', rows, ['company_id', 'company_client_id']),
    loadRefs('contact', rows, ['contact_id', 'contact_client_id']),
    loadRefs(
      'user',
      rows,
      ['assigned_to', 'assigned_to_client_id'],
      ['created_by', 'created_by_client_id'],
    ),
    loadTagRefs(rows),
  ])
  return { companies, contacts, users, tags }
}

export const ticketsSource: TicketsSource = {
  list: async (query): Promise<TicketsListResponse> =>
    listPage(
      {
        query: 'ticket_list',
        q: query.q,
        status: query.status,
        priority: query.priority,
        assigned_to: query.assigned_to,
        company_id: query.company_id,
        contact_id: query.contact_id,
        category: query.category,
        tag_id: query.tag_id,
        sla_breached: query.sla_breached,
        from: query.from,
        to: query.to,
      },
      query,
      async (rows) => {
        const refs = await ticketRefs(rows)
        return rows.map((row) => mapTicket(row, refs))
      },
    ),

  /**
   * `GET /api/tickets/stats` is filter-independent, so it comes from a dedicated aggregate
   * rather than from a page.
   *
   * `at_risk_count` reports `0`: "at risk" is a server-side threshold on the SLA countdown
   * (`docs/SLA-DESIGN.md`), and the countdown itself is not mirrored — see `mapTicket`.
   * `breached_count` uses the definition the ticket type file gives for an open ticket, which
   * needs only `sla_due_at` and the status.
   */
  stats: async (): Promise<TicketStats> => {
    const [row] = await runQuery({ query: 'ticket_stats' }, {})
    const at = (key: string) => num(row?.[key])
    return {
      total: at('total'),
      by_status: {
        open: at('status_open'),
        pending: at('status_pending'),
        in_progress: at('status_in_progress'),
        resolved: at('status_resolved'),
        closed: at('status_closed'),
      },
      by_priority: {
        low: at('priority_low'),
        normal: at('priority_normal'),
        high: at('priority_high'),
        urgent: at('priority_urgent'),
      },
      breached_count: at('breached_count'),
      at_risk_count: 0,
      // `null` means "no resolved ticket yet" and is NOT `0` — see the type file.
      avg_resolution_hours:
        row?.avg_resolution_hours === null || row?.avg_resolution_hours === undefined
          ? null
          : num(row.avg_resolution_hours),
    }
  },

  get: async (id): Promise<Ticket> => {
    const row = await readBack('ticket', id)
    return mapTicket(row, await ticketRefs([row]))
  },

  create: async (payload): Promise<Ticket> => {
    const clientId = await createRow('ticket', tagColumn(payload as WritePayload))
    const row = await readBack('ticket', clientId)
    return mapTicket(row, await ticketRefs([row]))
  },

  update: async (id, payload): Promise<Ticket> => {
    await updateRow('ticket', id, tagColumn(payload as WritePayload))
    const row = await readBack('ticket', id)
    return mapTicket(row, await ticketRefs([row]))
  },

  delete: (id) => deleteRow('ticket', id),

  /** The only way a ticket status changes (`docs/SLA-DESIGN.md` §4); a whitelisted action. */
  status: async (id, status): Promise<Ticket> => {
    await runAction('ticket', id, 'status', { status })
    const row = await readBack('ticket', id)
    return mapTicket(row, await ticketRefs([row]))
  },

  assign: async (id, assignedTo): Promise<Ticket> => {
    await runAction('ticket', id, 'assign', { assigned_to: assignedTo })
    const row = await readBack('ticket', id)
    return mapTicket(row, await ticketRefs([row]))
  },
}

/** See `crm.ts` — `tag_ids` is the REST field, `tags` the mirror column; both travel. */
function tagColumn(payload: WritePayload): WritePayload {
  if (!('tag_ids' in payload)) return payload
  return { ...payload, tags: payload.tag_ids }
}

// ------------------------------------------------------------------------------------------------
// Quotes
// ------------------------------------------------------------------------------------------------

async function quoteRefs(rows: LocalRow[]) {
  const [deals, companies, contacts, users] = await Promise.all([
    loadRefs('deal', rows, ['deal_id', 'deal_client_id']),
    loadRefs('company', rows, ['company_id', 'company_client_id']),
    loadRefs('contact', rows, ['contact_id', 'contact_client_id']),
    loadRefs('user', rows, ['created_by', 'created_by_client_id']),
  ])
  return { deals, companies, contacts, users }
}

export const quotesSource: QuotesSource = {
  list: async (query): Promise<QuotesListResponse> =>
    listPage(
      {
        query: 'quote_list',
        q: query.q,
        status: query.status,
        deal_id: query.deal_id,
        company_id: query.company_id,
        contact_id: query.contact_id,
        expired: query.expired,
        from: query.from,
        to: query.to,
      },
      query,
      async (rows) => {
        const refs = await quoteRefs(rows)
        // The REST list endpoint sends `items: null`; the detail endpoint sends the array.
        return rows.map((row) => mapQuote(row, refs, false))
      },
    ),

  /** Line items come out of the row's own `items` document (protocol §1.5), not a join. */
  get: async (id): Promise<Quote> => {
    const row = await readBack('quote', id)
    return mapQuote(row, await quoteRefs([row]), true)
  },

  create: async (payload): Promise<Quote> => {
    const clientId = await createRow('quote', quoteColumns(payload as WritePayload))
    const row = await readBack('quote', clientId)
    return mapQuote(row, await quoteRefs([row]), true)
  },

  update: async (id, payload): Promise<Quote> => {
    await updateRow('quote', id, quoteColumns(payload as WritePayload))
    const row = await readBack('quote', id)
    return mapQuote(row, await quoteRefs([row]), true)
  },

  delete: (id) => deleteRow('quote', id),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8) — `quote.send` is absent from the action whitelist. */
  send: (id) => http.post<{ data: Quote }>(`/api/quotes/${id}/send`).then((body) => body.data),

  /** `quote.status` IS whitelisted, so a status change survives being made offline. */
  status: async (id, status, reason): Promise<Quote> => {
    await runAction('quote', id, 'status', { status, reason: reason || undefined })
    const row = await readBack('quote', id)
    return mapQuote(row, await quoteRefs([row]), true)
  },

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8) — the server decides whether a new revision is created. */
  revise: (id) => http.post<{ data: Quote }>(`/api/quotes/${id}/revise`).then((body) => body.data),

  revisionFamily: async (rootNumber): Promise<Quote[]> => {
    const rows = await runQuery(
      { query: 'quote_revision_family', root_number: rootNumber },
      { limit: MAX_PAGE },
    )
    const refs = await quoteRefs(rows)
    return rows.map((row) => mapQuote(row, refs, false))
  },

  /**
   * ONLINE-ONLY (`SYNCDESKTOP.md` §8). `docs/QUOTE-FINANCIALS.md` is the single source for the
   * arithmetic and it is explicitly not to be copied — a second implementation would drift on
   * rounding long before anyone noticed it had.
   */
  calculate: (payload, options) =>
    http
      .post<{ data: QuoteCalculateResult }>('/api/quotes/calculate', payload, {
        signal: options?.signal,
      })
      .then((body) => body.data),

  /**
   * ONLINE-ONLY when uncached (`SYNCDESKTOP.md` §8). The `files::cache_quote_pdf` /
   * `files::open_cached` pair that serves the cached copy is F5-5; until it exists this always
   * goes to the network, which fails loudly offline rather than showing a stale document.
   */
  pdfBlob: (id) => http.get<Blob>(`/api/quotes/${id}/pdf`, { responseType: 'blob' }),
}

/**
 * Quote payloads carry `items`, which is both the REST field and the mirror column
 * (protocol §1.5 — there is no `quote_items` table), so it needs no translation. Only the
 * empty-payload guard is worth stating.
 */
function quoteColumns(payload: WritePayload): WritePayload {
  return payload
}
