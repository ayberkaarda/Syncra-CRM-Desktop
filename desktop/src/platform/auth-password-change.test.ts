// `platform/auth.ts` `adoptChangedPasswordUser` — a completed password change reaches the
// desktop session, not only the auth store.
//
// On the desktop `GET /api/me` is answered from the webview's session copy, and that copy's
// top-level `must_change_password` wins over the user document (`sessionUser`). The shared
// `completePasswordChange` only writes the store and the `me` cache, so the next `/api/me`
// refetch — or the re-adoption after a cancelled logout, which reads the same copy — brought the
// forced flag back: the personal realtime channel closed again and `RequireAuth` bounced the user
// to `/change-password`.
//
// Driven through the real shared change-password call, the real routes `installDesktopAuth`
// puts on the shared axios instance and the real `/api/me` read; only the network and the Tauri
// command boundary are faked.
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/components/ui', () => ({ toast: { error: vi.fn(), warning: vi.fn(), success: vi.fn() } }))
vi.mock('@/i18n', () => ({ default: { t: (key: string) => key }, applyUserLocale: vi.fn() }))
vi.mock('@/lib/echo', () => ({ connectEcho: vi.fn(), disconnectEcho: vi.fn() }))
vi.mock('@/router', () => ({ router: { navigate: vi.fn() } }))
vi.mock('../bridge/invoke', () => ({ invokeCommand: vi.fn(() => Promise.resolve(null)) }))
vi.mock('./bootstrap', () => ({ startBootstrapIfEmpty: vi.fn() }))

import { api, configureHttp } from '@/lib/axios'
import { queryClient } from '@/lib/queryClient'
import { useAuthStore } from '@/features/auth/store'
import { changePassword } from '@/features/auth/api/authApi'
import { applyMeResult, completePasswordChange, fetchCurrentUser } from '@/features/auth/hooks/useAuth'
import { personalChannelUserId } from '@/features/auth/personalChannel'
import type { User } from '@/features/auth/types'

import { invokeCommand } from '../bridge/invoke'
import { adoptSession, forgetSession, getDesktopSession, installDesktopAuth, type DesktopSession } from './auth'

const forcedUser = { id: 7, name: 'U', must_change_password: true } as unknown as User

function forcedSession(): DesktopSession {
  return { token_id: 70, user_id: 7, user: forcedUser, must_change_password: true, abilities: ['desktop'] }
}

/** The server's answer to `POST /api/password/change`. */
let changedUser: User

beforeEach(() => {
  forgetSession()
  queryClient.clear()
  installDesktopAuth()
  configureHttp({ baseURL: 'http://api.test', transport: 'bearer', getBearerToken: () => 'token' })
  api.defaults.adapter = ((config: unknown) =>
    Promise.resolve({ data: { data: changedUser }, status: 200, statusText: '', headers: {}, config })) as unknown as typeof api.defaults.adapter
  vi.mocked(invokeCommand).mockClear()
})

async function changeThePassword(): Promise<void> {
  const user = await changePassword({ current_password: 'old', password: 'New-pass1!', password_confirmation: 'New-pass1!' })
  completePasswordChange(queryClient, user)
}

describe('desktop password change completion', () => {
  it('a later /api/me answers with the cleared flag, so the personal channel stays open', async () => {
    adoptSession(forcedSession(), { token: 'token' })
    expect(personalChannelUserId(useAuthStore.getState())).toBeUndefined()

    changedUser = { ...forcedUser, must_change_password: false }
    await changeThePassword()
    expect(personalChannelUserId(useAuthStore.getState())).toBe(7)

    // Another `useAuth()` consumer refetches `me` once the cache is stale.
    const refetched = await fetchCurrentUser()
    applyMeResult({ ok: true, user: refetched })

    expect(refetched.must_change_password).toBe(false)
    expect(personalChannelUserId(useAuthStore.getState())).toBe(7)
    // What a cancelled logout re-adopts.
    expect(getDesktopSession()?.must_change_password).toBe(false)
    expect(getDesktopSession()?.user.must_change_password).toBe(false)
  })

  it('asks the engine to persist the fresh session (restore re-reads the manifest user)', async () => {
    adoptSession(forcedSession(), { token: 'token' })
    changedUser = { ...forcedUser, must_change_password: false }

    await changeThePassword()

    expect(invokeCommand).toHaveBeenCalledWith('restore')
  })

  it("ignores a document for another user than the session's", async () => {
    adoptSession(forcedSession(), { token: 'token' })
    changedUser = { ...forcedUser, id: 8, must_change_password: false } as User

    await changePassword({ current_password: 'old', password: 'New-pass1!', password_confirmation: 'New-pass1!' })

    expect(getDesktopSession()?.must_change_password).toBe(true)
    expect(invokeCommand).not.toHaveBeenCalledWith('restore')
  })
})
