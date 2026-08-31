// Fiyat listesi (PriceList) CRUD veri katmanı + liste içindeki ürün fiyatlarının yönetimi
// (kalem ekleme/güncelleme/kaldırma).
//
// Hata gövdesi tüm uçlarda `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
//
// Sıralama beyaz listesi (backend, `PriceListRepository::SORTABLE_COLUMNS`): name, code,
// created_at — varsayılan `-created_at`.
//
// ÖNEMLİ: `PUT /api/price-lists/{id}/products/{productId}` upsert semantiğine sahiptir —
// ürün listede zaten varsa fiyatını GÜNCELLER, yoksa yeni kalem OLUŞTURUR; sunucu her iki
// durumda da 200 döner (201 DEĞİL, bkz. backend `PriceListController::setPrice()` notu).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import i18n from '../../../i18n'
import type { PriceList, PriceListItem } from '../types'
import { getPlatform } from '../../../platform'

export const priceListsKeys = {
  all: ['price-lists'] as const,
  lists: ['price-lists', 'list'] as const,
  list: (query: PriceListsQuery) => ['price-lists', 'list', query] as const,
  detail: (id: number) => ['price-lists', 'detail', id] as const,
  items: (id: number, page: number) => ['price-lists', 'items', id, page] as const,
  itemsAll: (id: number) => ['price-lists', 'items', id] as const,
}

export type PriceListsQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  is_active?: boolean
  is_default?: boolean
}

export type PriceListsListResponse = {
  data: PriceList[]
  meta: {
    pagination: { current_page: number; per_page: number; total: number; last_page: number }
  }
}

export type PriceListItemsResponse = {
  data: PriceListItem[]
  meta: {
    pagination: { current_page: number; per_page: number; total: number; last_page: number }
  }
}

export type PriceListPayload = {
  name: string
  code: string
  description?: string | null
  currency?: string
  is_default?: boolean
  is_active?: boolean
  valid_from?: string | null
  valid_until?: string | null
}

export async function fetchPriceLists(query: PriceListsQuery): Promise<PriceListsListResponse> {
  const { data } = await api.get<PriceListsListResponse>('/api/price-lists', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[is_active]': query.is_active,
      'filter[is_default]': query.is_default,
    },
  })
  return data
}

export async function fetchPriceList(id: number): Promise<PriceList> {
  const { data } = await api.get<{ data: PriceList }>(`/api/price-lists/${id}`)
  return data.data
}

export async function fetchPriceListItems(id: number, page: number, perPage = 25): Promise<PriceListItemsResponse> {
  const { data } = await api.get<PriceListItemsResponse>(`/api/price-lists/${id}/products`, {
    params: { page, per_page: perPage },
  })
  return data
}

export async function createPriceListRequest(payload: PriceListPayload): Promise<PriceList> {
  const { data } = await api.post<{ data: PriceList }>('/api/price-lists', payload)
  return data.data
}

export async function updatePriceListRequest(id: number, payload: Partial<PriceListPayload>): Promise<PriceList> {
  const { data } = await api.patch<{ data: PriceList }>(`/api/price-lists/${id}`, payload)
  return data.data
}

export async function deletePriceListRequest(id: number): Promise<void> {
  await api.delete(`/api/price-lists/${id}`)
}

export async function setPriceRequest(priceListId: number, productId: number, unitPrice: number): Promise<PriceListItem> {
  const { data } = await api.put<{ data: PriceListItem }>(`/api/price-lists/${priceListId}/products/${productId}`, {
    unit_price: unitPrice,
  })
  return data.data
}

export async function removePriceRequest(priceListId: number, productId: number): Promise<void> {
  await api.delete(`/api/price-lists/${priceListId}/products/${productId}`)
}

export function usePriceLists(query: PriceListsQuery) {
  return useQuery({
    queryKey: priceListsKeys.list(query),
    queryFn: () => getPlatform().data.priceLists.list(query),
    placeholderData: keepPreviousData,
  })
}

export function usePriceList(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: priceListsKeys.detail(id ?? -1),
    queryFn: () => getPlatform().data.priceLists.get(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

export function usePriceListItems(id: number | undefined, page: number, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: priceListsKeys.items(id ?? -1, page),
    queryFn: () => getPlatform().data.priceLists.items(id as number, page),
    enabled: (options?.enabled ?? true) && id !== undefined,
    placeholderData: keepPreviousData,
  })
}

function invalidatePriceListCaches(queryClient: ReturnType<typeof useQueryClient>, id?: number) {
  void queryClient.invalidateQueries({ queryKey: priceListsKeys.lists })
  if (id !== undefined) {
    void queryClient.invalidateQueries({ queryKey: priceListsKeys.detail(id) })
    void queryClient.invalidateQueries({ queryKey: priceListsKeys.itemsAll(id) })
  }
}

export function useCreatePriceList() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: PriceListPayload) => getPlatform().data.priceLists.create(payload),
    onSuccess: (priceList) => {
      invalidatePriceListCaches(queryClient, priceList.id)
      toast.success(i18n.t('priceLists:toast.created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdatePriceList() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<PriceListPayload> }) => getPlatform().data.priceLists.update(id, payload),
    onSuccess: (priceList) => {
      invalidatePriceListCaches(queryClient, priceList.id)
      toast.success(i18n.t('priceLists:toast.updated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useDeletePriceList() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => getPlatform().data.priceLists.delete(id),
    onSuccess: () => {
      invalidatePriceListCaches(queryClient)
      toast.success(i18n.t('priceLists:toast.deleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useSetPrice(priceListId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ productId, unitPrice }: { productId: number; unitPrice: number }) =>
      getPlatform().data.priceLists.setPrice(priceListId, productId, unitPrice),
    onSuccess: () => {
      invalidatePriceListCaches(queryClient, priceListId)
      toast.success(i18n.t('priceLists:toast.priceSaved'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useRemovePrice(priceListId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (productId: number) => getPlatform().data.priceLists.removePrice(priceListId, productId),
    onSuccess: () => {
      invalidatePriceListCaches(queryClient, priceListId)
      toast.success(i18n.t('priceLists:toast.priceRemoved'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
