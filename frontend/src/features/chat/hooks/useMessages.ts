// Mesaj akışı — imleç (cursor) sayfalaması.
//
// Sunucu YENİDEN ESKİYE döner ve geçmişe `?before=<message_id>` ile gidilir; klasik
// sayfa numarası KULLANILMAZ çünkü akışa sürekli yeni mesaj eklenirken sayfa sınırları
// kayar ve aynı mesaj iki farklı sayfada görünürdü. İmleç bu kaymadan etkilenmez.
//
// UI'ye verilen `messages` dizisi ESKİDEN YENİYE'dir (en yeni en altta) — önbellekteki ters
// sıralamayı `flattenMessages` çevirir, böylece "daha eskisini yükle" sonrası liste başa
// eklenir ve mevcut kaydırma konumu bozulmaz.
import { useMemo } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import type { UseInfiniteQueryResult } from '@tanstack/react-query'
import { chatKeys, fetchMessages } from '../api'
import { flattenMessages, newestServerMessageId } from '../utils'
import type { MessagesInfiniteData } from '../utils'
import type { ChatMessage, MessagesPage } from '../types'

/**
 * Sayfa imleci: ilk sayfa `undefined` (= en yeni), sonrakiler `before=<message_id>`.
 * Ayrı bir ad verilmesinin sebebi aşağıdaki AÇIK generic listesi — bkz. `useMessages`.
 */
type MessagesPageParam = number | undefined

/** `chatKeys.messages()` `readonly ['chat','messages', number]` döner; generic'te aynen kullanılır. */
type MessagesQueryKey = ReturnType<typeof chatKeys.messages>

/**
 * Dönüş, HAM `useInfiniteQuery` sonucunun ÜST KÜMESİDİR: `data.pages`, `fetchNextPage`,
 * `hasNextPage`, `isFetchingNextPage`, `isLoading`, `refetch` … hepsi olduğu gibi durur
 * (doğrudan React Query bilen çağıranlar için), üzerine türetilmiş kolaylıklar eklenir.
 * Böylece hem `data.pages`'i kendisi düzleştiren bir bileşen hem de hazır `messages` dizisini
 * isteyen bir bileşen aynı kancayla çalışır.
 */
export type UseMessagesResult = UseInfiniteQueryResult<MessagesInfiniteData, Error> & {
  /** Render sırası: ESKİDEN YENİYE. İyimser ve silinmiş (tombstone) mesajlar DAHİLDİR. */
  messages: ChatMessage[]
  /** Daha eski mesaj var mı (`meta.has_more`) — `hasNextPage` ile aynı bilgi, okunur adı. */
  hasMore: boolean
  /** Bir önceki sayfayı (daha eskisini) yükler; zaten yükleniyorsa veya bitmişse no-op. */
  loadMore: () => void
  isLoadingMore: boolean
  /** Önbellekteki en yeni SUNUCU mesajının id'si — okundu/teslim bildirimlerinin dayanağı. */
  newestMessageId: number | null
}

export function useMessages(conversationId: number | null): UseMessagesResult {
  // GENERIC'LER NEDEN AÇIK YAZILIYOR: `useInfiniteQuery`'nin `TData` varsayılanı
  // `InfiniteData<TQueryFnData>`'dır ve `TPageParam`'ı İÇİNE TAŞIMAZ (bkz.
  // `node_modules/@tanstack/react-query/build/modern/useInfiniteQuery.d.ts`) — yani çıkarım
  // `InfiniteData<MessagesPage, unknown>` üretir ve bizim `MessagesInfiniteData`
  // (`InfiniteData<MessagesPage, number | undefined>`) tipimizle sayfa imleci parametresinde
  // ayrışır. `utils.ts` ve `chatCache.ts` imleci gerçek tipiyle (`number | undefined`) gördüğü
  // için doğru olan tarafı `unknown`'a düşürmek değil, generic'leri açıkça vermektir.
  // Sıra sözleşmesi: <TQueryFnData, TError, TData, TQueryKey, TPageParam>.
  const query = useInfiniteQuery<MessagesPage, Error, MessagesInfiniteData, MessagesQueryKey, MessagesPageParam>({
    queryKey: chatKeys.messages(conversationId ?? -1),
    queryFn: ({ pageParam }) => fetchMessages(conversationId as number, pageParam),
    initialPageParam: undefined,
    getNextPageParam: (lastPage) =>
      lastPage.meta.has_more ? (lastPage.meta.next_before ?? undefined) : undefined,
    enabled: conversationId !== null,
    // Akış zaten realtime olaylarla canlı tutuluyor; pencereye her dönüşte tüm sayfaları
    // yeniden çekmenin anlamı yok (ve iyimser kayıtları da ezme riski taşır).
    refetchOnWindowFocus: false,
  })

  const messages = useMemo(() => flattenMessages(query.data), [query.data])
  const newestMessageId = useMemo(() => newestServerMessageId(query.data), [query.data])

  // `Object.assign({}, ...)` KASITLI: `UseInfiniteQueryResult` bir birleşim (union) tipidir,
  // nesne yayılımı birleşimi genişletir; `Object.assign` tam istediğimiz kesişimi verir.
  return Object.assign({}, query, {
    messages,
    hasMore: query.hasNextPage,
    loadMore: () => {
      if (query.hasNextPage && !query.isFetchingNextPage) void query.fetchNextPage()
    },
    isLoadingMore: query.isFetchingNextPage,
    newestMessageId,
  })
}
