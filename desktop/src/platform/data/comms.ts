// `DataSource` implementations for chat, notifications and global search.
//
// Two contract details shape most of this file:
//
// * **`notifications.client_id` IS the id** (protocol §6.1 P12). That table has no
//   `server_id` column, so every notification method addresses rows by their UUID string and
//   never goes through the numeric-id path the other domains use.
// * **`ACTION_WHITELIST` is the offline boundary.** `conversation.read` and
//   `conversation.delivered` are on it and survive being done offline; conversation
//   membership changes are not, and go to `platform.http` rather than into an outbox that
//   would report success the server never granted.
//
// The global search at the bottom is the one method in this file that is neither purely
// local nor purely remote: `SYNCDESKTOP.md` §7.2 asks for the local FTS index and the
// online server search **unified**, with every hit labelled by the index that produced it.
import type {
  Attachment,
  ChatUnreadCount,
  Conversation,
  ConversationCursorAck,
  Message,
  MessagesPage,
} from '@/features/chat/types'
import type { Notification, NotificationsListResponse } from '@/features/notifications/types'
import type { SearchGroupKey, SearchResponse, SearchResultItem } from '@/features/search/types'
import type {
  ChatSource,
  NotificationsSource,
  SearchResultSource,
  SearchSource,
  WithSearchSource,
} from '@/platform/types'

import { http } from '../http'
import { sessionUserId } from '../session'
import {
  countRows,
  MAX_PAGE,
  num,
  pagination,
  runQuery,
  searchLocal,
  toInt,
  type EntityName,
  type LocalRow,
  type NamedQuery,
} from './engine'
import { loadRefs, loadRefsByIds } from './refs'
import {
  mapConversation,
  mapMessage,
  mapNotification,
  mapSearchResult,
  SEARCH_GROUPS,
} from './mappers'
import {
  createRow,
  deleteRow,
  deleteRowByClientId,
  readBack,
  runAction,
  runActionByClientId,
  runUserScopedAction,
  updateRow,
  updateRowByClientId,
  type WritePayload,
} from './writes'

// ------------------------------------------------------------------------------------------------
// Chat
// ------------------------------------------------------------------------------------------------

/** Conversations a record can be attached to (`conversable_type`). */
const CONVERSABLE_ENTITIES: Record<string, EntityName> = { deal: 'deal', ticket: 'ticket' }

async function conversationRefs(rows: LocalRow[]) {
  // One membership read covers the whole page; the alternative is a query per conversation.
  const membership = await runQuery({ query: 'conversation_membership' }, { limit: MAX_PAGE })
  const [users, morphs] = await Promise.all([
    loadRefs('user', membership, ['user_id', 'user_client_id']),
    loadConversableRefs(rows),
  ])
  return { users, morphs, membership, sessionUserId: sessionUserId() }
}

async function loadConversableRefs(rows: LocalRow[]) {
  const byType = new Map<string, Set<number>>()
  for (const row of rows) {
    const raw = typeof row.conversable_type === 'string' ? row.conversable_type : ''
    const type = (raw.split('\\').pop() ?? raw).toLowerCase()
    const id = toInt(row.conversable_id)
    if (!CONVERSABLE_ENTITIES[type] || id === undefined || id <= 0) continue
    const bucket = byType.get(type) ?? new Set<number>()
    bucket.add(id)
    byType.set(type, bucket)
  }
  const entries = await Promise.all(
    [...byType].map(
      async ([type, ids]) => [type, await loadRefsByIds(CONVERSABLE_ENTITIES[type], [...ids])] as const,
    ),
  )
  return new Map(entries)
}

/** My `conversation_user` row for one conversation, which is where mute and unread live. */
async function myMembership(conversationId: number): Promise<LocalRow | null> {
  const userId = sessionUserId()
  if (userId === undefined) return null
  const rows = await runQuery(
    { query: 'conversation_membership', user_id: userId, conversation_id: conversationId },
    { limit: 1 },
  )
  return rows[0] ?? null
}

async function conversationById(id: number): Promise<Conversation> {
  const row = await readBack('conversation', id)
  return mapConversation(row, await conversationRefs([row]))
}

