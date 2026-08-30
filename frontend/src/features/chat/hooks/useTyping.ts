// "Yazıyor..." göstergesi.
//
// Bu bilgi SUNUCUYA YAZILMAZ: her tuş vuruşu için HTTP isteği/broadcast üretmek, taşıdığı
// değere göre absürt bir maliyettir ve saklanmasının da bir anlamı yoktur (bir saniye sonra
// geçersiz). Bunun yerine Echo'nun whisper mekanizmasıyla istemciden istemciye gider —
// `channel.whisper('typing', ...)` / `channel.listenForWhisper('typing', ...)`.
//
// Kanal `conversationChannel.ts` üzerinden REFERANS SAYARAK alınır; burada ASLA `Echo.leave()`
// çağrılmaz, yoksa aynı kanalı dinleyen `useChatSocket`'in mesaj dinleyicileri de düşerdi.
import { useCallback, useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { getEcho, onConnectionStateChange } from '../../../lib/echo'
import { useAuthStore } from '../../auth/store'
import { chatKeys } from '../api'
import { acquireConversationChannel, releaseConversationChannel } from './conversationChannel'
import type { ChatUser, Conversation, TypingWhisper } from '../types'

const WHISPER_EVENT = 'typing'
/** Tuş başına whisper atılmaz: bu aralıkta en fazla bir sinyal gider (ön kenar / leading edge). */
const TYPING_THROTTLE_MS = 2000
/** Gelen "yazıyor" durumu bu süre boyunca yenilenmezse kendiliğinden düşer. */
const TYPING_TTL_MS = 3000
/** Süresi dolanları temizleme taraması. */
const TYPING_SWEEP_MS = 1000

export type UseTypingResult = {
  /** O anda yazmakta olan DİĞER kullanıcılar (kendi kullanıcın asla listede olmaz). */
  typingUsers: ChatUser[]
  /** Girdi alanındaki her değişiklikte çağrılabilir; içeride throttle edilir. */
  notifyTyping: () => void
}

type TypingEntry = { user: ChatUser; at: number }

export function useTyping(conversationId: number | null): UseTypingResult {
  const queryClient = useQueryClient()
  const currentUser = useAuthStore((state) => state.user)
  const currentUserId = currentUser?.id ?? null

  const [typingUsers, setTypingUsers] = useState<ChatUser[]>([])
  const entriesRef = useRef<Map<number, TypingEntry>>(new Map())
  const lastWhisperRef = useRef(0)
  const channelRef = useRef<ReturnType<typeof acquireConversationChannel>>(null)
  const [echoAvailable, setEchoAvailable] = useState<boolean>(() => getEcho() !== null)

  useEffect(() => onConnectionStateChange(() => setEchoAvailable(getEcho() !== null)), [])

  const publish = useCallback(() => {
    setTypingUsers(Array.from(entriesRef.current.values()).map((entry) => entry.user))
  }, [])

  // Yazıyor durumunu temizler: hem ref'leri (senkron, render dışı) hem de state'i sıfırlar.
  // AŞAĞIDAKİ abonelik efektinin CLEANUP'ından çağrılır — ayrı bir "konuşma değişince sıfırla"
  // efekti YOKTUR, çünkü aynı `conversationId` bağımlılığına sahip iki efekt arasında sıralama
  // garantisi olsa da React render sırasında state set eden bir efekt yerine cleanup'ı tercih
  // eder. Cleanup, `conversationId`/`echoAvailable` değiştiğinde (yeni efekt çalışmadan HEMEN
  // önce) ve unmount'ta çalışır; böylece yeni konuşmaya geçildiğinde eski "yazıyor" girdileri
  // asla görünmez.
  const resetTyping = useCallback(() => {
    entriesRef.current = new Map()
    setTypingUsers([])
    lastWhisperRef.current = 0
  }, [])

  useEffect(() => {
    // Erken çıkış yollarında da (konuşma yok / Echo hazır değil / kanal alınamadı) temizlik
    // cleanup'ı DÖNÜLÜR — sıfırlama efektin başında değil, HER ZAMAN cleanup'ta yapılır.
    if (conversationId === null || !echoAvailable) return resetTyping
    const channel = acquireConversationChannel(conversationId)
    if (!channel) return resetTyping
    channelRef.current = channel

    const handleWhisper = (payload: TypingWhisper) => {
      if (!payload || typeof payload.user_id !== 'number') return
      if (currentUserId !== null && payload.user_id === currentUserId) return

      // Whisper yükü minimaldir (`{ user_id, name }`); tam `ChatUser` gerekiyorsa konuşma
      // detayı önbelleğindeki üye listesinden zenginleştirilir. Üye bulunamazsa e-posta boş
      // bırakılır — gösterge için ad yeterlidir, ikinci bir istek atmaya değmez.
      const conversation = queryClient.getQueryData<Conversation>(chatKeys.conversation(conversationId))
      const member = conversation?.members.find((item) => item.id === payload.user_id)
      const user: ChatUser = member ?? { id: payload.user_id, name: payload.name ?? '', email: '' }

      entriesRef.current.set(user.id, { user, at: Date.now() })
      publish()
    }

    channel.listenForWhisper(WHISPER_EVENT, handleWhisper)

    return () => {
      channel.stopListeningForWhisper(WHISPER_EVENT, handleWhisper)
      channelRef.current = null
      releaseConversationChannel(conversationId)
      resetTyping()
    }
  }, [conversationId, echoAvailable, currentUserId, queryClient, publish, resetTyping])

  // Süresi dolan göstergeleri düşür. Tarama YALNIZCA yazan biri varken çalışır — boş sohbette
  // saniyede bir uyanan bir zamanlayıcı bırakmanın anlamı yok.
  useEffect(() => {
    if (typingUsers.length === 0) return
    const timer = window.setInterval(() => {
      const now = Date.now()
      let changed = false
      entriesRef.current.forEach((entry, userId) => {
        if (now - entry.at > TYPING_TTL_MS) {
          entriesRef.current.delete(userId)
          changed = true
        }
      })
      if (changed) publish()
    }, TYPING_SWEEP_MS)
    return () => window.clearInterval(timer)
  }, [typingUsers.length, publish])

  const notifyTyping = useCallback(() => {
    if (conversationId === null || currentUser === null) return
    const channel = channelRef.current
    if (!channel) return

    const now = Date.now()
    if (now - lastWhisperRef.current < TYPING_THROTTLE_MS) return
    lastWhisperRef.current = now

    const payload: TypingWhisper = { user_id: currentUser.id, name: currentUser.name }
    channel.whisper(WHISPER_EVENT, payload)
  }, [conversationId, currentUser])

  return { typingUsers, notifyTyping }
}
