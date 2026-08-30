// Kanban panosu veri katmanı: `GET /api/deals/board`, `PATCH /api/deals/{id}/move`,
// sütun/kart cache'i üzerinde çalışan SAF yardımcılar ve pano için gereken lookup'lar.
//
// Cache yardımcıları burada durur (bileşenlerde değil) çünkü pano cache'inin ŞEKLİ bu
// dosyanın sözleşmesidir: sorgu anahtarını, yanıt tipini ve o yanıtın nasıl güncelleneceğini
// tek yerde tutmak, üç ayrı yazarın (sürükle-bırak, taşıma yanıtı, realtime) aynı yapıyı
// farklı varsayımlarla bozmasını engeller.
import axios from 'axios'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/axios'
import type {
  BoardColumn,
  BoardFilters,
  BoardResponse,
  CompanyOption,
  DealCard,
  DealMovedEvent,
  MoveDealPayload,
  OwnerOption,
  PipelineStage,
} from '../types'

export const boardKeys = {
  all: ['deals', 'board'] as const,
  board: (filters: BoardFilters) => ['deals', 'board', normalizeFilters(filters)] as const,
}

export const pipelineStagesKeys = { all: ['pipeline-stages'] as const }
export const dealOwnerOptionsKeys = { all: ['deals', 'owner-options'] as const }
export const dealCompanyOptionsKeys = { all: ['deals', 'company-options'] as const }

/**
 * Sorgu anahtarına giren filtre nesnesini kanonikleştirir. TanStack Query anahtarları
 * yapısal olarak karşılaştırır; `{ q: undefined }` ile `{}` aynı anahtar olsun diye boş
 * alanlar tamamen düşürülür — aksi hâlde her filtre temizlemede yeni bir cache girdisi
 * oluşur ve pano bir an boş görünüp yeniden yüklenirdi.
 */
function normalizeFilters(filters: BoardFilters): BoardFilters {
  const normalized: BoardFilters = {}
  if (filters.q) normalized.q = filters.q
  if (filters.owner_id) normalized.owner_id = filters.owner_id
  if (filters.company_id) normalized.company_id = filters.company_id
  if (filters.from) normalized.from = filters.from
  if (filters.to) normalized.to = filters.to
  if (filters.per_stage) normalized.per_stage = filters.per_stage
  return normalized
}

async function fetchBoard(filters: BoardFilters): Promise<BoardResponse> {
  const { data } = await api.get<BoardResponse>('/api/deals/board', {
    params: {
      per_stage: filters.per_stage,
      'filter[q]': filters.q || undefined,
      'filter[owner_id]': filters.owner_id,
      'filter[company_id]': filters.company_id,
      'filter[from]': filters.from || undefined,
      'filter[to]': filters.to || undefined,
    },
  })
  return data
}

/**
 * Pano sorgusu.
 *
 * `refetchOnWindowFocus` KAPALI ve `staleTime` görece uzun: pano cache'i sürükleme
 * sırasında iyimser olarak elle yazılır (bkz. `useDealBoard`). Arka planda tetiklenen bir
 * refetch, kullanıcı kartı elinde tutarken sütunları sunucunun eski hâline geri çevirirdi.
 * Tazelik zaten `private-deals` kanalından olay bazlı geliyor.
 */
export function useBoardQuery(filters: BoardFilters) {
  return useQuery({
    queryKey: boardKeys.board(filters),
    queryFn: () => fetchBoard(filters),
    staleTime: 30_000,
    refetchOnWindowFocus: false,
  })
}

export async function moveDealRequest(dealId: number, payload: MoveDealPayload): Promise<DealCard> {
  const { data } = await api.patch<{ data: DealCard }>(`/api/deals/${dealId}/move`, payload)
  return data.data
}

/** 409 gövdesindeki güncel kartı çıkarır; başka bir hata ise `null`. */
export function conflictCardFrom(error: unknown): DealCard | null {
  if (!axios.isAxiosError(error)) return null
  if (error.response?.status !== 409) return null
  const body = error.response.data as { deal?: DealCard } | undefined
  return body?.deal ?? null
}

export function isValidationError(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 422
}

/** Sunucuya hiç ulaşamamış istek (ağ/CORS/timeout) — yanıt yok demektir. */
export function isNetworkError(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response === undefined
}

async function fetchPipelineStages(): Promise<PipelineStage[]> {
  const { data } = await api.get<{ data: PipelineStage[] }>('/api/pipeline-stages')
  return data.data
}

