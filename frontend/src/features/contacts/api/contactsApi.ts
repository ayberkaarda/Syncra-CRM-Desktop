// Kişiler modülü API katmanı — backend sözleşmesi görev tanımında belirtildi.
// Hata gövdesi tüm uçlarda: `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import type { TimelineItem } from '../../../components/shared/Timeline'
import type {
  CompanyOption,
  Contact,
  ContactPayload,
  ContactsQuery,
  CustomFieldDef,
  Tag,
  UserOption,
} from '../types'

type Pagination = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type ContactsListResponse = {
  data: Contact[]
  meta: { pagination: Pagination }
}

export type TimelineListResponse = {
  data: TimelineItem[]
  meta: { pagination: Pagination }
}

export const contactsKeys = {
  all: ['contacts'] as const,
  list: (query: ContactsQuery) => ['contacts', 'list', query] as const,
  detail: (id: number) => ['contacts', 'detail', id] as const,
  timeline: (id: number) => ['contacts', 'timeline', id] as const,
}

export const tagsKeys = {
  all: ['tags'] as const,
}

export const customFieldsKeys = {
  contacts: ['custom-fields', 'contacts'] as const,
}

export const companyOptionsKeys = {
  search: (q: string) => ['company-options', q] as const,
  all: ['company-options', 'all'] as const,
}

export const userOptionsKeys = {
  all: ['user-options'] as const,
}

async function fetchContacts(query: ContactsQuery): Promise<ContactsListResponse> {
  const { data } = await api.get<ContactsListResponse>('/api/contacts', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[company_id]': query.company_id || undefined,
      'filter[owner_id]': query.owner_id || undefined,
      'filter[is_primary]': query.is_primary === undefined ? undefined : String(query.is_primary),
      'filter[city]': query.city || undefined,
      'filter[tag_id]': query.tag_id || undefined,
      'filter[from]': query.from || undefined,
      'filter[to]': query.to || undefined,
    },
  })
  return data
}

async function fetchContactById(id: number): Promise<Contact> {
  const { data } = await api.get<{ data: Contact }>(`/api/contacts/${id}`)
  return data.data
}

async function fetchContactTimeline(id: number, page: number): Promise<TimelineListResponse> {
  const { data } = await api.get<TimelineListResponse>(`/api/contacts/${id}/timeline`, {
    params: { page },
  })
  return data
}

async function createContactRequest(payload: ContactPayload): Promise<Contact> {
  const { data } = await api.post<{ data: Contact }>('/api/contacts', payload)
  return data.data
}

async function updateContactRequest(id: number, payload: Partial<ContactPayload>): Promise<Contact> {
  const { data } = await api.patch<{ data: Contact }>(`/api/contacts/${id}`, payload)
  return data.data
}

async function deleteContactRequest(id: number): Promise<void> {
  await api.delete(`/api/contacts/${id}`)
}

async function fetchTags(): Promise<Tag[]> {
  const { data } = await api.get<{ data: Tag[] }>('/api/tags')
  return data.data
}

async function fetchCustomFields(): Promise<CustomFieldDef[]> {
  const { data } = await api.get<{ data: CustomFieldDef[] }>('/api/custom-fields', {
    params: { entity_type: 'contacts' },
  })
  return data.data
}

async function fetchCompanyOptions(search: string): Promise<CompanyOption[]> {
  const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', {
    params: { q: search || undefined, per_page: 20, sort: 'name' },
  })
  return data.data
}

async function fetchAllCompanyOptions(): Promise<CompanyOption[]> {
  const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', {
    params: { per_page: 100, sort: 'name' },
  })
  return data.data
}

async function fetchUserOptions(): Promise<UserOption[]> {
  const { data } = await api.get<{ data: UserOption[] }>('/api/users', {
    params: { per_page: 100, sort: 'name' },
  })
  return data.data
}

/** Server-side sayfalama/sıralama/arama/filtreleme destekli kişi listesi. */
export function useContacts(query: ContactsQuery) {
  return useQuery({
    queryKey: contactsKeys.list(query),
    queryFn: () => fetchContacts(query),
    // Filtre/sayfa değişirken tablo boşalıp titremesin diye önceki veri korunur.
    placeholderData: keepPreviousData,
  })
}

export function useContact(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: contactsKeys.detail(id ?? -1),
    queryFn: () => fetchContactById(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

/**
 * Zaman çizelgesi sayfaları `useInfiniteQuery` ile yönetilir; sayfalar arası birikmiş öğe listesi
 * ve "daha fazla yükle" durumu doğrudan buradan türetilir (bkz. `ContactDetailPage`).
 */
export function useContactTimeline(id: number | undefined) {
  return useInfiniteQuery({
    queryKey: contactsKeys.timeline(id ?? -1),
    queryFn: ({ pageParam }) => fetchContactTimeline(id as number, pageParam),
    initialPageParam: 1,
    getNextPageParam: (lastPage) =>
      lastPage.meta.pagination.current_page < lastPage.meta.pagination.last_page
        ? lastPage.meta.pagination.current_page + 1
        : undefined,
    enabled: id !== undefined,
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
    queryKey: customFieldsKeys.contacts,
    queryFn: fetchCustomFields,
    staleTime: 5 * 60_000,
  })
}

/** Kişi formundaki firma combobox'ı için arama sorgusuna bağlı, hafif (per_page=20) firma listesi. */
export function useCompanyOptions(search: string, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: companyOptionsKeys.search(search),
    queryFn: () => fetchCompanyOptions(search),
    enabled: options?.enabled ?? true,
    placeholderData: keepPreviousData,
  })
}

/** Liste sayfasındaki firma filtresi için tüm firmalar (per_page=100). */
export function useAllCompanyOptions() {
  return useQuery({
    queryKey: companyOptionsKeys.all,
    queryFn: fetchAllCompanyOptions,
    staleTime: 60_000,
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

export function useCreateContact() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('contacts')
  return useMutation({
    mutationFn: createContactRequest,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: contactsKeys.all })
      toast.success(t('toast.created'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateContact() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('contacts')
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<ContactPayload> }) => updateContactRequest(id, payload),
    onSuccess: (updatedContact) => {
      void queryClient.invalidateQueries({ queryKey: contactsKeys.all })
      void queryClient.invalidateQueries({ queryKey: contactsKeys.detail(updatedContact.id) })
      toast.success(t('toast.updated'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteContact() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('contacts')
  return useMutation({
    mutationFn: (id: number) => deleteContactRequest(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: contactsKeys.all })
      toast.success(t('toast.deleted'))
    },
    onError: (error) => {
      // Açık deal'ı olan kişi silinemez (422) — gerçek backend mesajı burada yüzeye çıkar.
      toast.error(getErrorMessage(error))
    },
  })
}