export const chatSource: ChatSource = {
  conversations: async (query): Promise<Conversation[]> => {
    const rows = await runQuery(
      { query: 'conversation_list', kind: query.type, q: query.q?.trim() || undefined },
      { limit: MAX_PAGE },
    )
    const refs = await conversationRefs(rows)
    return rows.map((row) => mapConversation(row, refs))
  },

  conversation: (id) => conversationById(id),

  /** The global badge: the sum of my own membership rows, plus the per-conversation split. */
  unreadCount: async (): Promise<ChatUnreadCount> => {
    const userId = sessionUserId()
    if (userId === undefined) return { total_unread: 0, per_conversation: {} }
    const rows = await runQuery(
      { query: 'conversation_membership', user_id: userId },
      { limit: MAX_PAGE },
    )
    const perConversation: Record<number, number> = {}
    let total = 0
    for (const row of rows) {
      const conversationId = toInt(row.conversation_id)
      const unread = num(row.unread_count)
      if (conversationId === undefined) continue
      perConversation[conversationId] = unread
      total += unread
    }
    return { total_unread: total, per_conversation: perConversation }
  },

  createConversation: async (payload): Promise<Conversation> => {
    // `member_ids` is not a mirror column; the local applier drops it and the server reads
    // it off the pushed payload, which is exactly the split `collect_payload` is built for.
    const clientId = await createRow('conversation', payload as unknown as WritePayload)
    const row = await readBack('conversation', clientId)
    return mapConversation(row, await conversationRefs([row]))
  },

  /**
   * ONLINE-ONLY. `POST /api/conversations/for-record` is a server-side GET-OR-CREATE: the
   * server decides whether a thread already exists for that record. Doing it locally would
   * create a second thread for a record that already has one, and the two would never merge.
   */
  recordConversation: (conversableType, conversableId) =>
    http
      .post<{ data: Conversation }>('/api/conversations/for-record', {
        conversable_type: conversableType,
        conversable_id: conversableId,
      })
      .then((body) => body.data),

  renameConversation: async (id, name): Promise<Conversation> => {
    await updateRow('conversation', id, { name })
    return conversationById(id)
  },

  deleteConversation: (id) => deleteRow('conversation', id),

  /**
   * ONLINE-ONLY. Membership is not one of the whitelisted `op = action` values
   * (`syncra_sync::protocol::ACTION_WHITELIST`), which is the wire's way of saying the server
   * answers `rejected` with `ONLINE_ONLY`. Queuing it would show the user a member who was
   * never added.
   */
  addMembers: (id, userIds) =>
    http
      .post<{ data: Conversation }>(`/api/conversations/${id}/members`, { user_ids: userIds })
      .then((body) => body.data),

  /** ONLINE-ONLY, same reason as `addMembers`. */
  removeMember: (id, userId) => http.delete<void>(`/api/conversations/${id}/members/${userId}`),

  /** ONLINE-ONLY, same reason as `addMembers`. */
  leaveConversation: (id) => http.post<void>(`/api/conversations/${id}/leave`),

  /**
   * Mute is a per-member column on `conversation_user` (protocol §2.2 names `is_muted` as one
   * of the three per-person columns), and that table is read-write in the sync scope — so
   * this is a plain field update, not an action, and it works offline.
   */
  muteConversation: async (id, isMuted): Promise<Conversation> => {
    const membership = await myMembership(id)
    if (membership && typeof membership.client_id === 'string') {
      await updateRowByClientId('conversation_user', membership.client_id, { is_muted: isMuted })
    }
    return conversationById(id)
  },

  markRead: async (id, messageId): Promise<ConversationCursorAck> => {
    // The request body field is `message_id`, NOT `last_read_message_id` — a wrong name is
    // accepted silently by the server and marks the whole thread read (`features/chat/api.ts`).
    await runAction('conversation', id, 'read', { message_id: messageId })
    return cursorAck(id)
  },

  markDelivered: async (id, messageId): Promise<ConversationCursorAck> => {
    await runAction('conversation', id, 'delivered', { message_id: messageId })
    return cursorAck(id)
  },

  messages: async (conversationId, before, perPage): Promise<MessagesPage> => {
    const limit = perPage ?? 30
    const rows = await runQuery(
      {
        query: 'conversation_messages',
        conversation_id: conversationId,
        before_server_id: before,
      },
      // One extra row answers `has_more` without a second count query.
      { limit: limit + 1 },
    )
    const hasMore = rows.length > limit
    const page = hasMore ? rows.slice(0, limit) : rows
    const users = await loadRefs('user', page, ['user_id', 'user_client_id'])
    const data = page.map((row) => mapMessage(row, users))
    return {
      data,
      // The page is newest-first, so the cursor for the next (older) page is its last row.
      meta: { has_more: hasMore, next_before: hasMore ? (data[data.length - 1]?.id ?? null) : null },
    }
  },

  sendMessage: async (conversationId, payload): Promise<Message> => {
    const clientId = await createRow('message', {
      conversation_id: conversationId,
      body: payload.body ?? undefined,
      attachment_id: payload.attachment_id ?? undefined,
      mentions: payload.mentions?.length ? payload.mentions : undefined,
      type: 'text',
    })
    const row = await readBack('message', clientId)
    return mapMessage(row, await loadRefs('user', [row], ['user_id', 'user_client_id']))
  },

  editMessage: async (messageId, body): Promise<Message> => {
    await updateRow('message', messageId, { body })
    const row = await readBack('message', messageId)
    return mapMessage(row, await loadRefs('user', [row], ['user_id', 'user_client_id']))
  },

  deleteMessage: (messageId) => deleteRow('message', messageId),

  searchMessages: async (q, conversationId): Promise<Message[]> => {
    const hits = await searchLocal(q, ['message'], 50)
    if (hits.length === 0) return []
    const rows = await runQuery(
      { query: 'rows_by_client_ids', entity: 'message', client_ids: hits.map((hit) => hit.client_id) },
      { limit: hits.length },
    )
    const scoped =
      conversationId === undefined
        ? rows
        : rows.filter((row) => toInt(row.conversation_id) === conversationId)
    const users = await loadRefs('user', scoped, ['user_id', 'user_client_id'])
    return scoped.map((row) => mapMessage(row, users))
  },

  /**
   * ONLINE-ONLY, and listed as such in `SYNCDESKTOP.md` §8 ("attachments upload").
   *
   * §8 files it under "queued", but the queue is `files::attach_from_paths` (F5-5) and it does
   * not exist yet — nor could this signature feed it: a `File` handle from the webview has no
   * path to hand the Rust side. Until F5-5 lands the upload goes straight to the network,
   * which fails visibly offline instead of pretending to have queued anything.
   */
  uploadAttachment: (file, onProgress, signal) => {
    const formData = new FormData()
    formData.append('file', file)
    return http
      .post<{ data: Attachment }>('/api/attachments', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
        signal,
        onUploadProgress: (event: { loaded: number; total?: number }) => {
          if (!onProgress || !event.total) return
          onProgress(Math.min(100, Math.round((event.loaded * 100) / event.total)))
        },
      })
      .then((body) => body.data)
  },
}

