// Müşteri Adayları (Leads) API katmanı — backend sözleşmesi görev tanımında belirtildi.
// Hata gövdesi tüm uçlarda: `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import axios from 'axios'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import i18n from '../../../i18n'
import { onlineOnlyMessage } from '../../../components/shared/onlineOnlyMessage'
import { getPlatform } from '../../../platform'
import type {
  ConvertLeadPayload,
  ConvertLeadResult,
  CustomField,
  DuplicateCandidate,
  DuplicateCheckInput,
  Lead,
  LeadsListResponse,
  LeadsQuery,
  OwnerOption,
  Tag,
} from '../types'

export const leadsKeys = {
  all: ['leads'] as const,
  list: (query: LeadsQuery) => ['leads', 'list', query] as const,
  detail: (id: number) => ['leads', 'detail', id] as const,
}

export const tagsKeys = { all: ['tags'] as const }
export const customFieldsKeys = {
  forEntity: (entityType: string) => ['custom-fields', entityType] as const,
}
export const ownerOptionsKeys = { all: ['leads', 'owner-options'] as const }

export async function fetchLeads(query: LeadsQuery): Promise<LeadsListResponse> {
  const { data } = await api.get<LeadsListResponse>('/api/leads', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[status]': query.status || undefined,
      'filter[source]': query.source || undefined,
      'filter[owner_id]': query.owner_id,
      'filter[tag_id]': query.tag_id,
      'filter[score_min]': query.score_min,
      'filter[score_max]': query.score_max,
      'filter[from]': query.from || undefined,
      'filter[to]': query.to || undefined,
    },
  })
  return data
}

export async function fetchLead(id: number): Promise<Lead> {
  const { data } = await api.get<{ data: Lead }>(`/api/leads/${id}`)
  return data.data
}

export type LeadPayload = {
  first_name: string
  last_name: string
  email?: string | null
  phone?: string | null
  company_name?: string | null
  position?: string | null
  source: string
  status?: string
  score?: number | null
  owner_id?: number | null
  notes?: string | null
  tag_ids?: number[]
  custom_fields?: Record<string, string>
}

export async function createLeadRequest(payload: LeadPayload): Promise<Lead> {
  const { data } = await api.post<{ data: Lead }>('/api/leads', payload)
  return data.data
}

export async function updateLeadRequest(id: number, payload: Partial<LeadPayload>): Promise<Lead> {
  const { data } = await api.patch<{ data: Lead }>(`/api/leads/${id}`, payload)
  return data.data
}

export async function deleteLeadRequest(id: number): Promise<void> {
  await api.delete(`/api/leads/${id}`)
}

export async function checkDuplicatesRequest(input: DuplicateCheckInput): Promise<DuplicateCandidate[]> {
  const { data } = await api.post<{ data: DuplicateCandidate[] }>('/api/leads/check-duplicates', input)
  return data.data
}

export async function convertLeadRequest(id: number, payload: ConvertLeadPayload): Promise<ConvertLeadResult> {
  const { data } = await api.post<{ data: ConvertLeadResult }>(`/api/leads/${id}/convert`, payload)
  return data.data
}

export async function assignLeadRequest(id: number, ownerId: number): Promise<Lead> {
  const { data } = await api.patch<{ data: Lead }>(`/api/leads/${id}/assign`, { owner_id: ownerId })
  return data.data
}

export async function fetchTags(): Promise<Tag[]> {
  const { data } = await api.get<{ data: Tag[] }>('/api/tags', { params: { per_page: 100 } })
  return data.data
}

export async function createTagRequest(payload: { name: string; color?: string }): Promise<Tag> {
  const { data } = await api.post<{ data: Tag }>('/api/tags', payload)
  return data.data
}

export async function fetchCustomFields(entityType: string): Promise<CustomField[]> {
  const { data } = await api.get<{ data: CustomField[] }>('/api/custom-fields', {
    params: { entity_type: entityType },
  })
  return data.data
}

export async function fetchOwnerOptions(): Promise<OwnerOption[]> {
  const { data } = await api.get<{ data: OwnerOption[] }>('/api/users', { params: { per_page: 100 } })
  return data.data
}

/** Server-side sayfalama/sıralama/arama/filtreleme destekli lead listesi. */
export function useLeads(query: LeadsQuery) {
  return useQuery({
    queryKey: leadsKeys.list(query),
    queryFn: () => getPlatform().data.leads.list(query),
    placeholderData: keepPreviousData,
  })
}

export function useLead(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: leadsKeys.detail(id ?? -1),
    queryFn: () => getPlatform().data.leads.get(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

export function useCreateLead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: LeadPayload) => getPlatform().data.leads.create(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: leadsKeys.all })
      toast.success(i18n.t('leads:toast.created'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useUpdateLead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<LeadPayload> }) => getPlatform().data.leads.update(id, payload),
    onSuccess: (updatedLead) => {
      void queryClient.invalidateQueries({ queryKey: leadsKeys.all })
      void queryClient.invalidateQueries({ queryKey: leadsKeys.detail(updatedLead.id) })
      toast.success(i18n.t('leads:toast.updated'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useDeleteLead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => getPlatform().data.leads.delete(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: leadsKeys.all })
      toast.success(i18n.t('leads:toast.deleted'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Duplicate kontrolü sessiz çalışır: kullanıcının yazarken sürekli hata
 * toast'u görmesini istemiyoruz (ör. tek karakterlik geçici e-posta hatası).
 * Hata durumunda `LeadFormModal`/`ConvertLeadModal` kendi state'inde boş
 * liste varsayar; toast göstermez.
 */
export function useCheckDuplicates() {
  return useMutation({
    mutationFn: (input: DuplicateCheckInput) => getPlatform().data.leads.checkDuplicates(input),
  })
}

export function useConvertLead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: ConvertLeadPayload }) => getPlatform().data.leads.convert(id, payload),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: leadsKeys.all })
      void queryClient.invalidateQueries({ queryKey: leadsKeys.detail(result.lead.id) })
      toast.success(i18n.t('leads:toast.converted'))
    },
    onError: (error) => {
      toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error))
    },
  })
}

export function useAssignLead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ownerId }: { id: number; ownerId: number }) => getPlatform().data.leads.assign(id, ownerId),
    onSuccess: (updatedLead) => {
      void queryClient.invalidateQueries({ queryKey: leadsKeys.all })
      void queryClient.invalidateQueries({ queryKey: leadsKeys.detail(updatedLead.id) })
      toast.success(i18n.t('leads:toast.assigned'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useTags() {
  return useQuery({
    queryKey: tagsKeys.all,
    queryFn: () => getPlatform().data.leads.tags(),
    staleTime: 60_000,
  })
}

export function useCreateTag() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: { name: string; color?: string }) => getPlatform().data.leads.createTag(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tagsKeys.all })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export function useCustomFields(entityType: string) {
  return useQuery({
    queryKey: customFieldsKeys.forEntity(entityType),
    queryFn: () => getPlatform().data.leads.customFields(entityType),
    staleTime: 60_000,
  })
}

/**
 * Sahip filtresi/seçici için kullanıcı listesi. `/api/users` `users.view`
 * izni ister — izni olmayan kullanıcılar için sessizce boş listeye düşer,
 * çağıran taraf (`isForbidden`) filtreyi/seçiciyi gizlemek için kullanır.
 */
export function useOwnerOptions() {
  const query = useQuery({
    queryKey: ownerOptionsKeys.all,
    queryFn: () => getPlatform().data.leads.ownerOptions(),
    staleTime: 60_000,
    retry: false,
  })

  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403

  return { ...query, isForbidden }
}
