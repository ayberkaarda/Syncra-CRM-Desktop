// Oturum sonlandırmanın UX katmanı — 3 katmanlı session revoke tasarımının
// 2. katmanı (bkz. backend `App\Events\UserDeactivated` başlığındaki yorum
// bloğu). Katman 1 (EnsureUserIsActive middleware) asıl güvenlik sınırıdır ve
// zaten çalışıyor; bu hook yalnızca açık bir sekmeyi anında düşürerek kullanıcı
// deneyimini iyileştirir — Reverb ulaşılamazsa kullanıcı zaten bir sonraki
// istekte katman 1 tarafından durdurulur.
import { useEffect } from 'react'
import { disconnectEcho, getEcho } from '../../../lib/echo'
import { toast } from '../../../components/ui'
import { useAuthStore } from '../store'
import { router } from '../../../router'
import i18n from '../../../i18n'

type UserDeactivatedPayload = {
  user_id: number
  message: string
}

/**
 * Kullanıcı giriş yapmışken `private-user.{id}` kanalına abone olur.
 * `App\Events\UserDeactivated::broadcastAs()` sabit olarak `'user.deactivated'`
 * döndürüyor (dosyadan doğrulandı) — Laravel Echo bu durumda tam sınıf adı
 * yerine kısa adı yayınlar, dinleyici de `.user.deactivated` ile eşleşir
 * (baştaki `.` = namespace'siz, ham event adı).
 *
 * Yönlendirmeyi doğrudan `window.location` ile yapmaz: `src/router.tsx` →
 * `registerAuthRedirect()` içindeki 401/`USER_DEACTIVATED` callback'iyle
 * BİREBİR aynı iki adımı (store temizle → aynı `router` örneğiyle `/login`'e
 * git) izler, böylece ikinci bir yönlendirme yolu icat edilmemiş olur.
 */
export function useRealtimeSession() {
  const userId = useAuthStore((state) => state.user?.id)

  useEffect(() => {
    if (!userId) return

    const echo = getEcho()
    if (!echo) return

    const channelName = `user.${userId}`
    const channel = echo.private(channelName)

    channel.listen('.user.deactivated', (payload: UserDeactivatedPayload) => {
      toast.error(payload.message || i18n.t('auth:session.deactivated'))
      disconnectEcho()
      useAuthStore.getState().clear()
      void router.navigate('/login')
    })

    return () => {
      echo.leave(channelName)
    }
    // Kullanıcı değişince (logout/login) abonelik temizlenip yeniden kurulur.
  }, [userId])
}
