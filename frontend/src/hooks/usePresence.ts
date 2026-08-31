// `presence-online` kanalına katılır ve o anki üye listesini/eş zamanlı
// katılma-ayrılma olaylarını tekilleştirilmiş bir haritada tutar. Sunucu
// tarafı yetkilendirme `routes/channels.php` → `Broadcast::channel('online', ...)`
// içinde tanımlı; üye payload'ı `App\Broadcasting\ChannelRegistry::payload()`.
//
// Abonelik/temizlik deseni `features/tickets/hooks/useTicketRealtime.ts` ile AYNI TEMELE
// dayanır (presence kanalı olduğu için `kind: 'presence'`): kanal aboneliği PAYLAŞILAN,
// referans sayan `src/lib/channelRegistry.ts` üzerinden alınır — doğrudan `echo.leave()`
// ÇAĞRILMAZ (referans saymaz; bugün bu kanalın tek abonesi bu hook olsa da `Echo.leave()`
// StrictMode'un çift mount'unda kendi ikinci aboneliğini altından çekebilir — registry bunu
// sayaçla çözer). Unmount'ta yalnızca KENDİ üç dinleyicimiz (`here`/`joining`/`leaving` — Echo
// presence kanallarında `stopListening` karşılığı yoktur, `leave` tüm dinleyicileri birlikte
// söker) `releaseChannel()` ile bırakılır.
import { useEffect, useState } from 'react'
import { getEcho, onConnectionStateChange } from '../lib/echo'
import { acquireChannel, releaseChannel } from '../lib/channelRegistry'
import type { PresenceChannel } from '../lib/channelRegistry'

export type PresenceMember = {
  id: number
  name: string
  email: string
  role: string | null
  department: string | null
}

export type UsePresenceResult = {
  members: PresenceMember[]
  count: number
  isConnected: boolean
}

const CHANNEL_NAME = 'online' // -> presence-online (Echo prefixes it)

export function usePresence(): UsePresenceResult {
  const [membersById, setMembersById] = useState<Map<number, PresenceMember>>(new Map())
  const [isConnected, setIsConnected] = useState(false)

  useEffect(() => {
    if (!getEcho()) return
    const channel = acquireChannel(CHANNEL_NAME, 'presence') as PresenceChannel | null
    if (!channel) return

    // Aynı kullanıcı birden fazla sekme açtığında `here`/`joining` aynı id'yi
    // tekrar gönderebilir — Map üzerinde id anahtarıyla tekilleştiriyoruz, bu
    // yüzden ikinci sekme listeyi büyütmez.
    channel.here((initialMembers: PresenceMember[]) => {
      setMembersById(new Map(initialMembers.map((member) => [member.id, member])))
    })

    channel.joining((member: PresenceMember) => {
      setMembersById((prev) => {
        const next = new Map(prev)
        next.set(member.id, member)
        return next
      })
    })

    channel.leaving((member: PresenceMember) => {
      setMembersById((prev) => {
        if (!prev.has(member.id)) return prev
        const next = new Map(prev)
        next.delete(member.id)
        return next
      })
    })

    return () => {
      releaseChannel(CHANNEL_NAME)
      setMembersById(new Map())
    }
  }, [])

  useEffect(() => onConnectionStateChange((state) => setIsConnected(state === 'connected')), [])

  const members = Array.from(membersById.values())

  return { members, count: members.length, isConnected }
}
