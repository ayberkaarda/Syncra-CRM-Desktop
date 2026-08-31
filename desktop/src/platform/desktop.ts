// Desktop platform adapter — `SYNCDESKTOP.md` §7.1, `docs/DESKTOP-ARCHITECTURE.md` §3.5.
//
// SCOPE OF THIS TURN (F3-A): bring the shell up. `kind`, `http` (bearer), `connectivity`
// (engine-authoritative), `realtime`, `notify`, `capabilities` and `onlineOnly` are real.
// `data` is an explicit, LOUD scaffold: the 124 `DataSource` methods (§E.5.1) each need a
// `NamedQuery` whitelist entry plus a row -> DTO mapping, which is F3-B. Until then every one
// of them throws `NOT_IMPLEMENTED` rather than resolving to `undefined` — a silent `undefined`
// would surface as a blank list three layers away from the cause.
//
// This module lives under `desktop/` and NOT under `frontend/src/platform/` on purpose
// (KARAR A2): it imports `@tauri-apps/api`, which must never become a `frontend/package.json`
// dependency or leak into the web bundle.
import { api, configureHttp } from '@/lib/axios'
import {
  configureRealtimeAuth,
  connectEcho,
  disconnectEcho,
  getConnectionState,
  getEcho,
} from '@/lib/echo'
import { toast } from '@/components/ui'
import type {
  AppNotification,
  Capability,
  ConnState,
  DataSource,
  OnlineOnlyError,
  Platform,
  RealtimeChannel,
} from '@/platform/types'

import { CommandError, invokeCommand } from '../bridge/invoke'

// ------------------------------------------------------------------------------------------------
// Engine status
// ------------------------------------------------------------------------------------------------

/** Wire shape of `syncra_sync::types::SyncStatus` (serde keeps the Rust field names). */
interface SyncStatus {
  online: boolean
  syncing: boolean
  pending: number
  conflicts: number
  last_sync_at: string | null
  write_blocked: string | null
}

/**
 * How often `connectivity.subscribe` re-reads the engine's status.
 *
 * `sync::status` is documented as a "cheap, synchronous snapshot — safe to poll"
 * (`src-tauri/src/commands/sync.rs`). Polling is a placeholder: the real feed is
 * `EngineEvent::StatusChanged` through `bridge/events.ts` (§3.6), which is F3-B. This turn has
 * no event bridge, and a shell whose connectivity never updates would be worse than a slow one.
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

async function readStatus(): Promise<SyncStatus> {
  lastStatus = await invokeCommand<SyncStatus>('status')
  return lastStatus
}

// ------------------------------------------------------------------------------------------------
// HTTP — bearer, never cookies
// ------------------------------------------------------------------------------------------------

/**
 * The device token, held in memory only. The durable copy lives in the OS keychain on the Rust
 * side (K9); the webview never persists it.
 *
 * F3-B populates this from `invoke('restore')` / `invoke('login')`. Until then the shell has no
 * session and the router lands on `/login` — which is exactly the state F3-A has to render.
 */
let deviceToken: string | undefined

/** Called by the auth bridge once a session exists (F3-B). Pass `undefined` on logout. */
export function setDeviceToken(token: string | undefined): void {
  deviceToken = token
}

// TUZAK 2 / KARAR A12 (`docs/DESKTOP-ARCHITECTURE.md` §6.4): the webview's origin on Windows is
// `http://tauri.localhost`. Were that origin ever treated as stateful by Sanctum, every bearer
// POST would come back 419 and `lib/axios.ts`'s single CSRF retry would make it look like a
// random one-off failure. Second line of defence, here: `transport: 'bearer'` turns
// `withCredentials`/`withXSRFToken` OFF, so no cookie is ever sent and that path cannot be
// entered at all.
configureHttp({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  transport: 'bearer',
  getBearerToken: () => deviceToken,
})

// Reverb over a bearer token. `/api/broadcasting/auth` (note the `/api` prefix) is the stateless
// route F1 registers; the web app's cookie-authenticated `/broadcasting/auth` is a DIFFERENT
// route with a different middleware stack (§6.4 TUZAK 1) and would reject a bearer request.
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

