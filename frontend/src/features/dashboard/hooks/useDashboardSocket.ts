// Dashboard canlılığı — `private-dashboard` kanalı, `.dashboard.invalidate` olayı (bkz. görev
// tanımı §Canlılık — baştaki nokta ZORUNLU, laravel-echo'nun broadcast-özel olayları için
// kuralı).
//
// Abonelik/temizlik deseni `features/tickets/hooks/useTicketRealtime.ts` ile AYNIDIR:
// `src/lib/echo.ts`ye DOKUNULMAZ, yalnızca `getEcho()`/`onConnectionStateChange()` kullanılır.
// Kanal aboneliği PAYLAŞILAN, referans sayan `src/lib/channelRegistry.ts` üzerinden alınır —
// doğrudan `echo.leave()` ÇAĞRILMAZ (referans saymaz; bu kanalın bugün başka bir abonesi olmasa
// da `Echo.leave()` StrictMode'un çift mount'unda ya da hızlı unmount/remount'ta kendi ikinci
// aboneliğini altından çekebilir — registry bunu sayaçla çözer). Unmount'ta yalnızca KENDİ
// dinleyicimiz `channel.stopListening()` ile bırakılır ve `releaseChannel()` çağrılır.
//
// GEÇ BAĞLANMA: `getEcho()` mount anında `null` olabilir — Echo örneği kimlik doğrulama
// tamamlandıktan sonra kurulur (bkz. `useDealRealtime.ts`). Bu yüzden abonelik efekti
// `echoAvailable` durumuna bağlıdır; bağlantı kurulduğunda efekt yeniden çalışıp gerçek
// aboneliği açar.
//
// SEÇİCİ INVALIDATION: payload'daki `keys` yalnızca bayatlayan sorguları adlandırır — tüm
// dashboard'u değil, yalnızca o anahtarların ÖN EKİYLE eşleşen sorguları geçersiz kılarız
// (`dashboardKeys.kpisPrefix` gibi — tarih aralığı parametresinden bağımsız, o an ekranda hangi
// aralık seçili olursa olsun eşleşir). Tanınmayan bir anahtar gelirse (ileride eklenen bir olay
// tipi) tüm `dashboard` önekini geçersiz kılmaya düşer — sessizce yok saymak yerine güvenli bir
// geniş tazeleme.
import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { getEcho, onConnectionStateChange } from '../../../lib/echo'
import { acquireChannel, releaseChannel } from '../../../lib/channelRegistry'
import { dashboardKeys } from '../api'
import type { DashboardInvalidateEvent, DashboardInvalidateKey } from '../types'

const CHANNEL_NAME = 'dashboard' // -> private-dashboard (Echo öneki ekler)
const EVENT_NAME = '.dashboard.invalidate'

const KEY_PREFIX: Record<DashboardInvalidateKey, readonly unknown[]> = {
  kpis: dashboardKeys.kpisPrefix,
  funnel: dashboardKeys.funnelPrefix,
  'revenue-trend': dashboardKeys.revenueTrendPrefix,
  'recent-activities': dashboardKeys.recentActivitiesPrefix,
  'task-summary': dashboardKeys.taskSummaryPrefix,
}

export function useDashboardSocket() {
  const queryClient = useQueryClient()
  const [echoAvailable, setEchoAvailable] = useState<boolean>(() => getEcho() !== null)

  useEffect(() => onConnectionStateChange(() => setEchoAvailable(getEcho() !== null)), [])

  useEffect(() => {
    if (!echoAvailable) return
    const channel = acquireChannel(CHANNEL_NAME)
    if (!channel) return

    const handleInvalidate = (payload: DashboardInvalidateEvent) => {
      const keys = Array.isArray(payload?.keys) ? payload.keys : []
      if (keys.length === 0) {
        void queryClient.invalidateQueries({ queryKey: dashboardKeys.all })
        return
      }
      for (const key of keys) {
        const prefix = KEY_PREFIX[key]
        void queryClient.invalidateQueries({ queryKey: prefix ?? dashboardKeys.all })
      }
    }

    channel.listen(EVENT_NAME, handleInvalidate)

    return () => {
      channel.stopListening(EVENT_NAME, handleInvalidate)
      releaseChannel(CHANNEL_NAME)
    }
  }, [echoAvailable, queryClient])
}
