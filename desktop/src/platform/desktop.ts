// Desktop platform adapter — `SYNCDESKTOP.md` §7.1, `docs/DESKTOP-ARCHITECTURE.md` §3.5.
//
// `data` is now the real thing: all 124 `DataSource` methods are bound, and the binding table
// is `platform/data/manifest.ts`. Reads run against the local mirror through the `NamedQuery`
// whitelist, writes go to the outbox through `mutate()`, and the `SYNCDESKTOP.md` §8
// online-only actions go to `http` — never to `mutate()` (KARAR A15), because an outbox entry
// for "send this quote" is a lie the user finds out about later.
//
// This module lives under `desktop/` and NOT under `frontend/src/platform/` on purpose
// (KARAR A2): it imports `@tauri-apps/api`, which must never become a `frontend/package.json`
// dependency or leak into the web bundle.
import {
  configureRealtimeAuth,
  connectEcho,
  disconnectEcho,
  getConnectionState,
} from '@/lib/echo'
import { api } from '@/lib/axios'
import { toast } from '@/components/ui'
import type {
  AppNotification,
  Capability,
  ConnState,
  OnlineOnlyError,
  Platform,
} from '@/platform/types'

import { invokeCommand } from '../bridge/invoke'
import { realtimeChannel, startRealtimeBridge, stopRealtimeBridge } from '../bridge/realtime'
import type { SyncStatus as EngineSyncStatus } from '../ui/commands'
import { desktopData } from './data'
import { http } from './http'

export { setDeviceToken } from './http'

// ------------------------------------------------------------------------------------------------
// Engine status
// ------------------------------------------------------------------------------------------------

/**
 * Wire shape of `syncra_sync::types::SyncStatus` (serde keeps the Rust field names).
 * Declared once in `ui/commands.ts` alongside the other command wire types.
 */
type SyncStatus = EngineSyncStatus

/**
 * How often `connectivity.subscribe` re-reads the engine's status.
 *
 * `sync::status` is documented as a "cheap, synchronous snapshot — safe to poll"
 * (`src-tauri/src/commands/sync.rs`). The authoritative feed is `EngineEvent::StatusChanged`
 * through `bridge/events.ts`, which the entry point subscribes to; this poll is the backstop
 * for the window between process start and that subscription landing.
 */
const STATUS_POLL_MS = 5000

/**
 * Last status read from the engine. Starts `online: true` so the shell does not flash "offline"
 * before the first read lands.
 *
 * `navigator.onLine` is deliberately NOT used anywhere in this file (§3.5): it reports "online"
 * for a machine that has a LAN but cannot reach the API host, which is the most common failure
 * mode in the closed-network deployments this product targets. The engine is the authority.
 */
let lastStatus: SyncStatus = {
  online: true,
  syncing: false,
  pending: 0,
  conflicts: 0,
  last_sync_at: null,
  write_blocked: null,
}

/**
 * Everything that wants to RENDER the engine's status, as opposed to the `connectivity`
 * adapter's coarse online/offline callback.
 *
 * The desktop chrome (`ui/ConnectivityBar.tsx`) needs `syncing`, `pending`, `conflicts`,
 * `last_sync_at` and `write_blocked`, not just a `ConnState`. It subscribes HERE rather than
 * opening its own `invoke('status')` poll, because a second poller would drift out of step
 * with this one and the two would disagree on screen — the authoritative feed is
 * `EngineEvent::StatusChanged`, which lands in `applyEngineStatus` below, and there is exactly
 * one place it can be observed from.
 */
const statusListeners = new Set<(status: SyncStatus) => void>()

function publishStatus(next: SyncStatus): void {
  lastStatus = next
  for (const listener of statusListeners) listener(next)
}

async function readStatus(): Promise<SyncStatus> {
  publishStatus(await invokeCommand<SyncStatus>('status'))
  return lastStatus
}

/** Adopt a status the engine pushed, so the poll and the event feed agree. */
export function applyEngineStatus(status: unknown): void {
  if (status && typeof status === 'object' && 'online' in status) {
    publishStatus(status as SyncStatus)
  }
}

/** Last known engine status. Never `null`: the optimistic default stands until the first read. */
export function getEngineStatus(): SyncStatus {
  return lastStatus
}