// `Parameters<typeof api.get>[1]` rather than `AxiosRequestConfig`: `axios` is a
// `frontend/package.json` dependency and is not resolvable from `desktop/`, and the platform
// contract deliberately types `config` as `unknown` so axios never enters it (§3.3).
type HttpConfig = Parameters<typeof api.get>[1]

const http: Platform['http'] = {
  get: async (url, config) => (await api.get(url, config as HttpConfig)).data,
  post: async (url, body, config) => (await api.post(url, body, config as HttpConfig)).data,
  put: async (url, body, config) => (await api.put(url, body, config as HttpConfig)).data,
  patch: async (url, body, config) => (await api.patch(url, body, config as HttpConfig)).data,
  delete: async (url, config) => (await api.delete(url, config as HttpConfig)).data,
}

// ------------------------------------------------------------------------------------------------
// DataSource scaffold — F3-B replaces this wholesale
// ------------------------------------------------------------------------------------------------

/**
 * The 16 `DataSource` domains (`frontend/src/platform/types.ts`). Listed explicitly rather than
 * derived from a value, and `satisfies`-checked against the contract, so a domain added there
 * becomes a compile error here instead of a runtime "undefined is not a function".
 */
const DATA_DOMAINS = [
  'deals',
  'contacts',
  'companies',
  'leads',
  'tasks',
  'tickets',
  'quotes',
  'activities',
  'chat',
  'notifications',
  'search',
  'products',
  'priceLists',
  'exchange',
  'savedViews',
  'users',
] as const satisfies readonly (keyof DataSource)[]

/**
 * `"<domain>.<method>"` entries already mapped onto a `data::*` command.
 *
 * EMPTY ON PURPOSE this turn — nothing is wired yet. Each entry needs (a) a `NamedQuery`
 * whitelist member on the Rust side and (b) a local-row -> feature-DTO mapping. Neither exists,
 * and a half-mapped method returning a differently-shaped object is strictly worse than one that
 * refuses to run. F3-B fills this in one method at a time.
 */
const IMPLEMENTED = new Map<string, (...args: never[]) => unknown>()

function notImplemented(domain: string, method: string) {
  return () => {
    throw new CommandError({
      code: 'NOT_IMPLEMENTED',
      message:
        `platform.data.${domain}.${method}() is not wired to a data:: command yet ` +
        '(F3-B: NamedQuery whitelist + row mapping).',
    })
  }
}

function domainScaffold(domain: string): unknown {
  return new Proxy(
    {},
    {
      get(_target, property) {
        // Property probes (`then` when something awaits the object, `Symbol.toPrimitive` on
        // string coercion, React devtools) must not be answered with a throwing function, or an
        // accidental `await platform.data.deals` would explode instead of resolving.
        if (typeof property !== 'string') return undefined
        return IMPLEMENTED.get(`${domain}.${property}`) ?? notImplemented(domain, property)
      },
    },
  )
}

const data = Object.fromEntries(
  DATA_DOMAINS.map((domain) => [domain, domainScaffold(domain)]),
) as unknown as DataSource

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

function wrapChannel(name: string): RealtimeChannel {
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

// Echo runs unchanged, only re-authorised (bearer). KARAR A11 — routing realtime events through
// `handle_realtime` so the ENGINE, not the UI cache, stays the single source of truth — needs
// `bridge/realtime.ts` plus a `handle_realtime` command, both F3-B. Until then the desktop
// subscribes exactly the way the web does.
const realtime: Platform['realtime'] = {
  connect: () => void connectEcho(),
  disconnect: disconnectEcho,
  channel: wrapChannel,
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
  data,
  connectivity,
  realtime,
  notify,
  capabilities,
  onlineOnly,
}

/**
 * One eager status read at startup, so `connectivity.isOnline()` reflects the engine rather than
 * its optimistic default within milliseconds of boot. Failure is non-fatal on purpose: the shell
 * must open even when the engine cannot answer.
 */
export function primeDesktopPlatform(): void {
  void readStatus().catch(() => undefined)
}