/**
 * The cursor acknowledgement both read endpoints return.
 *
 * `unread_count` is server-authoritative on the web (a partial read may leave it non-zero);
 * locally the membership row is the only source there is, and it is what the next pull will
 * correct.
 */
async function cursorAck(conversationId: number): Promise<ConversationCursorAck> {
  const membership = await myMembership(conversationId)
  return {
    last_read_message_id: membership ? (toInt(membership.last_read_message_id) ?? null) : null,
    // The mirror has no delivered cursor; protocol §2.2 lists only the three per-person
    // columns, and `last_delivered_message_id` is not among them.
    last_delivered_message_id: null,
    unread_count: membership ? num(membership.unread_count) : 0,
  }
}

// ------------------------------------------------------------------------------------------------
// Notifications
// ------------------------------------------------------------------------------------------------

export const notificationsSource: NotificationsSource = {
  list: async (query): Promise<NotificationsListResponse> => {
    const named: NamedQuery = { query: 'notification_list', read: query.read }
    const [rows, total] = await Promise.all([
      runQuery(named, { limit: 15, offset: (Math.max(1, query.page ?? 1) - 1) * 15 }),
      countRows(named),
    ])
    return {
      data: rows.map(mapNotification),
      meta: { pagination: pagination({ page: query.page, per_page: 15 }, total) },
    }
  },

  unreadCount: () => countRows({ query: 'notification_list', read: 'unread' }),

  markRead: async (id): Promise<Notification> => {
    // Addressed by `client_id`: `notifications.id` IS the local identity (protocol §6.1 P12).
    await runActionByClientId('notification', id, 'read')
    return mapNotification(await readBack('notification', id))
  },

  /** The one mutation with no row identity at all (protocol §4.3 P10, `scope: "user"`). */
  markAllRead: () => runUserScopedAction('notification', 'read_all'),

  delete: (id) => deleteRowByClientId('notification', id),
}

