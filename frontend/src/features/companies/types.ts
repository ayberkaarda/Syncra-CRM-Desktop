// Firmalar modülü tipleri — backend sözleşmesiyle birebir eşleşir (bkz. görev tanımı).
// Not: `contacts` modülüyle paralel bir şerit tarafından geliştiriliyor; bilinçli olarak
// buradan bağımsız, kendi tip tanımlarımız tutulur (import edilmez).

// Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3). `related.*` yalnızca
// ilgili modül izniyle YÜKLENDİYSE anahtarı taşır — bkz. backend `CompanyController::
// loadRelatedRecords()`. `RelatedGroupData` şekli `features/related/types.ts`'te tanımlı
// (TEK ortak sözleşme, kopyalanmadı).
import type { DealRelatedItem, QuoteRelatedItem, RelatedGroupData, TicketRelatedItem } from '../related/types'

export type Tag = {
  id: number
  name: string
  color: string
}

export type UserOption = {
  id: number
  name: string
}

export type CustomFieldDef = {
  id: number
  key: string
  label: string
  type: string
  options?: string[]
}

export type Company = {
  id: number
  name: string
  email: string | null
  phone: string | null
  website: string | null
  industry: string | null
  address: string | null
  city: string | null
  country: string | null
  employee_count: number | null
  annual_revenue: number | null
  notes: string | null
  owner: { id: number; name: string } | null
  tags: Tag[]
  custom_fields: Record<string, string>
  contacts_count: number
  deals_count: number
  primary_contact: { id: number; full_name: string; email: string | null } | null
  // `contacts` burada YOK: bu yön zaten `useCompanyContacts` ile ayrı bir uçtan (tam liste)
  // karşılanıyor (bkz. `CompanyController::loadRelatedRecords()` dokümanı).
  related?: {
    deals?: RelatedGroupData<DealRelatedItem>
    quotes?: RelatedGroupData<QuoteRelatedItem>
    tickets?: RelatedGroupData<TicketRelatedItem>
  }
  created_at: string
  updated_at: string
}

export type CompaniesQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  industry?: string
  owner_id?: number
  city?: string
  country?: string
  tag_id?: number
  from?: string
  to?: string
}

export type CompanyPayload = {
  name: string
  email?: string | null
  phone?: string | null
  website?: string | null
  industry?: string | null
  address?: string | null
  city?: string | null
  country?: string | null
  employee_count?: number | null
  annual_revenue?: number | null
  notes?: string | null
  owner_id?: number | null
  tag_ids?: number[]
  custom_fields?: Record<string, string>
}

// Firmaya bağlı kişiler mini tablosu için — `GET /api/contacts?filter[company_id]=`.
export type ContactSummary = {
  id: number
  first_name: string
  last_name: string
  full_name: string
  email: string | null
  phone: string | null
  position: string | null
  is_primary: boolean
}
