import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { Channel, ChannelAuthorizationCallback } from 'pusher-js'
import { api } from './axios'

// laravel-echo's Pusher connector reads the client off `window.Pusher`.
declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

export type SyncraEcho = Echo<'reverb'>

/**
 * Connection status surfaced to the rest of the app. Narrower than
 * pusher-js's own `ConnectionState` — `initialized` and `disconnected` are
 * folded into `unavailable` since neither means "actively connected" and the
 * UI only needs to distinguish "trying", "up", "down (retrying)" and
 * "gave up".
 */
export type EchoConnectionState = 'connecting' | 'connected' | 'unavailable' | 'failed'

type StateListener = (state: EchoConnectionState) => void

let echoInstance: SyncraEcho | null = null
let connectionState: EchoConnectionState = 'unavailable'
const stateListeners = new Set<StateListener>()

function mapPusherState(state: string): EchoConnectionState {
  switch (state) {
    case 'connecting':
      return 'connecting'
    case 'connected':
      return 'connected'
    case 'failed':
      return 'failed'
    // 'initialized' | 'disconnected' | anything else pusher-js may add later.
    default:
      return 'unavailable'
  }
}

function setConnectionState(next: EchoConnectionState) {
  if (next === connectionState) return
  connectionState = next
  stateListeners.forEach((listener) => listener(next))
}

/**
 * Lazily creates (or returns the existing) Echo instance wired to our
 * Reverb server. Building the instance does not by itself open a socket —
 * pusher-js only connects once `.connect()` is called (see `connectEcho`) —
 * but this is still not exported: callers go through `connectEcho`/
 * `disconnectEcho` so the connection lifecycle stays tied to auth state.
 */
function createEcho(): SyncraEcho {
  if (echoInstance) {
    return echoInstance
  }

  if (typeof window !== 'undefined') {
    window.Pusher = Pusher
  }

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT),
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    // Authorize private/presence channels through our Sanctum-aware axios
    // instance so the session cookie + XSRF header (and the 419 CSRF retry)
    // ride along automatically.
    authorizer: (channel: Channel) => ({
      authorize(socketId: string, callback: ChannelAuthorizationCallback) {
        api
          .post('/broadcasting/auth', {
            socket_id: socketId,
            channel_name: channel.name,
          })
          .then((response) => callback(null, response.data))
          .catch((error) => callback(error, null))
      },
    }),
  })

  // pusher-js is the source of truth for connection state; forward its
  // `state_change` event as-is, no polling. Reconnection itself is entirely
  // pusher-js's job — this only observes and republishes.
  echoInstance.connector.pusher.connection.bind(
    'state_change',
    ({ current }: { previous: string; current: string }) => {
      setConnectionState(mapPusherState(current))
    }
  )

  return echoInstance
}

/**
 * Opens the realtime connection. Call only once the user is authenticated —
 * an anonymous visitor has nothing to subscribe to, so there is no reason to
 * open a socket for them. Safe to call again on an already-connected/
 * connecting instance (pusher-js no-ops).
 */
export function connectEcho(): SyncraEcho {
  const echo = createEcho()
  echo.connect()
  return echo
}

/** Disconnects and clears the current Echo instance, e.g. on logout. */
export function disconnectEcho() {
  echoInstance?.disconnect()
  echoInstance = null
  setConnectionState('unavailable')
}

/**
 * Returns the current Echo instance without creating or connecting one.
 * Feature hooks (presence, realtime session) use this to join channels —
 * only `connectEcho`/`disconnectEcho` own the connection lifecycle.
 */
export function getEcho(): SyncraEcho | null {
  return echoInstance
}

/**
 * Subscribes to connection-state changes and immediately replays the
 * current state to the new listener. Returns an unsubscribe function.
 */
export function onConnectionStateChange(listener: StateListener): () => void {
  stateListeners.add(listener)
  listener(connectionState)
  return () => {
    stateListeners.delete(listener)
  }
}
