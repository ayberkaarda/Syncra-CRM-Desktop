import { useEffect } from 'react'
import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { changePassword, forgotPassword, login, logout, me, updatePreferences } from '../api/authApi'
import { useAuthStore } from '../store'
import type { LoginPayload, User } from '../types'
import { connectEcho, disconnectEcho } from '../../../lib/echo'
import { applyUserLocale } from '../../../i18n'
import { isSessionEndedError } from '../../../lib/axios'
import { advanceSessionEpoch, currentSessionEpoch } from '../../../lib/sessionEpoch'

const ME_QUERY_KEY = ['auth', 'me'] as const

/** Bound on re-asking: each attempt only repeats after a sign-in change while it was out. */
const ME_ATTEMPTS = 3

/**
 * `GET /api/me`, answered for the sign-in in effect when it settles.
 *
 * Bir `/api/me` login sürerken beklemede kalabilir (açılıştaki ilk istek ya da login öncesi
 * başlamış bir yenileme). Eski oturumun cookie'siyle gitmiş bu istek login'den sonra 401 ile
 * dönerse interceptor onu düşürür ama hata query'ye yine ulaşır ve `useAuth` efekti
 * `unauthenticated` yazıp yeni oturumu kapatırdı; 200 ile dönerse eski kullanıcıyı yazardı.
 * `invalidateQueries` verisi olmayan beklemedeki isteği iptal etmez, aynı promise'i paylaşır.
 * Bu yüzden: istek sürerken oturum dönemi değiştiyse yanıt (başarı ya da
 * `isSessionEndedError`) atılır ve soru güncel oturum için yeniden sorulur.
 */
export async function fetchCurrentUser(): Promise<User> {
  for (let attempt = 1; ; attempt += 1) {
    const epoch = currentSessionEpoch()
    try {
      const user = await me()
      if (currentSessionEpoch() === epoch || attempt >= ME_ATTEMPTS) return user
    } catch (error) {
      if (!isSessionEndedError(error) || attempt >= ME_ATTEMPTS) throw error
    }
  }
}

/** What `useAuth` writes into the store once the `me` query settles. */
export function applyMeResult(result: { ok: true; user: User } | { ok: false }): void {
  const store = useAuthStore.getState()
  if (result.ok) {
    store.setUser(result.user)
    store.setStatus('authenticated')
    // Sunucudaki kişisel dil tercihi otoritedir (§1.3) — ama bu tarayıcıda elle
    // seçilmiş bir dil varsa `applyUserLocale` onu EZMEZ (bkz. i18n/index.ts).
    void applyUserLocale(result.user.locale)
    return
  }
  // 401 bekleniyor (oturum yok) — başka bir hata da olsa güvenli taraf
  // kimliksiz kabul etmektir.
  store.setUser(null)
  store.setStatus('unauthenticated')
}

/**
 * Başarılı login. Her login yeni bir oturumdur — aynı kullanıcı `/login`'den yeniden girse
 * bile önceki cookie oturumu biter — bu yüzden oturum dönemi kullanıcı kimliğinden bağımsız
 * olarak burada ilerletilir; önceki oturumdan geç gelen 401/USER_DEACTIVATED artık eşleşmez.
 */
export function completeLogin(queryClient: QueryClient, loggedInUser: User): void {
  advanceSessionEpoch()
  const store = useAuthStore.getState()
  store.setUser(loggedInUser)
  store.setStatus('authenticated')
  void applyUserLocale(loggedInUser.locale)
  void queryClient.invalidateQueries({ queryKey: ME_QUERY_KEY })
}

/**
 * Uygulama açılışında `/api/me` çağırır, sonucu auth store'a yazar ve
 * store'un `status`/`user` alanlarını dışa verir. Login/logout mutasyonları
 * da burada — başarılı login'de store dolar ve `me` query'si invalidate edilir.
 */
export function useAuth() {
  const queryClient = useQueryClient()
  const user = useAuthStore((state) => state.user)
  const status = useAuthStore((state) => state.status)
  const setStatus = useAuthStore((state) => state.setStatus)
  const clear = useAuthStore((state) => state.clear)

  const meQuery = useQuery({
    queryKey: ME_QUERY_KEY,
    queryFn: fetchCurrentUser,
    retry: false,
    refetchOnWindowFocus: false,
  })

  useEffect(() => {
    if (meQuery.isLoading) {
      setStatus('loading')
      return
    }
    if (meQuery.isSuccess) {
      applyMeResult({ ok: true, user: meQuery.data })
      return
    }
    if (meQuery.isError) {
      applyMeResult({ ok: false })
    }
  }, [meQuery.isLoading, meQuery.isSuccess, meQuery.isError, meQuery.data, setStatus])

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
    onSuccess: (loggedInUser) => completeLogin(queryClient, loggedInUser),
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
