// Kişisel kanal (`private-user.{id}`) için TEK kapı.
//
// Sunucu (`backend/routes/channels.php`) bu kanalı şifre değişimi hâlâ zorunlu olan
// (`must_change_password=true`) kullanıcıya VERMEZ: kanal bildirimleri, görev hatırlatıcılarını
// ve sohbet okunmamış sayılarını taşır. Kanalı dinleyen dört kanca (`useRealtimeSession`,
// `useNotificationSocket`, `useChatUnread`, `useTaskReminders`) ve masaüstü köprüsü
// (`desktop/src/bridge/realtime.ts`) kullanıcı kimliğini BURADAN okur:
//
//   * bayrak `true` iken `undefined` döner -> abonelik hiç denenmez (reddedilecek bir
//     `/api/broadcasting/auth` isteği gitmez), açık bir abonelik varsa bırakılır;
//   * şifre değişince (`completePasswordChange` store'a bayrağı temizlenmiş kullanıcıyı yazar)
//     değer `undefined` -> id olur; efektler kimlik aynı kalsa bile yeniden çalışır ve kanal
//     sayfa yenilemeden açılır.
import { useAuthStore } from './store'
import type { AuthStatus } from './store'
import type { User } from './types'

/** Kişisel kanala abone olunabilecek kullanıcı id'si, yoksa `undefined`. */
export function personalChannelUserId(state: {
  status: AuthStatus
  user: Pick<User, 'id' | 'must_change_password'> | null
}): number | undefined {
  const { status, user } = state
  if (status !== 'authenticated' || !user) return undefined
  if (user.must_change_password) return undefined
  return user.id
}

/** {@link personalChannelUserId}'nin React seçicisi — ilkel değer döndüğü için gereksiz render yok. */
export function usePersonalChannelUserId(): number | undefined {
  return useAuthStore(personalChannelUserId)
}
