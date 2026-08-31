// Reference resolution for the local mirror.
//
// The REST resources the web talks to embed their relations (`owner: {id, name}`,
// `company: {id, name}`, `tags: [...]`); the mirror stores foreign keys instead. This module
// turns a page of rows into the lookup tables the mappers need, with **one query per relation
// per page** rather than one per row — a 50-row list would otherwise cost 200 round trips.
//
// Every foreign key is carried twice (`docs/DESKTOP-SYNC-PROTOCOL.md` §5.3): the server id
// (`company_id`) and the resolved local reference (`company_client_id`). Both are indexed
// here, because a record created offline points at a parent that may itself still be offline
// and therefore has no server id at all.
import {
  runQuery,
  toInt,
  type CountScope,
  type EntityName,
  type LocalRow,
  type NamedQuery,
} from './engine'

/** Rows of one related table, addressable by either identity. */
export class RefIndex {
  private readonly byServer = new Map<number, LocalRow>()
  private readonly byClient = new Map<string, LocalRow>()

  constructor(rows: LocalRow[]) {
    for (const row of rows) {
      const serverId = toInt(row.server_id)
      if (serverId !== undefined) this.byServer.set(serverId, row)
      if (typeof row.client_id === 'string') this.byClient.set(row.client_id, row)
    }
  }

  /** The related row, preferring the server id and falling back to the local reference. */
  resolve(serverId: unknown, clientId: unknown): LocalRow | null {
    const id = toInt(serverId)
    if (id !== undefined) {
      const hit = this.byServer.get(id)
      if (hit) return hit
    }
    if (typeof clientId === 'string') {
      const hit = this.byClient.get(clientId)
      if (hit) return hit
    }
    return null
  }

  /** The related row for a bare server id. */
  byId(serverId: unknown): LocalRow | null {
    const id = toInt(serverId)
    return id === undefined ? null : (this.byServer.get(id) ?? null)
  }
}

/** An index with nothing in it — for the rare mapper that has no relation to resolve. */
export const EMPTY_REFS = new RefIndex([])

/** One foreign key on the owning row: the server id column and its local counterpart. */
export type FkColumns = readonly [serverCol: string, clientCol?: string]

/**
 * Load every row `rows` points at through the given foreign keys.
 *
 * Several keys can share one call — `tasks` points at `users` twice (assignee and creator),
 * and resolving them together is one index and two queries instead of two indexes and four.
 * Two queries at most, and only the ones that have something to ask for.
 */
export async function loadRefs(
  entity: EntityName,
  rows: LocalRow[],
  ...keys: FkColumns[]
): Promise<RefIndex> {
  const serverIds = new Set<number>()
  const clientIds = new Set<string>()

  for (const row of rows) {
    for (const [serverCol, clientCol] of keys) {
      const id = toInt(row[serverCol])
      if (id !== undefined && id > 0) serverIds.add(id)
      if (clientCol) {
        const clientId = row[clientCol]
        if (typeof clientId === 'string' && clientId) clientIds.add(clientId)
      }
    }
  }

  return loadRefsByIds(entity, [...serverIds], [...clientIds])
}

/** Load related rows from explicit id lists. */
export async function loadRefsByIds(
  entity: EntityName,
  serverIds: number[],
  clientIds: string[] = [],
): Promise<RefIndex> {
  const queries: NamedQuery[] = []
  if (serverIds.length > 0) queries.push({ query: 'rows_by_server_ids', entity, ids: serverIds })
  if (clientIds.length > 0) queries.push({ query: 'rows_by_client_ids', entity, client_ids: clientIds })
  if (queries.length === 0) return EMPTY_REFS

  const pages = await Promise.all(
    queries.map((query, index) =>
      runQuery(query, {
        limit: index === 0 ? serverIds.length : clientIds.length,
        // A parent the user deleted locally still has to render inside the child it is
        // named on; hiding it would silently blank the column instead.
        include_tombstones: true,
      }),
    ),
  )
  return new RefIndex(pages.flat())
}

/** `{parent server id -> child row count}` for one whitelisted relation. */
export async function loadCounts(
  scope: CountScope,
  parentIds: number[],
): Promise<Map<number, number>> {
  const unique = [...new Set(parentIds.filter((id) => Number.isFinite(id) && id > 0))]
  const counts = new Map<number, number>()
  if (unique.length === 0) return counts

  const rows = await runQuery({ query: 'related_counts', scope, parent_ids: unique }, {})
  for (const row of rows) {
    const parent = toInt(row.parent_id)
    const total = toInt(row.total)
    if (parent !== undefined && total !== undefined) counts.set(parent, total)
  }
  return counts
}
