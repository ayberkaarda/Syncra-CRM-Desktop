// Panonun gerçek zamanlı senkronu — `private-deals` kanalı, `.deal.moved` olayı.
//
// Abonelik/temizlik deseni `src/features/logs/hooks/useActivityStream.ts` ile aynıdır:
// `src/lib/echo.ts`ye DOKUNULMAZ, yalnızca `getEcho()` / `onConnectionStateChange()`
// kullanılır ve bileşen unmount olduğunda `echo.leave()` çağrılır.
//
// ============================================================================
// YÜK TAM KART DEĞİL
// ============================================================================
// Sunucu düz skalerler yayınlar (`deal_id`, `to_stage_id`, `position`, `version`, `status`,
// `title`, `amount`, sahip). `probability`, `expected_close_date`, `company`, `contact` ve
// `tags` YOKTUR. Bu yüzden olay, cache'teki kartın ÜZERİNE yazılır; kart cache'te yoksa
// (ör. `has_more` nedeniyle o sütunun yüklenmemiş kuyruğunda) birleştirilecek taban yok
// demektir ve pano invalidate edilir. Eksik alanları `null` ile doldurmak, kullanıcının
// gözünün önünde etiketleri ve firmayı kartlardan silerdi.
//
// ============================================================================
// KENDİ HAREKETİNİN YANKISI
// ============================================================================
// Sunucu `toOthers()` ile yayınladığı için hareketi yapan istemciye olay GELMEZ. Yine de
// `moved_by_id` kontrolü var: aynı kullanıcının ikinci bir sekmesi ya da ileride
// `toOthers()`in düşmesi hâlinde, taşımayı yapan sekmenin iyimser güncellemesi bir de olayla
// tekrar uygulanır ve kart yanıp sönerdi.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { getEcho, onConnectionStateChange } from '../../../lib/echo'
import type { EchoConnectionState } from '../../../lib/echo'
import { useAuthStore } from '../../auth/store'
import { applyMovedEvent, boardKeys } from '../api/boardApi'
import type { BoardFilters, BoardResponse, DealMovedEvent } from '../types'

const CHANNEL_NAME = 'deals' // -> private-deals (Echo öneki ekler)
const EVENT_NAME = '.deal.moved'
const HIGHLIGHT_MS = 2000

export type UseDealRealtimeResult = {
  connectionState: EchoConnectionState
  isConnected: boolean
  /** `dealId -> kartı taşıyan kişinin adı`. Vurgu süresi dolunca girdi düşer. */
  recentlyMoved: Record<number, string>
}

export function useDealRealtime(filters: BoardFilters): UseDealRealtimeResult {
  const queryClient = useQueryClient()
  const queryKey = useMemo(() => boardKeys.board(filters), [filters])
  const currentUserId = useAuthStore((state) => state.user?.id ?? null)

  const [connectionState, setConnectionState] = useState<EchoConnectionState>('unavailable')
  const [echoAvailable, setEchoAvailable] = useState<boolean>(() => getEcho() !== null)
  const [recentlyMoved, setRecentlyMoved] = useState<Record<number, string>>({})

  // Olay dinleyicisi bir kez kurulur; filtre değişince (yeni sorgu anahtarı) aboneliği
  // yeniden kurmak yerine güncel anahtarı ref'ten okur. Aksi hâlde her arama tuşuna
  // basışta kanal terk edilip yeniden bağlanılırdı.
  //
  // Ref'ler render sırasında DEĞİL, efektte tazelenir (React'in kuralı: render saf kalmalı).
  // Bu iki efekt bilerek abonelik efektinden ÖNCE tanımlıdır — efektler tanım sırasına göre
  // çalıştığı için dinleyici kurulduğunda ref'ler zaten güncel olur.
  const queryKeyRef = useRef(queryKey)
  const currentUserIdRef = useRef(currentUserId)

  useEffect(() => {
    queryKeyRef.current = queryKey
  }, [queryKey])

  useEffect(() => {
    currentUserIdRef.current = currentUserId
  }, [currentUserId])

  const timersRef = useRef(new Map<number, ReturnType<typeof setTimeout>>())
  const hadConnectionRef = useRef(false)

  const highlight = useCallback((dealId: number, movedByName: string) => {
    setRecentlyMoved((previous) => ({ ...previous, [dealId]: movedByName }))
    const timers = timersRef.current
    const existing = timers.get(dealId)
    if (existing) clearTimeout(existing)
    timers.set(
      dealId,
      setTimeout(() => {
        timers.delete(dealId)
        setRecentlyMoved((previous) => {
          if (!(dealId in previous)) return previous
          const next = { ...previous }
          delete next[dealId]
          return next
        })
      }, HIGHLIGHT_MS)
    )
  }, [])

  useEffect(() => {
    const timers = timersRef.current
    return () => {
      timers.forEach((timer) => clearTimeout(timer))
      timers.clear()
    }
  }, [])

  // Bağlantı durumu. `getEcho()` mount anında henüz `null` olabilir (Echo örneği
  // `useAuth` kimlik doğrulandığında kuruluyor) — durum değiştiğinde tekrar bakılır ve
  // örnek oluştuğu anda abonelik efekti yeniden çalışır.
  useEffect(
    () =>
      onConnectionStateChange((state) => {
        setConnectionState(state)
        setEchoAvailable(getEcho() !== null)
      }),
    []
  )

  /**
   * Yeniden bağlanma. Bağlantı kopukken sunucuda yapılan taşımalar bu istemciye HİÇ ulaşmaz
   * — Pusher protokolünde kaçırılan olaylar tekrar oynatılmaz. Bu yüzden soket geri
   * geldiğinde tek doğru davranış panoyu sunucudan tazelemektir.
   *
   * İlk bağlantı bunu tetiklemez: pano zaten o sırada yükleniyor.
   */
  useEffect(() => {
    if (connectionState !== 'connected') return
    if (hadConnectionRef.current) {
      void queryClient.invalidateQueries({ queryKey: boardKeys.all })
    }
    hadConnectionRef.current = true
  }, [connectionState, queryClient])

  useEffect(() => {
    if (!echoAvailable) return
    const echo = getEcho()
    if (!echo) return

    const channel = echo.private(CHANNEL_NAME)

    channel.listen(EVENT_NAME, (payload: DealMovedEvent) => {
      const userId = currentUserIdRef.current
      if (userId !== null && payload.moved_by_id === userId) return

      const key = queryKeyRef.current
      const board = queryClient.getQueryData<BoardResponse>(key)
      if (!board) return

      const next = applyMovedEvent(board, payload)
      if (next) {
        queryClient.setQueryData(key, next)
      } else {
        void queryClient.invalidateQueries({ queryKey: boardKeys.all })
      }

      highlight(payload.deal_id, payload.moved_by_name)
    })

    return () => {
      echo.leave(CHANNEL_NAME)
    }
  }, [echoAvailable, highlight, queryClient])

  return {
    connectionState,
    isConnected: connectionState === 'connected',
    recentlyMoved,
  }
}
