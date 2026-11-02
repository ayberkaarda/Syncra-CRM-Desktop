// `frontend/src/lib/axios.ts` — a late 401 from a sign-in that has already ended must not sign
// the current one out (WP5).
//
// The shared instance is used by both builds: the web with the Sanctum cookie, the desktop with
// a bearer token. Each request is stamped with the session epoch (`lib/sessionEpoch.ts`, moved
// by the auth store) and, under the bearer transport, the token it went out with. A 401 or
// `USER_DEACTIVATED` only reaches `onUnauthorized` while that stamp is still current.
//
// `frontend/` has no test runner of its own; this suite runs under the desktop vitest project,
// which resolves `@` to `frontend/src` and so exercises the shared file itself.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/components/ui', () => ({ toast: { error: vi.fn(), warning: vi.fn(), success: vi.fn() } }))
vi.mock('@/i18n', () => ({ default: { t: (key: string) => key }, applyUserLocale: vi.fn() }))
vi.mock('@/lib/echo', () => ({ connectEcho: vi.fn(), disconnectEcho: vi.fn() }))

import { api, configureHttp, registerUnauthorizedHandler } from '@/lib/axios'
import { advanceSessionEpoch } from '@/lib/sessionEpoch'
import { queryClient } from '@/lib/queryClient'
import { useAuthStore } from '@/features/auth/store'
import { login, logout } from '@/features/auth/api/authApi'
import { applyMeResult, completeLogin, fetchCurrentUser } from '@/features/auth/hooks/useAuth'
import type { LoginPayload, User } from '@/features/auth/types'

type AdapterConfig = { url?: string; method?: string; headers: Record<string, unknown> }

/** One request that reached the network, waiting for the test to answer it. */
interface Call {
  config: AdapterConfig
  answer: (status: number, data?: unknown) => void
}

let calls: Call[] = []

/** Records each request and lets the test decide when and how it is answered. */
function fakeAdapter(config: AdapterConfig): Promise<unknown> {
  return new Promise((resolve, reject) => {
    calls.push({
      config,
      answer: (status, data = {}) => {
        const response = { data, status, statusText: '', headers: {}, config }
        if (status >= 200 && status < 300) {
          resolve(response)
        } else {
          reject(Object.assign(new Error(`HTTP ${status}`), { isAxiosError: true, config, response }))
        }
      },
    })
  })
}

async function nextCall(index: number): Promise<Call> {
  await vi.waitFor(() => expect(calls.length).toBeGreaterThan(index))
  return calls[index]
}

const onUnauthorized = vi.fn()
let bearer: string | undefined

function useCookie(): void {
  configureHttp({ baseURL: 'http://api.test', transport: 'cookie' })
}

function useBearer(): void {
  configureHttp({ baseURL: 'http://api.test', transport: 'bearer', getBearerToken: () => bearer })
}

const userA = { id: 1, name: 'A' } as unknown as User
const userB = { id: 2, name: 'B' } as unknown as User

beforeEach(() => {
  calls = []
  bearer = undefined
  onUnauthorized.mockReset()
  api.defaults.adapter = fakeAdapter as unknown as typeof api.defaults.adapter
  registerUnauthorizedHandler(onUnauthorized)
  useCookie()
})

afterEach(() => {
  useAuthStore.setState({ user: null, status: 'idle' })
})

