import { create } from 'zustand'
import type { User } from './types'

export type AuthStatus = 'idle' | 'loading' | 'authenticated' | 'unauthenticated'

type AuthState = {
  user: User | null
  status: AuthStatus
  setUser: (user: User | null) => void
  setStatus: (status: AuthStatus) => void
  clear: () => void
}

/**
 * Auth durumu — sunucu tarafı cookie session'ının istemcideki yansımasıdır.
 * BİLEREK localStorage'a persist EDİLMEZ: kullanıcı bilgisi her açılışta
 * `/api/me` ile tazelenir (bkz. `useAuth`). Persist edilen tek store tema
 * store'udur (`src/stores/themeStore.ts`).
 */
export const useAuthStore = create<AuthState>()((set) => ({
  user: null,
  status: 'idle',
  setUser: (user) => set({ user }),
  setStatus: (status) => set({ status }),
  clear: () => set({ user: null, status: 'unauthenticated' }),
}))
