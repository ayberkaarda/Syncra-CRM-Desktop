// Konuşma OKUMA kancaları (liste + detay). Kayda bağlı sohbet `useRecordConversation.ts`,
// mutasyonlar `useConversationMutations.ts` içindedir.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { chatKeys, fetchConversation, fetchConversations } from '../api'
import { sortConversations } from '../utils'
import type { ConversationsQuery } from '../types'

/**
 * Konuşma listesi. Sıralama İSTEMCİDE de garanti altına alınır (`select: sortConversations`):
 * sunucu `last_message_at` azalan döndürse bile realtime olaylarla önbelleği yerinde
 * güncellediğimiz için (bkz. `useChatSocket`) sıranın tek bir yerde zorlanması gerekir.
 *
 * `placeholderData: keepPreviousData` — arama kutusuna yazarken (`q`) liste boşalıp
 * titremesin diye önceki sonuç korunur (desen: `useTickets`).
 */
export function useConversations(params: ConversationsQuery = {}) {
  const query: ConversationsQuery = { type: params.type, q: params.q?.trim() || undefined }
  return useQuery({
    queryKey: chatKeys.conversationList(query),
    queryFn: () => fetchConversations(query),
    select: sortConversations,
    placeholderData: keepPreviousData,
  })
}

/** Tek konuşma detayı. `id` null iken sorgu hiç çalışmaz (henüz konuşma seçilmemiş demektir). */
export function useConversation(id: number | null) {
  return useQuery({
    queryKey: chatKeys.conversation(id ?? -1),
    queryFn: () => fetchConversation(id as number),
    enabled: id !== null,
  })
}
