// `frontend/src/features/auth/personalChannel.ts` — the one gate on `private-user.{id}`.
//
// The server refuses the personal channel while `must_change_password` is true
// (`backend/routes/channels.php`). The four web hooks that share it and the desktop bridge read
// the user id through `personalChannelUserId`, so (1) a forced-change user never asks for it and
// (2) completing the change flips the selector from `undefined` to the id — the user id itself
// does not change, which is why keying on `user.id` left the channel off until a reload.
//
// `frontend/` has no test runner of its own; this suite runs under the desktop vitest project,
// which resolves `@` to `frontend/src` and so exercises the shared files themselves.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/components/ui', () => ({ toast: { error: vi.fn(), warning: vi.fn(), success: vi.fn() } }))
vi.mock('@/i18n', () => ({ default: { t: (key: string) => key }, applyUserLocale: vi.fn() }))
vi.mock('@/lib/echo', () => ({ connectEcho: vi.fn(), disconnectEcho: vi.fn() }))

import { api, configureHttp } from '@/lib/axios'
import { queryClient } from '@/lib/queryClient'
import { useAuthStore } from '@/features/auth/store'
import type { AuthStatus } from '@/features/auth/store'
import { changePassword } from '@/features/auth/api/authApi'
import { completePasswordChange } from '@/features/auth/hooks/useAuth'
import { personalChannelUserId } from '@/features/auth/personalChannel'
import type { User } from '@/features/auth/types'

type AdapterConfig = { url?: string; method?: string; data?: unknown; headers: Record<string, unknown> }

const requests: AdapterConfig[] = []
let serverUser: User

/** The network, and only the network: answers `POST /api/password/change` like the server. */
function fakeAdapter(config: AdapterConfig): Promise<unknown> {
  requests.push(config)
  return Promise.resolve({ data: { data: serverUser }, status: 200, statusText: '', headers: {}, config })
}

const forced = { id: 7, name: 'U', must_change_password: true } as unknown as User

beforeEach(() => {
  requests.length = 0
  configureHttp({ baseURL: 'http://api.test', transport: 'cookie' })
  api.defaults.adapter = fakeAdapter as unknown as typeof api.defaults.adapter
})

afterEach(() => {
  useAuthStore.setState({ user: null, status: 'idle' })
  queryClient.clear()
})

describe('personalChannelUserId', () => {
  const statuses: AuthStatus[] = ['idle', 'loading', 'authenticated', 'unauthenticated']

  for (const status of statuses) {
    for (const flag of [true, false]) {
      const expected = status === 'authenticated' && !flag ? 7 : undefined
      it(`status=${status}, must_change_password=${flag} -> ${String(expected)}`, () => {
        expect(personalChannelUserId({ status, user: { id: 7, must_change_password: flag } })).toBe(expected)
      })
    }
    it(`status=${status}, no user -> undefined`, () => {
      expect(personalChannelUserId({ status, user: null })).toBeUndefined()
    })
  }
})

describe('password change completion opens the personal channel without a reload', () => {
  it('flips the selector from undefined to the same user id', async () => {
    useAuthStore.getState().setUser(forced)
    useAuthStore.getState().setStatus('authenticated')
    expect(personalChannelUserId(useAuthStore.getState())).toBeUndefined()

    const seen: Array<number | undefined> = []
    const stop = useAuthStore.subscribe((state) => seen.push(personalChannelUserId(state)))

    serverUser = { ...forced, must_change_password: false }
    const updated = await changePassword({
      current_password: 'old',
      password: 'New-pass1!',
      password_confirmation: 'New-pass1!',
    })
    completePasswordChange(queryClient, updated)
    stop()

    expect(requests).toHaveLength(1)
    expect(requests[0].url).toBe('/api/password/change')
    expect(useAuthStore.getState().user?.id).toBe(7)
    expect(useAuthStore.getState().user?.must_change_password).toBe(false)
    expect(personalChannelUserId(useAuthStore.getState())).toBe(7)
    expect(seen).toContain(7)
    // The `me` cache carries the same document, so a later read does not resurrect the flag.
    expect(queryClient.getQueryData(['auth', 'me'])).toEqual(updated)
  })
})
