// Sohbet veri katmanının SAF yardımcıları — React'e, axios'a ve Echo'ya bağımlılığı YOKTUR;
// yalnızca React Query önbelleğindeki mesaj sayfalarını (immutable şekilde) dönüştürür.
// Kancaların içinde satır arası mantık bırakmamak ve davranışları tek yerde test edilebilir
// tutmak için ayrıldı.
//
// ÖNBELLEK SIRALAMA SÖZLEŞMESİ (her fonksiyon bunu korur):
//   `InfiniteData<MessagesPage>` → `pages[0]` EN YENİ sayfa, `pages[n]` daha eski.
//   Her sayfanın `data` dizisi de YENİDEN ESKİYE sıralıdır (sunucu böyle döner).
//   Yani "en yeni mesaj" = `pages[0].data[0]`; yeni gelen mesaj oraya EKLENİR (unshift).
//   UI'nin gördüğü düz liste `flattenMessages()` ile ESKİDEN YENİYE çevrilir.
import type { InfiniteData } from '@tanstack/react-query'
import i18n from '../../i18n'
import type {
  Attachment,
  ChatMessage,
  ChatUser,
  Conversation,
  Message,
  MessagesPage,
  SendMessagePayload,
  TickState,
} from './types'

export type MessagesInfiniteData = InfiniteData<MessagesPage, number | undefined>

// ----------------------------------------------------------------------------------------------
// Tik (teslim durumu) aritmetiği
// ----------------------------------------------------------------------------------------------

/**
 * Tik sıralaması MONOTONDUR: bir mesajın tiki yalnızca ileri gidebilir. Gecikmeli/yeniden
 * sıralanmış realtime olaylar (`.message.read` `.message.delivered`'dan önce gelebilir) tik'i
 * GERİ ALMAMALIDIR — bu yüzden her güncelleme `maxTick` üzerinden yapılır.
 */
export const TICK_RANK: Record<TickState, number> = { sent: 0, delivered: 1, read: 2 }

export function maxTick(a: TickState, b: TickState): TickState {
  return TICK_RANK[b] > TICK_RANK[a] ? b : a
}

// ----------------------------------------------------------------------------------------------
// İyimser (optimistic) mesaj üretimi
// ----------------------------------------------------------------------------------------------

/**
 * Geçici mesaj id'leri NEGATİFTİR ve azalarak ilerler. Negatiflik kasıtlı: sunucu id'leri her
 * zaman pozitif olduğu için `id < 0` tek başına "bu kayıt henüz onaylanmadı" demektir ve gerçek
 * bir id ile ASLA çakışamaz (imleç sayfalamasında da `before=` değeri olarak seçilmez).
 */
let optimisticIdCounter = 0

export function nextOptimisticId(): number {
  optimisticIdCounter -= 1
  return optimisticIdCounter
}

export function isOptimistic(message: ChatMessage): boolean {
  return message.client !== undefined || message.id < 0
}

export function createOptimisticMessage(input: {
  conversationId: number
  user: ChatUser | null
  payload: SendMessagePayload
  /** Ek zaten yüklenmişse (bkz. `useUploadAttachment`) önizleme için doğrudan geçilir. */
  attachment?: Attachment | null
  clientId?: number
}): ChatMessage {
  const clientId = input.clientId ?? nextOptimisticId()
  const attachment = input.attachment ?? null
  return {
    id: clientId,
    conversation_id: input.conversationId,
    user: input.user,
    body: input.payload.body ?? null,
    type: attachment ? 'file' : 'text',
    attachment,
    edited_at: null,
    deleted_at: null,
    created_at: new Date().toISOString(),
    // Henüz sunucuya ulaşmadı; en düşük tik ile başlar, onay/olaylar ilerletir.
    tick: 'sent',
    client: { status: 'pending', payload: input.payload, clientId },
  }
}

// ----------------------------------------------------------------------------------------------
// Sayfa dönüşümleri (hepsi immutable)
// ----------------------------------------------------------------------------------------------

/** Sayfaların `data` dizilerini dönüştürür; DEĞİŞMEYEN sayfalar referansını korur. */
function transformPages(
  data: MessagesInfiniteData,
  transform: (messages: ChatMessage[]) => ChatMessage[],
): MessagesInfiniteData {
  let changed = false
  const pages = data.pages.map((page) => {
    const next = transform(page.data)
    if (next === page.data) return page
    changed = true
    return { ...page, data: next }
  })
  return changed ? { ...data, pages } : data
}

