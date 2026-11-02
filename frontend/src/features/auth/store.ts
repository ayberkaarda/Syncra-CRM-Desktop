import { create } from 'zustand'
import { advanceSessionEpoch } from '../../lib/sessionEpoch'
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
 *
 * Kimlik değiştiğinde (`clear()`, ya da `setUser` ile başka bir kullanıcıya
 * geçiş) oturum dönemi (`lib/sessionEpoch.ts`) ilerletilir: eski oturumdan
 * kalan bir isteğin geç gelen 401'i yeni oturumu kapatmasın diye. Aynı
 * kullanıcının güncellenmesi (tercih, şifre değişimi) dönemi değiştirmez.
 */
export const useAuthStore = create<AuthState>()((set, get) => ({
  user: null,
  status: 'idle',
  setUser: (user) => {
    if ((get().user?.id ?? null) !== (user?.id ?? null)) advanceSessionEpoch()
    set({ user })
  },
  setStatus: (status) => set({ status }),
  clear: () => {
    advanceSessionEpoch()
    set({ user: null, status: 'unauthenticated' })
  },
}))