describe('shared axios 401 handler — cookie transport (web)', () => {
  it('a 401 for the current session still signs out', async () => {
    const request = api.get('/api/deals')
    ;(await nextCall(0)).answer(401)

    await expect(request).rejects.toMatchObject({ response: { status: 401 } })
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('a 401 for a request sent before the session changed is dropped, and still rejects', async () => {
    const request = api.get('/api/deals')
    const call = await nextCall(0)

    // User A signs out and user B signs in while A's request is on the wire.
    advanceSessionEpoch()
    call.answer(401)

    await expect(request).rejects.toMatchObject({ response: { status: 401 } })
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('the auth store moves the epoch: A logs out, B logs in, A\'s late 401 leaves B alone', async () => {
    useAuthStore.getState().setUser(userA)
    useAuthStore.getState().setStatus('authenticated')

    const request = api.get('/api/deals')
    const call = await nextCall(0)

    useAuthStore.getState().clear()
    useAuthStore.getState().setUser(userB)
    useAuthStore.getState().setStatus('authenticated')
    call.answer(401)

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).not.toHaveBeenCalled()
    expect(useAuthStore.getState().user).toBe(userB)
  })

  it('refreshing the same user does not make its own requests look stale', async () => {
    useAuthStore.getState().setUser(userA)
    const request = api.get('/api/deals')
    const call = await nextCall(0)

    // e.g. a preference change writing the updated document back.
    useAuthStore.getState().setUser({ ...userA, name: 'A2' } as User)
    call.answer(401)

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('a stale USER_DEACTIVATED is dropped too', async () => {
    const request = api.get('/api/deals')
    const call = await nextCall(0)
    advanceSessionEpoch()
    call.answer(403, { errors: { code: 'USER_DEACTIVATED' } })

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('419 still refetches the CSRF cookie and retries once', async () => {
    const request = api.post('/api/deals', { name: 'x' })
    ;(await nextCall(0)).answer(419)

    const csrf = await nextCall(1)
    expect(csrf.config.url).toBe('/sanctum/csrf-cookie')
    csrf.answer(204)

    const retry = await nextCall(2)
    expect(retry.config.url).toBe('/api/deals')
    retry.answer(200, { ok: true })

    await expect(request).resolves.toMatchObject({ data: { ok: true } })
    expect(calls).toHaveLength(3)
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('a retried request that answers 401 in the same session still signs out', async () => {
    const request = api.post('/api/deals', { name: 'x' })
    ;(await nextCall(0)).answer(419)
    ;(await nextCall(1)).answer(204)
    ;(await nextCall(2)).answer(401)

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })
})

describe('shared axios 401 handler — bearer transport (desktop)', () => {
  beforeEach(() => {
    useBearer()
  })

  it('a 401 for the token still in use signs out', async () => {
    bearer = 'token-A'
    const request = api.get('/api/me/devices')
    const call = await nextCall(0)
    expect(call.config.headers.Authorization).toBe('Bearer token-A')
    call.answer(401)

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('a 401 for a token that has since been replaced is dropped', async () => {
    bearer = 'token-A'
    const request = api.get('/api/me/devices')
    const call = await nextCall(0)

    // The engine signed in again (same user, new device token) without the store changing.
    bearer = 'token-B'
    call.answer(401)

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('a 401 on a request that carried no token still reaches the handler while none is set', async () => {
    // `installUnauthorizedGuard` relies on this to recover the token from the engine.
    const request = api.get('/api/me/devices')
    ;(await nextCall(0)).answer(401)

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })
})

// ------------------------------------------------------------------------------------------------
// The web sign-in flow end to end: `authApi.login` -> `completeLogin` (what `useAuth`'s login
// mutation runs on success), `fetchCurrentUser` (the `me` query function) and `applyMeResult`
// (what `useAuth`'s effect writes once that query settles). Only the network is faked.
// ------------------------------------------------------------------------------------------------

/** The `n`-th request to `url` (0-based), once it has gone out. */
async function callTo(url: string, n = 0): Promise<Call> {
  await vi.waitFor(() => expect(calls.filter((c) => c.config.url === url).length).toBeGreaterThan(n))
  return calls.filter((c) => c.config.url === url)[n]
}

function countTo(url: string): number {
  return calls.filter((c) => c.config.url === url).length
}

/** Let every pending interceptor and adapter step run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 20))

/** Sign in through the real `authApi.login`, answering its CSRF and login requests. */
async function signIn(user: User): Promise<void> {
  const csrfBefore = countTo('/sanctum/csrf-cookie')
  const loginsBefore = countTo('/api/login')
  const pending = login({ email: 'x@example.com', password: 'secret' } as LoginPayload)
  ;(await callTo('/sanctum/csrf-cookie', csrfBefore)).answer(204)
  ;(await callTo('/api/login', loginsBefore)).answer(200, { data: user })
  completeLogin(queryClient, await pending)
}

describe('web sign-in flow', () => {
  beforeEach(() => {
    queryClient.clear()
  })

  /** Mirrors `useAuth`: run the `me` query and write its outcome into the store. */
  function runMeQuery(): Promise<void> {
    return queryClient
      .fetchQuery({ queryKey: ['auth', 'me'], queryFn: fetchCurrentUser, retry: false })
      .then(
        (user) => applyMeResult({ ok: true, user }),
        () => applyMeResult({ ok: false }),
      )
  }

  it('a /me pending across a login that answers 401 late does not sign the new user out', async () => {
    const meDone = runMeQuery()
    const staleMe = await callTo('/api/me')

    await signIn(userB)
    staleMe.answer(401)

    // Asked again for the session now in effect.
    ;(await callTo('/api/me', 1)).answer(200, { data: userB })
    await meDone

    expect(useAuthStore.getState().status).toBe('authenticated')
    expect(useAuthStore.getState().user?.id).toBe(2)
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('a /me pending across a login that answers with the previous user is not written', async () => {
    const meDone = runMeQuery()
    const staleMe = await callTo('/api/me')

    await signIn(userB)
    staleMe.answer(200, { data: userA })
    ;(await callTo('/api/me', 1)).answer(200, { data: userB })
    await meDone

    expect(useAuthStore.getState().user?.id).toBe(2)
  })

  it("a stale request's late 419 is not replayed with the new session's cookie", async () => {
    await signIn(userA)
    const loggingOut = logout()
    const staleLogout = await callTo('/api/logout')

    await signIn(userB)
    const csrfBefore = countTo('/sanctum/csrf-cookie')
    staleLogout.answer(419)

    await expect(loggingOut).rejects.toMatchObject({ response: { status: 419 } })
    await settle()
    expect(countTo('/sanctum/csrf-cookie')).toBe(csrfBefore)
    expect(countTo('/api/logout')).toBe(1)
  })

  it('a 419 whose CSRF refetch is overtaken by a login is not replayed', async () => {
    await signIn(userA)
    const loggingOut = logout()
    const csrfBefore = countTo('/sanctum/csrf-cookie')
    ;(await callTo('/api/logout')).answer(419)
    const refetch = await callTo('/sanctum/csrf-cookie', csrfBefore)

    await signIn(userB)
    refetch.answer(204)

    await expect(loggingOut).rejects.toMatchObject({ response: { status: 419 } })
    await settle()
    expect(countTo('/api/logout')).toBe(1)
  })

  it("signing in again as the same user ends the previous session's requests", async () => {
    await signIn(userA)
    const request = api.get('/api/deals')
    const stale = await callTo('/api/deals')

    await signIn(userA)
    stale.answer(401)

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).not.toHaveBeenCalled()
  })

  it('CONTROL: without a new login the same 401 still signs out', async () => {
    await signIn(userA)
    const request = api.get('/api/deals')
    ;(await callTo('/api/deals')).answer(401)

    await expect(request).rejects.toBeDefined()
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })
})
