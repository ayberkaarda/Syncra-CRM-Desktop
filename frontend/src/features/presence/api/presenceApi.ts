// Online kullanıcılar — ilk boya / Echo bağlanmadan önceki görünüm / bağlantı
// koptuğunda düşülecek yedek. Canlı güncellemeler `presence-online` websocket
// kanalından gelir (bkz. `hooks/usePresence.ts`); bu uç ikinci bir doğruluk
// kaynağı DEĞİLDİR (bkz. backend `PresenceController` yorumları).
import { api } from '../../../lib/axios'

export type OnlineUser = {
  id: number
  name: string
  email: string
  role: string | null
  department: string | null
}

export type OnlineUsersResponse = {
  data: OnlineUser[]
  meta: {
    count: number
    source: 'reverb' | 'cache'
    stale: boolean
  }
}

export async function fetchOnlineUsers(): Promise<OnlineUsersResponse> {
  const { data } = await api.get<OnlineUsersResponse>('/api/presence/online')
  return data
}
