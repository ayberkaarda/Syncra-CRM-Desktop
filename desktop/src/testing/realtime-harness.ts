// Test-only: a REAL laravel-echo over a REAL pusher-js client whose socket is stubbed.
//
// The point is fidelity on the parts a hand-written fake would get wrong: pusher-js 8 keeps a
// channel whose authorization is still in flight when it is unsubscribed (it only marks it
// cancelled), re-subscribing reinstates that in-flight request instead of authorizing again,
// and a failed authorization leaves the channel unsubscribed for good. laravel-echo caches its
// channel objects and `leave()` drops them. Both libraries are loaded from `frontend/`'s
// `node_modules` — the copies the app ships — by path, so this runs their published
// builds and none of their behaviour is re-implemented here.
//
// What is stubbed: the websocket. The client is disconnected right after construction, then
// marked `connected` with a socket id, and `send_event` is captured. Authorization goes through
// a custom authorizer the test settles by hand; a granted one is followed by the server's
// `pusher_internal:subscription_succeeded`, exactly the frame Reverb answers a valid
// `pusher:subscribe` with.
import { createRequire } from 'node:module'

// ESM build: the package is `type: module`, so its `.common.js` cannot be `require`d.
import EchoModule from '../../../frontend/node_modules/laravel-echo/dist/echo.js'

import type { SyncraEcho } from '@/lib/echo'

const require = createRequire(import.meta.url)

type AuthCallback = (error: Error | null, data: { auth: string } | null) => void

type PusherChannelLike = {
  subscribed: boolean
  subscriptionPending: boolean
  subscriptionCancelled: boolean
  handleEvent(event: { event: string; data: unknown }): void
}

type PusherLike = {
  disconnect(): void
  channel(name: string): PusherChannelLike | undefined
  connection: {
    state: string
    socket_id: string
    send_event: (event: string, data: unknown, channel?: string) => boolean
  }
}

type PusherCtor = new (key: string, options: Record<string, unknown>) => PusherLike
type EchoCtor = new (options: Record<string, unknown>) => SyncraEcho

const Pusher = require('../../../frontend/node_modules/pusher-js') as PusherCtor
const Echo = EchoModule as unknown as EchoCtor

/** One `/api/broadcasting/auth` request, waiting for the test to answer it. */
export interface PendingAuth {
  channel: string
  settled: boolean
  /** The server authorizes: pusher sends `pusher:subscribe`, Reverb confirms it. */
  grant(): void
  /** The server refuses (the personal channel while a password change is forced). */
  refuse(status?: number): void
}

export interface RealtimeHarness {
  echo: SyncraEcho
  pusher: PusherLike
  auths: PendingAuth[]
  /** Authorization requests for one wire channel name, oldest first. */
  authsFor(channel: string): PendingAuth[]
  /** `pusher:unsubscribe` frames sent, by channel. */
  unsubscribes: string[]
  /** Whether pusher holds a confirmed subscription for the wire name. */
  isSubscribed(channel: string): boolean
  /** Deliver a server frame on a channel (event name as pusher sees it, no leading dot). */
  deliver(channel: string, event: string, data?: unknown): void
}

export function createRealtimeHarness(): RealtimeHarness {
  const auths: PendingAuth[] = []
  const unsubscribes: string[] = []

  const pusher = new Pusher('test-key', {
    cluster: '',
    wsHost: '127.0.0.1',
    wsPort: 1,
    forceTLS: false,
    enabledTransports: ['ws'],
    authorizer: (channel: { name: string }) => ({
      authorize: (_socketId: string, callback: AuthCallback) => {
        const auth: PendingAuth = {
          channel: channel.name,
          settled: false,
          grant() {
            if (auth.settled) throw new Error(`auth for ${channel.name} already settled`)
            auth.settled = true
            callback(null, { auth: 'signature' })
            pusher.channel(channel.name)?.handleEvent({ event: 'pusher_internal:subscription_succeeded', data: {} })
          },
          refuse(status = 403) {
            if (auth.settled) throw new Error(`auth for ${channel.name} already settled`)
            auth.settled = true
            callback(Object.assign(new Error(`HTTP ${status}`), { status }), null)
          },
        }
        auths.push(auth)
      },
    }),
  })

  // No socket: stop the connection attempt the constructor started, then present a connected
  // client so `subscribe()` authorizes immediately, as it does on a live connection.
  pusher.disconnect()
  pusher.connection.state = 'connected'
  pusher.connection.socket_id = '1234.5678'
  pusher.connection.send_event = (event, data) => {
    if (event === 'pusher:unsubscribe') unsubscribes.push((data as { channel: string }).channel)
    return true
  }

  const echo = new Echo({ broadcaster: 'reverb', key: 'test-key', client: pusher, withoutInterceptors: true })

  return {
    echo,
    pusher,
    auths,
    authsFor: (channel) => auths.filter((auth) => auth.channel === channel),
    unsubscribes,
    isSubscribed: (channel) => pusher.channel(channel)?.subscribed === true,
    deliver: (channel, event, data = {}) => pusher.channel(channel)?.handleEvent({ event, data }),
  }
}
