import { useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { changePassword, forgotPassword, login, logout, me, updatePreferences } from '../api/authApi'
import { useAuthStore } from '../store'
import type { LoginPayload } from '../types'
import { connectEcho, disconnectEcho } from '../../../lib/echo'
import { applyUserLocale } from '../../../i18n'

const ME_QUERY_KEY = ['auth', 'me'] as const

/**
 * Uygulama açılışında `/api/me` çağırır, sonucu auth store'a yazar ve
 * store'un `status`/`user` alanlarını dışa verir. Login/logout mutasyonları
 * da burada — başarılı login'de store dolar ve `me` query'si invalidate edilir.
 */
export function useAuth() {
  const queryClient = useQueryClient()
  const user = useAuthStore((state) => state.user)
  const status = useAuthStore((state) => state.status)
  const setUser = useAuthStore((state) => state.setUser)
  const setStatus = useAuthStore((state) => state.setStatus)
  const clear = useAuthStore((state) => state.clear)

  const meQuery = useQuery({
    queryKey: ME_QUERY_KEY,
    queryFn: me,
    retry: false,
    refetchOnWindowFocus: false,
  })

  useEffect(() => {
    if (meQuery.isLoading) {
      setStatus('loading')
      return
    }
    if (meQuery.isSuccess) {
      setUser(meQuery.data)
      setStatus('authenticated')
      // Sunucudaki kişisel dil tercihi otoritedir (§1.3) — ama bu tarayıcıda elle
      // seçilmiş bir dil varsa `applyUserLocale` onu EZMEZ (bkz. i18n/index.ts).
      void applyUserLocale(meQuery.data.locale)
      return
    }
    if (meQuery.isError) {
      // 401 bekleniyor (oturum yok) — başka bir hata da olsa güvenli taraf
      // kimliksiz kabul etmektir.
      setUser(null)
      setStatus('unauthenticated')
    }
  }, [meQuery.isLoading, meQuery.isSuccess, meQuery.isError, meQuery.data, setUser, setStatus])

  // Realtime bağlantının yaşam döngüsü auth durumuna bağlanır: kimlik
  // doğrulandığında (ilk `/api/me`, login, ya da forced-logout sonrası tekrar
  // login) bağlan; oturum kapandığında (logout, 401, hesap pasifleştirme)
  // bağlantıyı kapat. Tek kaynak `status` olduğu için login/logout
  // mutasyonlarının ayrıca connectEcho/disconnectEcho çağırmasına gerek yok.
  useEffect(() => {
    if (status === 'authenticated') {
      connectEcho()
    } else if (status === 'unauthenticated') {
      disconnectEcho()
    }
  }, [status])

  const loginMutation = useMutation({
    mutationFn: (payload: LoginPayload) => login(payload),
    onSuccess: (loggedInUser) => {
      setUser(loggedInUser)
      setStatus('authenticated')
      void applyUserLocale(loggedInUser.locale)
      void queryClient.invalidateQueries({ queryKey: ME_QUERY_KEY })
    },
  })

  const logoutMutation = useMutation({
    mutationFn: logout,
    onSettled: () => {
      clear()
      void queryClient.invalidateQueries({ queryKey: ME_QUERY_KEY })
    },
  })

  return {
    user,
    status,
    isAuthenticated: status === 'authenticated',
    login: loginMutation.mutateAsync,
    isLoggingIn: loginMutation.isPending,
    logout: logoutMutation.mutateAsync,
  }
}

/** `LoginPage` dışında, dar amaçlı "unut şifre" akışı için ayrı export. */
export function useForgotPassword() {
  return useMutation({ mutationFn: forgotPassword })
}

/**
 * Zorunlu (ve gelecekte gönüllü) şifre değişimi — `ChangePasswordPage` bunu
 * kullanır. Başarı gövdesi zaten güncel `User`'ı taşıdığı için ek bir
 * `/api/me` çağrısı yapılmaz: store doğrudan güncellenir ve `me` query
 * cache'i aynı veriyle senkron tutulur (bkz. AUTH-FLOWS.md §4.2).
 */
export function useChangePassword() {
  const queryClient = useQueryClient()
  const setUser = useAuthStore((state) => state.setUser)

  return useMutation({
    mutationFn: changePassword,
    onSuccess: (updatedUser) => {
      setUser(updatedUser)
      queryClient.setQueryData(ME_QUERY_KEY, updatedUser)
    },
  })
}

/**
 * Kişisel görüntüleme para birimi tercihi (`users.preferred_currency`) — Faz 14 / İz E
 * (docs/PHASE-INTL.md §2.3/§1.8). `CurrencySwitcher` (features/preferences) bunu kullanır.
 *
 * DİL SEÇİCİSİNDEN (`LanguageSwitcher`) BİLEREK FARKLI DAVRANIR: `LanguageSwitcher` sunucu
 * yazması başarısız olsa da sessiz kalır (dil zaten yerel `i18n` çekirdeğinde uygulanmıştır,
 * localStorage kalıcılığı yeterlidir). Para birimi öyle DEĞİL — rapor/dashboard rakamlarının
 * HANGİ para biriminde göründüğünü belirleyen bir VERİ eksenidir (§1.8) ve yerelde ayrı bir
 * kalıcılık mekanizması (localStorage fallback) YOK; sunucudaki `preferred_currency` tek
 * doğruluk kaynağıdır. Bu yüzden `onError`da çağıran taraf (`useCurrencyPreference`) İYİMSER
 * GÜNCELLEMEYİ GERİ ALIR + kullanıcıya toast gösterir — sessiz kalınırsa ekran sunucuyla
 * senkron olmayan, yanlış para biriminde bir rakam göstermeye devam ederdi.
 */
export function useUpdatePreferredCurrency() {
  const queryClient = useQueryClient()
  const setUser = useAuthStore((state) => state.setUser)

  return useMutation({
    mutationFn: (preferred_currency: string) => updatePreferences({ preferred_currency }),
    onSuccess: (updatedUser) => {
      setUser(updatedUser)
      queryClient.setQueryData(ME_QUERY_KEY, updatedUser)
    },
  })
}
