// Müşteri Adayları (Leads) modülü tipleri — backend sözleşmesiyle birebir eşleşir
// (bkz. Faz 6 / E görev tanımı). `LeadResource`/`DuplicateCandidateResource` PHP
// tarafındaki alan adlarıyla aynı isimlendirme kullanılır.

// Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3). Ters yön
// (bir kişi/firma/fırsatın hangi lead'den geldiği) şemada YOK — bkz.
// `LeadController::loadRelatedRecords()` dokümanı.
import type { RelatedGroupData } from '../related/types'

export const LEAD_SOURCES = [
  'website',
  'referral',
  'cold_call',
  'email_campaign',
  'social_media',
  'event',
  'other',
] as const

export type LeadSource = (typeof LEAD_SOURCES)[number]

/** Formda/filtrede seçilebilen durumlar — `converted` yalnızca /convert ucuyla ulaşılır. */
export const LEAD_EDITABLE_STATUSES = ['new', 'contacted', 'qualified', 'unqualified'] as const

export const LEAD_STATUSES = [...LEAD_EDITABLE_STATUSES, 'converted'] as const

export type LeadStatus = (typeof LEAD_STATUSES)[number]

export type LeadOwner = { id: number; name: string } | null

export type LeadTag = { id: number; name: string; color: string | null }

/**
 * Faz 13 — yatay yazma izolasyonu. Backend `ExposesAbilities` trait'iyle HER ZAMAN üretir
 * (kullanıcı yoksa tümü `false`) — opsiyonel DEĞİL. Arayüz bu bileşik kararı (izin + sahiplik +
 * durum) KENDİ BAŞINA yeniden kurmaz, bkz. `backend/app/Http/Resources/LeadResource.php`.
 */
export type LeadAbilities = { update: boolean; convert: boolean; delete: boolean; assign: boolean }

export type Lead = {
  id: number
  first_name: string
  last_name: string
  full_name: string
  email: string | null
  phone: string | null
  company_name: string | null
  position: string | null
  source: LeadSource
  status: LeadStatus
  score: number
  notes: string | null
  owner: LeadOwner
  tags: LeadTag[]
  custom_fields: Record<string, string>
  converted_at: string | null
  converted_contact_id: number | null
  converted_company_id: number | null
  converted_deal_id: number | null
  related?: {
    converted_contact?: RelatedGroupData<{ id: number; full_name: string }>
    converted_company?: RelatedGroupData<{ id: number; name: string }>
    converted_deal?: RelatedGroupData<{ id: number; title: string }>
  }
  created_at: string
  updated_at: string
  can: LeadAbilities
}

export type Pagination = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type LeadsListResponse = {
  data: Lead[]
  meta: { pagination: Pagination }
}

export type LeadsQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  status?: string
  source?: string
  owner_id?: number
  tag_id?: number
  score_min?: number
  score_max?: number
  from?: string
  to?: string
}

// ---------------------------------------------------------------------------
// Etiketler / özel alanlar (paylaşılan lookup uçları)
// ---------------------------------------------------------------------------

export type Tag = { id: number; name: string; color: string | null }

export const CUSTOM_FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'boolean'] as const

export type CustomFieldType = (typeof CUSTOM_FIELD_TYPES)[number]

export type CustomField = {
  id: number
  entity_type: string
  name: string
  key: string
  type: CustomFieldType
  options: string[] | null
  is_required: boolean
  position: number
}

// ---------------------------------------------------------------------------
// Duplicate tespiti
// ---------------------------------------------------------------------------

export type DuplicateCandidateType = 'lead' | 'contact'

export type DuplicateLevel = 'strong' | 'possible'

/** `matched_on` yalnızca bu üç değeri taşıyabilir (bkz. DuplicateDetector). */
export type DuplicateMatchReason = 'email' | 'phone' | 'name'

export type DuplicateCandidate = {
  type: DuplicateCandidateType
  id: number
  name: string
  email: string | null
  phone: string | null
  company: string | null
  score: number
  level: DuplicateLevel
  matched_on: DuplicateMatchReason[]
}

export type DuplicateCheckInput = {
  email?: string
  phone?: string
  first_name?: string
  last_name?: string
  company_name?: string
  exclude_lead_id?: number
}

// ---------------------------------------------------------------------------
// Dönüştürme
// ---------------------------------------------------------------------------

export type ConvertLeadPayload = {
  create_deal: boolean
  deal_title?: string
  deal_amount?: number
  company_id?: number
  contact_id?: number
}

export type ConvertedContact = { id: number; [key: string]: unknown }
export type ConvertedCompany = { id: number; [key: string]: unknown }
export type ConvertedDeal = { id: number; [key: string]: unknown }

export type ConvertLeadResult = {
  contact: ConvertedContact | null
  company: ConvertedCompany | null
  deal: ConvertedDeal | null
  lead: Lead
}

// ---------------------------------------------------------------------------
// Sahip seçimi (/api/users)
// ---------------------------------------------------------------------------

export type OwnerOption = { id: number; name: string; email?: string }

// ---------------------------------------------------------------------------
// CSV içe aktarma
// ---------------------------------------------------------------------------

export type ImportDuplicateMode = 'skip' | 'create' | 'update'

export type ImportRowError = { row: number; message: string }

export type ImportResult = {
  total: number
  created: number
  skipped: number
  updated: number
  failed: number
  errors: ImportRowError[]
  errors_truncated?: boolean
}

export type ImportStatus = 'pending' | 'processing' | 'completed' | 'failed'

export type ImportBatchStatus = {
  batch_id: string
  status: ImportStatus
  result?: ImportResult
}
