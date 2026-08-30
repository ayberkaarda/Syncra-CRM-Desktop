// Sohbet (Chat) modülü tipleri — backend `ConversationResource` / `MessageResource` /
// `AttachmentResource` ile birebir eşleşir (bkz. Faz 12 görev tanımı "BACKEND SÖZLEŞMESİ").
// Alan adları BAĞLAYICIDIR; sözleşmenin dışına çıkılmaz.
//
// İki katman vardır ve karıştırılmamalıdır:
//  1. SUNUCU tipleri (`Message`, `Conversation`, `Attachment`, ...) — telde ne varsa o.
//  2. İSTEMCİ tipi (`ChatMessage`) — sunucu mesajının üzerine yalnızca önbellekte yaşayan
//     `client` alanını ekler (iyimser gönderim durumu + tekrar deneme gövdesi). Sunucudan gelen
//     hiçbir mesajda `client` YOKTUR; `client` dolu olan her mesaj henüz onaylanmamış (ya da
//     başarısız olmuş) yerel bir kayıttır. `ChatMessage extends Message` olduğu için `Message`
//     bekleyen her UI kodu `ChatMessage` ile de çalışır.

export type ConversationType = 'dm' | 'group' | 'record'
export type MessageType = 'text' | 'file' | 'system'

/**
 * Kendi gönderdiğin mesajın teslim durumu. Sıralama MONOTONDUR:
 * `sent` < `delivered` < `read` — bkz. `utils.ts` içindeki `TICK_RANK` / `maxTick`.
 * Realtime olaylar bu değeri yalnızca İLERİ taşıyabilir, asla geri alamaz.
 */
export type TickState = 'sent' | 'delivered' | 'read'

export type ChatUser = { id: number; name: string; email: string }

export type Attachment = {
  id: number
  original_name: string
  mime_type: string
  size: number
  is_image: boolean
  url: string
}

/**
 * Tek bir mesaj (`GET /api/conversations/{id}/messages`, `MessageResource`).
 *
 * - `deleted_at` DOLU ise mesaj bir "mezar taşı"dır (tombstone): listeden ÇIKARILMAZ,
 *   `body` ve `attachment` sunucuda `null`'a çevrilir, UI "Bu mesaj silindi" gösterir.
 * - `tick` yalnızca KENDİ mesajların için anlamlıdır; başkasının mesajında sunucu ne
 *   gönderirse göndersin UI onu okumaz.
 */
export type Message = {
  id: number
  conversation_id: number
  user: ChatUser | null
  body: string | null
  type: MessageType
  attachment: Attachment | null
  edited_at: string | null
  deleted_at: string | null
  created_at: string
  tick: TickState
}

export type ConversableRef = { type: string; id: number; label: string }

export type Conversation = {
  id: number
  type: ConversationType
  name: string | null
  display_name: string
  conversable: ConversableRef | null
  created_by: number | null
  last_message_at: string | null
  last_message_preview: string | null
  unread_count: number
  is_muted: boolean
  members: ChatUser[]
}

/** `POST /api/conversations/for-record` gövdesindeki `conversable_type` değerleri. */
export type RecordConversableType = 'deal' | 'ticket'

// ----------------------------------------------------------------------------------------------
// İstemci tarafı (önbellek) tipleri
// ----------------------------------------------------------------------------------------------

/** İyimser mesajın yaşam döngüsü: gönderiliyor → (hata) → tekrar denenebilir. */
export type OptimisticStatus = 'pending' | 'failed'

/**
 * Yalnızca React Query önbelleğinde yaşayan istemci meta verisi. Sunucudan gelen hiçbir
 * mesajda bulunmaz — varlığı "bu kayıt henüz sunucuda onaylanmadı" demektir.
 */
export type ChatMessageClientMeta = {
  status: OptimisticStatus
  /** Başarısız gönderimi TEKRAR DENEMEK için saklanan istek gövdesi. */
  payload: SendMessagePayload
  /** Geçici (negatif) mesaj id'si — `useSendMessage().retry(clientId)` bununla çağrılır. */
  clientId: number
}

