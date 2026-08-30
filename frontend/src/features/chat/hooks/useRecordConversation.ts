// Bir kayda (deal/ticket) bağlı sohbet — `POST /api/conversations/for-record`.
import { useEffect } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { chatKeys, fetchOrCreateRecordConversation } from '../api'
import type { Conversation, RecordConversableType } from '../types'

/**
 * Sunucuda GET-OR-CREATE olduğu için POST olmasına rağmen MUTASYON değil SORGU olarak
 * modellenir: idempotenttir, aynı kayıt için hep aynı konuşmayı döner ve sonucu önbelleğe
 * alınabilir. Böylece kayıt detay sayfası her açıldığında yeni bir konuşma OLUŞMAZ; `enabled`
 * bayrağıyla da (ör. sohbet paneli görünene kadar) hiç çağrılmayabilir.
 *
 * Dönen konuşma ayrıca `chatKeys.conversation(id)` önbelleğine yazılır; böylece aynı konuşmanın
 * detayına bakan diğer kancalar (`useConversation`, `useConversationMembers`) ikinci bir istek
 * atmaz.
 */
export function useRecordConversation(
  recordType: RecordConversableType,
  recordId: number | null,
  options?: { enabled?: boolean },
) {
  const queryClient = useQueryClient()
  const enabled = (options?.enabled ?? true) && recordId !== null

  const query = useQuery({
    queryKey: chatKeys.recordConversation(recordType, recordId ?? -1),
    queryFn: () => fetchOrCreateRecordConversation(recordType, recordId as number),
    enabled,
    // Bir kez çözüldükten sonra sonuç neredeyse hiç değişmez; gereksiz tekrar POST atmamak
    // için uzun süre taze sayılır (detay realtime olaylarla zaten güncelleniyor).
    staleTime: 5 * 60_000,
  })

  const conversation: Conversation | undefined = query.data
  useEffect(() => {
    if (!conversation) return
    queryClient.setQueryData(chatKeys.conversation(conversation.id), conversation)
  }, [conversation, queryClient])

  return query
}
