// Reverb frame -> `handle_realtime` -> engine mini-pull — `docs/DESKTOP-ARCHITECTURE.md` §6.3,
// KARAR A11.
//
// ## What this module refuses to do
//
// On the web a socket frame refreshes the query cache directly, and that is correct there: the
// cache IS the client's copy of the server. On the desktop the client's copy of the server is
// the local SQLite mirror, and the cache is only a view of it. So a frame that invalidated a
// query directly would send the UI to the network for a row the engine has never seen — the
// offline-first contract broken in one line, and broken silently: online it looks perfect.
//
// The desktop path is therefore:
//
//     Reverb --(Echo, bearer auth)--> this module --invoke('handle_realtime')--> SyncEngine
//       --> pull(just those tables) --> EngineEvent::TablesChanged --> bridge/events.ts
//       --> queryClient.invalidateQueries
//
// `invalidateQueries` appears exactly once in `desktop/src`, in `bridge/events.ts`, on the
// engine's side of that arrow. `scripts/check-realtime-wiring.mjs` enforces it.
//
// ## The mapping is written by hand — deriving it is FORBIDDEN (same discipline as D-5)
//
// "Event name -> table name" is wrong for most of the rows below. `.chat.unread` moves three
// tables and names none of them; `.task.reminder` touches `tasks` and reads nothing about
// tasks in its name; `.deal.moved` moves a row the engine mirrors under `deals` but the same
// channel is authorised by `deals.view`, not by any table. Every row therefore names the
// frontend file:line it was read from, and `scripts/check-realtime-wiring.mjs` fails if the web
// grows a channel or an event that no row here accounts for.
//
// ## The one exception: `presence-online`
//
// Presence is not persisted anywhere — it is who happens to hold a socket right now. There is
// no mirror table for it, `SyncEngine` has no entity that could be pulled, and routing it
// through the engine would mean a round trip to produce data the socket already carries. It
// stays on its existing direct path (`frontend/src/hooks/usePresence.ts`, whose members feed
// `useOnlineUsers`) and this bridge deliberately never subscribes to it. That is the only
// realtime frame on the desktop that does not go to the engine, and it is listed in
// `UNMAPPED_CHANNELS` with that reason rather than left out.
import { getEcho, onConnectionStateChange } from '@/lib/echo'
import type { SyncraEcho } from '@/lib/echo'
import { useAuthStore } from '@/features/auth/store'
import type { RealtimeChannel } from '@/platform/types'

import type { EntityName } from '../platform/data/engine'
import { invokeCommand } from './invoke'

/**
 * Tauri command name (`desktop/src-tauri/src/commands/sync.rs`, registered in
 * `src-tauri/src/lib.rs`'s `generate_handler!`).
 *
 * Kept as a named constant because a typo here is the quietest failure in the whole bridge:
 * `invoke` would reject, the catch below would log, and the mirror would simply stop reacting
 * to the socket while every screen still worked. `scripts/check-realtime-wiring.mjs` compares
 * this string against both Rust sites.
 */
export const HANDLE_REALTIME_COMMAND = 'handle_realtime'

/** Wire shape of `syncra_sync::RealtimeEvent` (`crates/syncra-sync/src/types.rs`). */
export interface RealtimeEvent {
  entities: EntityName[]
}

/** How this bridge gets a channel's frames. */
export type SubscribeMode =
  /** The bridge subscribes for the whole session — the mirror must stay fresh for screens
   *  nobody has open, which is exactly what a hook-scoped subscription cannot do. */
  | 'always'
  /** Same, but the name carries the signed-in user's id, so it waits for one. */
  | 'user'
  /** The bridge never subscribes; it binds to a subscription the app already opened. Used
   *  where the channel name is a runtime id this module cannot enumerate. */
  | 'attach'

/** A channel the desktop routes to the engine. */
export interface RealtimeChannelSpec {
  /** Channel identity as `docs/DESKTOP-ARCHITECTURE.md` §6.2 names it, prefix included and
   *  `{id}` where a runtime id goes. */
  channel: string
  mode: SubscribeMode
  /** Where the web subscribes to it. */
  source: string
}

/** One `(channel, event) -> tables` row. */
export interface RealtimeBinding {
  /** Matches a {@link RealtimeChannelSpec.channel}. */
  channel: string
  /** Echo event name; the leading dot is Laravel's `broadcastAs` alias. */
  event: string
  /** `RealtimeEvent.entities` — the tables the engine should pull. */
  entities: readonly EntityName[]
  /** Frontend `file:line` this row was read from. */
  source: string
  /** Why these tables and not others. */
  why: string
}

