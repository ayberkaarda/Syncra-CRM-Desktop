// Sohbet veri katmanı — YALNIZCA uç fonksiyonları ve sorgu anahtarı fabrikası.
// React Query kancaları `hooks/**` altındadır (desen: `features/notifications/api.ts` +
// `features/tickets/api/ticketsApi.ts`). Hata gövdesi tüm uçlarda
// `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { api } from '../../lib/axios'
import type {
  Attachment,
  ChatUnreadCount,
  Conversation,
  ConversationCursorAck,
  ConversationsQuery,
  CreateConversationPayload,
  Message,
  MessagesPage,
  RecordConversableType,
  SendMessagePayload,
} from './types'

/**
 * Tüm sohbet sorguları `['chat', ...]` kökü altındadır; böylece çıkışta/temizlikte tek
 * `invalidateQueries({ queryKey: chatKeys.all })` yeter.
 */
export const chatKeys = {
  all: ['chat'] as const,
  conversations: ['chat', 'conversations'] as const,
  conversationList: (query: ConversationsQuery) => ['chat', 'conversations', 'list', query] as const,
  conversation: (id: number) => ['chat', 'conversations', 'detail', id] as const,
  recordConversation: (type: RecordConversableType, id: number) =>
    ['chat', 'conversations', 'record', type, id] as const,
  messages: (conversationId: number) => ['chat', 'messages', conversationId] as const,
  search: (q: string, conversationId?: number) => ['chat', 'search', q, conversationId ?? null] as const,
  unreadCount: ['chat', 'unread-count'] as const,
}

// ----------------------------------------------------------------------------------------------
// Konuşmalar
// ----------------------------------------------------------------------------------------------

export async function fetchConversations(query: ConversationsQuery): Promise<Conversation[]> {
  const { data } = await api.get<{ data: Conversation[] }>('/api/conversations', {
    params: {
      'filter[type]': query.type || undefined,
      q: query.q?.trim() || undefined,
    },
  })
  return data.data
}

/**
 * Global rozet sayacı. Backend paralel yazıldığı için yanıt TOLERANSLI okunur:
 * `{ data: { total_unread, per_conversation } }` beklenir, ama `unread_count` /
 * `conversations` gibi eşdeğer adlar da kabul edilir ve eksik alanlar sıfıra düşer —
 * rozetin küçük bir isim farkı yüzünden çökmesi kabul edilemez.
 */
export async function fetchChatUnreadCount(): Promise<ChatUnreadCount> {
  const { data } = await api.get<Record<string, unknown>>('/api/conversations/unread-count')
  const raw = (data.data ?? data) as Record<string, unknown>
  const total = raw.total_unread ?? raw.unread_count ?? raw.total ?? 0
  const per = (raw.per_conversation ?? raw.conversations ?? raw.by_conversation ?? {}) as Record<string, unknown>

  const perConversation: Record<number, number> = {}
  for (const [key, value] of Object.entries(per)) {
    const id = Number(key)
    const count = Number(value)
    if (Number.isFinite(id) && Number.isFinite(count)) perConversation[id] = count
  }

  return { total_unread: Number(total) || 0, per_conversation: perConversation }
}

export async function fetchConversation(id: number): Promise<Conversation> {
  const { data } = await api.get<{ data: Conversation }>(`/api/conversations/${id}`)
  return data.data
}

export async function createConversationRequest(payload: CreateConversationPayload): Promise<Conversation> {
  const { data } = await api.post<{ data: Conversation }>('/api/conversations', {
    type: payload.type,
    name: payload.name ?? undefined,
    member_ids: payload.member_ids,
  })
  return data.data
}

/** Kayıt (deal/ticket) sohbeti — sunucuda GET-OR-CREATE'tir, tekrar çağırmak güvenlidir. */
export async function fetchOrCreateRecordConversation(
  conversableType: RecordConversableType,
  conversableId: number,
): Promise<Conversation> {
  const { data } = await api.post<{ data: Conversation }>('/api/conversations/for-record', {
    conversable_type: conversableType,
    conversable_id: conversableId,
  })
  return data.data
}

export async function renameConversationRequest(id: number, name: string): Promise<Conversation> {
  const { data } = await api.patch<{ data: Conversation }>(`/api/conversations/${id}`, { name })
  return data.data
}

export async function deleteConversationRequest(id: number): Promise<void> {
  await api.delete(`/api/conversations/${id}`)
}

export async function addConversationMembersRequest(id: number, userIds: number[]): Promise<Conversation> {
  const { data } = await api.post<{ data: Conversation }>(`/api/conversations/${id}/members`, {
    user_ids: userIds,
  })
  return data.data
}

export async function removeConversationMemberRequest(id: number, userId: number): Promise<void> {
  await api.delete(`/api/conversations/${id}/members/${userId}`)
}

export async function leaveConversationRequest(id: number): Promise<void> {
  await api.post(`/api/conversations/${id}/leave`)
}

