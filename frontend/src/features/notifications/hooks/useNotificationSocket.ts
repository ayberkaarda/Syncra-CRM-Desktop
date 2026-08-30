// `private-user.{currentUserId}` kanalının gerçek zamanlı senkronu — `.notification.created`
// olayı (görev tanımı, baştaki nokta ZORUNLU çünkü sunucu `broadcastAs` kullanıyor). Abonelik/
// temizlik deseni `features/tickets/hooks/useTicketRealtime.ts` ile AYNIDIR: `lib/echo.ts`'ye
// DOKUNULMAZ, yalnızca `getEcho()`/`onConnectionStateChange()` kullanılır.
//
// ==============================================================================================
// PAYLAŞILAN ABONELİK — bu hook `AppLayout` seviyesinde TEK SEFER çağrılacak (teknik lider
// bağlayacak) ama olası çift çağrıya (ör. StrictMode çift mount, ileride ikinci bir çağıran)
// karşı modül seviyesinde bir referans sayacı tutulur: yalnızca İLK aktif abone gerçek
// `channel.listen` bağlar, sonraki abonelikler sayacı artırıp aynı bağlantıyı paylaşır;
// unmount'ta sayaç sıfıra inince kanal GERÇEKTEN bırakılır (`channel.stopListening` +
// `echo.leave`). Böylece N bileşen bu hook'u çağırsa da tek bir dinleyici/tek bir toast/tek bir
// invalidate zinciri çalışır.
// ==============================================================================================
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { getEcho, onConnectionStateChange } from '../../../lib/echo'
import type { EchoConnectionState } from '../../../lib/echo'
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
let sharedChannelName: string | null = null

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
    const echo = getEcho()
    if (!echo) return

    const channelName = `user.${userId}`
    const channel = echo.private(channelName)

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
      sharedChannelName = channelName
      sharedUnbind = () => channel.stopListening(EVENT_NAME, handleCreated)
    }

    return () => {
      sharedSubscriberCount -= 1
      if (sharedSubscriberCount <= 0) {
        sharedSubscriberCount = 0
        sharedUnbind?.()
        sharedUnbind = null
        if (sharedChannelName) {
          echo.leave(sharedChannelName)
          sharedChannelName = null
        }
      }
    }
  }, [echoAvailable, userId, queryClient, navigate, setUnreadCount])

  return { connectionState, isConnected: connectionState === 'connected' }
}