/** A web channel the desktop deliberately does not route to the engine. */
export interface UnmappedChannel {
  channel: string
  source: string
  reason: string
}

/** A web event the desktop deliberately does not route to the engine. */
export interface UnroutedEvent {
  event: string
  channel: string
  source: string
  reason: string
}

// ------------------------------------------------------------------------------------------------
// The tables (hand-written; see the header)
// ------------------------------------------------------------------------------------------------

export const REALTIME_CHANNELS: readonly RealtimeChannelSpec[] = [
  // Subscribed for the session, not for the screen: a card moved while the board is closed
  // still has to reach the mirror, or the board is stale the moment it opens offline.
  { channel: 'private-deals', mode: 'always', source: 'features/deals/hooks/useDealRealtime.ts:32,132' },
  { channel: 'private-tickets', mode: 'always', source: 'features/tickets/hooks/useTicketRealtime.ts:32,78' },
  // Four web hooks share this one channel (notifications, chat badge, task reminders, session
  // revoke). The bridge is a fifth, independent subscriber; it does not replace them.
  { channel: 'private-user.{id}', mode: 'user', source: 'features/notifications/hooks/useNotificationSocket.ts:63' },
  // ATTACH, not subscribe: the id set is "the conversations the user has open", owned by the
  // reference-counting registry in `features/chat/hooks/conversationChannel.ts:42`. This module
  // cannot enumerate it and must not resurrect a room the registry has released, so it binds to
  // whatever the connector currently holds. Messages for rooms that are NOT open still reach the
  // mirror: the server also emits `.chat.unread` on `private-user.{id}`, which is routed above.
  { channel: 'private-conversation.{id}', mode: 'attach', source: 'features/chat/hooks/conversationChannel.ts:42' },
]

export const REALTIME_BINDINGS: readonly RealtimeBinding[] = [
  {
    channel: 'private-deals',
    event: '.deal.moved',
    entities: ['deal'],
    source: 'features/deals/hooks/useDealRealtime.ts:33',
    why: 'stage + position of one card; `pipeline_stages` is read-only and unchanged by a move',
  },
  {
    channel: 'private-tickets',
    event: '.ticket.sla.warning',
    entities: ['ticket'],
    source: 'features/tickets/hooks/useTicketRealtime.ts:33',
    why: 'the SLA scanner patches sla_due_at/status/priority on the ticket row (docs/SLA-DESIGN.md §5.5)',
  },
  {
    channel: 'private-tickets',
    event: '.ticket.sla.breached',
    entities: ['ticket'],
    source: 'features/tickets/hooks/useTicketRealtime.ts:34',
    why: 'same row, the breached side of the same scanner pass',
  },
  {
    channel: 'private-user.{id}',
    event: '.notification.created',
    entities: ['notification'],
    source: 'features/notifications/hooks/useNotificationSocket.ts:27',
    why: 'one new `notifications` row; the unread badge is derived from that table, not stored elsewhere',
  },
  {
    channel: 'private-user.{id}',
    event: '.task.reminder',
    entities: ['task'],
    source: 'features/tasks/hooks/useTaskReminders.ts:21',
    why: 'the reminder fires off a task row the user may not have mirrored yet (due_at, taskable)',
  },
  {
    channel: 'private-user.{id}',
    event: '.chat.unread',
    entities: ['message', 'conversation', 'conversation_user'],
    source: 'features/chat/hooks/useChatUnread.ts:21,66',
    why: 'three tables and the name says none of them: a new message row, the conversation preview/order the web patches by hand, and the per-member unread counter that lives on the pivot',
  },
  {
    channel: 'private-conversation.{id}',
    event: '.message.created',
    entities: ['message', 'conversation', 'conversation_user'],
    source: 'features/chat/hooks/useChatSocket.ts:253',
    why: 'same three tables as `.chat.unread`; this is the in-room copy of the same server change',
  },
  {
    channel: 'private-conversation.{id}',
    event: '.message.updated',
    entities: ['message'],
    source: 'features/chat/hooks/useChatSocket.ts:254',
    why: 'an edit rewrites the message body only',
  },
  {
    channel: 'private-conversation.{id}',
    event: '.message.deleted',
    entities: ['message', 'conversation'],
    source: 'features/chat/hooks/useChatSocket.ts:255',
    why: 'the tombstone plus the conversation preview, which shows the last message',
  },
  {
    channel: 'private-conversation.{id}',
    event: '.message.read',
    entities: ['conversation_user'],
    source: 'features/chat/hooks/useChatSocket.ts:256',
    why: 'read cursors are pivot columns; no message row changes',
  },
  {
    channel: 'private-conversation.{id}',
    event: '.message.delivered',
    entities: ['conversation_user'],
    source: 'features/chat/hooks/useChatSocket.ts:257',
    why: 'delivery cursors, same pivot',
  },
  {
    channel: 'private-conversation.{id}',
    event: '.conversation.updated',
    entities: ['conversation'],
    source: 'features/chat/hooks/useChatSocket.ts:258',
    why: 'title/members metadata on the conversation row; the payload\'s per-user fields are ignored by the web too',
  },
]

