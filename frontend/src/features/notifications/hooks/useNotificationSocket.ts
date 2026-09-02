// `private-user.{currentUserId}` kanalının gerçek zamanlı senkronu — `.notification.created`
// olayı (görev tanımı, baştaki nokta ZORUNLU çünkü sunucu `broadcastAs` kullanıyor). Abonelik/
// temizlik deseni `features/tickets/hooks/useTicketRealtime.ts` ile AYNIDIR: `lib/echo.ts`'ye
// DOKUNULMAZ, yalnızca `getEcho()`/`onConnectionStateChange()` kullanılır. Kanalın kendisi ise
// PAYLAŞILAN, referans sayan `src/lib/channelRegistry.ts` üzerinden alınır/bırakılır — aynı
// `user.{id}` kanalını `useTaskReminders.ts`, `useRealtimeSession.ts` ve `useChatUnread.ts` da
// dinliyor; doğrudan `echo.leave()` çağrılsaydı (referans saymadığı için) bu diğer abonelerin
// dinleyicilerini de düşürürdü.
//
// ==============================================================================================
// PAYLAŞILAN DİNLEYİCİ — bu hook `AppLayout` seviyesinde TEK SEFER çağrılacak (teknik lider
// bağlayacak) ama olası çift çağrıya (ör. StrictMode çift mount, ileride ikinci bir çağıran)
// karşı modül seviyesinde AYRI bir referans sayacı (`sharedSubscriberCount`) tutulur: yalnızca
// İLK aktif abone gerçek `channel.listen` bağlar, sonraki abonelikler sayacı artırıp aynı
// dinleyiciyi paylaşır; bu hook'un kendi mount sayacı sıfıra inince YALNIZCA KENDİ dinleyicisi
// `channel.stopListening` ile bırakılır. Kanalın kendisi (`registry`'deki sayaç) bundan BAĞIMSIZ
// yönetilir — her mount `acquireChannel`, her unmount `releaseChannel` çağırır; kanal yalnızca
// TÜM `user.{id}` aboneleri (dört kanca) bıraktığında gerçekten `echo.leave` ile kapanır. Böylece
// N bileşen bu hook'u çağırsa da tek bir dinleyici/tek bir toast/tek bir invalidate zinciri
// çalışır.
// ==============================================================================================
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { getEcho, onConnectionStateChange } from '../../../lib/echo'
import type { EchoConnectionState } from '../../../lib/echo'
import { acquireChannel, releaseChannel } from '../../../lib/channelRegistry'
import { toast } from '../../../components/ui'
import i18n from '../../../i18n'
import { useAuthStore } from '../../auth/store'
import { notificationsKeys } from './useNotifications'
import { useNotificationStore } from '../store'
import type { NotificationCreatedEvent } from '../types'

const EVENT_NAME = '.notification.created'

// Modül seviyesi paylaşılan abonelik durumu — yukarıdaki gerekçeye bkz.
let sharedSubscriberCount = 0
let sharedUnbind: (() => void) | null = null

export type UseNotificationSocketResult = {
  connectionState: EchoConnectionState
  isConnected: boolean
}

export function useNotificationSocket(): UseNotificationSocketResult {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const setUnreadCount = useNotificationStore((state) => state.setUnreadCount)
  const userId = useAuthStore((state) => state.user?.id)

  const [connectionState, setConnectionState] = useState<EchoConnectionState>('unavailable')
  const [echoAvailable, setEchoAvailable] = useState<boolean>(() => getEcho() !== null)

  useEffect(
    () =>
      onConnectionStateChange((state) => {
        setConnectionState(state)
        setEchoAvailable(getEcho() !== null)
      }),
    []
  )

  useEffect(() => {
    if (!echoAvailable || userId === undefined) return
    const channelName = `user.${userId}`
    const channel = acquireChannel(channelName)
    if (!channel) return

    sharedSubscriberCount += 1

    // Yalnızca ilk aktif abone gerçek dinleyiciyi bağlar — sonraki çağıranlar aynı bağlantıyı
    // paylaşır (bkz. dosya başındaki gerekçe).
    if (!sharedUnbind) {
      const handleCreated = (payload: NotificationCreatedEvent) => {
        // Sunucu otoriteli sayaç — bkz. `types.ts`'teki `NotificationCreatedEvent` notu.
        setUnreadCount(payload.unread_count)
        void queryClient.invalidateQueries({ queryKey: notificationsKeys.lists })

        toast(payload.title, {
          description: payload.body,
          action: {
            label: i18n.t('common:actions.view'),
            onClick: () => navigate(payload.link),
          },
        })
      }

      channel.listen(EVENT_NAME, handleCreated)
      sharedUnbind = () => channel.stopListening(EVENT_NAME, handleCreated)
    }

    return () => {
      // `sharedSubscriberCount`/`sharedUnbind` yalnızca BU KANCANIN kendi dinleyicisini kaç
      // mount'un paylaştığını izler (bkz. dosya başındaki gerekçe) — sayaç sıfıra inince yalnızca
      // KENDİ dinleyicimiz bırakılır. `releaseChannel` ise HER unmount'ta, bu sayaçtan BAĞIMSIZ
      // çağrılır: her `acquireChannel` çağrısı (yukarıda, HER mount'ta) tam olarak BİR
      // `releaseChannel` ile eşleşmelidir, aksi hâlde kanal defterindeki sayaç asla sıfıra inmez
      // ve kanal hiç bırakılmaz.
      sharedSubscriberCount -= 1
      if (sharedSubscriberCount <= 0) {
        sharedSubscriberCount = 0
        sharedUnbind?.()
        sharedUnbind = null
      }
      releaseChannel(channelName)
    }
  }, [echoAvailable, userId, queryClient, navigate, setUnreadCount])

  return { connectionState, isConnected: connectionState === 'connected' }
}
