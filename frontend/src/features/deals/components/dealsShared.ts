// Yardımcı (tamamlayıcı) yerel tip + API katmanı — YALNIZCA C şeridin gerçek
// `../types.ts` / `../api/dealsApi.ts` / `../api/boardApi.ts` dosyalarının KAPSAMADIĞI
// lookup'lar için: etiketler, özel alan tanımları, seçili firmaya göre filtrelenmiş kişi
// listesi ve form içindeki ARANABİLİR firma combobox'ı (C'nin `useDealCompanyOptions`'ı
// `boardApi.ts`'te sabit top-100 listesi döner, arama parametresi almaz — form için ayrı bir
// debounce'lu arama hook'u burada tutulur, farklı bir query key ile C'nin cache'iyle
// ÇAKIŞMAZ).
//
// Deal/PipelineStage/DealTag/DealStatus/DealsQuery/DealPayload gibi C'nin dosyalarında zaten
// tanımlı olan tipler ve useDeals/useDeal/useCreateDeal/useUpdateDeal/useDeleteDeal/
// useAssignDeal/usePipelineStages/useDealOwnerOptions/useDealCompanyOptions hook'ları BURADA
// TEKRARLANMAZ — bileşenler bunları doğrudan `../types`, `../api/dealsApi`, `../api/boardApi`
// dosyalarından import eder.
import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/axios'

// ---------------------------------------------------------------------------
// Tipler
// ---------------------------------------------------------------------------

export const CUSTOM_FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'boolean'] as const
export type CustomFieldType = (typeof CUSTOM_FIELD_TYPES)[number]

export type DealCustomField = {
  id: number
  entity_type: string
  name: string
  key: string
  type: CustomFieldType
  options: string[] | null
  is_required: boolean
  position: number
}

export type ContactOption = { id: number; full_name: string }

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

export const dealTagsKeys = { all: ['tags'] as const }
export const dealCustomFieldsKeys = { deals: ['custom-fields', 'deals'] as const }
export const dealContactOptionsKeys = {
  forCompany: (companyId: number | undefined, q: string) => ['deals', 'contact-options', companyId ?? null, q] as const,
}
export const dealCompanyOptionsSearchKeys = { search: (q: string) => ['deals', 'company-options', 'search', q] as const }

// ---------------------------------------------------------------------------
// Fetchers
// ---------------------------------------------------------------------------

async function fetchDealTags(): Promise<{ id: number; name: string; color: string | null }[]> {
  const { data } = await api.get<{ data: { id: number; name: string; color: string | null }[] }>('/api/tags')
  return data.data
}

async function fetchDealCustomFields(): Promise<DealCustomField[]> {
  const { data } = await api.get<{ data: DealCustomField[] }>('/api/custom-fields', {
    params: { entity_type: 'deals' },
  })
  return data.data
}

async function fetchDealContactOptions(companyId: number | undefined, search: string): Promise<ContactOption[]> {
  const { data } = await api.get<{ data: ContactOption[] }>('/api/contacts', {
    params: {
      q: search || undefined,
      per_page: 20,
      sort: 'last_name',
      'filter[company_id]': companyId || undefined,
    },
  })
  return data.data
}

async function fetchDealCompanyOptionsSearch(search: string): Promise<{ id: number; name: string }[]> {
  const { data } = await api.get<{ data: { id: number; name: string }[] }>('/api/companies', {
    params: { q: search || undefined, per_page: 20, sort: 'name' },
  })
  return data.data
}

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

export function useDealTags() {
  return useQuery({
    queryKey: dealTagsKeys.all,
    queryFn: fetchDealTags,
    staleTime: 5 * 60_000,
  })
}

export function useDealCustomFields() {
  return useQuery({
    queryKey: dealCustomFieldsKeys.deals,
    queryFn: fetchDealCustomFields,
    staleTime: 5 * 60_000,
  })
}

export function useDealContactOptions(companyId: number | undefined, search = '', options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: dealContactOptionsKeys.forCompany(companyId, search),
    queryFn: () => fetchDealContactOptions(companyId, search),
    enabled: options?.enabled ?? true,
  })
}

/** Form içindeki ARANABİLİR firma combobox'ı için — bkz. dosya başındaki not. */
export function useDealCompanyOptionsSearch(search: string, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: dealCompanyOptionsSearchKeys.search(search),
    queryFn: () => fetchDealCompanyOptionsSearch(search),
    enabled: options?.enabled ?? true,
  })
}
