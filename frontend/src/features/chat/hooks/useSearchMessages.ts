// Mesaj arama (`GET /api/messages/search`). `conversation_id` verilirse arama o konuşmayla
// sınırlanır, verilmezse kullanıcının erişebildiği tüm sohbetlerde arar.
import { useEffect, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { chatKeys, searchMessagesRequest } from '../api'

/** Bu uzunluğun altındaki sorgular sunucuya HİÇ gitmez — tek harflik arama tüm arşivi tarar. */
export const MIN_SEARCH_LENGTH = 2

const SEARCH_DEBOUNCE_MS = 300

/**
 * Geciktirme (debounce) kancanın İÇİNDEDİR: arama kutusuna yazan her bileşenin bunu ayrıca
 * kurmasını beklemek, er ya da geç her tuş vuruşunda istek atan bir çağıran doğurur.
 * Çağıran ham `q`'yu verir; kanca 300 ms sessizlik olmadan sorgu anahtarını değiştirmez.
 */
export function useSearchMessages(q: string, conversationId?: number) {
  const trimmed = q.trim()
  const [debounced, setDebounced] = useState(trimmed)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(trimmed), SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [trimmed])

  return useQuery({
    queryKey: chatKeys.search(debounced, conversationId),
    queryFn: () => searchMessagesRequest(debounced, conversationId),
    enabled: debounced.length >= MIN_SEARCH_LENGTH,
    // Yeni sonuçlar gelene kadar önceki liste ekranda kalsın (boş-dolu titremesi olmasın).
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })
}