export const UNMAPPED_CHANNELS: readonly UnmappedChannel[] = [
  {
    channel: 'presence-online',
    source: 'hooks/usePresence.ts:22,32',
    reason:
      'presence is not persisted: no mirror table, no Entity, nothing for the engine to pull. The socket payload IS the data, so it stays on its direct path (KARAR A11 exception).',
  },
  {
    channel: 'private-dashboard',
    source: 'features/dashboard/hooks/useDashboardSocket.ts:24,46',
    reason:
      'the dashboard is an online-only surface (SYNCDESKTOP.md §8): its numbers are server aggregates that are not mirrored, so a mini-pull would have nothing to pull. Offline it shows the last cached answer.',
  },
  {
    channel: 'private-logs',
    source: 'features/logs/hooks/useActivityStream.ts:15,49',
    reason:
      'the audit log (Spatie activity_log, `LiveActivityEntry`) is a different thing from the mirrored `activities` CRM entity and is online-only (SYNCDESKTOP.md §8) — nothing is mirrored to pull.',
  },
]

export const UNROUTED_EVENTS: readonly UnroutedEvent[] = [
  {
    event: '.user.deactivated',
    channel: 'private-user.{id}',
    source: 'features/auth/hooks/useRealtimeSession.ts:43',
    reason:
      'session teardown, not a data change: no mirrored table moves. The web hook clears the store and navigates; the engine learns the token is gone from its own 401 -> EngineEvent::AuthLost path.',
  },
  {
    event: '.dashboard.invalidate',
    channel: 'private-dashboard',
    source: 'features/dashboard/hooks/useDashboardSocket.ts:25',
    reason: 'the only event of an unmapped, online-only channel (see UNMAPPED_CHANNELS).',
  },
  {
    event: '.activity.logged',
    channel: 'private-logs',
    source: 'features/logs/hooks/useActivityStream.ts:16',
    reason: 'the only event of an unmapped, online-only channel (see UNMAPPED_CHANNELS).',
  },
]

// ------------------------------------------------------------------------------------------------
// Lookups
// ------------------------------------------------------------------------------------------------

/** Channel identity -> its bindings. */
const BINDINGS_BY_CHANNEL = new Map<string, RealtimeBinding[]>()
for (const binding of REALTIME_BINDINGS) {
  const list = BINDINGS_BY_CHANNEL.get(binding.channel)
  if (list) list.push(binding)
  else BINDINGS_BY_CHANNEL.set(binding.channel, [binding])
}

/** The tables one `(channel, event)` pair moves; `undefined` when the pair is not routed. */
export function entitiesForEvent(channel: string, event: string): readonly EntityName[] | undefined {
  return BINDINGS_BY_CHANNEL.get(channel)?.find((binding) => binding.event === event)?.entities
}

/** `'private-user.{id}'` + `7` -> `'private-user.7'`. */
function concreteName(template: string, id: string | number): string {
  return template.replace('{id}', String(id))
}

/** `'private-user.7'` matches `'private-user.{id}'`. */
function templatePattern(template: string): RegExp {
  const escaped = template.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace('\\{id\\}', '(\\d+)')
  return new RegExp(`^${escaped}$`)
}

/** Echo takes the bare name; it adds the `private-`/`presence-` prefix itself. */
function bareName(wireName: string): string {
  return wireName.replace(/^(private-|presence-)/, '')
}

// ------------------------------------------------------------------------------------------------
// Dispatch
// ------------------------------------------------------------------------------------------------

/**
 * How long frames are collected before one `handle_realtime` call goes out.
 *
 * One user action produces a burst: sending a chat message emits `.message.created`, then
 * `.message.delivered`, then `.message.read` within a few hundred milliseconds, and each one
 * would otherwise be its own engine round trip against the same table. The engine treats the
 * event as a hint (`SyncEngine::handle_realtime` filters it against the manifest and drops it
 * when offline), so coalescing a burst into one hint loses nothing — the entity set is the
 * union.
 */
const COALESCE_MS = 200

/** How often the bridge re-checks that its listeners are still attached. See {@link reconcile}. */
const RECONCILE_MS = 5000

