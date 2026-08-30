// Açık konuşmanın gerçek zamanlı senkronu — `private-conversation.{id}` kanalı.
//
// Olay adlarının başındaki NOKTA ZORUNLUDUR (sunucu `broadcastAs` kullanıyor; nokta olmadan
// Laravel olay adını `App\Events\...` olarak arar ve hiçbir şey tetiklenmez).
//
// Kanal aboneliği `conversationChannel.ts` üzerinden REFERANS SAYARAK alınır: aynı kanalı
// `useTyping` de dinliyor ve `Echo.leave()` referans saymadığı için doğrudan çağrılsaydı
// birbirlerinin dinleyicilerini düşürürlerdi (bkz. o dosyanın başındaki gerekçe; aynı desen
// `features/notifications/hooks/useNotificationSocket.ts`).
//
// BU KANCA ÜÇ İŞ YAPAR:
//  1. Gelen olayları React Query önbelleğine yazar (mesaj ekle/güncelle/mezar taşı, tik ilerlet).
//  2. `delivered` bildirimini DEBOUNCE ile, tek istekte ve EN YÜKSEK id ile gönderir.
//  3. `read` bildirimini yalnızca pencere GÖRÜNÜR ve konuşma AÇIKKEN gönderir; sekme arka
//     plandayken erteler, odak dönünce tekrar dener.
import { useCallback, useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { onConnectionStateChange, getEcho } from '../../../lib/echo'
import type { EchoConnectionState } from '../../../lib/echo'
import { useAuthStore } from '../../auth/store'
import { markConversationDeliveredRequest, markConversationReadRequest } from '../api'
import { advanceOwnTicks, removeMessage, tombstoneMessage, upsertIncomingMessage } from '../utils'
import {
  bumpConversationPreview,
  mergeConversationUpdate,
  setConversationUnreadCache,
  updateMessagesCache,
} from './chatCache'
import { acquireConversationChannel, releaseConversationChannel } from './conversationChannel'
import { useMessages } from './useMessages'
import { useChatStore } from '../store'
import type {
  ConversationUpdatedEvent,
  MessageCreatedEvent,
  MessageDeletedEvent,
  MessageDeliveredEvent,
  MessageReadEvent,
  MessageUpdatedEvent,
} from '../types'

/** Art arda gelen mesajlar için tek `delivered` isteği: her mesaja bir istek atılmaz. */
const DELIVERED_DEBOUNCE_MS = 400
/** Hızlı kaydırmada her satır için ayrı `read` isteği atılmaması için. */
const READ_DEBOUNCE_MS = 500

export type UseChatSocketResult = {
  connectionState: EchoConnectionState
  isConnected: boolean
  /**
   * Okundu bildirimini elle tetikler (ör. kullanıcı listenin en altına kaydırdı).
   * `messageId` verilmezse önbellekteki en yeni sunucu mesajı kullanılır. Görünürlük ve
   * monotonluk kontrolleri yine uygulanır.
   */
  markRead: (messageId?: number) => void
}

export function useChatSocket(conversationId: number | null): UseChatSocketResult {
  const queryClient = useQueryClient()
  const currentUserId = useAuthStore((state) => state.user?.id ?? null)
  const setActiveConversationId = useChatStore((state) => state.setActiveConversationId)
  const applyConversationUnread = useChatStore((state) => state.applyConversationUnread)

  // Okundu bildiriminin dayanağı: önbellekteki en yeni SUNUCU mesajı. `useMessages` aynı sorgu
  // anahtarını paylaştığı için burada çağrılması EK BİR İSTEK DOĞURMAZ, yalnızca önbelleğe
  // reaktif abone olur.
  const { newestMessageId } = useMessages(conversationId)

  const [connectionState, setConnectionState] = useState<EchoConnectionState>('unavailable')
  const [echoAvailable, setEchoAvailable] = useState<boolean>(() => getEcho() !== null)

  // Bildirim imleçleri — `target` istenen, `sent` sunucuya iletilmiş en yüksek id.
  // İkisinin farkı "gönderilecek var mı" sorusunu tek karşılaştırmayla cevaplar ve aynı id'nin
  // iki kez gönderilmesini engeller (monotonluk).
  const deliveredTargetRef = useRef(0)
  const deliveredSentRef = useRef(0)
  const deliveredTimerRef = useRef<number | null>(null)
  const readTargetRef = useRef(0)
  const readSentRef = useRef(0)
  const readTimerRef = useRef<number | null>(null)

  useEffect(
    () =>
      onConnectionStateChange((state) => {
        setConnectionState(state)
        setEchoAvailable(getEcho() !== null)
      }),
    [],
  )

  // Konuşma değişince tüm imleçler ve zamanlayıcılar sıfırlanır: önceki konuşmanın mesaj
  // id'leriyle yeni konuşmaya bildirim göndermek kesinlikle yanlış olurdu.
  useEffect(() => {
    deliveredTargetRef.current = 0
    deliveredSentRef.current = 0
    readTargetRef.current = 0
    readSentRef.current = 0
    setActiveConversationId(conversationId)

    return () => {
      if (deliveredTimerRef.current !== null) window.clearTimeout(deliveredTimerRef.current)
      if (readTimerRef.current !== null) window.clearTimeout(readTimerRef.current)
      deliveredTimerRef.current = null
      readTimerRef.current = null
      setActiveConversationId(null)
    }
  }, [conversationId, setActiveConversationId])

  // --------------------------------------------------------------------------------------------
  // delivered / read bildirimleri
  // --------------------------------------------------------------------------------------------

  const flushDelivered = useCallback(() => {
    if (conversationId === null) return
    const target = deliveredTargetRef.current
    const previous = deliveredSentRef.current
    if (target <= previous) return
    deliveredSentRef.current = target
    void markConversationDeliveredRequest(conversationId, target).catch(() => {
      // Başarısızsa imleci geri al ki sonraki mesajda tekrar denensin — teslim bilgisi
      // kaybolursa karşı taraf mesajı sonsuza dek tek tikle görür.
      deliveredSentRef.current = previous
    })
  }, [conversationId])

  const scheduleDelivered = useCallback(
    (messageId: number) => {
      if (messageId <= deliveredTargetRef.current) return
      deliveredTargetRef.current = messageId
      if (deliveredTimerRef.current !== null) window.clearTimeout(deliveredTimerRef.current)
      deliveredTimerRef.current = window.setTimeout(flushDelivered, DELIVERED_DEBOUNCE_MS)
    },
    [flushDelivered],
  )

  const flushRead = useCallback(() => {
    if (conversationId === null) return
    // Arka plandaki sekmede mesaj OKUNMUŞ SAYILMAZ; imleç korunur, odak dönünce gönderilir.
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return
    const target = readTargetRef.current
    const previous = readSentRef.current
    if (target <= previous) return
    readSentRef.current = target
    void markConversationReadRequest(conversationId, target)
      .then((ack) => {
        // Rozet YEREL OLARAK SIFIRLANMAZ: sunucunun döndüğü `unread_count` uygulanır. Kısmi
        // okumada (daha yeni mesajlar varken imleç ortada kaldıysa) bu değer sıfır olmayabilir.
        applyConversationUnread(conversationId, ack.unread_count)
        setConversationUnreadCache(queryClient, conversationId, ack.unread_count)
      })
      .catch(() => {
        readSentRef.current = previous
      })
  }, [conversationId, applyConversationUnread, queryClient])

  const scheduleRead = useCallback(
    (messageId: number) => {
      if (messageId > readTargetRef.current) readTargetRef.current = messageId
      if (readTimerRef.current !== null) window.clearTimeout(readTimerRef.current)
      readTimerRef.current = window.setTimeout(flushRead, READ_DEBOUNCE_MS)
    },
    [flushRead],
  )

  const markRead = useCallback(
    (messageId?: number) => {
      const target = messageId ?? newestMessageId
      if (target === null || target === undefined || target <= 0) return
      scheduleRead(target)
    },
    [newestMessageId, scheduleRead],
  )

  // Konuşma açıkken önbelleğin en yeni mesajı değiştikçe okundu imleci ilerler.
  useEffect(() => {
    if (conversationId === null || newestMessageId === null) return
    scheduleRead(newestMessageId)
  }, [conversationId, newestMessageId, scheduleRead])

  // Odak geri geldiğinde ertelenmiş okundu bildirimi tekrar denenir.
  useEffect(() => {
    if (conversationId === null) return
    const handleVisibility = () => {
      if (document.visibilityState === 'visible') flushRead()
    }
    document.addEventListener('visibilitychange', handleVisibility)
    window.addEventListener('focus', handleVisibility)
    return () => {
      document.removeEventListener('visibilitychange', handleVisibility)
      window.removeEventListener('focus', handleVisibility)
    }
  }, [conversationId, flushRead])

  // --------------------------------------------------------------------------------------------
  // Kanal aboneliği
  // --------------------------------------------------------------------------------------------

  useEffect(() => {
    if (conversationId === null || !echoAvailable) return
    const channel = acquireConversationChannel(conversationId)
    if (!channel) return

    const handleCreated = (payload: MessageCreatedEvent) => {
      const message = payload.message
      updateMessagesCache(queryClient, conversationId, (data) =>
        upsertIncomingMessage(data, message, currentUserId),
      )
      bumpConversationPreview(queryClient, conversationId, message)

      // Teslim bildirimi YALNIZCA başkasının mesajı için anlamlıdır; kendi mesajımıza
      // "teslim aldım" demek sunucuda kendi tikimizi bozardı.
      if (message.user && message.user.id !== currentUserId) {
        scheduleDelivered(message.id)
      }
    }

    const handleUpdated = (payload: MessageUpdatedEvent) => {
      updateMessagesCache(queryClient, conversationId, (data) =>
        upsertIncomingMessage(data, payload.message, currentUserId),
      )
    }

    const handleDeleted = (payload: MessageDeletedEvent) => {
      updateMessagesCache(queryClient, conversationId, (data) =>
        // Geçici (negatif id) kayıtlar sunucuda yok; gerçek mesajlar mezar taşına çevrilir.
        payload.message_id < 0
          ? removeMessage(data, payload.message_id)
          : tombstoneMessage(data, payload.message_id),
      )
    }

    const handleRead = (payload: MessageReadEvent) => {
      // Kendi okuma olayımız kendi tikimizi ilerletmez — tik "karşı taraf gördü" demektir.
      if (currentUserId === null || payload.user_id === currentUserId) return
      updateMessagesCache(queryClient, conversationId, (data) =>
        advanceOwnTicks(data, currentUserId, payload.last_read_message_id, 'read'),
      )
    }

    const handleDelivered = (payload: MessageDeliveredEvent) => {
      if (currentUserId === null || payload.user_id === currentUserId) return
      updateMessagesCache(queryClient, conversationId, (data) =>
        advanceOwnTicks(data, currentUserId, payload.last_delivered_message_id, 'delivered'),
      )
    }

    const handleConversationUpdated = (payload: ConversationUpdatedEvent) => {
      // KISMİ birleştirme — olay paylaşılan kanalda yayınlandığı için yükteki `unread_count`
      // ve `is_muted` kişiye özel DEĞİLDİR ve yok sayılmalıdır (bkz. `mergeConversationUpdate`).
      mergeConversationUpdate(queryClient, payload.conversation)
    }

    const bindings: Array<[string, CallableFunction]> = [
      ['.message.created', handleCreated],
      ['.message.updated', handleUpdated],
      ['.message.deleted', handleDeleted],
      ['.message.read', handleRead],
      ['.message.delivered', handleDelivered],
      ['.conversation.updated', handleConversationUpdated],
    ]

    bindings.forEach(([event, handler]) => channel.listen(event, handler))

    return () => {
      bindings.forEach(([event, handler]) => channel.stopListening(event, handler))
      releaseConversationChannel(conversationId)
    }
  }, [conversationId, echoAvailable, currentUserId, queryClient, scheduleDelivered])

  return {
    connectionState,
    isConnected: connectionState === 'connected',
    markRead,
  }
}