/** UI'nin render ettiği düz liste: ESKİDEN YENİYE, id bazında tekilleştirilmiş. */
export function flattenMessages(data: MessagesInfiniteData | undefined): ChatMessage[] {
  if (!data) return []
  const seen = new Set<number>()
  const result: ChatMessage[] = []
  // Sayfalar yeniden eskiye geldiği için ters gezilir; sayfa içi de ters.
  for (let p = data.pages.length - 1; p >= 0; p -= 1) {
    const page = data.pages[p]
    for (let i = page.data.length - 1; i >= 0; i -= 1) {
      const message = page.data[i]
      if (seen.has(message.id)) continue
      seen.add(message.id)
      result.push(message)
    }
  }
  return result
}

export function findMessage(data: MessagesInfiniteData | undefined, id: number): ChatMessage | undefined {
  if (!data) return undefined
  for (const page of data.pages) {
    const found = page.data.find((message) => message.id === id)
    if (found) return found
  }
  return undefined
}

/** Önbellekteki en yeni SUNUCU mesajının id'si — iyimser (negatif) kayıtlar sayılmaz. */
export function newestServerMessageId(data: MessagesInfiniteData | undefined): number | null {
  if (!data) return null
  let max: number | null = null
  for (const page of data.pages) {
    for (const message of page.data) {
      if (message.id > 0 && (max === null || message.id > max)) max = message.id
    }
  }
  return max
}

/** Tek bir mesajı id ile yamalar; mesaj yoksa önbellek olduğu gibi döner. */
export function patchMessage(
  data: MessagesInfiniteData,
  id: number,
  patch: (message: ChatMessage) => ChatMessage,
): MessagesInfiniteData {
  return transformPages(data, (messages) => {
    const index = messages.findIndex((message) => message.id === id)
    if (index === -1) return messages
    const next = messages.slice()
    next[index] = patch(messages[index])
    return next
  })
}

export function removeMessage(data: MessagesInfiniteData, id: number): MessagesInfiniteData {
  return transformPages(data, (messages) => {
    if (!messages.some((message) => message.id === id)) return messages
    return messages.filter((message) => message.id !== id)
  })
}

/**
 * Sunucudan gelen bir mesajı önbelleğe yazar. ÇİFT YAZMA ÖNLEMENİN kalbi burasıdır ve iki
 * kademede çalışır:
 *  1. Aynı id zaten varsa YERİNDE değiştirilir — aynı mesajın broadcast'i kaç kez gelirse
 *     gelsin liste büyümez.
 *  2. Yoksa ve mesaj BİZE aitse, bekleyen bir iyimser kopyayla (aynı yazar + aynı gövde +
 *     aynı ek) eşleştirilip onun YERİNE konur. Böylece `.message.created` HTTP yanıtından
 *     ÖNCE gelse bile ekranda bir an için iki kopya belirmez; yanıt geldiğinde
 *     `replaceOptimisticMessage` zaten yerleşmiş gerçek kaydı yeniden yazar (yine tek kopya).
 *  3. Hiçbiri tutmazsa en yeni sayfanın başına eklenir.
 */
export function upsertIncomingMessage(
  data: MessagesInfiniteData,
  incoming: Message,
  currentUserId: number | null,
): MessagesInfiniteData {
  if (findMessage(data, incoming.id)) {
    return patchMessage(data, incoming.id, (existing) => mergeServerMessage(existing, incoming))
  }

  if (currentUserId !== null && incoming.user?.id === currentUserId) {
    const match = findMatchingOptimistic(data, incoming, currentUserId)
    if (match !== null) {
      return patchMessage(data, match, () => ({ ...incoming }))
    }
  }

  const pages = data.pages.slice()
  if (pages.length === 0) {
    pages.push({ data: [{ ...incoming }], meta: { has_more: false, next_before: null } })
    return { ...data, pages, pageParams: data.pageParams.length ? data.pageParams : [undefined] }
  }
  pages[0] = { ...pages[0], data: [{ ...incoming }, ...pages[0].data] }
  return { ...data, pages }
}

/**
 * Sunucu kaydını mevcut kaydın üzerine yazarken tik GERİ ALINMAZ (bkz. `maxTick`) — broadcast
 * yükü `tick: 'sent'` taşıyor olabilir ama biz o mesajın çoktan okunduğunu görmüş olabiliriz.
 */
function mergeServerMessage(existing: ChatMessage, incoming: Message): ChatMessage {
  return { ...incoming, tick: maxTick(existing.tick, incoming.tick) }
}

function findMatchingOptimistic(
  data: MessagesInfiniteData,
  incoming: Message,
  currentUserId: number,
): number | null {
  for (const page of data.pages) {
    for (const message of page.data) {
      if (message.client?.status !== 'pending') continue
      if (message.user?.id !== currentUserId) continue
      if ((message.body ?? '') !== (incoming.body ?? '')) continue
      if ((message.attachment?.id ?? null) !== (incoming.attachment?.id ?? null)) continue
      return message.id
    }
  }
  return null
}

