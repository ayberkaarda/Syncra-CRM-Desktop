// Desktop platform adapter — `SYNCDESKTOP.md` §7.1, `docs/DESKTOP-ARCHITECTURE.md` §3.5.
//
// `data` is now the real thing: all 128 `DataSource` methods are bound, and the binding table
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
  Platform,
} from '@/platform/types'

import { realtimeChannel, startRealtimeBridge, stopRealtimeBridge } from '../bridge/realtime'
import { desktopData } from './data'
import { http } from './http'
import { onlineOnly } from './onlineOnly'
import {
  STATUS_POLL_MS,
  isEngineOnline,
  readStatus,
  subscribeToEngineStatus,
} from './status'

export { setDeviceToken } from './http'

// The engine-status store moved to `./status` so `platform/data/*` can read the online verdict
// for §8 without closing an import cycle through this module (see that file's header). It is
// re-exported here because `main.desktop.tsx`, `ui/ConnectivityBar.tsx` and `ui/useEngineStatus.ts`
// have always imported it from `platform/desktop` and nothing about their contract changed.
export { applyEngineStatus, getEngineStatus, refreshEngineStatus } from './status'
export { subscribeToEngineStatus }

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
  isOnline: isEngineOnline,
  subscribe(callback) {
    let previous: ConnState = isEngineOnline() ? 'online' : 'offline'

    const emit = (online: boolean): void => {
      const next: ConnState = online ? 'online' : 'offline'
      if (next === previous) return
      previous = next
      callback(next)
    }

    // The engine's own push feed (`EngineEvent::StatusChanged` -> `applyEngineStatus`). Without
    // it this adapter learned about a transition only on the next poll tick, i.e. up to
    // `STATUS_POLL_MS` late — tolerable for a status pill, NOT for §8's "disabled + tooltip":
    // for those five seconds every online-only trigger stayed enabled while the verb behind it
    // would already have refused. `emit` de-duplicates, so the poll below is now purely a
    // backstop and the two feeds cannot double-report a transition.
    const unsubscribe = subscribeToEngineStatus((status) => emit(status.online))

    const timer = setInterval(() => {
      void readStatus()
        .then((status) => emit(status.online))
        // A failed status read means the engine did not answer, which is not the same as the
        // server being unreachable — the last known value stands rather than being flipped.
        .catch(() => undefined)
    }, STATUS_POLL_MS)

    return () => {
      unsubscribe()
      clearInterval(timer)
    }
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

// `clipboard` is absent by design: K10 makes clipboard capture opt-in and default-off, and this
// set is what the UI reads to decide whether to offer the feature at all.
const capabilities = new Set<Capability>([
  'offline',
  'deep-link',
  'hotkey',
  'tray',
  'files',
  'screenshot',
  // F5-6. Declares that the SHELL can watch the clipboard, not that it currently is: the
  // watch is opt-in and off by default (`DesktopSettings::clipboard_capture`, K10). The
  // distinction is the same one every other entry makes — `files` does not mean a file is
  // open — and `platform.capabilities` is read to decide whether a feature can be offered at
  // all, which on this platform it can.
  'clipboard',
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
