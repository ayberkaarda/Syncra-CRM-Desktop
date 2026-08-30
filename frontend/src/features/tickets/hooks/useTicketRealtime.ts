// `private-tickets` kanalının gerçek zamanlı senkronu — `.ticket.sla.warning` /
// `.ticket.sla.breached` olayları (docs/SLA-DESIGN.md §5.5, `tickets:scan-sla` tarayıcısı her
// 5 dakikada üretir).
//
// Abonelik/temizlik deseni `src/features/deals/hooks/useDealRealtime.ts` ile AYNIDIR (görev
// tanımının açıkça işaret ettiği dosya): `src/lib/echo.ts`'ye DOKUNULMAZ, yalnızca `getEcho()` /
// `onConnectionStateChange()` kullanılır ve bileşen unmount olduğunda `echo.leave()` çağrılır.
//
// ==============================================================================================
// CACHE GÜNCELLEME STRATEJİSİ — görev tanımı: "ilgili ticket cache'te varsa alanlarını
// güncelle, yoksa listeyi invalidate et"
// ==============================================================================================
// Olay yükü DÜZ SKALERDİR, tam `TicketResource` DEĞİL (bkz. `types.ts`). Bu yüzden ticket
// cache'te (detay VEYA herhangi bir liste sayfası) VARSA yalnızca SLA'yla ilgili alanlar
// yamanır; yoksa (henüz hiç yüklenmemiş bir ticket) liste sorguları invalidate edilir ki bir
// sonraki fetch doğru veriyi getirsin. `stats` (ihlal/risk sayıları) HER İKİ durumda da
// invalidate edilir — ucuz bir sorgu ve olay tam olarak bu sayıları etkiler.
//
// Yamalanan `sla_remaining_seconds`, olay payload'ındaki `remaining_seconds`/`overdue_seconds`
// SUNUCU DEĞERİDİR (detected_at anında hesaplanmış) — `useSlaCountdown` bu değer DEĞİŞTİĞİNDE
// otomatik olarak yeni bir `t0/r0` referans noktası kaydeder (bkz. o hook'un başındaki adım 4),
// bu yüzden burada ayrıca bir "referans sıfırlama" çağrısına gerek YOKTUR.
import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { getEcho, onConnectionStateChange } from '../../../lib/echo'
import type { EchoConnectionState } from '../../../lib/echo'
import { toast } from '../../../components/ui'
import { ticketsKeys } from '../api/ticketsApi'
import type { Ticket, TicketsListResponse, TicketSlaBreachedEvent, TicketSlaWarningEvent } from '../types'

const CHANNEL_NAME = 'tickets' // -> private-tickets (Echo öneki ekler)
const WARNING_EVENT = '.ticket.sla.warning'
const BREACHED_EVENT = '.ticket.sla.breached'

export type UseTicketRealtimeResult = {
  connectionState: EchoConnectionState
  isConnected: boolean
}

function patchTicket(ticket: Ticket, patch: Partial<Ticket>): Ticket {
  return { ...ticket, ...patch }
}

export function useTicketRealtime(): UseTicketRealtimeResult {
  const queryClient = useQueryClient()
  const { t } = useTranslation('tickets')

  const [connectionState, setConnectionState] = useState<EchoConnectionState>('unavailable')
  const [echoAvailable, setEchoAvailable] = useState<boolean>(() => getEcho() !== null)
  const hadConnectionRef = useRef(false)

  useEffect(
    () =>
      onConnectionStateChange((state) => {
        setConnectionState(state)
        setEchoAvailable(getEcho() !== null)
      }),
    []
  )

  /** Bağlantı kopukken kaçırılan olaylar tekrar oynatılmaz (Pusher protokolü) — soket geri
   * geldiğinde liste + stats sunucudan tazelenir. İlk bağlantı bunu tetiklemez. */
  useEffect(() => {
    if (connectionState !== 'connected') return
    if (hadConnectionRef.current) {
      void queryClient.invalidateQueries({ queryKey: ticketsKeys.lists })
      void queryClient.invalidateQueries({ queryKey: ticketsKeys.stats })
    }
    hadConnectionRef.current = true
  }, [connectionState, queryClient])

  useEffect(() => {
    if (!echoAvailable) return
    const echo = getEcho()
    if (!echo) return

    const channel = echo.private(CHANNEL_NAME)

    function applyPatch(ticketId: number, patch: Partial<Ticket>) {
      let foundAnywhere = false

      const detailKey = ticketsKeys.detail(ticketId)
      const detail = queryClient.getQueryData<Ticket>(detailKey)
      if (detail) {
        foundAnywhere = true
        queryClient.setQueryData(detailKey, patchTicket(detail, patch))
      }

      queryClient.setQueriesData<TicketsListResponse>({ queryKey: ticketsKeys.lists }, (previous) => {
        if (!previous) return previous
        const index = previous.data.findIndex((t) => t.id === ticketId)
        if (index === -1) return previous
        foundAnywhere = true
        const nextData = [...previous.data]
        nextData[index] = patchTicket(nextData[index], patch)
        return { ...previous, data: nextData }
      })

      if (!foundAnywhere) {
        void queryClient.invalidateQueries({ queryKey: ticketsKeys.lists })
      }
      // İhlal/risk sayıları her iki durumda da etkilenir.
      void queryClient.invalidateQueries({ queryKey: ticketsKeys.stats })
    }

    channel.listen(WARNING_EVENT, (payload: TicketSlaWarningEvent) => {
      applyPatch(payload.ticket_id, {
        sla_due_at: payload.sla_due_at,
        sla_remaining_seconds: payload.remaining_seconds,
        sla_paused: false, // tarayıcı duraklamış ticket'ları hiç taramaz (§5.5)
        status: payload.status,
        priority: payload.priority,
      })
      toast.warning(t('toast.slaWarning', { ticketNumber: payload.ticket_number, subject: payload.subject }))
    })

    channel.listen(BREACHED_EVENT, (payload: TicketSlaBreachedEvent) => {
      applyPatch(payload.ticket_id, {
        sla_due_at: payload.sla_due_at,
        sla_remaining_seconds: -payload.overdue_seconds,
        sla_paused: false,
        sla_breached: true,
        status: payload.status,
        priority: payload.priority,
      })
      toast.error(t('toast.slaBreached', { ticketNumber: payload.ticket_number, subject: payload.subject }))
    })

    return () => {
      echo.leave(CHANNEL_NAME)
    }
  }, [echoAvailable, queryClient, t])

  return { connectionState, isConnected: connectionState === 'connected' }
}