/** Önbellekte tutulan mesaj: sunucu mesajı + (varsa) iyimser gönderim meta verisi. */
export type ChatMessage = Message & { client?: ChatMessageClientMeta }

// ----------------------------------------------------------------------------------------------
// İstek / yanıt gövdeleri
// ----------------------------------------------------------------------------------------------

export type ConversationsQuery = {
  type?: ConversationType
  q?: string
}

export type CreateConversationPayload = {
  type: ConversationType
  name?: string | null
  member_ids: number[]
}

export type SendMessagePayload = {
  body?: string | null
  attachment_id?: number | null
  mentions?: number[]
}

/**
 * `GET /api/conversations/{id}/messages?before=&per_page=` yanıtı.
 *
 * SIRALAMA SÖZLEŞMESİ: sayfa içindeki `data` dizisi YENİDEN ESKİYE doğrudur ve sayfalama
 * imleçlidir (`before=<message_id>`). Bu yüzden `pages[0]` EN YENİ sayfadır, sonraki sayfalar
 * geçmişe gider. UI'ye verilen düz liste bu iki katmanın tersine çevrilmesiyle üretilir
 * (`utils.ts` → `flattenMessages`).
 */
export type MessagesPage = {
  data: ChatMessage[]
  meta: {
    has_more: boolean
    /** Bir sonraki sayfa için `?before=` değeri; `has_more` false ise `null`. */
    next_before: number | null
  }
}

/**
 * `POST /api/conversations/{id}/read` ve `.../delivered` yanıtı.
 *
 * İSTEK GÖVDESİNDEKİ ALAN ADI `message_id`'DİR (`last_message_id` DEĞİL — backend
 * `CursorConversationRequest` böyle bekliyor). Alan `sometimes|nullable` olduğu için yanlış
 * bir ad 422 ÜRETMEZ, sessizce "imleç verilmedi" sayılır ve sunucu konuşmanın EN SON mesajını
 * işaretler; bu yüzden ad burada ve `api.ts`'te açıkça belgelenmiştir.
 *
 * Yanıttaki `unread_count` SUNUCU OTORİTELİDİR: kısmi okuma sonrası sıfır olmayabilir, bu
 * yüzden rozet yerel olarak sıfırlanmaz, bu değerle hizalanır.
 */
export type ConversationCursorAck = {
  last_read_message_id: number | null
  last_delivered_message_id: number | null
  unread_count: number
}

/** `GET /api/conversations/unread-count` — normalize edilmiş şekil (bkz. `api.ts`). */
export type ChatUnreadCount = {
  total_unread: number
  per_conversation: Record<number, number>
}

// ----------------------------------------------------------------------------------------------
// Realtime olay yükleri — `private-conversation.{id}` kanalı
// (olay adlarının başındaki NOKTA ZORUNLUDUR: sunucu `broadcastAs` kullanıyor)
// ----------------------------------------------------------------------------------------------

export type MessageCreatedEvent = { message: Message }
export type MessageUpdatedEvent = { message: Message }
export type MessageDeletedEvent = { message_id: number; conversation_id: number }
export type MessageReadEvent = {
  user_id: number
  conversation_id: number
  last_read_message_id: number
}
export type MessageDeliveredEvent = {
  user_id: number
  conversation_id: number
  last_delivered_message_id: number
}
export type ConversationUpdatedEvent = { conversation: Conversation }

/** `private-user.{currentUserId}` kanalındaki `.chat.unread` yükü — global rozetin kaynağı. */
export type ChatUnreadEvent = {
  conversation_id: number
  conversation_unread: number
  total_unread: number
  preview: string | null
  sender_name: string | null
}

/**
 * "Yazıyor" bildirimi SUNUCUYA YAZILMAZ — Echo whisper ile istemciden istemciye gider
 * (`channel.whisper('typing', payload)` / `channel.listenForWhisper('typing', cb)`).
 * Bu yüzden yük minimaldir: e-posta gibi alanlar taşınmaz, gerekirse konuşma önbelleğindeki
 * üye listesinden zenginleştirilir (bkz. `hooks/useTyping.ts`).
 */
export type TypingWhisper = { user_id: number; name: string }
