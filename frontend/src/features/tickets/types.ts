// Destek Talepleri (Tickets) modülü tipleri — backend `TicketResource` ile birebir eşleşir
// (bkz. Faz 8 / B görev tanımı + `docs/SLA-DESIGN.md` §6 API sözleşmesi). SLA alan adları ve
// tipleri BAĞLAYICIDIR — dokümandaki isimlerin dışına çıkılmaz.
//
// `sla_remaining_seconds` İSTEMCİDE ARİTMETİĞE GİRDİ OLARAK KULLANILIR (bkz.
// `hooks/useSlaCountdown.ts`) ama `Date.now()` ile ASLA karşılaştırılmaz — yalnızca
// `performance.now()` (monoton saat) ile eritilir. Bkz. dosya başındaki geniş gerekçe
// `hooks/useSlaCountdown.ts` içinde.

export const TICKET_PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const
export type TicketPriority = (typeof TICKET_PRIORITIES)[number]

export const TICKET_STATUSES = ['open', 'pending', 'in_progress', 'resolved', 'closed'] as const
export type TicketStatus = (typeof TICKET_STATUSES)[number]

export type TicketContactRef = { id: number; full_name: string }
export type TicketCompanyRef = { id: number; name: string }
export type TicketUserRef = { id: number; name: string }
export type TicketTag = { id: number; name: string; color: string | null }

/**
 * Faz 13 — yatay yazma izolasyonu. Backend `ExposesAbilities` trait'iyle HER ZAMAN üretir
 * (kullanıcı yoksa tümü `false`) — opsiyonel DEĞİL. `status`, `PATCH /api/tickets/{id}/status`
 * ucunun sorduğu `update` yeteneğine eşlenir (bkz. `TicketResource`teki gerekçe) — ayrı bir
 * policy metodu YOKTUR, isim yalnızca uç ile eşleşsin diye farklıdır.
 */
export type TicketAbilities = { update: boolean; status: boolean; delete: boolean; assign: boolean }

/**
 * Ticket detayı/liste satırı (`GET /api/tickets`, `GET /api/tickets/{id}`, `TicketResource`).
 *
 * SLA alanları (docs/SLA-DESIGN.md §6):
 * - `sla_due_at`: yalnızca mutlak tarih GÖSTERİMİ için, istemci bununla aritmetik YAPMAZ.
 * - `sla_total_seconds`: ilerleme çubuğu paydası, ticket'ın kendi hedefinden türetilir.
 * - `sla_remaining_seconds`: SUNUCUDA, yanıt üretilirken hesaplanır. `resolved`/`closed`
 *   sonrası `null`. Akarken pozitif/negatif olabilir (negatif = aşılmış, kırpılmaz).
 * - `sla_paused`: `status === 'pending'` ile eşdeğer; true iken sayaç DONUKTUR.
 * - `sla_breached`: açık ticket'ta AKTİF ihlal, çözülmüşte TARİHSEL ihlal — türetilmiş, sunucu
 *   otoritedir.
 */
export type Ticket = {
  id: number
  ticket_number: string
  subject: string
  description: string
  priority: TicketPriority
  status: TicketStatus
  category: string | null

  sla_due_at: string | null
  sla_total_seconds: number
  sla_remaining_seconds: number | null
  sla_paused: boolean
  sla_breached: boolean
  sla_paused_seconds: number
  sla_target_hours: number

  first_response_at: string | null
  resolved_at: string | null
  closed_at: string | null

  notes_count: number

  contact: TicketContactRef | null
  company: TicketCompanyRef | null
  assignee: TicketUserRef | null
  creator: TicketUserRef | null
  tags: TicketTag[]
  custom_fields: Record<string, string>

  created_at: string | null
  updated_at: string | null
  can: TicketAbilities
}

export type Pagination = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type TicketsListResponse = {
  data: Ticket[]
  meta: { pagination: Pagination }
}

/** `GET /api/tickets/stats` — filtrelerden ve sayfalamadan BAĞIMSIZ genel özet. */
export type TicketStats = {
  total: number
  by_status: Record<TicketStatus, number>
  by_priority: Record<TicketPriority, number>
  breached_count: number
  at_risk_count: number
  /** Çözülmüş ticket yoksa `null` (0 değil — "veri yok" ile "ortalama 0 saat" ayrımı). */
  avg_resolution_hours: number | null
}

export type TicketsQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  status?: TicketStatus
  priority?: TicketPriority
  assigned_to?: number
  company_id?: number
  contact_id?: number
  category?: string
  tag_id?: number
  /** true ise `filter[sla_breached]=1` gönderilir; false/undefined ise filtre hiç eklenmez. */
  sla_breached?: boolean
  from?: string
  to?: string
}

/**
 * `POST /api/tickets` / `PATCH /api/tickets/{id}` gövdesi. `status` KASITLI OLARAK YOK —
 * oluşturmada sunucu her zaman `open` yazar, güncellemede `status` alanı `PATCH
 * /api/tickets/{id}` ucunda 422 `missing` hatası üretir (bkz. `docs/SLA-DESIGN.md` §4).
 * Durum yalnızca `changeTicketStatusRequest` (`/status` ucu) ile değişir.
 */
export type TicketPayload = {
  subject: string
  description: string
  priority?: TicketPriority
  category?: string | null
  contact_id?: number | null
  company_id?: number | null
  assigned_to?: number | null
  tag_ids?: number[]
  custom_fields?: Record<string, string>
}

export type CompanyOption = { id: number; name: string }
export type ContactOption = { id: number; full_name: string }
export type UserOption = { id: number; name: string }

export const CUSTOM_FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'boolean'] as const
export type CustomFieldType = (typeof CUSTOM_FIELD_TYPES)[number]

export type TicketCustomField = {
  id: number
  entity_type: string
  name: string
  key: string
  type: CustomFieldType
  options: string[] | null
  is_required: boolean
  position: number
}

/**
 * `private-tickets` kanalındaki `.ticket.sla.warning` yükü (`App\Events\TicketSlaWarning`).
 * DÜZ SKALERLER — tam `TicketResource` DEĞİL (bkz. `useDealRealtime.ts`'teki aynı gerekçe:
 * `SerializesModels` kuyruğa yalnızca id koyar, işçi olay ANINDA hesaplayıp skaler yayınlar).
 */
export type TicketSlaWarningEvent = {
  ticket_id: number
  ticket_number: string
  subject: string
  priority: TicketPriority
  status: TicketStatus
  assigned_to: number | null
  sla_due_at: string | null
  /** Sunucu hesabı — olay üretildiği andaki kalan süre (saniye). */
  remaining_seconds: number
  detected_at: string
}

/** `.ticket.sla.breached` yükü (`App\Events\TicketSlaBreached`) — şekli warning ile aynı,
 * yalnızca `remaining_seconds` yerine `overdue_seconds` (hedefin aşıldığı süre, pozitif). */
export type TicketSlaBreachedEvent = {
  ticket_id: number
  ticket_number: string
  subject: string
  priority: TicketPriority
  status: TicketStatus
  assigned_to: number | null
  sla_due_at: string | null
  overdue_seconds: number
  detected_at: string
}
