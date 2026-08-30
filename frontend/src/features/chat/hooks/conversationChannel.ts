// `private-conversation.{id}` kanalının PAYLAŞILAN abonelik defteri.
//
// NEDEN GEREKLİ: `Echo.leave(name)` referans SAYMAZ — kanalı ve üzerindeki TÜM dinleyicileri
// koşulsuz kapatır. Aynı konuşmayı iki bağımsız kanca dinliyor (`useChatSocket` olayları,
// `useTyping` whisper'ları) ve biri unmount olurken `leave` çağırsaydı, diğerinin dinleyicilerini
// altından çekerdi — mesajlar akmayı bırakır, hata da vermezdi. Bu yüzden abonelik/bırakma
// tek elden, referans sayacıyla yürütülür (aynı gerekçe: `features/presence/hooks/useOnlineUsers.ts`
// ve `features/notifications/hooks/useNotificationSocket.ts`).
//
// Sayaç MODÜL seviyesindedir: React StrictMode'un çift mount'u, konuşma değiştirirken oluşan
// kısa "eski efekt temizlenmeden yeni efekt kuruldu" örtüşmesi ve ileride eklenecek üçüncü bir
// dinleyici bu sayede tek bir gerçek aboneliği paylaşır.
import { getEcho } from '../../../lib/echo'
import type { SyncraEcho } from '../../../lib/echo'

export type ConversationChannel = ReturnType<SyncraEcho['private']>

type Entry = { count: number; channel: ConversationChannel }

const registry = new Map<string, Entry>()

export function conversationChannelName(conversationId: number): string {
  return `conversation.${conversationId}`
}

/**
 * Kanala katılır (ya da mevcut aboneliği paylaşır) ve sayacı artırır.
 * Echo henüz bağlı değilse `null` döner — çağıran kanca bağlantı kurulunca yeniden dener.
 * Her başarılı `acquire` çağrısı BİR `release` ile eşleşmelidir.
 */
export function acquireConversationChannel(conversationId: number): ConversationChannel | null {
  const echo = getEcho()
  if (!echo) return null

  const name = conversationChannelName(conversationId)
  const existing = registry.get(name)
  if (existing) {
    existing.count += 1
    return existing.channel
  }

  const channel = echo.private(name)
  registry.set(name, { count: 1, channel })
  return channel
}

/**
 * Sayacı azaltır; SIFIRA indiğinde kanal gerçekten bırakılır. Dinleyicilerini bırakmak
 * çağıranın sorumluluğudur (`stopListening` / `stopListeningForWhisper`) — bu fonksiyon
 * yalnızca kanalın kendisini yönetir.
 */
export function releaseConversationChannel(conversationId: number): void {
  const name = conversationChannelName(conversationId)
  const entry = registry.get(name)
  if (!entry) return

  entry.count -= 1
  if (entry.count > 0) return

  registry.delete(name)
  getEcho()?.leave(name)
}