export async function muteConversationRequest(id: number, isMuted: boolean): Promise<Conversation> {
  const { data } = await api.patch<{ data: Conversation }>(`/api/conversations/${id}/mute`, {
    is_muted: isMuted,
  })
  return data.data
}

/**
 * Okundu/teslim edildi bildirimleri KÜMÜLATİFTİR: her mesaj için ayrı istek atılmaz, en yüksek
 * mesaj id'si tek istekte gönderilir (bkz. `hooks/useChatSocket.ts` içindeki debounce).
 *
 * ALAN ADI `message_id` — DEĞİŞTİRME. Backend `CursorConversationRequest` bu adı bekler ve alan
 * `sometimes|nullable` olduğu için başka bir ad (ör. `last_message_id`) 422 ÜRETMEZ: istek 200
 * döner, sunucu "imleç verilmedi" sayıp konuşmanın EN SON mesajını işaretler. Yani yanlış ad
 * sessizce TÜM konuşmayı okundu yapar — hiçbir tip kontrolü ya da HTTP hatası bunu yakalamaz.
 */
export async function markConversationReadRequest(
  id: number,
  messageId: number,
): Promise<ConversationCursorAck> {
  const { data } = await api.post<{ data: ConversationCursorAck }>(`/api/conversations/${id}/read`, {
    message_id: messageId,
  })
  return normalizeCursorAck(data.data)
}

export async function markConversationDeliveredRequest(
  id: number,
  messageId: number,
): Promise<ConversationCursorAck> {
  const { data } = await api.post<{ data: ConversationCursorAck }>(`/api/conversations/${id}/delivered`, {
    message_id: messageId,
  })
  return normalizeCursorAck(data.data)
}

/** Yanıt gövdesi eksik/kısmi gelirse rozet aritmetiği bozulmasın diye savunmacı okunur. */
function normalizeCursorAck(raw: Partial<ConversationCursorAck> | undefined): ConversationCursorAck {
  return {
    last_read_message_id: raw?.last_read_message_id ?? null,
    last_delivered_message_id: raw?.last_delivered_message_id ?? null,
    unread_count: Number(raw?.unread_count) || 0,
  }
}

// ----------------------------------------------------------------------------------------------
// Mesajlar
// ----------------------------------------------------------------------------------------------

export const MESSAGES_PER_PAGE = 30

/**
 * İmleç sayfalaması: `before` verilmezse EN YENİ sayfa döner, verildiğinde o id'den ESKİ
 * mesajlar gelir. Sayfa içeriği yeniden eskiye sıralıdır (bkz. `types.ts` → `MessagesPage`).
 */
export async function fetchMessages(
  conversationId: number,
  before?: number,
  perPage: number = MESSAGES_PER_PAGE,
): Promise<MessagesPage> {
  const { data } = await api.get<MessagesPage>(`/api/conversations/${conversationId}/messages`, {
    params: { before, per_page: perPage },
  })
  return {
    data: data.data ?? [],
    meta: {
      has_more: data.meta?.has_more ?? false,
      next_before: data.meta?.next_before ?? null,
    },
  }
}

export async function sendMessageRequest(conversationId: number, payload: SendMessagePayload): Promise<Message> {
  const { data } = await api.post<{ data: Message }>(`/api/conversations/${conversationId}/messages`, {
    body: payload.body ?? undefined,
    attachment_id: payload.attachment_id ?? undefined,
    mentions: payload.mentions?.length ? payload.mentions : undefined,
  })
  return data.data
}

export async function editMessageRequest(messageId: number, body: string): Promise<Message> {
  const { data } = await api.patch<{ data: Message }>(`/api/messages/${messageId}`, { body })
  return data.data
}

export async function deleteMessageRequest(messageId: number): Promise<void> {
  await api.delete(`/api/messages/${messageId}`)
}

export async function searchMessagesRequest(q: string, conversationId?: number): Promise<Message[]> {
  const { data } = await api.get<{ data: Message[] }>('/api/messages/search', {
    params: { q, conversation_id: conversationId },
  })
  return data.data ?? []
}

// ----------------------------------------------------------------------------------------------
// Ekler
// ----------------------------------------------------------------------------------------------

/**
 * Çok parçalı (multipart) yükleme; alan adı `file`. `onProgress` axios'un
 * `onUploadProgress`'inden beslenir — sunucu `Content-Length` bilgisini vermezse (`total`
 * tanımsız) yüzde hesaplanamaz, o durumda geri çağrı ÇAĞRILMAZ ve UI belirsiz (indeterminate)
 * göstergede kalır.
 */
export async function uploadAttachmentRequest(
  file: File,
  onProgress?: (percent: number) => void,
  signal?: AbortSignal,
): Promise<Attachment> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await api.post<{ data: Attachment }>('/api/attachments', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    signal,
    onUploadProgress: (event) => {
      if (!onProgress || !event.total) return
      onProgress(Math.min(100, Math.round((event.loaded * 100) / event.total)))
    },
  })
  return data.data
}
