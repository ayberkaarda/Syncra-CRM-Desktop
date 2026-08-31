// `private-conversation.{id}` kanalının PAYLAŞILAN abonelik defteri.
//
// NEDEN GEREKLİ: `Echo.leave(name)` referans SAYMAZ — kanalı ve üzerindeki TÜM dinleyicileri
// koşulsuz kapatır. Aynı konuşmayı iki bağımsız kanca dinliyor (`useChatSocket` olayları,
// `useTyping` whisper'ları) ve biri unmount olurken `leave` çağırsaydı, diğerinin dinleyicilerini
// altından çekerdi — mesajlar akmayı bırakır, hata da vermezdi.
//
// İnce bir sarmalayıcıdır: gerçek referans sayma/`echo.leave` mantığı artık ORTAK
// `src/lib/channelRegistry.ts`'te yaşıyor (bu dosyadaki desenin genelleştirilmiş hâli —
// `useTaskReminders`/`useRealtimeSession`/`useNotificationSocket`/`useChatUnread` da aynı
// kanal isim çakışması sorununu `private-user.{id}` için yaşıyordu). Bu dosya yalnızca
// konuşmaya özgü kanal adlandırmasını (`conversation.{id}`) ve tip adını korur, böylece
// `useChatSocket.ts`/`useTyping.ts` içindeki mevcut çağrı yerleri DEĞİŞMEDEN çalışmaya devam
// eder.
import { acquireChannel, releaseChannel } from '../../../lib/channelRegistry'
import type { PrivateChannel } from '../../../lib/channelRegistry'

export type ConversationChannel = PrivateChannel

export function conversationChannelName(conversationId: number): string {
  return `conversation.${conversationId}`
}

/**
 * Kanala katılır (ya da mevcut aboneliği paylaşır) ve sayacı artırır.
 * Echo henüz bağlı değilse `null` döner — çağıran kanca bağlantı kurulunca yeniden dener.
 * Her başarılı `acquire` çağrısı BİR `release` ile eşleşmelidir.
 */
export function acquireConversationChannel(conversationId: number): ConversationChannel | null {
  return acquireChannel(conversationChannelName(conversationId)) as ConversationChannel | null
}

/**
 * Sayacı azaltır; SIFIRA indiğinde kanal gerçekten bırakılır. Dinleyicilerini bırakmak
 * çağıranın sorumluluğudur (`stopListening` / `stopListeningForWhisper`) — bu fonksiyon
 * yalnızca kanalın kendisini yönetir.
 */
export function releaseConversationChannel(conversationId: number): void {
  releaseChannel(conversationChannelName(conversationId))
}
