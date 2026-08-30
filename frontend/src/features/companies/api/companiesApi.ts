// Firmalar modülü API katmanı — backend sözleşmesi görev tanımında belirtildi.
// Hata gövdesi tüm uçlarda: `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import i18n from '../../../i18n'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import type { TimelineItem } from '../../../components/shared/Timeline'
import type { Company, CompaniesQuery, CompanyPayload, ContactSummary, CustomFieldDef, Tag, UserOption } from '../types'

type Pagination = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type CompaniesListResponse = {
  data: Company[]
  meta: { pagination: Pagination }
}

export type TimelineListResponse = {
  data: TimelineItem[]
  meta: { pagination: Pagination }
}

type ContactsListResponse = {
  data: ContactSummary[]
  meta: { pagination: Pagination }
}

export const companiesKeys = {
  all: ['companies'] as const,
  list: (query: CompaniesQuery) => ['companies', 'list', query] as const,
  detail: (id: number) => ['companies', 'detail', id] as const,
  timeline: (id: number) => ['companies', 'timeline', id] as const,
  contacts: (id: number) => ['companies', 'contacts', id] as const,
}

export const tagsKeys = {
  all: ['tags'] as const,
}

export const customFieldsKeys = {
  companies: ['custom-fields', 'companies'] as const,
}

export const userOptionsKeys = {
  all: ['user-options'] as const,
}

async function fetchCompanies(query: CompaniesQuery): Promise<CompaniesListResponse> {
  const { data } = await api.get<CompaniesListResponse>('/api/companies', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[industry]': query.industry || undefined,
      'filter[owner_id]': query.owner_id || undefined,
      'filter[city]': query.city || undefined,
      'filter[country]': query.country || undefined,
      'filter[tag_id]': query.tag_id || undefined,
      'filter[from]': query.from || undefined,
      'filter[to]': query.to || undefined,
    },
  })
  return data
}

async function fetchCompanyById(id: number): Promise<Company> {
  const { data } = await api.get<{ data: Company }>(`/api/companies/${id}`)
  return data.data
}

async function fetchCompanyTimeline(id: number, page: number): Promise<TimelineListResponse> {
  const { data } = await api.get<TimelineListResponse>(`/api/companies/${id}/timeline`, {
    params: { page },
  })
  return data
}

// Backend `is_primary` alanına göre sıralamayı desteklemiyor (izinli sort listesinde yok),
// bu yüzden `last_name` ile çekilip birincil kişi varsa istemci tarafında en üste taşınır.
async function fetchCompanyContacts(id: number): Promise<ContactSummary[]> {
  const { data } = await api.get<ContactsListResponse>('/api/contacts', {
    params: {
      'filter[company_id]': id,
      per_page: 50,
      sort: 'last_name',
    },
  })
  const items = data.data
  const primary = items.filter((c) => c.is_primary)
  const rest = items.filter((c) => !c.is_primary)
  return [...primary, ...rest]
}

async function createCompanyRequest(payload: CompanyPayload): Promise<Company> {
  const { data } = await api.post<{ data: Company }>('/api/companies', payload)
  return data.data
}

async function updateCompanyRequest(id: number, payload: Partial<CompanyPayload>): Promise<Company> {
  const { data } = await api.patch<{ data: Company }>(`/api/companies/${id}`, payload)
  return data.data
}

async function deleteCompanyRequest(id: number): Promise<void> {
  await api.delete(`/api/companies/${id}`)
}

async function fetchTags(): Promise<Tag[]> {
  const { data } = await api.get<{ data: Tag[] }>('/api/tags')
  return data.data
}

async function fetchCustomFields(): Promise<CustomFieldDef[]> {
  const { data } = await api.get<{ data: CustomFieldDef[] }>('/api/custom-fields', {
    params: { entity_type: 'companies' },
  })
  return data.data
}

async function fetchUserOptions(): Promise<UserOption[]> {
  const { data } = await api.get<{ data: UserOption[] }>('/api/users', {
    params: { per_page: 100, sort: 'name' },
  })
  return data.data
}

/** Server-side sayfalama/sıralama/arama/filtreleme destekli firma listesi. */
export function useCompanies(query: CompaniesQuery) {
  return useQuery({
    queryKey: companiesKeys.list(query),
    queryFn: () => fetchCompanies(query),
    // Filtre/sayfa değişirken tablo boşalıp titremesin diye önceki veri korunur.
    placeholderData: keepPreviousData,
  })
}

export function useCompany(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: companiesKeys.detail(id ?? -1),
    queryFn: () => fetchCompanyById(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

/**
 * Zaman çizelgesi sayfaları `useInfiniteQuery` ile yönetilir; sayfalar arası birikmiş öğe
 * listesi ve "daha fazla yükle" durumu doğrudan buradan türetilir (bkz. `CompanyDetailPage`).
 */
export function useCompanyTimeline(id: number | undefined) {
  return useInfiniteQuery({
    queryKey: companiesKeys.timeline(id ?? -1),
    queryFn: ({ pageParam }) => fetchCompanyTimeline(id as number, pageParam),
    initialPageParam: 1,
    getNextPageParam: (lastPage) =>
      lastPage.meta.pagination.current_page < lastPage.meta.pagination.last_page
        ? lastPage.meta.pagination.current_page + 1
        : undefined,
    enabled: id !== undefined,
  })
}

/** Firmaya bağlı kişiler mini tablosu — birincil kişi (varsa) istemci tarafında en üste taşınır. */
export function useCompanyContacts(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: companiesKeys.contacts(id ?? -1),
    queryFn: () => fetchCompanyContacts(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

export function useTags() {
  return useQuery({
    queryKey: tagsKeys.all,
    queryFn: fetchTags,
    staleTime: 5 * 60_000,
  })
}

export function useCustomFields() {
  return useQuery({
    queryKey: customFieldsKeys.companies,
    queryFn: fetchCustomFields,
    staleTime: 5 * 60_000,
  })
}

/** `users.view` izni olmayan kullanıcılar için 403'e düşmemek üzere `enabled: false` ile çağrılmalı. */
export function useUserOptions(options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: userOptionsKeys.all,
    queryFn: fetchUserOptions,
    enabled: options?.enabled ?? true,
    staleTime: 60_000,
  })
}

export function useCreateCompany() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: createCompanyRequest,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: companiesKeys.all })
      toast.success(i18n.t('companies:toast.created'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateCompany() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<CompanyPayload> }) => updateCompanyRequest(id, payload),
    onSuccess: (updatedCompany) => {
      void queryClient.invalidateQueries({ queryKey: companiesKeys.all })
      void queryClient.invalidateQueries({ queryKey: companiesKeys.detail(updatedCompany.id) })
      toast.success(i18n.t('companies:toast.updated'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteCompany() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => deleteCompanyRequest(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: companiesKeys.all })
      toast.success(i18n.t('companies:toast.deleted'))
    },
    onError: (error) => {
      // Açık fırsatı (deal) olan firma silinemez (422) — gerçek backend mesajı burada yüzeye çıkar.
      toast.error(getErrorMessage(error))
    },
  })
}
