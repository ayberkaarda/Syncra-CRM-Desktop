// Canlı Akış sekmesi — `private-logs` kanalına abone olur, `.activity.logged` event'ini
// dinler. `src/lib/echo.ts`ye (Faz 4) dokunulmaz, yalnızca `getEcho`/`onConnectionStateChange`
// kullanılır (bkz. görev tanımı §DOKUNMA).
//
// Abonelik/temizlik deseni `features/tickets/hooks/useTicketRealtime.ts` ile AYNIDIR: kanal
// aboneliği PAYLAŞILAN, referans sayan `src/lib/channelRegistry.ts` üzerinden alınır — doğrudan
// `echo.leave()` ÇAĞRILMAZ (referans saymaz; bu kanalın bugün başka bir abonesi olmasa da
// `Echo.leave()` StrictMode'un çift mount'unda ya da hızlı unmount/remount'ta — ör. sekme
// değişince `TabPanel` bu bileşeni unmount/remount ederken — kendi ikinci aboneliğini altından
// çekebilir; registry bunu sayaçla çözer). Abonelik, bu hook'u kullanan bileşen unmount
// olduğunda yalnızca KENDİ dinleyicimiz `channel.stopListening()` ile bırakılır ve
// `releaseChannel()` çağrılır.
//
// Bellek sınırı: en fazla son 100 kayıt tutulur (`MAX_ENTRIES`). Duraklatıldığında yeni
// kayıtlar sessizce DÜŞÜRÜLÜR (arkada biriktirilmez) — bu hem "akış hızlıyken kullanıcı
// okuyamaz" ihtiyacını karşılar hem de duraklatma süresi ne kadar uzun olursa olsun bellek
// sınırlı kalır.
import { useEffect, useRef, useState } from 'react'
import { getEcho, onConnectionStateChange } from '../../../lib/echo'
import type { EchoConnectionState } from '../../../lib/echo'
import { acquireChannel, releaseChannel } from '../../../lib/channelRegistry'
import type { LiveActivityEntry } from '../types'

const CHANNEL_NAME = 'logs' // -> private-logs
const EVENT_NAME = '.activity.logged'
const MAX_ENTRIES = 100

export type StreamEntry = LiveActivityEntry & {
  /** İstemci tarafında üretilen benzersiz anahtar — aynı id iki kez gelirse (yeniden bağlanma
   * sonrası olası tekrar) React key çakışmasını önler. */
  _key: string
}

export type UseActivityStreamResult = {
  entries: StreamEntry[]
  paused: boolean
  togglePause: () => void
  clear: () => void
  connectionState: EchoConnectionState
}

let sequence = 0

export function useActivityStream(): UseActivityStreamResult {
  const [entries, setEntries] = useState<StreamEntry[]>([])
  const [paused, setPaused] = useState(false)
  const [connectionState, setConnectionState] = useState<EchoConnectionState>('unavailable')
  const pausedRef = useRef(paused)

  useEffect(() => {
    pausedRef.current = paused
  }, [paused])

  useEffect(() => {
    if (!getEcho()) return
    const channel = acquireChannel(CHANNEL_NAME)
    if (!channel) return

    const handleLogged = (payload: LiveActivityEntry) => {
      // Duraklatılmışken yeni kayıtlar bilerek düşürülür — sınırsız biriktirme yasak
      // (bkz. görev tanımı §KESİN YASAKLAR) ve zaten kullanıcı akışı okumak istiyor.
      if (pausedRef.current) return
      sequence += 1
      const entry: StreamEntry = { ...payload, _key: `${payload.id}-${sequence}` }
      setEntries((prev) => [entry, ...prev].slice(0, MAX_ENTRIES))
    }

    channel.listen(EVENT_NAME, handleLogged)

    return () => {
      channel.stopListening(EVENT_NAME, handleLogged)
      releaseChannel(CHANNEL_NAME)
    }
  }, [])

  useEffect(() => onConnectionStateChange(setConnectionState), [])

  function togglePause() {
    setPaused((prev) => !prev)
  }

  function clear() {
    setEntries([])
  }

  return { entries, paused, togglePause, clear, connectionState }
}
