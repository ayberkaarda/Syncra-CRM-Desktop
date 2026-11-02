// `frontend/src/lib/channelRegistry.ts` against the real laravel-echo / pusher-js pair
// (`testing/realtime-harness.ts`).
//
// The late-403 case: an authorization is in flight, every holder releases the channel, a holder
// acquires it again before the answer arrives. pusher-js reinstates the in-flight request rather
// than authorizing again, so when that old request is refused the channel the new holder owns is
// never subscribed — unless the registry asks for a fresh authorization.
//
// `frontend/` has no test runner of its own; this suite runs under the desktop vitest project.
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { createRealtimeHarness, type RealtimeHarness } from '../testing/realtime-harness'

const holder = vi.hoisted(() => ({ harness: null as RealtimeHarness | null }))

vi.mock('@/lib/echo', () => ({ getEcho: () => holder.harness?.echo ?? null }))

import { acquireChannel, releaseChannel } from '@/lib/channelRegistry'

// The registry is module state: a fresh channel name per test keeps one test's failure (a hold
// it never released) from leaking into the next.
let serial = 0
let NAME = ''
let WIRE = ''

let h: RealtimeHarness

beforeEach(() => {
  serial += 1
  NAME = `user.${serial}`
  WIRE = `private-${NAME}`
  h = createRealtimeHarness()
  holder.harness = h
})

describe('channelRegistry — late authorization failure', () => {
  it('a re-acquire whose inherited authorization is refused gets a fresh one', () => {
    acquireChannel(NAME)
    expect(h.authsFor(WIRE)).toHaveLength(1)

    releaseChannel(NAME)
    const channel = acquireChannel(NAME)!
    // pusher-js semantics: the in-flight request was reinstated, nothing new went out.
    expect(h.authsFor(WIRE)).toHaveLength(1)

    const received: unknown[] = []
    const onFrame = (payload: unknown) => received.push(payload)
    channel.listen('.notification.created', onFrame)

    h.authsFor(WIRE)[0].refuse(403)
    expect(h.authsFor(WIRE)).toHaveLength(2)

    h.authsFor(WIRE)[1].grant()
    expect(h.isSubscribed(WIRE)).toBe(true)
    h.deliver(WIRE, 'notification.created', { id: 1 })
    expect(received).toEqual([{ id: 1 }])

    channel.stopListening('.notification.created', onFrame)
    releaseChannel(NAME)
  })

  it('retries once, not forever', () => {
    acquireChannel(NAME)
    h.authsFor(WIRE)[0].refuse(403)
    expect(h.authsFor(WIRE)).toHaveLength(2)
    h.authsFor(WIRE)[1].refuse(403)
    expect(h.authsFor(WIRE)).toHaveLength(2)
    expect(h.isSubscribed(WIRE)).toBe(false)
    releaseChannel(NAME)
  })

  it('a refusal for a channel nobody holds any more does not resubscribe it', () => {
    acquireChannel(NAME)
    releaseChannel(NAME)
    h.authsFor(WIRE)[0].refuse(403)

    expect(h.authsFor(WIRE)).toHaveLength(1)
    expect(h.echo.connector.channels[WIRE]).toBeUndefined()
  })

  it('a successful subscription restores the retry for a later failure', () => {
    acquireChannel(NAME)
    h.authsFor(WIRE)[0].refuse(403)
    h.authsFor(WIRE)[1].grant()
    expect(h.isSubscribed(WIRE)).toBe(true)
    releaseChannel(NAME)
    expect(h.unsubscribes).toEqual([WIRE])
  })
})

describe('channelRegistry — StrictMode-style double acquire/release', () => {
  it('shares one subscription and leaves exactly once', () => {
    const first = acquireChannel(NAME)
    const second = acquireChannel(NAME)
    expect(second).toBe(first)
    expect(h.authsFor(WIRE)).toHaveLength(1)
    h.authsFor(WIRE)[0].grant()

    releaseChannel(NAME)
    expect(h.isSubscribed(WIRE)).toBe(true)
    expect(h.unsubscribes).toEqual([])

    releaseChannel(NAME)
    expect(h.unsubscribes).toEqual([WIRE])
    expect(h.echo.connector.channels[WIRE]).toBeUndefined()

    // A later mount starts over with a fresh authorization.
    acquireChannel(NAME)
    expect(h.authsFor(WIRE)).toHaveLength(2)
    releaseChannel(NAME)
  })

  it('mount, unmount, mount while authorizing ends subscribed', () => {
    acquireChannel(NAME)
    releaseChannel(NAME)
    acquireChannel(NAME)
    h.authsFor(WIRE)[0].grant()
    expect(h.isSubscribed(WIRE)).toBe(true)
    releaseChannel(NAME)
  })
})
