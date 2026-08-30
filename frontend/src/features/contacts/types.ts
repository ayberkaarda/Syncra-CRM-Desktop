// Kişiler modülü tipleri — backend sözleşmesiyle birebir eşleşir.

// Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3).
import type { DealRelatedItem, QuoteRelatedItem, RelatedGroupData, TicketRelatedItem } from '../related/types'

export type Tag = {
  id: number
  name: string
  color: string
}

export type CompanyOption = {
  id: number
  name: string
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

export type Contact = {
  id: number
  first_name: string
  last_name: string
  full_name: string
  email: string | null
  phone: string | null
  mobile: string | null
  position: string | null
  is_primary: boolean
  address: string | null
  city: string | null
  country: string | null
  notes: string | null
  company: { id: number; name: string } | null
  owner: { id: number; name: string } | null
  tags: Tag[]
  custom_fields: Record<string, string>
  deals_count?: number
  tickets_count?: number
  // Faz 14 / İz F — C3 ilişkili-kayıtlar paneli. `company` burada YOK: yukarıdaki `company`
  // alanı zaten bu yönü karşılıyor (bkz. `ContactController::loadRelatedRecords()`).
  related?: {
    deals?: RelatedGroupData<DealRelatedItem>
    tickets?: RelatedGroupData<TicketRelatedItem>
    quotes?: RelatedGroupData<QuoteRelatedItem>
  }
  created_at: string
  updated_at: string
}

export type ContactsQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  company_id?: number
  owner_id?: number
  is_primary?: boolean
  city?: string
  tag_id?: number
  from?: string
  to?: string
}

export type ContactPayload = {
  first_name: string
  last_name: string
  email?: string
  phone?: string
  mobile?: string
  position?: string
  company_id?: number | null
  owner_id?: number | null
  is_primary?: boolean
  address?: string
  city?: string
  country?: string
  notes?: string
  tag_ids?: number[]
  custom_fields?: Record<string, string>
}
