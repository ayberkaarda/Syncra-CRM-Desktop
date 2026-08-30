// Kanban panosunun beyni: sürükle-bırak yerleştirme hesabı, iyimser güncelleme, geri alma ve
// çakışma çözümü. Bileşenler yalnızca render eder; buradaki tek "durum" TanStack Query
// cache'idir.
//
// ============================================================================
// NEDEN AYRI BİR "ÖNİZLEME" STATE'İ YOK
// ============================================================================
// Sürükleme sırasında kartın yeni yeri doğrudan sorgu cache'ine yazılır. İkinci bir önizleme
// state'i tutmak, aynı panonun iki kaynağını üretirdi: realtime olayı cache'i günceller,
// önizleme onu görmezden gelir ve bırakma anında sessizce ezerdi. Tek cache = tek doğru.
//
// ============================================================================
// GERİ ALMA: PANO ANLIK GÖRÜNTÜSÜ DEĞİL, KARTIN KENDİSİ
// ============================================================================
// Sürükleme başlarken kartın O ANKİ tam hâli (`position` + `pipeline_stage_id` dahil)
// saklanır. Geri alma, tüm panoyu eski fotoğrafına döndürmek yerine SADECE o kartı eski
// `position` anahtarına geri oturtur. Fark, iki isteğin üst üste bindiği durumda ortaya
// çıkar: kullanıcı A kartını sürükleyip hemen B kartını sürüklerse, A'nın yanıtı geldiğinde
// pano fotoğrafını geri yüklemek B'nin iyimser hareketini de silerdi. Kart bazlı geri alma
// yalnızca kendi kartına dokunur — aynı gerekçe 409 ve 422 yollarında da geçerli.
import { useCallback, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
  type DragOverEvent,
  type DragStartEvent,
  type UniqueIdentifier,
} from '@dnd-kit/core'
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import { stageLabel } from '../utils/stageLabel'
import {
  boardKeys,
  columnOf,
  conflictCardFrom,
  findCardLocation,
  isNetworkError,
  moveCardToIndex,
  moveDealRequest,
  neighboursAt,
  placeCardInBoard,
  stageIdOfCard,
  useBoardQuery,
} from '../api/boardApi'
import type { BoardFilters, BoardResponse, DealCard, MoveDealPayload } from '../types'

const COLUMN_ID_PREFIX = 'stage:'

/** Sütunun droppable kimliği. Kart kimlikleri SAYI olduğu için ikisi asla çakışmaz. */
export function columnDroppableId(stageId: number): string {
  return `${COLUMN_ID_PREFIX}${stageId}`
}

/**
 * Droppable kimliğinden aşama id'si — sütun kimliği değilse `null`. Önek tek yerde
 * tanımlıdır; bileşenler `'stage:'` metnini kendileri parçalamaz.
 */
export function parseColumnId(id: UniqueIdentifier): number | null {
  const raw = String(id)
  if (!raw.startsWith(COLUMN_ID_PREFIX)) return null
  const stageId = Number(raw.slice(COLUMN_ID_PREFIX.length))
  return Number.isFinite(stageId) ? stageId : null
}

/** Üzerine gelinen hedefin ait olduğu aşama — hedef ister sütun boşluğu ister bir kart olsun. */
function resolveOverStageId(board: BoardResponse, overId: UniqueIdentifier): number | null {
  const columnStageId = parseColumnId(overId)
  if (columnStageId !== null) return columnStageId
  return stageIdOfCard(board, Number(overId))
}

type MoveOrigin = {
  card: DealCard
  stageId: number
  index: number
  before_deal_id: number | null
  after_deal_id: number | null
}

/** Kayıp/kazanma nedeni sorulmadan istek gönderilemeyen bekleyen taşıma. */
export type PendingReasonMove = {
  kind: 'lost' | 'won'
  stageName: string
  dealTitle: string
  dealId: number
  payload: MoveDealPayload
  origin: DealCard
}