// ------------------------------------------------------------------------------------------------
// Global search — the ONE unified read (`SYNCDESKTOP.md` §7.2)
//
// > "Command palette lokal FTS + online sunucu **birleşik** (kaynak etiketi)"
//
// Two indexes answer, not one:
//
//   * **local FTS always runs.** Offline it is the only index there is, and a palette that
//     went quiet without a network would be worse than no palette.
//   * **the server runs too, whenever the engine reports online.** The mirror holds a
//     retention window, not the whole database (`SYNCDESKTOP.md` §5.1), so a record that has
//     never been pulled exists ONLY on the server — and before this, an online user simply
//     could not find it, with nothing on screen to say why.
//
// The failure direction is asymmetric on purpose: the server half is allowed to fail and the
// local half is not. A 5xx, a timeout or a dropped link degrades the palette to local results
// (with a warning in the console); it never turns search into something that returns nothing.
// ------------------------------------------------------------------------------------------------

/** How many hits the palette asks the local index for before grouping. */
const SEARCH_LIMIT = 40

/**
 * Tag every item in a response with the index it came from.
 *
 * `search_source` is an optional extra field (`WithSearchSource`, `platform/types.ts`), so a
 * tagged response is still a plain `SearchResponse` — which is exactly why the web adapter,
 * which never calls this, needs no change and shows no label.
 */
function tagged(response: SearchResponse, source: SearchResultSource): SearchResponse {
  const out: SearchResponse = {}
  for (const [group, items] of Object.entries(response)) {
    if (!Array.isArray(items)) continue
    out[group as SearchGroupKey] = items.map(
      (item): WithSearchSource<SearchResultItem> => ({ ...item, search_source: source }),
    )
  }
  return out
}

/**
 * The identity the two halves share.
 *
 * The mirror keeps `server_id` for every row that has ever been pushed, and `mapSearchResult`
 * reports it as the item's `id` (`rowId()`), which is the same number `GET /api/search`
 * returns. A row created offline has no `server_id` yet and reports `-local_rowid` instead —
 * negative, so it cannot collide with anything the server sends, which is correct: the server
 * has never heard of that record.
 */
function searchIdentity(item: SearchResultItem): string {
  return `${item.type}:${item.id}`
}

/**
 * The local half: FTS5, grouped into the same envelope `GET /api/search` returns.
 *
 * The server omits a group entirely when the user cannot see that module (an absent key is
 * NOT the same as `[]` — `features/search/types.ts`). Locally the same rule holds for a
 * different reason: a module the user cannot see was never pulled, so it produces no hits
 * and therefore no key.
 */
async function localSearchGroups(term: string): Promise<SearchResponse> {
  const entities = Object.keys(SEARCH_GROUPS) as EntityName[]
  const hits = await searchLocal(term, entities, SEARCH_LIMIT)
  if (hits.length === 0) return {}

  const byEntity = new Map<string, string[]>()
  for (const hit of hits) {
    const bucket = byEntity.get(hit.entity) ?? []
    bucket.push(hit.client_id)
    byEntity.set(hit.entity, bucket)
  }

  const pages = await Promise.all(
    [...byEntity].map(
      async ([entity, clientIds]) =>
        [
          entity,
          await runQuery(
            { query: 'rows_by_client_ids', entity: entity as EntityName, client_ids: clientIds },
            { limit: clientIds.length },
          ),
        ] as const,
    ),
  )

  const response: SearchResponse = {}
  for (const [entity, rows] of pages) {
    const group = SEARCH_GROUPS[entity]?.group as keyof SearchResponse | undefined
    if (!group) continue
    const items = rows
      .map((row) => mapSearchResult(entity, row))
      .filter((item): item is SearchResultItem => item !== null)
    if (items.length > 0) response[group] = items
  }
  return tagged(response, 'local')
}

/**
 * The server half: the very same `GET /api/search` the web adapter calls.
 *
 * No client-side permission filter is added here either — the whitelist is
 * `GlobalSearchService::search()`'s `Gate::allows('viewAny', ...)` pass, and re-filtering a
 * set the server already filtered would only create a false sense of two checks
 * (`CommandPalette.tsx`, `docs/PHASE-AUDIT.md` §5.4 C1).
 */
