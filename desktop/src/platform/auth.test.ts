// `platform/auth.ts` `handleAuthLost` — `EngineEvent::AuthLost` names the session it is about
// (`token_id`), and an event that arrives after the webview adopted the NEXT session must not
// sign that session out (WP5).
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/components/ui', () => ({ toast: { error: vi.fn(), warning: vi.fn(), success: vi.fn() } }))
vi.mock('@/i18n', () => ({ default: { t: (key: string) => key }, applyUserLocale: vi.fn() }))
vi.mock('@/lib/echo', () => ({ connectEcho: vi.fn(), disconnectEcho: vi.fn() }))
vi.mock('@/router', () => ({ router: { navigate: vi.fn() } }))
vi.mock('../bridge/invoke', () => ({ invokeCommand: vi.fn() }))
vi.mock('./bootstrap', () => ({ startBootstrapIfEmpty: vi.fn() }))

import { useAuthStore } from '@/features/auth/store'
import type { User } from '@/features/auth/types'

import { queryClient } from '@/lib/queryClient'
import { login, logout } from '@/features/auth/api/authApi'
import { completeLogin } from '@/features/auth/hooks/useAuth'
import type { LoginPayload } from '@/features/auth/types'

import { invokeCommand } from '../bridge/invoke'
import {
  adoptSession,
  forgetSession,
  getDesktopSession,
  handleAuthLost,
  installDesktopAuth,
  restoreDesktopSession,
  type DesktopSession,
} from './auth'
import { getDeviceToken } from './http'

function session(tokenId: number, userId: number): DesktopSession {
  return {
    token_id: tokenId,
    user_id: userId,
    user: { id: userId, must_change_password: false } as unknown as User,
    must_change_password: false,
    abilities: ['desktop'],
  }
}

beforeEach(() => {
  forgetSession()
})

describe('handleAuthLost', () => {
  it('signs out the session the event names', () => {
    adoptSession(session(10, 1), { token: 'token-A' })

    handleAuthLost(10)

    expect(getDesktopSession()).toBeNull()
    expect(getDeviceToken()).toBeUndefined()
    expect(useAuthStore.getState().status).toBe('unauthenticated')
  })

  it("ignores a late event about user A after user B signed in", () => {
    adoptSession(session(10, 1), { token: 'token-A' })
    forgetSession()
    adoptSession(session(20, 2), { token: 'token-B' })

    handleAuthLost(10)

    expect(getDesktopSession()?.token_id).toBe(20)
    expect(getDeviceToken()).toBe('token-B')
    expect(useAuthStore.getState().status).toBe('authenticated')
    expect(useAuthStore.getState().user?.id).toBe(2)
  })

  it('ignores a late event about the previous token of the same user', () => {
    adoptSession(session(11, 1), { token: 'token-A2' })

    handleAuthLost(10)

    expect(getDesktopSession()?.token_id).toBe(11)
    expect(getDeviceToken()).toBe('token-A2')
  })

  it('ignores an event that names no session while one is held', () => {
    adoptSession(session(10, 1), { token: 'token-A' })

    handleAuthLost(null)

    expect(getDesktopSession()?.token_id).toBe(10)
    expect(useAuthStore.getState().status).toBe('authenticated')
  })

  it('with no session held it still clears the store, as before', () => {
    useAuthStore.setState({ status: 'loading' })

    handleAuthLost(10)

    expect(useAuthStore.getState().status).toBe('unauthenticated')
  })
})

// ------------------------------------------------------------------------------------------------
// `revalidateSession` — the background `restore` that `restoreDesktopSession` starts. Driven
// through the real routes `installDesktopAuth` puts on the shared axios instance (`authApi`
// login/logout -> the engine commands); only the Tauri command boundary is faked.
// ------------------------------------------------------------------------------------------------

describe('background restore overtaken by another sign-in', () => {
  /** What the fake engine holds. */
  let engine: { session: DesktopSession; token: string } | null
  let restore: { reject: (error: unknown) => void; resolve: (value: unknown) => void }

  beforeEach(() => {
    vi.stubGlobal('window', {
      location: { pathname: '/', search: '' },
      history: { replaceState: vi.fn() },
      setTimeout,
    })
    installDesktopAuth()
    engine = { session: session(10, 1), token: 'token-A' }
    vi.mocked(invokeCommand).mockImplementation(async (command: string) => {
      switch (command) {
        case 'session':
          return engine === null ? { session: null, token: null } : engine
        case 'restore':
          return new Promise((resolve, reject) => {
            restore = { resolve, reject }
          })
        case 'logout':
          engine = null
          return 'wiped'
        case 'login':
          engine = { session: session(20, 2), token: 'token-B' }
          return engine.session
        default:
          throw new Error(`unexpected command ${command}`)
      }
    })
  })

  const flush = () => new Promise((resolve) => setTimeout(resolve, 20))

  it("A's late AUTH_REQUIRED does not sign out B, who signed in meanwhile", async () => {
    await restoreDesktopSession()
    expect(getDesktopSession()?.token_id).toBe(10)

    await logout()
    completeLogin(queryClient, await login({ email: 'b@example.com', password: 'x' } as LoginPayload))
    expect(getDesktopSession()?.token_id).toBe(20)

    // The engine kept B (A's token generation is gone) but answers A's restore with the 401.
    restore.reject({ code: 'AUTH_REQUIRED' })
    await flush()

    expect(getDesktopSession()?.token_id).toBe(20)
    expect(getDeviceToken()).toBe('token-B')
    expect(useAuthStore.getState().status).toBe('authenticated')
    expect(useAuthStore.getState().user?.id).toBe(2)
  })

  it('CONTROL: AUTH_REQUIRED for the session still held signs it out', async () => {
    await restoreDesktopSession()

    restore.reject({ code: 'AUTH_REQUIRED' })
    await flush()

    expect(getDesktopSession()).toBeNull()
    expect(useAuthStore.getState().status).toBe('unauthenticated')
  })
})
