// Fırsat (Deal) CRUD veri katmanı — liste, detay, oluşturma/güncelleme/silme ve sahip atama.
// Kanban panosunun kendi uçları AYRI dosyada (`boardApi.ts`): panonun cache şekli, iyimser
// güncellemesi ve çakışma yolu buradaki sıradan CRUD'dan bağımsızdır.
//
// Hata gövdesi tüm uçlarda `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import { boardKeys } from './boardApi'
import type { Deal, DealStatus } from '../types'

export const dealsKeys = {
  all: ['deals'] as const,
  lists: ['deals', 'list'] as const,
  list: (query: DealsQuery) => ['deals', 'list', query] as const,
  detail: (id: number) => ['deals', 'detail', id] as const,
}

export type DealsQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  stage_id?: number
  status?: DealStatus
  owner_id?: number
  company_id?: number
  contact_id?: number
  tag_id?: number
  amount_min?: number
  amount_max?: number
  from?: string
  to?: string
}

export type DealsListResponse = {
  data: Deal[]
  meta: {
    pagination: { current_page: number; per_page: number; total: number; last_page: number }
  }
}

export type DealPayload = {
  title: string
  description?: string | null
  amount?: number | null
  currency?: string | null
  pipeline_stage_id?: number | null
  probability?: number | null
  expected_close_date?: string | null
  company_id?: number | null
  contact_id?: number | null
  owner_id?: number | null
  tag_ids?: number[]
  custom_fields?: Record<string, string>
}

async function fetchDeals(query: DealsQuery): Promise<DealsListResponse> {
  const { data } = await api.get<DealsListResponse>('/api/deals', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[stage_id]': query.stage_id,
      'filter[status]': query.status || undefined,
      'filter[owner_id]': query.owner_id,
      'filter[company_id]': query.company_id,
      'filter[contact_id]': query.contact_id,
      'filter[tag_id]': query.tag_id,
      'filter[amount_min]': query.amount_min,
      'filter[amount_max]': query.amount_max,
      'filter[from]': query.from || undefined,
      'filter[to]': query.to || undefined,
    },
  })
  return data
}

async function fetchDeal(id: number): Promise<Deal> {
  const { data } = await api.get<{ data: Deal }>(`/api/deals/${id}`)
  return data.data
}

async function createDealRequest(payload: DealPayload): Promise<Deal> {
  const { data } = await api.post<{ data: Deal }>('/api/deals', payload)
  return data.data
}

async function updateDealRequest(id: number, payload: Partial<DealPayload>): Promise<Deal> {
  const { data } = await api.patch<{ data: Deal }>(`/api/deals/${id}`, payload)
  return data.data
}

async function deleteDealRequest(id: number): Promise<void> {
  await api.delete(`/api/deals/${id}`)
}

async function assignDealRequest(id: number, ownerId: number | null): Promise<Deal> {
  const { data } = await api.patch<{ data: Deal }>(`/api/deals/${id}/assign`, { owner_id: ownerId })
  return data.data
}

export function useDeals(query: DealsQuery) {
  return useQuery({
    queryKey: dealsKeys.list(query),
    queryFn: () => fetchDeals(query),
    placeholderData: keepPreviousData,
  })
}

export function useDeal(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: dealsKeys.detail(id ?? -1),
    queryFn: () => fetchDeal(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

/**
 * Yazma mutasyonları liste/detayın YANINDA pano sorgusunu da invalidate eder: yeni bir fırsat
 * bir aşamaya düşer, tutarı değişen kart sütun toplamını değiştirir. Pano ayrı bir cache
 * ağacında olduğu için (`boardKeys`) `dealsKeys.all` invalidation'ı onu KAPSAMAZ.
 */
function invalidateDealCaches(queryClient: ReturnType<typeof useQueryClient>, id?: number) {
  void queryClient.invalidateQueries({ queryKey: dealsKeys.lists })
  void queryClient.invalidateQueries({ queryKey: boardKeys.all })
  if (id !== undefined) {
    void queryClient.invalidateQueries({ queryKey: dealsKeys.detail(id) })
  }
}

export function useCreateDeal() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('deals')
  return useMutation({
    mutationFn: createDealRequest,
    onSuccess: (deal) => {
      invalidateDealCaches(queryClient, deal.id)
      toast.success(t('toast.created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateDeal() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('deals')
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<DealPayload> }) =>
      updateDealRequest(id, payload),
    onSuccess: (deal) => {
      invalidateDealCaches(queryClient, deal.id)
      toast.success(t('toast.updated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useDeleteDeal() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('deals')
  return useMutation({
    mutationFn: (id: number) => deleteDealRequest(id),
    onSuccess: () => {
      invalidateDealCaches(queryClient)
      toast.success(t('toast.deleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useAssignDeal() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('deals')
  return useMutation({
    mutationFn: ({ id, ownerId }: { id: number; ownerId: number | null }) =>
      assignDealRequest(id, ownerId),
    onSuccess: (deal) => {
      invalidateDealCaches(queryClient, deal.id)
      toast.success(t('toast.ownerAssigned'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