export type UseDealBoardResult = {
  board: BoardResponse | undefined
  isLoading: boolean
  isFetching: boolean
  isError: boolean
  refetch: () => void
  sensors: ReturnType<typeof useSensors>
  activeCard: DealCard | null
  isMoving: boolean
  pendingReason: PendingReasonMove | null
  onDragStart: (event: DragStartEvent) => void
  onDragOver: (event: DragOverEvent) => void
  onDragEnd: (event: DragEndEvent) => void
  onDragCancel: () => void
  submitReason: (reason: string) => void
  cancelReason: () => void
}

export function useDealBoard(filters: BoardFilters): UseDealBoardResult {
  const { t } = useTranslation('deals')
  const queryClient = useQueryClient()
  const queryKey = useMemo(() => boardKeys.board(filters), [filters])
  const query = useBoardQuery(filters)

  const [activeCard, setActiveCard] = useState<DealCard | null>(null)
  const [pendingReason, setPendingReason] = useState<PendingReasonMove | null>(null)
  const originRef = useRef<MoveOrigin | null>(null)

  // `activationConstraint.distance`: kart tıklanınca detay sayfasına gidiyor. Eşik olmadan
  // her tıklama bir sürükleme başlatır ve tıklama olayı hiç ulaşmaz.
  // `KeyboardSensor` + `sortableKeyboardCoordinates`: kart Tab ile odaklanır, Boşluk/Enter ile
  // alınır, ok tuşlarıyla sütun içinde ve sütunlar arasında taşınır (WCAG 2.1 AA — proje kuralı).
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  const readBoard = useCallback(
    () => queryClient.getQueryData<BoardResponse>(queryKey),
    [queryClient, queryKey]
  )

  const writeBoard = useCallback(
    (next: BoardResponse) => queryClient.setQueryData(queryKey, next),
    [queryClient, queryKey]
  )

  const invalidateBoard = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: boardKeys.all })
  }, [queryClient])

  /**
   * Tek bir kartı sunucudaki (ya da geri alınacak eski) hâline oturtur. `placeCardInBoard`
   * kartı bulunduğu yerden çıkarıp `position` sırasına göre yeniden ekler ve sütun
   * sayaçlarını/toplamlarını buna göre düzeltir. Kartın aşaması panoda yoksa (pasifleşmiş
   * aşama) elde doğru bir yerleştirme yok demektir — yalnızca o durumda pano invalidate edilir.
   */
  const settleCard = useCallback(
    (card: DealCard) => {
      const current = readBoard()
      if (!current) return
      const next = placeCardInBoard(current, card)
      if (next) writeBoard(next)
      else invalidateBoard()
    },
    [invalidateBoard, readBoard, writeBoard]
  )

  const moveMutation = useMutation({
    mutationFn: ({ dealId, payload }: { dealId: number; payload: MoveDealPayload }) =>
      moveDealRequest(dealId, payload),
  })
  // `mutateAsync` referansı TanStack Query v5'te kararlıdır; mutation NESNESİ ise her
  // render'da yenilenir. Bağımlılık olarak nesneyi vermek `commitMove`'u (ve dolayısıyla
  // `onDragEnd`'i) her render'da yeniden üretirdi.
  const moveAsync = moveMutation.mutateAsync

  /**
   * İsteği gönderir ve dört yolu da kapatır:
   *
   * - **200:** dönen kart (artmış `version`, sunucunun hesapladığı `position`) yerine oturur.
   *   İyimser sıra ile sunucununki farklıysa SUNUCUNUNKİ kazanır.
   * - **409:** gövdedeki `deal` kartın sunucudaki gerçek hâlidir; pano BAŞTAN YÜKLENMEZ,
   *   yalnızca o kart doğru yerine oturtulur ve kullanıcı bilgilendirilir.
   * - **422:** iş kuralı reddi (kayıp nedeni yok, pasif aşama, tutarsız komşu) — kart eski
   *   yerine geri alınır, sunucunun mesajı gösterilir.
   * - **Ağ hatası:** yanıt hiç yok; geri alınır ve bağlantı hatası bildirilir.
   */
  const commitMove = useCallback(
    async (dealId: number, payload: MoveDealPayload, origin: DealCard) => {
      try {
        const card = await moveAsync({ dealId, payload })
        settleCard(card)
      } catch (error) {
        const conflictCard = conflictCardFrom(error)
        if (conflictCard) {
          settleCard(conflictCard)
          toast.warning(t('board.toast.conflict'))
          return
        }

        settleCard(origin)

        if (isNetworkError(error)) {
          toast.error(t('board.toast.networkError'))
          return
        }

        toast.error(getErrorMessage(error))
      }
    },
    [moveAsync, settleCard, t]
  )

  const onDragStart = useCallback(
    (event: DragStartEvent) => {
      const board = readBoard()
      if (!board) return
      const cardId = Number(event.active.id)
      const location = findCardLocation(board, cardId)
      if (!location) return

      const neighbours = neighboursAt(board, location.stageId, location.index, cardId)
      originRef.current = {
        card: location.card,
        stageId: location.stageId,
        index: location.index,
        ...neighbours,
      }
      setActiveCard(location.card)
    },
    [readBoard]
  )

  /**
   * Sürükleme SÜTUN DEĞİŞTİRDİĞİNDE kartı hedef sütuna taşır. Aynı sütun içindeki sıralama
   * burada değil `onDragEnd`'de kesinleşir: sortable stratejisi zaten kartları görsel olarak
   * kaydırır, aradaki her `over` değişiminde diziyi yeniden yazmak gereksiz render üretir.
   *
   * Hedef kartın ÜSTÜNE mi ALTINA mı bırakılacağı, sürüklenen kutunun canlı (translated)
   * dikey merkezine göre belirlenir — kullanıcı ne görüyorsa dizin o olur. Aynı hesap klavye
   * sensöründe de çalışır, çünkü o da translated rect üretir.
   */
  const onDragOver = useCallback(
    (event: DragOverEvent) => {
      const { active, over } = event
      if (!over) return

      const board = readBoard()
      if (!board) return

      const cardId = Number(active.id)
      const fromStageId = stageIdOfCard(board, cardId)
      const toStageId = resolveOverStageId(board, over.id)
      if (fromStageId === null || toStageId === null || fromStageId === toStageId) return

      const target = columnOf(board, toStageId)
      if (!target) return

      let index = target.deals.length
      if (parseColumnId(over.id) === null) {
        const overIndex = target.deals.findIndex((deal) => deal.id === Number(over.id))
        if (overIndex !== -1) {
          const translated = active.rect.current.translated
          const isBelow =
            translated !== null &&
            translated.top + translated.height / 2 > over.rect.top + over.rect.height / 2
          index = overIndex + (isBelow ? 1 : 0)
        }
      }

      writeBoard(moveCardToIndex(board, cardId, toStageId, index))
    },
    [readBoard, writeBoard]
  )

  const onDragEnd = useCallback(
    (event: DragEndEvent) => {
      setActiveCard(null)
      const origin = originRef.current
      originRef.current = null
      if (!origin) return

      const board = readBoard()
      if (!board) return

      const { active, over } = event
      const cardId = Number(active.id)

      // Geçerli bir hedefin dışına bırakıldı: `onDragOver` sütun değiştirmiş olabilir, kartı
      // kendi eski anahtarına geri oturt.
      if (!over) {
        settleCard(origin.card)
        return
      }

      const currentStageId = stageIdOfCard(board, cardId)
      const overStageId = resolveOverStageId(board, over.id)
      if (currentStageId === null || overStageId === null) {
        settleCard(origin.card)
        return
      }

      // Aynı sütun içinde yeniden sıralama: hedef kartın dizini doğrudan yeni dizindir
      // (`arrayMove` semantiği) — kart önce listeden çıkarılır, sonra o dizine eklenir.
      let working = board
      if (currentStageId === overStageId && parseColumnId(over.id) === null) {
        const column = columnOf(working, currentStageId)
        const oldIndex = column?.deals.findIndex((deal) => deal.id === cardId) ?? -1
        const newIndex = column?.deals.findIndex((deal) => deal.id === Number(over.id)) ?? -1
        if (oldIndex !== -1 && newIndex !== -1 && oldIndex !== newIndex) {
          working = moveCardToIndex(working, cardId, currentStageId, newIndex)
        }
      }

      const finalLocation = findCardLocation(working, cardId)
      if (!finalLocation) {
        settleCard(origin.card)
        return
      }

      const neighbours = neighboursAt(working, finalLocation.stageId, finalLocation.index, cardId)

      // Kart aynı aşamada AYNI komşular arasına bırakıldı: görünür hiçbir şey değişmedi,
      // sunucuya gitmenin tek etkisi boş yere `version` artırmak olurdu.
      if (
        finalLocation.stageId === origin.stageId &&
        neighbours.before_deal_id === origin.before_deal_id &&
        neighbours.after_deal_id === origin.after_deal_id
      ) {
        settleCard(origin.card)
        return
      }

      writeBoard(working)

      const stage = columnOf(working, finalLocation.stageId)?.stage
      if (!stage) {
        settleCard(origin.card)
        return
      }

      const payload: MoveDealPayload = {
        to_stage_id: stage.id,
        before_deal_id: neighbours.before_deal_id,
        after_deal_id: neighbours.after_deal_id,
        // İYİMSER GÜNCELLEMEDEN ÖNCEKİ sürüm. Cache'teki karttan okunsaydı, bu arada gelen bir
        // realtime olayı sürümü tazelemiş olur ve çakışma tespiti sessizce devre dışı kalırdı.
        version: origin.card.version,
      }

      const changedStage = finalLocation.stageId !== origin.stageId
      if (changedStage && (stage.is_lost || stage.is_won)) {
        setPendingReason({
          kind: stage.is_lost ? 'lost' : 'won',
          stageName: stageLabel(t, stage),
          dealTitle: origin.card.title,
          dealId: cardId,
          payload,
          origin: origin.card,
        })
        return
      }

      void commitMove(cardId, payload, origin.card)
    },
    // `t` KASITLI bağımlılıkta: `stageLabel()` her çağrıda GÜNCEL `t` ile çalışmalı, aksi
    // halde dil değişiminde bekleyen kazanç/kayıp modalının aşama adı donardı (bu fazda
    // tekrarlanan hata sınıfı).
    [commitMove, readBoard, settleCard, writeBoard, t]
  )

  const onDragCancel = useCallback(() => {
    setActiveCard(null)
    const origin = originRef.current
    originRef.current = null
    if (origin) settleCard(origin.card)
  }, [settleCard])

  const submitReason = useCallback(
    (reason: string) => {
      const pending = pendingReason
      if (!pending) return
      setPendingReason(null)
      const trimmed = reason.trim()
      const payload: MoveDealPayload = {
        ...pending.payload,
        ...(pending.kind === 'lost'
          ? { lost_reason: trimmed }
          : trimmed
            ? { won_reason: trimmed }
            : {}),
      }
      void commitMove(pending.dealId, payload, pending.origin)
    },
    [commitMove, pendingReason]
  )

  /** Kullanıcı nedeni girmekten vazgeçti: iyimser güncelleme geri alınır, istek hiç gitmez. */
  const cancelReason = useCallback(() => {
    const pending = pendingReason
    setPendingReason(null)
    if (pending) settleCard(pending.origin)
  }, [pendingReason, settleCard])

  return {
    board: query.data,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isError: query.isError,
    refetch: () => void query.refetch(),
    sensors,
    activeCard,
    isMoving: moveMutation.isPending,
    pendingReason,
    onDragStart,
    onDragOver,
    onDragEnd,
    onDragCancel,
    submitReason,
    cancelReason,
  }
}
