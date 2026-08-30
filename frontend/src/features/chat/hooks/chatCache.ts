// React Query önbelleğine dokunan ORTAK yardımcılar. `utils.ts` saf veri dönüşümü yapar
// (QueryClient bilmez); burası o dönüşümleri doğru anahtarlara uygular. Hem mutasyonlar
// (`useMessageMutations`) hem realtime dinleyicisi (`useChatSocket`) aynı yolları kullansın
// diye tek yerde toplandı — iki tarafın önbelleği farklı şekilde yazması, çift mesaj/kaybolan
// mesaj hatalarının en yaygın kaynağıdır.
import type { QueryClient } from '@tanstack/react-query'
import { chatKeys } from '../api'
import { patchConversationPreview, previewOf, upsertConversation } from '../utils'
import type { MessagesInfiniteData } from '../utils'
import type { Conversation, Message } from '../types'

/**
 * Mesaj önbelleğini günceller. Önbellek HENÜZ YOKSA hiçbir şey yazılmaz: boş bir yapı
 * uydurmak, sorgu ilk kez çalıştığında "daha eski mesaj yok" yanılgısı yaratır ve geçmişi
 * kilitler. Sorgu çalıştığında zaten sunucudan tam sayfa gelecek.
 */
export function updateMessagesCache(
  queryClient: QueryClient,
  conversationId: number,
  updater: (data: MessagesInfiniteData) => MessagesInfiniteData,
): void {
  queryClient.setQueryData<MessagesInfiniteData>(chatKeys.messages(conversationId), (old) =>
    old ? updater(old) : old,
  )
}

export function getMessagesCache(
  queryClient: QueryClient,
  conversationId: number,
): MessagesInfiniteData | undefined {
  return queryClient.getQueryData<MessagesInfiniteData>(chatKeys.messages(conversationId))
}

/**
 * Konuşma LİSTESİ sorguları filtreye göre ayrı anahtarlarda tutulur
 * (`['chat','conversations','list', {type,q}]`). `setQueriesData` kısmi anahtarla çağrılsaydı
 * detay sorgularını (`...,'detail',id`) da yakalar ve dizi bekleyen güncelleyici tek nesnenin
 * üzerine yazardı — bu yüzden eşleşme `predicate` ile YALNIZCA liste anahtarlarına kısıtlanır.
 */
function updateConversationLists(
  queryClient: QueryClient,
  updater: (list: Conversation[]) => Conversation[],
): void {
  queryClient.setQueriesData<Conversation[]>(
    {
      predicate: (query) => {
        const key = query.queryKey
        return key[0] === 'chat' && key[1] === 'conversations' && key[2] === 'list'
      },
    },
    (old) => (old ? updater(old) : old),
  )
}

/**
 * Konuşma herhangi bir liste önbelleğinde var mı? Global rozet olayı (`.chat.unread`) hiç
 * görmediğimiz bir konuşma için geldiğinde listeyi yerinde yamalamak imkânsızdır; ancak o
 * durumda sunucudan yeniden çekilir. Her olayda koşulsuz `invalidate` etmek yoğun sohbette
 * gereksiz istek yağmuru yaratırdı — bu kontrol onu önler.
 */
export function hasConversationInLists(queryClient: QueryClient, conversationId: number): boolean {
  const entries = queryClient.getQueriesData<Conversation[]>({
    predicate: (query) => {
      const key = query.queryKey
      return key[0] === 'chat' && key[1] === 'conversations' && key[2] === 'list'
    },
  })
  return entries.some(([, list]) => Array.isArray(list) && list.some((item) => item.id === conversationId))
}

/** Yeni/güncellenmiş konuşmayı hem detay hem tüm liste önbelleklerine yazar. */
export function syncConversationCaches(queryClient: QueryClient, conversation: Conversation): void {
  queryClient.setQueryData(chatKeys.conversation(conversation.id), conversation)
  updateConversationLists(queryClient, (list) => upsertConversation(list, conversation))
}

/**
 * Bir mesaj geldiğinde/gönderildiğinde konuşma satırının önizlemesini ve zaman damgasını
 * tazeler; liste `last_message_at`'e göre yeniden sıralandığı için satır kendiliğinden en
 * üste çıkar (sunucudan yeni liste çekmeye gerek kalmadan).
 */
