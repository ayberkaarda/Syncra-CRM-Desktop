import { create } from 'zustand'
import type { ChatUnreadEvent } from './types'

type ChatState = {
  /** Tüm konuşmalardaki okunmamış mesaj toplamı — kenar çubuğundaki global rozet. */
  totalUnread: number
  /** Konuşma id → o konuşmadaki okunmamış sayısı. */
  perConversation: Record<number, number>
  /**
   * Ekranda AÇIK olan konuşma. `useChatSocket` mount/unmount'ta yazar. Rozet mantığı bunu
   * kullanır: açık konuşmaya düşen bir olay için ayrıca rozet yakmanın anlamı yoktur, okundu
   * bildirimi zaten yolda.
   */
  activeConversationId: number | null

  setTotalUnread: (count: number) => void
  /** Sunucudan gelen tam anlık görüntüyü (`GET /api/conversations/unread-count`) yazar. */
  setSnapshot: (snapshot: { total_unread: number; per_conversation: Record<number, number> }) => void
  setConversationUnread: (conversationId: number, count: number) => void
  /** `.chat.unread` olayını uygular — sunucu OTORİTEDİR, yerel toplama güvenilmez. */
  applyUnreadEvent: (event: ChatUnreadEvent) => void
  /**
   * Okundu bildiriminin SUNUCU yanıtındaki `unread_count`'u uygular: satırı o değere çeker ve
   * toplamı yalnızca aradaki FARK kadar düzeltir. Kısmi okumada (kullanıcı 200 mesajın
   * yarısını okudu) sayaç sıfırlanmaz, sunucunun söylediği değerde kalır.
   */
  applyConversationUnread: (conversationId: number, count: number) => void
  /** Konuşma okundu işaretlendiğinde: satırı sıfırla, toplamdan tam olarak o kadarını düş. */
  clearConversationUnread: (conversationId: number) => void
  setActiveConversationId: (conversationId: number | null) => void
  reset: () => void
}

/**
 * Sohbet rozet durumu — `features/notifications/store.ts` ile aynı sade zustand desenidir
 * (persist YOK; her açılışta sunucudan tazelenir).
 *
 * İki yazar vardır ve ikisi de SUNUCU OTORİTELİDİR:
 *  1. `useChatUnread` ilk mount'ta `GET /api/conversations/unread-count` ile `setSnapshot`,
 *  2. `private-user.{id}` kanalındaki `.chat.unread` olayı ile `applyUnreadEvent` — olay yükü
 *     hem konuşma bazlı hem toplam sayacı taşıdığı için istemcide toplama/çıkarma YAPILMAZ.
 *
 * `clearConversationUnread` tek istisnadır: okundu bildirimi başarıyla gittiğinde sunucunun
 * bir sonraki olayını beklemeden rozeti anında söndürmek için toplamdan yalnızca o konuşmanın
 * bilinen sayısını düşer (asla sıfırın altına inmez).
 */
export const useChatStore = create<ChatState>()((set) => ({
  totalUnread: 0,
  perConversation: {},
  activeConversationId: null,

  setTotalUnread: (count) => set({ totalUnread: Math.max(0, count) }),

  setSnapshot: (snapshot) =>
    set({
      totalUnread: Math.max(0, snapshot.total_unread),
      perConversation: { ...snapshot.per_conversation },
    }),

  setConversationUnread: (conversationId, count) =>
    set((state) => ({
      perConversation: { ...state.perConversation, [conversationId]: Math.max(0, count) },
    })),

  applyUnreadEvent: (event) =>
    set((state) => ({
      totalUnread: Math.max(0, event.total_unread),
      perConversation: {
        ...state.perConversation,
        [event.conversation_id]: Math.max(0, event.conversation_unread),
      },
    })),

  applyConversationUnread: (conversationId, count) =>
    set((state) => {
      const previous = state.perConversation[conversationId] ?? 0
      const next = Math.max(0, count)
      if (previous === next) return state
      return {
        perConversation: { ...state.perConversation, [conversationId]: next },
        totalUnread: Math.max(0, state.totalUnread - (previous - next)),
      }
    }),

  clearConversationUnread: (conversationId) =>
    set((state) => {
      const previous = state.perConversation[conversationId] ?? 0
      if (previous === 0) return state
      const perConversation = { ...state.perConversation, [conversationId]: 0 }
      return { perConversation, totalUnread: Math.max(0, state.totalUnread - previous) }
    }),

  setActiveConversationId: (conversationId) => set({ activeConversationId: conversationId }),

  reset: () => set({ totalUnread: 0, perConversation: {}, activeConversationId: null }),
}))