export function usePipelineStages() {
  return useQuery({
    queryKey: pipelineStagesKeys.all,
    queryFn: fetchPipelineStages,
    staleTime: 300_000,
  })
}

async function fetchOwnerOptions(): Promise<OwnerOption[]> {
  const { data } = await api.get<{ data: OwnerOption[] }>('/api/users', { params: { per_page: 100 } })
  return data.data
}

/**
 * Sahip filtresi seçenekleri. `/api/users` `users.view` ister — izni olmayan kullanıcı 403
 * alır; çağıran taraf `isForbidden` ile filtreyi tamamen GİZLER (bkz. görev tanımı).
 */
export function useDealOwnerOptions() {
  const query = useQuery({
    queryKey: dealOwnerOptionsKeys.all,
    queryFn: fetchOwnerOptions,
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}

async function fetchCompanyOptions(): Promise<CompanyOption[]> {
  const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', {
    params: { per_page: 100, sort: 'name' },
  })
  return data.data.map((company) => ({ id: company.id, name: company.name }))
}

export function useDealCompanyOptions() {
  const query = useQuery({
    queryKey: dealCompanyOptionsKeys.all,
    queryFn: fetchCompanyOptions,
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}

/* -------------------------------------------------------------------------- */
/*  Cache yardımcıları — saf fonksiyonlar, hepsi yeni nesne döndürür           */
/* -------------------------------------------------------------------------- */

function cloneBoard(board: BoardResponse): BoardResponse {
  return {
    ...board,
    data: board.data.map((column) => ({
      ...column,
      deals: [...column.deals],
      meta: { ...column.meta },
    })),
  }
}

export function findCardLocation(
  board: BoardResponse,
  cardId: number
): { stageId: number; index: number; card: DealCard } | null {
  for (const column of board.data) {
    const index = column.deals.findIndex((deal) => deal.id === cardId)
    if (index !== -1) {
      return { stageId: column.stage.id, index, card: column.deals[index] }
    }
  }
  return null
}

export function stageIdOfCard(board: BoardResponse, cardId: number): number | null {
  return findCardLocation(board, cardId)?.stageId ?? null
}

export function columnOf(board: BoardResponse, stageId: number): BoardColumn | undefined {
  return board.data.find((column) => column.stage.id === stageId)
}

/**
 * Kartın bırakıldığı konumun KOMŞULARI — sunucuya `position` yerine bunlar gönderilir.
 *
 * `before` üstteki (küçük `position`), `after` alttaki (büyük `position`) karttır; sunucu
 * `FractionalIndex::between(before, after)` ile anahtarı kendisi üretir. Kartın KENDİSİ
 * asla komşu olamaz — bu yüzden komşular, kart HEDEF LİSTEYE YERLEŞTİRİLDİKTEN SONRAKİ
 * dizinin `index-1` / `index+1` elemanlarıdır; aynı sütun içindeki taşımada kart eski
 * yerinden zaten çıkarılmış olduğu için kendi eski komşuluğunu bildiremez.
 *
 * Kısmen yüklenmiş sütunda (`has_more`) en alta bırakıldığında `after` null gider; sunucu
 * eksik komşuyu veritabanından tamamladığı için kart yine gerçek son kartın altına oturur.
 */
export function neighboursAt(
  board: BoardResponse,
  stageId: number,
  index: number,
  selfId: number
): { before_deal_id: number | null; after_deal_id: number | null } {
  const column = columnOf(board, stageId)
  if (!column) return { before_deal_id: null, after_deal_id: null }
  const before = column.deals[index - 1]
  const after = column.deals[index + 1]
  return {
    before_deal_id: before && before.id !== selfId ? before.id : null,
    after_deal_id: after && after.id !== selfId ? after.id : null,
  }
}

/**
 * Kartı hedef sütunda verilen DİZİNE taşır (sürükleme önizlemesi). Sunucudan gelecek
 * `position` henüz bilinmediği için dizin tabanlıdır; yanıt geldiğinde `placeCardInBoard`
 * ile `position`'a göre yeniden oturtulur.
 */
export function moveCardToIndex(
  board: BoardResponse,
  cardId: number,
  toStageId: number,
  toIndex: number
): BoardResponse {
  const location = findCardLocation(board, cardId)
  if (!location) return board

  const next = cloneBoard(board)
  const source = next.data.find((column) => column.stage.id === location.stageId)
  const target = next.data.find((column) => column.stage.id === toStageId)
  if (!source || !target) return board

  const [card] = source.deals.splice(location.index, 1)

  if (location.stageId !== toStageId) {
    source.meta.count -= 1
    source.meta.total_amount -= card.amount
    target.meta.count += 1
    target.meta.total_amount += card.amount
  }

  const moved: DealCard = { ...card, pipeline_stage_id: toStageId }
  const clamped = Math.max(0, Math.min(toIndex, target.deals.length))
  target.deals.splice(clamped, 0, moved)

  return next
}

/**
 * Kartın SUNUCUDAKİ hâlini panoya oturtur: nerede olursa olsun çıkarır, `pipeline_stage_id`
 * sütununa `position` sırasına göre ekler. Taşıma 200'ü, 409 çakışması ve realtime olayı
 * aynı fonksiyonu kullanır — iyimser sıralama ile sunucununki farklıysa SUNUCUNUNKİ kazanır.
 *
 * Sütundaki diğer kartların `position` değerleri değişmediği için, tek kartı anahtarına göre
 * araya sokmak sunucunun sırasını birebir üretir. Anahtar alfabesi `0-9a-z` olduğundan düz
 * sözlük karşılaştırması (`>`) sunucudaki `orderBy('position')` ile aynı sırayı verir.
 *
 * Hedef aşama panoda yoksa (pasifleştirilmiş aşama — sütun hiç render edilmez) `null` döner;
 * çağıran taraf bunu "panoyu invalidate et" sinyali olarak okur.
 */
export function placeCardInBoard(board: BoardResponse, card: DealCard): BoardResponse | null {
  const next = cloneBoard(board)
  const target = next.data.find((column) => column.stage.id === card.pipeline_stage_id)
  if (!target) return null

  let previous: DealCard | null = null
  let previousStageId: number | null = null

  for (const column of next.data) {
    const index = column.deals.findIndex((deal) => deal.id === card.id)
    if (index !== -1) {
      previous = column.deals[index]
      previousStageId = column.stage.id
      column.deals.splice(index, 1)
      break
    }
  }

  if (previous === null) {
    target.meta.count += 1
    target.meta.total_amount += card.amount
  } else if (previousStageId !== card.pipeline_stage_id) {
    const source = next.data.find((column) => column.stage.id === previousStageId)
    if (source) {
      source.meta.count -= 1
      source.meta.total_amount -= previous.amount
    }
    target.meta.count += 1
    target.meta.total_amount += card.amount
  } else if (previous.amount !== card.amount) {
    target.meta.total_amount += card.amount - previous.amount
  }

  const insertAt = target.deals.findIndex((deal) => deal.position > card.position)
  target.deals.splice(insertAt === -1 ? target.deals.length : insertAt, 0, card)

  return next
}

function computeOverdue(status: DealCard['status'], expectedCloseDate: string | null): boolean {
  if (status !== 'open' || !expectedCloseDate) return false
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const due = new Date(`${expectedCloseDate}T00:00:00`)
  if (Number.isNaN(due.getTime())) return false
  return due < today
}

/**
 * `.deal.moved` olayını panoya uygular.
 *
 * Yük TAM KART DEĞİLDİR (`probability`, `expected_close_date`, `company`, `contact`, `tags`
 * yoktur) — bu yüzden cache'teki kartın ÜZERİNE yalnızca gelen skalerler yazılır, geri kalan
 * alanlar korunur. Kart cache'te hiç yoksa (ör. `has_more` yüzünden o sütunun yüklenmemiş
 * kuyruğunda) birleştirilecek bir taban yok demektir: `null` döner ve çağıran panoyu
 * invalidate eder.
 *
 * `is_overdue` sunucudan gelmediği hâlde yeniden hesaplanır: kart kazanıldı/kaybedildi
 * aşamasına taşındığında `status` artık `open` olmadığı için gecikme uyarısı DÜŞMELİDİR,
 * yoksa kapanmış kart panoda hâlâ "gecikti" diye kırmızı durur.
 */
export function applyMovedEvent(board: BoardResponse, event: DealMovedEvent): BoardResponse | null {
  const location = findCardLocation(board, event.deal_id)
  if (!location) return null

  const merged: DealCard = {
    ...location.card,
    pipeline_stage_id: event.to_stage_id,
    position: event.position,
    version: event.version,
    status: event.status,
    title: event.title,
    amount: Number(event.amount),
    currency: event.currency,
    owner: event.owner_id === null ? null : { id: event.owner_id, name: event.owner_name ?? '—' },
    is_overdue: computeOverdue(event.status, location.card.expected_close_date),
  }

  return placeCardInBoard(board, merged)
}