export function bumpConversationPreview(
  queryClient: QueryClient,
  conversationId: number,
  message: Pick<Message, 'body' | 'attachment' | 'deleted_at' | 'created_at'>,
  unreadCount?: number,
): void {
  updateConversationLists(queryClient, (list) =>
    patchConversationPreview(list, conversationId, {
      last_message_at: message.created_at,
      last_message_preview: previewOf(message),
      ...(unreadCount === undefined ? {} : { unread_count: unreadCount }),
    }),
  )
}

/**
 * Konuşma satırının okunmamış rozetini önbellekte SUNUCUNUN söylediği değere çeker (store'dan
 * bağımsız). Sıfır varsayılmaz: kısmi okumada sunucu sıfır olmayan bir sayı döndürebilir.
 */
export function setConversationUnreadCache(
  queryClient: QueryClient,
  conversationId: number,
  unreadCount: number,
): void {
  const next = Math.max(0, unreadCount)
  updateConversationLists(queryClient, (list) =>
    patchConversationPreview(list, conversationId, { unread_count: next }),
  )
  queryClient.setQueryData<Conversation>(chatKeys.conversation(conversationId), (old) =>
    old ? { ...old, unread_count: next } : old,
  )
}

/**
 * `.conversation.updated` olayının önbelleğe uygulanması — KISMİ birleştirme.
 *
 * NEDEN TAM NESNE YAZILAMAZ: bu olay konuşmanın PAYLAŞILAN kanalında yayınlanır, yani tek bir
 * yük tüm üyelere gider. `unread_count` ve `is_muted` KİŞİYE ÖZELDİR ve böyle bir yayında
 * kişiselleştirilemediği için sunucu onları sabit `0` / `false` gönderir — BAĞLAYICI DEĞİLLER.
 * Tam nesneyi yazsaydık, biri grubu yeniden adlandırdığında o gruptaki HERKESİN okunmamış
 * rozeti sıfırlanır ve sessize aldıkları sohbet sessizden çıkardı.
 *
 * Bu yüzden yalnızca gerçekten paylaşılan alanlar birleştirilir; kişiye özel iki alan
 * önbellekteki YEREL değerinde bırakılır. Zustand `perConversation` sayacına da dokunulmaz.
 *
 * KONUŞMA ÖNBELLEKTE HİÇ YOKSA: yerel bir değer olmadığı için `unread_count`/`is_muted`
 * uydurulmaz (uydurmak, yeni eklendiğimiz bir gruba ait okunmamışları görünmez yapabilirdi).
 * Bunun yerine liste sorguları invalidate edilir; sunucu satırı KİŞİYE ÖZEL doğru değerlerle
 * geri döner. Zaten bu olayı ilk kez gördüğümüz tipik senaryo, konuşmaya yeni eklenmiş
 * olmamızdır — listenin tazelenmesi gereken durum tam olarak budur.
 */
export function mergeConversationUpdate(queryClient: QueryClient, incoming: Conversation): void {
  // Kişiye özel alanları KORUYARAK yalnızca paylaşılan alanları alır.
  const mergeShared = (local: Conversation): Conversation => ({
    ...local,
    type: incoming.type,
    name: incoming.name,
    display_name: incoming.display_name,
    conversable: incoming.conversable,
    created_by: incoming.created_by,
    members: incoming.members,
    // `unread_count` ve `is_muted` BİLEREK atlanır — bkz. yukarıdaki gerekçe.
  })

  let known = false

  const detail = queryClient.getQueryData<Conversation>(chatKeys.conversation(incoming.id))
  if (detail) {
    known = true
    queryClient.setQueryData(chatKeys.conversation(incoming.id), mergeShared(detail))
  }

  updateConversationLists(queryClient, (list) => {
    const index = list.findIndex((item) => item.id === incoming.id)
    if (index === -1) return list
    known = true
    const next = list.slice()
    next[index] = mergeShared(next[index])
    // Sıralama `last_message_at`'e dayanır ve bu olay onu değiştirmez; yeniden sıralanmaz.
    return next
  })

  if (!known) {
    void queryClient.invalidateQueries({ queryKey: chatKeys.conversations })
  }
}