let pendingEntities = new Set<EntityName>()
let flushTimer: ReturnType<typeof setTimeout> | null = null

function flush(): void {
  flushTimer = null
  if (pendingEntities.size === 0) return
  const event: RealtimeEvent = { entities: [...pendingEntities] }
  pendingEntities = new Set()
  void invokeCommand<void>(HANDLE_REALTIME_COMMAND, { event }).catch((error: unknown) => {
    // A dropped hint is recoverable — the engine's 60 second loop and the next `sync_now` will
    // catch the same rows — so this never throws into the socket callback. It is still logged:
    // a command-name or payload-shape mismatch would otherwise present as "realtime quietly
    // stopped working", which is the failure this whole module exists to prevent.
    console.warn(`[realtime] ${HANDLE_REALTIME_COMMAND} rejected`, error)
  })
}

/** Queue the tables one frame moved. */
function route(entities: readonly EntityName[]): void {
  for (const entity of entities) pendingEntities.add(entity)
  if (flushTimer !== null) return
  flushTimer = setTimeout(flush, COALESCE_MS)
}

// ------------------------------------------------------------------------------------------------
// Subscription lifecycle
// ------------------------------------------------------------------------------------------------

/** The bit of a laravel-echo channel this module uses. */
type ListenableChannel = {
  listen(event: string, callback: CallableFunction): unknown
  stopListening(event: string, callback?: CallableFunction): unknown
}

interface Attachment {
  /** Identity check: `Echo.leave()` deletes the cached channel, so a later `private()` hands
   *  back a NEW object. That inequality is how {@link reconcile} notices it was torn down. */
  channel: ListenableChannel
  template: string
  /** The exact handler bound per event. Unbinding MUST pass the reference back:
   *  `stopListening(event)` with no callback unbinds every handler on that event, including
   *  the web hooks' — the bridge would be tearing down features it does not own. */
  handlers: Array<[string, CallableFunction]>
}

/** Concrete wire name -> what this module bound on it. */
const attachments = new Map<string, Attachment>()

let started = false
let stopConnectionListener: (() => void) | null = null
let stopAuthListener: (() => void) | null = null
let reconcileTimer: ReturnType<typeof setInterval> | null = null

function bind(channel: ListenableChannel, template: string, wireName: string): void {
  const handlers: Array<[string, CallableFunction]> = []
  for (const binding of BINDINGS_BY_CHANNEL.get(template) ?? []) {
    const handler = () => route(binding.entities)
    channel.listen(binding.event, handler)
    handlers.push([binding.event, handler])
  }
  attachments.set(wireName, { channel, template, handlers })
}

/** Unbind this module's handlers from one attachment, leaving every other listener alone. */
function unbind(wireName: string): void {
  const attachment = attachments.get(wireName)
  if (!attachment) return
  for (const [event, handler] of attachment.handlers) {
    attachment.channel.stopListening(event, handler)
  }
  attachments.delete(wireName)
}

/**
 * Make the live subscriptions match {@link REALTIME_CHANNELS}. Idempotent, and cheap when
 * nothing changed (an identity comparison per channel).
 *
 * ## Why this runs on a timer as well as on events
 *
 * The web hooks own the same channels and call `Echo.leave(name)` on unmount —
 * `useDealRealtime.ts:153`, `useTicketRealtime.ts:131`, `useTaskReminders.ts:72`,
 * `useRealtimeSession.ts:51` and `useNotificationSocket.ts:96` all do. `leave` does not reference-count: it unsubscribes the
 * channel and every listener on it, including this module's. Closing the Kanban board would
 * therefore silently stop `deals` from reaching the engine for the rest of the session.
 *
 * Fixing that at the source means teaching those hooks to share a subscription, which is a
 * `frontend/**` change this turn does not own. The bridge instead re-checks and re-subscribes,
 * bounding the gap to {@link RECONCILE_MS}. The cost of the gap is itself bounded: the engine
 * pulls every table on its 60 second loop regardless, so a missed hint delays a row, it does
 * not lose one.
 */
function reconcile(): void {
  const echo = getEcho()
  if (!echo) {
    // Echo is torn down on logout (`disconnectEcho` nulls the instance). Every channel object
    // went with it; holding stale references would defeat the identity check above.
    attachments.clear()
    return
  }

  const userId = useAuthStore.getState().user?.id ?? null

  for (const spec of REALTIME_CHANNELS) {
    if (spec.mode === 'attach') {
      attachExisting(echo, spec.channel)
      continue
    }
    if (spec.mode === 'user' && userId === null) {
      dropTemplate(spec.channel)
      continue
    }
    const wireName = spec.mode === 'user' ? concreteName(spec.channel, userId as number) : spec.channel
    dropTemplate(spec.channel, wireName)
    const channel = echo.private(bareName(wireName)) as unknown as ListenableChannel
    if (attachments.get(wireName)?.channel === channel) continue
    bind(channel, spec.channel, wireName)
  }
}