/** Observe {@link getEngineStatus}. Returns the unsubscribe handle. */
export function subscribeToEngineStatus(listener: (status: SyncStatus) => void): () => void {
  statusListeners.add(listener)
  return () => {
    statusListeners.delete(listener)
  }
}

/** Force one status read — after a manual sync, or when a screen that shows it opens. */
export function refreshEngineStatus(): Promise<SyncStatus> {
  return readStatus()
}

// Reverb over a bearer token. `/api/broadcasting/auth` (note the `/api` prefix) is the
// stateless route F1 registers; the web app's cookie-authenticated `/broadcasting/auth` is a
// DIFFERENT route with a different middleware stack (§6.4 TUZAK 1) and would reject a bearer
// request.
configureRealtimeAuth({
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT),
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
  authorizer: (channel) => ({
    authorize(socketId, callback) {
      api
        .post('/api/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
        .then((response) => callback(null, response.data))
        .catch((error) => callback(error, null))
    },
  }),
})

// ------------------------------------------------------------------------------------------------
// Connectivity, realtime, notifications
// ------------------------------------------------------------------------------------------------

const connectivity: Platform['connectivity'] = {
  isOnline: () => lastStatus.online,
  subscribe(callback) {
    let previous: ConnState = lastStatus.online ? 'online' : 'offline'
    const timer = setInterval(() => {
      void readStatus()
        .then((status) => {
          const next: ConnState = status.online ? 'online' : 'offline'
          if (next === previous) return
          previous = next
          callback(next)
        })
        // A failed status read means the engine did not answer, which is not the same as the
        // server being unreachable — the last known value stands rather than being flipped.
        .catch(() => undefined)
    }, STATUS_POLL_MS)
    return () => clearInterval(timer)
  },
}

// Echo runs unchanged, only re-authorised (bearer) — the socket, the reconnect logic and the
// channel names are the web's. What changed with KARAR A11 is where a frame GOES: not into the
// query cache but into `bridge/realtime.ts`, which translates it and invokes `handle_realtime`
// so the ENGINE refreshes the mirror and the cache is invalidated from `TablesChanged`. This
// module owns none of that mapping; it only makes sure the bridge is armed with the connection
// lifecycle it already controlled.
const realtime: Platform['realtime'] = {
  connect: () => {
    connectEcho()
    startRealtimeBridge()
  },
  disconnect: () => {
    // Order matters: unbind before the instance is nulled, or the handlers are unreachable and
    // the next `connectEcho()` binds a second set on the new instance.
    stopRealtimeBridge()
    disconnectEcho()
  },
  channel: realtimeChannel,
  state: (): ConnState => (getConnectionState() === 'connected' ? 'online' : 'offline'),
}

// Native notifications (`tauri-plugin-notification`, for when the app sits in the background)
// are F5-2. In-app toasts match the web's behaviour and need no plugin permission.
function notify({ level, message }: AppNotification): void {
  toast[level](message)
}

/**
 * `SYNCDESKTOP.md` §8, defence layer 2: offline, `fn` is never called — the caller gets an
 * `OnlineOnlyError` whose `action` resolves the `desktop.onlineOnly.<action>` tooltip key (S9).
 */
function onlineOnly<T>(action: string, fn: () => T): T | OnlineOnlyError {
  if (!connectivity.isOnline()) {
    return {
      code: 'ONLINE_ONLY',
      action,
      message: `"${action}" requires a connection.`,
    }
  }
  return fn()
}

// `clipboard` is absent by design: K10 makes clipboard capture opt-in and default-off, and this
// set is what the UI reads to decide whether to offer the feature at all.
const capabilities = new Set<Capability>([
  'offline',
  'deep-link',
  'hotkey',
  'tray',
  'files',
  'screenshot',
])

export const desktopPlatform: Platform = {
  kind: 'desktop',
  http,
  data: desktopData,
  connectivity,
  realtime,
  notify,
  capabilities,
  onlineOnly,
}

/**
 * One eager status read at startup, so `connectivity.isOnline()` reflects the engine rather
 * than its optimistic default within milliseconds of boot. Failure is non-fatal on purpose:
 * the shell must open even when the engine cannot answer.
 */
export function primeDesktopPlatform(): void {
  void readStatus().catch(() => undefined)
}