async function serverSearchGroups(term: string): Promise<SearchResponse> {
  const body = await http.get<{ data: SearchResponse }>('/api/search', { params: { q: term } })
  return tagged(body?.data ?? {}, 'server')
}

/**
 * Merge the two halves, **local wins**.
 *
 * A record both indexes know about appears ONCE. The local copy is the one kept, and that
 * direction is not arbitrary: the local row may carry the user's own edit that has not been
 * pushed yet, so preferring the server's copy would show someone their record without the
 * change they just made to it — the exact thing the pending badge exists to reassure them
 * about.
 *
 * A group present in only one half survives untouched. Groups that end up empty are dropped,
 * because an empty group HEADING in the palette still leaks "this module exists, you cannot
 * see into it" (the same reason the local half never emits one).
 */
export function mergeSearchGroups(local: SearchResponse, server: SearchResponse): SearchResponse {
  const merged: SearchResponse = {}
  const groups = new Set<SearchGroupKey>([
    ...(Object.keys(local) as SearchGroupKey[]),
    ...(Object.keys(server) as SearchGroupKey[]),
  ])
  for (const group of groups) {
    const localItems = local[group] ?? []
    const seen = new Set(localItems.map(searchIdentity))
    const serverItems = (server[group] ?? []).filter((item) => !seen.has(searchIdentity(item)))
    const items = [...localItems, ...serverItems]
    if (items.length > 0) merged[group] = items
  }
  return merged
}

/**
 * The composition, with both halves injected.
 *
 * The halves are parameters rather than direct calls so that this — the part with the actual
 * decisions in it (parallelism, the swallowed server failure, who wins a duplicate) — can be
 * tested against fakes, with no Tauri host and no HTTP server. `comms.test.ts` does exactly
 * that; `searchSource.query` below binds the real two.
 *
 * `server === null` means "offline, do not even ask": the engine already knows the API is
 * unreachable, and a doomed request would only make every keystroke wait for a timeout.
 */
export async function unifiedSearch(
  term: string,
  local: (term: string) => Promise<SearchResponse>,
  server: ((term: string) => Promise<SearchResponse>) | null,
): Promise<SearchResponse> {
  // Concurrent: the two indexes are independent, and serialising them would add the server's
  // latency to every local result the user could already have been looking at.
  const [localGroups, serverGroups] = await Promise.all([
    local(term),
    server === null
      ? null
      : server(term).catch((error: unknown) => {
          // Logged, not swallowed: "the server half is down" is a real condition someone has
          // to be able to diagnose. It is not raised to the palette as an error, because the
          // local results beside it are valid, and an error screen over them would hide the
          // answer the user may well already have.
          console.warn('[search] server half failed; showing local results only', error)
          return null
        }),
  ])
  return serverGroups === null ? localGroups : mergeSearchGroups(localGroups, serverGroups)
}

/**
 * Whether the engine considers the API reachable.
 *
 * Deferred import on purpose. `platform/desktop.ts` imports `./data`, which imports THIS
 * module; a static `import { getEngineStatus } from '../desktop'` would close that cycle, and
 * if anything ever evaluated `data/index.ts` before `desktop.ts`, the top-level
 * `desktopPlatform = { data: desktopData, … }` would read `desktopData` in its TDZ and the app
 * would not boot. The dynamic form keeps the static graph acyclic; after the first call it is
 * a module-cache hit.
 *
 * Rolldown reports `INEFFECTIVE_DYNAMIC_IMPORT` for it, and that is the expected outcome, not
 * a problem to fix: `desktop.ts` is already in the entry chunk (`main.desktop.tsx` imports it
 * statically), so nothing is code-split off. The dynamic form is here for evaluation ORDER,
 * not for chunking — turning it back into a static import to silence the warning would
 * reintroduce the cycle it exists to break.
 *
 * `navigator.onLine` is NOT used, here or anywhere else in this tree
 * (`docs/DESKTOP-ARCHITECTURE.md` §3.5): it reports "online" for a machine that has a LAN but
 * cannot reach the API host, which is the common failure mode in the closed networks this
 * product is deployed into. The engine is the authority.
 */
async function engineIsOnline(): Promise<boolean> {
  const { getEngineStatus } = await import('../desktop')
  return getEngineStatus().online
}

export const searchSource: SearchSource = {
  query: async (term): Promise<SearchResponse> =>
    unifiedSearch(term, localSearchGroups, (await engineIsOnline()) ? serverSearchGroups : null),
}