/**
 * İyimser kaydı sunucu yanıtıyla takas eder. Broadcast önce geldiyse geçici kayıt zaten
 * silinmiş olabilir — bu durumda gerçek kayıt `upsertIncomingMessage` ile (id eşleşmesiyle)
 * yerine yazılır, yeni bir kopya OLUŞMAZ.
 */
export function replaceOptimisticMessage(
  data: MessagesInfiniteData,
  clientId: number,
  real: Message,
  currentUserId: number | null,
): MessagesInfiniteData {
  if (findMessage(data, clientId)) {
    // Aynı gerçek id başka bir yolla (broadcast) zaten eklendiyse geçici kaydı sadece düşür.
    if (findMessage(data, real.id)) {
      return patchMessage(removeMessage(data, clientId), real.id, (existing) =>
        mergeServerMessage(existing, real),
      )
    }
    return patchMessage(data, clientId, () => ({ ...real }))
  }
  return upsertIncomingMessage(data, real, currentUserId)
}

/** Gönderilemeyen iyimser mesajı listede BIRAKIR, yalnızca durumunu `failed` yapar. */
export function markOptimisticFailed(data: MessagesInfiniteData, clientId: number): MessagesInfiniteData {
  return patchMessage(data, clientId, (message) => ({
    ...message,
    client: message.client
      ? { ...message.client, status: 'failed' }
      : { status: 'failed', payload: { body: message.body }, clientId },
  }))
}

/**
 * Silinen mesaj listeden ÇIKARILMAZ: `deleted_at` doldurulur, `body`/`attachment` boşaltılır
 * (sunucunun tombstone davranışının aynısı). UI "Bu mesaj silindi" yazar, konuşma akışındaki
 * boşluk ve komşu mesajların gruplaması bozulmaz.
 */
export function tombstoneMessage(data: MessagesInfiniteData, id: number, deletedAt?: string): MessagesInfiniteData {
  return patchMessage(data, id, (message) => ({
    ...message,
    body: null,
    attachment: null,
    deleted_at: deletedAt ?? message.deleted_at ?? new Date().toISOString(),
  }))
}

/**
 * `.message.read` / `.message.delivered` sonrası KENDİ mesajlarımızın tikini ilerletir:
 * olaydaki `last_*_message_id`'ye kadar olan (ve ondan eski) tüm mesajlar hedef duruma
 * TAŞINIR — ama `maxTick` sayesinde asla geri gitmez.
 */
export function advanceOwnTicks(
  data: MessagesInfiniteData,
  currentUserId: number,
  uptoMessageId: number,
  tick: TickState,
): MessagesInfiniteData {
  return transformPages(data, (messages) => {
    let changed = false
    const next = messages.map((message) => {
      if (message.user?.id !== currentUserId) return message
      if (message.id <= 0 || message.id > uptoMessageId) return message
      const merged = maxTick(message.tick, tick)
      if (merged === message.tick) return message
      changed = true
      return { ...message, tick: merged }
    })
    return changed ? next : messages
  })
}

// ----------------------------------------------------------------------------------------------
// Konuşma listesi yardımcıları
// ----------------------------------------------------------------------------------------------

/** Liste her zaman `last_message_at` azalan sıradadır; tarihi olmayanlar en sona düşer. */
export function sortConversations(list: Conversation[]): Conversation[] {
  return list.slice().sort((a, b) => {
    const left = a.last_message_at ? Date.parse(a.last_message_at) : 0
    const right = b.last_message_at ? Date.parse(b.last_message_at) : 0
    if (left === right) return b.id - a.id
    return right - left
  })
}

export function upsertConversation(list: Conversation[], conversation: Conversation): Conversation[] {
  const index = list.findIndex((item) => item.id === conversation.id)
  const next = list.slice()
  if (index === -1) next.push(conversation)
  else next[index] = { ...next[index], ...conversation }
  return sortConversations(next)
}

/** Yeni mesaj geldiğinde/gönderildiğinde liste satırının önizlemesini ve sırasını tazeler. */
export function patchConversationPreview(
  list: Conversation[],
  conversationId: number,
  patch: { last_message_at?: string | null; last_message_preview?: string | null; unread_count?: number },
): Conversation[] {
  const index = list.findIndex((item) => item.id === conversationId)
  if (index === -1) return list
  const next = list.slice()
  next[index] = { ...next[index], ...patch }
  return sortConversations(next)
}

/** Bir mesajın liste önizlemesinde nasıl görüneceği (ek varsa dosya adı, silinmişse tire). */
export function previewOf(message: Pick<Message, 'body' | 'attachment' | 'deleted_at'>): string {
  if (message.deleted_at) return i18n.t('chat:message.deletedPreview')
  if (message.body && message.body.trim().length > 0) return message.body
  if (message.attachment) return message.attachment.original_name
  return ''
}
