// Fırsatlar (Deals) modülü paylaşılan tipleri — backend kaynaklarıyla birebir eşleşir:
// `DealCardResource` (pano kartı), `DealResource` (detay), `PipelineStageResource`.
//
// ÖNEMLİ (`position`): fractional index, SAYI DEĞİL STRING'dir (alfabe `0-9a-z`,
// bkz. `backend/app/Support/FractionalIndex.php`). Sıralama düz sözlük sırasıdır —
// JS'in `<` operatörü ile karşılaştırılabilir, `Number()`'a ÇEVRİLMEMELİDİR.
//
// ÖNEMLİ (`version`): iyimser kilit sayacı. Kart taşınırken sunucuya EKRANDA GÖRÜLEN
// sürüm gönderilir; sunucudaki farklıysa istek 409 ile reddedilir.

// Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3).
import type { QuoteRelatedItem, RelatedGroupData } from '../related/types'

export type DealStatus = 'open' | 'won' | 'lost'

/** Backend'in gönderdiği semantik renk adı (`tokenBadgeVariant` ile Badge varyantına çevrilir). */
export type TokenColor = string | null

export type PipelineStage = {
  id: number
  name: string
  /** DOLUYSA `name` bizim çekirdek taksonomimizdendir ve `enums:pipelineStage.<name_key>`
   *  çevrilerek gösterilir (bkz. `utils/stageLabel.ts`); NULL'sa `name` MÜŞTERİ VERİSİDİR
   *  (admin yeniden adlandırmış ya da yeni aşama oluşturmuş) ve OLDUĞU GİBİ basılır. */
  name_key: string | null
  slug: string
  position: number
  probability: number
  color: TokenColor
  is_won: boolean
  is_lost: boolean
  /** Yalnızca `GET /api/pipeline-stages` yanıtında; pano sütunlarında gelmez. */
  is_active?: boolean
  /** Yalnızca `withCount` ile yüklendiğinde (`GET /api/pipeline-stages`). */
  deals_count?: number
}

export type DealTag = {
  id: number
  name: string
  color: TokenColor
}

export type DealRef = { id: number; name: string }
export type DealContactRef = { id: number; full_name: string }

/**
 * Faz 13 — yatay yazma izolasyonu. Bu kullanıcının BU KAYITTA neyi yapabildiği; backend
 * `ExposesAbilities` trait'iyle üretir, `request.user()` yoksa TÜMÜ `false`, aksi halde HER ZAMAN
 * mevcuttur (opsiyonel DEĞİL). Arayüz bu bileşik kararı (izin + sahiplik) KENDİ BAŞINA yeniden
 * kurmaz — bkz. `backend/app/Http/Resources/Concerns/ExposesAbilities.php`.
 */
export type DealCardAbilities = { update: boolean; move: boolean }
export type DealAbilities = { update: boolean; move: boolean; delete: boolean; assign: boolean }

/**
 * Kanban kartı. Panodan, taşıma 200 yanıtından ve 409 çakışma zarfından AYNI şekilde
 * gelir — istemci üç yolda da tek bir "kartı yerine oturt" fonksiyonu kullanabilir.
 */
export type DealCard = {
  id: number
  title: string
  amount: number
  currency: string
  pipeline_stage_id: number
  position: string
  version: number
  probability: number | null
  expected_close_date: string | null
  status: DealStatus
  company: DealRef | null
  contact: DealContactRef | null
  owner: DealRef | null
  tags: DealTag[]
  is_overdue: boolean
  can: DealCardAbilities
}

/** Fırsat detayı (`GET /api/deals/{id}`, `DealResource`). Liste/detay/form tarafı kullanır. */
export type Deal = {
  id: number
  title: string
  description: string | null
  amount: number
  currency: string
  position: string
  version: number
  probability: number | null
  expected_close_date: string | null
  status: DealStatus
  lost_reason: string | null
  won_reason: string | null
  closed_at: string | null
  pipeline_stage: PipelineStage | null
  company: DealRef | null
  contact: DealContactRef | null
  owner: DealRef | null
  tags: DealTag[]
  custom_fields: Record<string, string>
  is_overdue: boolean
  // Faz 14 / İz F — C3 ilişkili-kayıtlar paneli. `company`/`contact` burada YOK: yukarıdaki
  // alanlar zaten bu yönleri karşılıyor (bkz. `DealController::loadRelatedRecords()`).
  related?: {
    quotes?: RelatedGroupData<QuoteRelatedItem>
  }
  created_at: string | null
  updated_at: string | null
  can: DealAbilities
}

export type BoardColumnMeta = {
  /**
   * Aşamadaki TOPLAM kart sayısı — yüklenen (`deals.length`) kart sayısından bağımsızdır.
   * `has_more` true iken `deals.length < count` olur.
   */
  count: number
  /** Aşamadaki TÜM kartların tutar toplamı; yine yüklenen kart sayısından bağımsız. */
  total_amount: number
  has_more: boolean
}

export type BoardColumn = {
  stage: PipelineStage
  deals: DealCard[]
  meta: BoardColumnMeta
}

export type BoardResponse = {
  data: BoardColumn[]
  meta: {
    currency: string
    total_open_amount: number
  }
}

/** Pano filtreleri — tamamı URL query string'inde tutulur (bkz. `DealsBoardPage`). */
export type BoardFilters = {
  q?: string
  owner_id?: number
  company_id?: number
  from?: string
  to?: string
  per_stage?: number
}

/**
 * `PATCH /api/deals/{deal}/move` gövdesi.
 *
 * `position` BİLEREK YOK ve gönderilmesi sunucuda `prohibited` ile reddedilir: iki istemci
 * aynı anda aynı iki kartın arasına bırakırsa ikisi de aynı anahtarı hesaplar ve aşamada
 * çakışan iki `position` oluşur. İstemci yalnızca komşuları bildirir.
 *
 * `before_deal_id` = bırakılan konumun ÜSTÜNDEKİ kart (küçük `position`),
 * `after_deal_id`  = ALTINDAKİ kart (büyük `position`).
 * Sunucu eksik tarafı veritabanından tamamlar, "liste sonu" varsaymaz.
 */
export type MoveDealPayload = {
  to_stage_id: number
  before_deal_id: number | null
  after_deal_id: number | null
  version: number
  lost_reason?: string
  won_reason?: string
}

/** 409 zarfı: hata gövdesinin YANINDA kartın sunucudaki güncel hâli gelir. */
export type DealMoveConflictBody = {
  errors: { message: string; code: 'DEAL_VERSION_CONFLICT' }
  deal: DealCard
}

/**
 * `private-deals` kanalındaki `.deal.moved` yükü. DÜZ SKALERLER — tam kart DEĞİL:
 * `probability`, `expected_close_date`, `company`, `contact`, `tags` YOKTUR. Cache'te
 * kart varsa alanları birleştirilir; yoksa pano invalidate edilir.
 *
 * `amount` burada STRING gelir (`(string) $deal->amount`), kart kaynağında ise float —
 * birleştirirken `Number()` ile normalize edilmelidir.
 */
export type DealMovedEvent = {
  deal_id: number
  from_stage_id: number
  to_stage_id: number
  position: string
  version: number
  status: DealStatus
  title: string
  amount: string | number
  currency: string
  owner_id: number | null
  owner_name: string | null
  moved_by_id: number
  moved_by_name: string
  moved_at: string
}

export type OwnerOption = { id: number; name: string }
export type CompanyOption = { id: number; name: string }
