// Global okunmamış sohbet rozeti — `private-user.{currentUserId}` kanalındaki `.chat.unread`.
//
// KANAL SAHİPLİĞİ NOTU: `user.{id}` kanalı Faz 10'dan beri `useNotificationSocket` tarafından
// da dinleniyor. `Echo.leave()` referans SAYMADIĞI için burada ASLA çağrılmaz — çağrılsaydı
// bildirim zilinin dinleyicisi de sessizce düşerdi. Bu kanca yalnızca kendi dinleyicisini
// bağlar ve `stopListening` ile bırakır; kanalın ömrü bildirim kancasına aittir.
//
// Rozet SUNUCU OTORİTELİDİR: ilk değer `GET /api/conversations/unread-count` ile alınır,
// sonrasında her olay hem konuşma bazlı hem toplam sayacı taşıdığı için istemcide toplama
// yapılmaz (bkz. `store.ts`).
import { useCallback, useEffect, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { getEcho, onConnectionStateChange } from '../../../lib/echo'
import { useAuthStore } from '../../auth/store'
import { chatKeys, fetchChatUnreadCount } from '../api'
import { bumpConversationPreview, hasConversationInLists } from './chatCache'
import { useChatStore } from '../store'
import type { ChatUnreadEvent } from '../types'

const EVENT_NAME = '.chat.unread'

// Bu kancayı birden fazla bileşen çağırabilir (kenar çubuğu rozeti + sohbet sayfası);
// yalnızca ilk abone gerçek dinleyiciyi bağlar (desen: `useNotificationSocket`).
let sharedSubscriberCount = 0
let sharedUnbind: (() => void) | null = null

export type UseChatUnreadResult = {
  totalUnread: number
  perConversation: Record<number, number>
  /** Tek bir konuşmanın okunmamış sayısı (bilinmiyorsa 0). */
  unreadFor: (conversationId: number) => number
  isLoading: boolean
  /** Sunucudan tam anlık görüntüyü yeniden çeker (ör. uzun bir çevrimdışılık sonrası). */
  refresh: () => void
}

export function useChatUnread(): UseChatUnreadResult {
  const queryClient = useQueryClient()
  const userId = useAuthStore((state) => state.user?.id)
  const totalUnread = useChatStore((state) => state.totalUnread)
  const perConversation = useChatStore((state) => state.perConversation)
  const setSnapshot = useChatStore((state) => state.setSnapshot)
  const applyUnreadEvent = useChatStore((state) => state.applyUnreadEvent)

  const [echoAvailable, setEchoAvailable] = useState<boolean>(() => getEcho() !== null)
  useEffect(() => onConnectionStateChange(() => setEchoAvailable(getEcho() !== null)), [])

  const snapshotQuery = useQuery({
    queryKey: chatKeys.unreadCount,
    queryFn: fetchChatUnreadCount,
    enabled: userId !== undefined,
    staleTime: 60_000,
  })

  const snapshot = snapshotQuery.data
  useEffect(() => {
    if (snapshot) setSnapshot(snapshot)
  }, [snapshot, setSnapshot])

  useEffect(() => {
    if (!echoAvailable || userId === undefined) return
    const echo = getEcho()
    if (!echo) return

    const channel = echo.private(`user.${userId}`)
    sharedSubscriberCount += 1

    if (!sharedUnbind) {
      const handleUnread = (payload: ChatUnreadEvent) => {
        applyUnreadEvent(payload)

        // Liste satırının önizlemesi + sırası anında tazelensin; konuşma listede hiç yoksa
        // (yeni açılmış bir sohbet) tek seferlik invalidate ile çekilir.
        if (hasConversationInLists(queryClient, payload.conversation_id)) {
          bumpConversationPreview(
            queryClient,
            payload.conversation_id,
            {
              body: payload.preview,
              attachment: null,
              deleted_at: null,
              created_at: new Date().toISOString(),
            },
            payload.conversation_unread,
          )
        } else {
          void queryClient.invalidateQueries({ queryKey: chatKeys.conversations })
        }
      }

      channel.listen(EVENT_NAME, handleUnread)
      sharedUnbind = () => channel.stopListening(EVENT_NAME, handleUnread)
    }

    return () => {
      sharedSubscriberCount -= 1
      if (sharedSubscriberCount <= 0) {
        sharedSubscriberCount = 0
        sharedUnbind?.()
        sharedUnbind = null
        // `echo.leave('user.{id}')` BİLEREK ÇAĞRILMAZ — bkz. dosya başındaki kanal sahipliği notu.
      }
    }
  }, [echoAvailable, userId, applyUnreadEvent, queryClient])

  const unreadFor = useCallback(
    (conversationId: number) => perConversation[conversationId] ?? 0,
    [perConversation],
  )

  const refresh = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: chatKeys.unreadCount })
  }, [queryClient])

  return {
    totalUnread,
    perConversation,
    unreadFor,
    isLoading: snapshotQuery.isLoading,
    refresh,
  }
}
