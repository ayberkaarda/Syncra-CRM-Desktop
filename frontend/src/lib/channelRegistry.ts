// PAYLAŞILAN, referans sayan Echo private/presence kanal defteri.
//
// NEDEN GEREKLİ: `Echo.leave(name)` referans SAYMAZ — kanalı ve üzerindeki TÜM dinleyicileri
// koşulsuz kapatır. Aynı kanalı (ör. `private-user.{id}`) birbirinden BAĞIMSIZ birden fazla
// kanca dinleyebiliyor (`useTaskReminders`, `useRealtimeSession`, `useNotificationSocket`,
// `useChatUnread`); biri unmount olurken doğrudan `echo.leave()` çağırsaydı diğerlerinin
// dinleyicilerini altından çekerdi. Masaüstünde aynı sorun `desktop/src/bridge/realtime.ts`nin
// bağlandığı kanallar için de geçerlidir.
//
// Bu modül `features/chat/hooks/conversationChannel.ts`'te (`private-conversation.{id}` için)
// zaten kanıtlanmış desenin GENELLEŞTİRİLMİŞ hâlidir: `acquireChannel`/`releaseChannel` çiftiyle
// her kanal adı için TEK bir gerçek Echo aboneliği paylaşılır; sayaç sıfıra inince kanal
// GERÇEKTEN bırakılır (`echo.leave`). `conversationChannel.ts` artık bunun üzerine kuruludur.
//
// Sayaç MODÜL seviyesindedir: React StrictMode'un çift mount'u, kanal/kimlik değişirken oluşan
// kısa "eski efekt temizlenmeden yeni efekt kuruldu" örtüşmesi ve aynı kanalı dinleyen üçüncü
// (dördüncü, ...) bir kanca bu sayede tek bir gerçek aboneliği paylaşır.
//
// YETKİLENDİRME HATASINDA BİR KEZ YENİDEN DENEME — pusher-js 8 semantiği:
//   * `pusher.unsubscribe(name)` yetkilendirmesi SÜREN (`subscriptionPending`) bir kanalı
//     kapatmaz, yalnızca `subscriptionCancelled` işaretler; kanal nesnesi tabloda kalır.
//   * Ardından gelen `pusher.subscribe(name)` (yeni `echo.private`) TAZE yetkilendirme
//     İSTEMEZ, süren eski isteği `reinstateSubscription()` ile geri kabul eder.
//   * Eski istek 403 dönerse `pusher:subscription_error` yayılır, kanal abone OLMADAN kalır ve
//     pusher bir daha denemez — defter girdiyi tutmaya devam ettiği için kancalar kanalı açık sanar.
// Bu yüzden defter her girdi için `pusher:subscription_error`'ı dinler: girdi hâlâ tutuluyorsa
// (bir abone hâlâ istiyorsa; kişisel kanal için bu, `usePersonalChannelUserId` kapısından
// geçmiş demektir) BİR KEZ `channel.subscribe()` çağrılır. Hata yolu `subscriptionPending`'i
// düşürdüğü için bu, pusher'da TAZE bir yetkilendirme başlatır. Başarılı abonelik hakkı sıfırlar.
import { getEcho } from './echo'
import type { SyncraEcho } from './echo'

export type PrivateChannel = ReturnType<SyncraEcho['private']>
export type PresenceChannel = ReturnType<SyncraEcho['join']>
export type RegistryChannel = PrivateChannel | PresenceChannel

type ChannelKind = 'private' | 'presence'

type Entry = {
  count: number
  channel: RegistryChannel
  /** Bu girdi için yetkilendirme hatası sonrası yeniden deneme harcandı mı. */
  retried: boolean
  /** Girdinin pusher kanalına bağladığı iç dinleyicileri çözer. */
  detach: () => void
}

/** Echo kanalının defterin kullandığı kısmı (`PusherChannel.subscription` / `subscribe`). */
type PusherBacked = {
  subscription?: {
    bind(event: string, callback: CallableFunction): unknown
    unbind(event: string, callback?: CallableFunction): unknown
  }
  subscribe?: () => void
}

const registry = new Map<string, Entry>()

/**
 * Kanala katılır (ya da mevcut aboneliği paylaşır) ve sayacı artırır. Echo henüz bağlı değilse
 * `null` döner — çağıran kanca bağlantı kurulunca (`echoAvailable`/`onConnectionStateChange`
 * üzerinden) yeniden dener. Her başarılı `acquireChannel` çağrısı BİR `releaseChannel` ile
 * eşleşmelidir.
 *
 * `kind` yalnızca kanal HENÜZ kayıtlı değilken (ilk `echo.private`/`echo.join` çağrısını
 * belirlerken) kullanılır; kanal zaten kayıtlıysa mevcut abonelik (ilk açanın türüyle) paylaşılır.
 * Aynı kanal adının hem `private` hem `presence` olarak istenmesi programlama hatasıdır — kanal
 * adları çakışmayacak şekilde seçilmelidir.
 */
export function acquireChannel(channelName: string, kind: ChannelKind = 'private'): RegistryChannel | null {
  const echo = getEcho()
  if (!echo) return null

  const existing = registry.get(channelName)
  if (existing) {
    existing.count += 1
    return existing.channel
  }

  const channel = kind === 'presence' ? echo.join(channelName) : echo.private(channelName)
  const entry: Entry = { count: 1, channel, retried: false, detach: () => {} }
  watchSubscription(channelName, entry)
  registry.set(channelName, entry)
  return channel
}

/** Bkz. dosya başı: tutulan bir kanalın yetkilendirme hatasında tek seferlik taze deneme. */
function watchSubscription(channelName: string, entry: Entry): void {
  const backed = entry.channel as unknown as PusherBacked
  const subscription = backed.subscription
  if (!subscription || typeof backed.subscribe !== 'function') return

  const onError = () => {
    // Bırakılmış (ya da yerine yenisi açılmış) bir girdi için gelen geç hata kimseyi ilgilendirmez.
    if (registry.get(channelName) !== entry || entry.retried) return
    entry.retried = true
    backed.subscribe?.()
  }
  const onSucceeded = () => {
    entry.retried = false
  }

  subscription.bind('pusher:subscription_error', onError)
  subscription.bind('pusher:subscription_succeeded', onSucceeded)
  entry.detach = () => {
    subscription.unbind('pusher:subscription_error', onError)
    subscription.unbind('pusher:subscription_succeeded', onSucceeded)
  }
}

/**
 * Sayacı azaltır; SIFIRA indiğinde kanal gerçekten bırakılır (`echo.leave`). Dinleyicilerini
 * bırakmak çağıranın sorumluluğudur (`stopListening` / `stopListeningForWhisper`) — bu fonksiyon
 * yalnızca kanalın kendisini (aboneliğini) yönetir.
 */
export function releaseChannel(channelName: string): void {
  const entry = registry.get(channelName)
  if (!entry) return

  entry.count -= 1
  if (entry.count > 0) return

  registry.delete(channelName)
  entry.detach()
  getEcho()?.leave(channelName)
}