/**
 * Bind to the `attach`-mode channels the app currently holds, and forget the ones it has
 * released. Reads the connector's own registry rather than calling `Echo.private()`, which
 * would re-subscribe a room the chat registry deliberately closed.
 */
function attachExisting(echo: SyncraEcho, template: string): void {
  const pattern = templatePattern(template)
  const live = echo.connector.channels

  for (const [wireName, attachment] of [...attachments]) {
    if (attachment.template !== template) continue
    // The room was released (`releaseConversationChannel` -> `Echo.leave`); the channel object
    // and every listener on it are already gone, so there is nothing to unbind — just forget it.
    if (!(wireName in live)) attachments.delete(wireName)
  }

  for (const wireName of Object.keys(live)) {
    if (!pattern.test(wireName)) continue
    const channel = live[wireName] as unknown as ListenableChannel
    if (attachments.get(wireName)?.channel === channel) continue
    bind(channel, template, wireName)
  }
}

/** Unbind every attachment of one template except (optionally) the one that is still wanted. */
function dropTemplate(template: string, keep?: string): void {
  for (const [wireName, attachment] of [...attachments]) {
    if (attachment.template === template && wireName !== keep) unbind(wireName)
  }
}

/**
 * Arm the bridge. Idempotent; returns the teardown handle.
 *
 * Called from `main.desktop.tsx` BEFORE the first render, for the same reason
 * `subscribeToEngineEvents` is: a frame that arrives while nothing is listening is gone, pusher
 * does not replay it. Echo itself does not exist yet at that point (`connectEcho()` runs after
 * login, `features/auth/hooks/useAuth.ts:59`), which is why this arms listeners on the
 * connection state and the auth store rather than subscribing immediately — the first
 * `reconcile()` that finds an Echo instance does the subscribing.
 */
export function startRealtimeBridge(): () => void {
  if (!started) {
    started = true
    // Fires immediately with the current state (`lib/echo.ts:onConnectionStateChange`), so this
    // also covers "Echo was already connected when the bridge started".
    stopConnectionListener = onConnectionStateChange(() => reconcile())
    stopAuthListener = useAuthStore.subscribe((state, previous) => {
      if (state.user?.id !== previous.user?.id) reconcile()
    })
    reconcileTimer = setInterval(reconcile, RECONCILE_MS)
    reconcile()
  }
  return stopRealtimeBridge
}

/** Tear the bridge down. Safe to call when it was never started. */
export function stopRealtimeBridge(): void {
  if (!started) return
  started = false
  stopConnectionListener?.()
  stopConnectionListener = null
  stopAuthListener?.()
  stopAuthListener = null
  if (reconcileTimer !== null) clearInterval(reconcileTimer)
  reconcileTimer = null
  if (flushTimer !== null) clearTimeout(flushTimer)
  flushTimer = null
  pendingEntities = new Set()
  for (const wireName of [...attachments.keys()]) unbind(wireName)
}

/**
 * `Platform['realtime'].channel` for the desktop — the payload path, NOT the cache path.
 *
 * A caller still gets the frame verbatim, because transient UI legitimately needs it: the SLA
 * toast, the notification toast, presence membership, typing whispers. What no desktop caller
 * gets from here is a refreshed cache — that arrives later, from the engine, through
 * `bridge/events.ts`. The engine routing is not established by this call and does not depend on
 * anyone making it: it is table-driven in {@link REALTIME_BINDINGS} and owned by
 * {@link startRealtimeBridge}.
 *
 * Shaped exactly like the web adapter's (`frontend/src/platform/web.ts:246`) so a caller that
 * writes against the contract behaves the same on both platforms.
 */
export function realtimeChannel(name: string): RealtimeChannel {
  // Defensive: if some future caller reaches the platform adapter before the entry point armed
  // the bridge, arm it now rather than serve a channel whose frames reach no engine.
  startRealtimeBridge()
  const echoChannel = getEcho()?.channel(name)
  const wrapped: RealtimeChannel = {
    listen(event, callback) {
      echoChannel?.listen(event, callback)
      return wrapped
    },
    stopListening(event) {
      echoChannel?.stopListening(event)
      return wrapped
    },
  }
  return wrapped
}
