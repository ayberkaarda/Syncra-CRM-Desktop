// `bridge/realtime.ts` — the desktop's own hold on `private-user.{id}`, driven through the real
// laravel-echo / pusher-js pair (`testing/realtime-harness.ts`), the real channel registry and
// the real auth store.
//
// The server refuses that channel while `must_change_password` is true. The bridge must not ask
// for it then, must join it the moment a password change completes (the user id does not change,
// so an id comparison never fires), must actually LEAVE it when eligibility ends, and must not be
// torn down by a web hook releasing its own hold.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createRealtimeHarness, type RealtimeHarness } from '../testing/realtime-harness'

const holder = vi.hoisted(() => ({ harness: null as RealtimeHarness | null }))

vi.mock('@/components/ui', () => ({ toast: { error: vi.fn(), warning: vi.fn(), success: vi.fn() } }))
vi.mock('@/i18n', () => ({ default: { t: (key: string) => key }, applyUserLocale: vi.fn() }))
vi.mock('@/lib/echo', () => ({
  getEcho: () => holder.harness?.echo ?? null,
  // The tests drive reconciliation through the auth store; the connection never changes.
  onConnectionStateChange: () => () => {},
  connectEcho: vi.fn(),
  disconnectEcho: vi.fn(),
}))
vi.mock('./invoke', () => ({ invokeCommand: vi.fn(() => Promise.resolve()) }))

import { api, configureHttp } from '@/lib/axios'
import { acquireChannel, releaseChannel } from '@/lib/channelRegistry'
import { queryClient } from '@/lib/queryClient'
import { useAuthStore } from '@/features/auth/store'
import { changePassword } from '@/features/auth/api/authApi'
import { completePasswordChange } from '@/features/auth/hooks/useAuth'
import type { User } from '@/features/auth/types'

import { invokeCommand } from './invoke'
import { startRealtimeBridge, stopRealtimeBridge } from './realtime'

const WIRE = 'private-user.7'
const forced = { id: 7, name: 'U', must_change_password: true } as unknown as User
const cleared = { ...forced, must_change_password: false } as User

let h: RealtimeHarness

/** The real change-password completion, with only the network faked. */
async function completeChange(): Promise<void> {
  api.defaults.adapter = ((config: unknown) =>
    Promise.resolve({ data: { data: cleared }, status: 200, statusText: '', headers: {}, config })) as unknown as typeof api.defaults.adapter
  const user = await changePassword({ current_password: 'old', password: 'New-pass1!', password_confirmation: 'New-pass1!' })
  completePasswordChange(queryClient, user)
}

beforeEach(() => {
  h = createRealtimeHarness()
  holder.harness = h
  configureHttp({ baseURL: 'http://api.test', transport: 'cookie' })
  vi.mocked(invokeCommand).mockClear()
})

afterEach(() => {
  stopRealtimeBridge()
  useAuthStore.setState({ user: null, status: 'idle' })
  queryClient.clear()
})

describe('desktop realtime bridge — personal channel lifecycle', () => {
  it('does not ask for the personal channel while the password change is forced', () => {
    useAuthStore.setState({ user: forced, status: 'authenticated' })
    startRealtimeBridge()

    expect(h.authsFor('private-deals')).toHaveLength(1)
    expect(h.authsFor(WIRE)).toHaveLength(0)
  })

  it('ineligible -> eligible: joins as soon as the password change completes, and routes frames', async () => {
    useAuthStore.setState({ user: forced, status: 'authenticated' })
    startRealtimeBridge()

    await completeChange()

    expect(h.authsFor(WIRE)).toHaveLength(1)
    h.authsFor(WIRE)[0].grant()
    expect(h.isSubscribed(WIRE)).toBe(true)

    h.deliver(WIRE, 'chat.unread', { conversation_id: 1 })
    await vi.waitFor(() =>
      expect(invokeCommand).toHaveBeenCalledWith('handle_realtime', expect.anything()),
    )
  })

  it('eligible -> ineligible: the bridge leaves a channel nobody else holds', () => {
    useAuthStore.setState({ user: cleared, status: 'authenticated' })
    startRealtimeBridge()
    h.authsFor(WIRE)[0].grant()
    expect(h.isSubscribed(WIRE)).toBe(true)

    useAuthStore.getState().setUser(forced)

    expect(h.unsubscribes).toContain(WIRE)
    expect(h.echo.connector.channels[WIRE]).toBeUndefined()
  })

  it('a web hook releasing its hold does not tear down the bridge subscription', () => {
    useAuthStore.setState({ user: cleared, status: 'authenticated' })
    startRealtimeBridge()
    h.authsFor(WIRE)[0].grant()

    // A hook mounts and unmounts (StrictMode does both twice).
    acquireChannel('user.7')
    acquireChannel('user.7')
    releaseChannel('user.7')
    releaseChannel('user.7')

    expect(h.isSubscribed(WIRE)).toBe(true)
    expect(h.unsubscribes).not.toContain(WIRE)

    stopRealtimeBridge()
    expect(h.unsubscribes).toContain(WIRE)
  })

  it('a late 403 after a quick ineligible -> eligible flip still ends subscribed', () => {
    useAuthStore.setState({ user: cleared, status: 'authenticated' })
    startRealtimeBridge()
    expect(h.authsFor(WIRE)).toHaveLength(1)

    // Eligibility drops and returns while that first authorization is still in flight.
    useAuthStore.getState().setUser(forced)
    useAuthStore.getState().setUser(cleared)
    expect(h.authsFor(WIRE)).toHaveLength(1)

    h.authsFor(WIRE)[0].refuse(403)
    expect(h.authsFor(WIRE)).toHaveLength(2)
    h.authsFor(WIRE)[1].grant()
    expect(h.isSubscribed(WIRE)).toBe(true)
  })

  it('signing out releases the hold', () => {
    useAuthStore.setState({ user: cleared, status: 'authenticated' })
    startRealtimeBridge()
    h.authsFor(WIRE)[0].grant()

    useAuthStore.getState().clear()

    expect(h.unsubscribes).toContain(WIRE)
  })
})
