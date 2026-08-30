// Ürün (Product) CRUD veri katmanı — liste, detay, oluşturma/güncelleme/silme, kategori
// listesi ve fiyat çözümleme.
//
// Hata gövdesi tüm uçlarda `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
//
// Sıralama beyaz listesi (backend, `ProductRepository::SORTABLE_COLUMNS`): name, sku,
// category, unit_price, stock_quantity, created_at — varsayılan `name` ARTAN. Beyaz liste
// dışı bir değer backend'de SESSİZCE varsayılana düşer, istemci tarafında ek doğrulama
// gerekmez.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import type { Product, ResolvedProductPrice } from '../types'

export const productsKeys = {
  all: ['products'] as const,
  lists: ['products', 'list'] as const,
  list: (query: ProductsQuery) => ['products', 'list', query] as const,
  detail: (id: number) => ['products', 'detail', id] as const,
  categories: ['products', 'categories'] as const,
  price: (id: number, priceListId: number | undefined) => ['products', 'price', id, priceListId ?? null] as const,
}

export type ProductsQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  category?: string
  is_active?: boolean
  tag_id?: number
  price_min?: number
  price_max?: number
  in_stock?: boolean
}

export type ProductsListResponse = {
  data: Product[]
  meta: {
    pagination: { current_page: number; per_page: number; total: number; last_page: number }
  }
}

export type ProductPayload = {
  name: string
  sku?: string | null
  description?: string | null
  category?: string | null
  unit_price: number
  currency?: string
  tax_rate?: number
  unit?: string
  stock_quantity?: number | null
  is_active?: boolean
  tag_ids?: number[]
  custom_fields?: Record<string, string>
}

async function fetchProducts(query: ProductsQuery): Promise<ProductsListResponse> {
  const { data } = await api.get<ProductsListResponse>('/api/products', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[category]': query.category || undefined,
      'filter[is_active]': query.is_active,
      'filter[tag_id]': query.tag_id,
      'filter[price_min]': query.price_min,
      'filter[price_max]': query.price_max,
      'filter[in_stock]': query.in_stock,
    },
  })
  return data
}

async function fetchProduct(id: number): Promise<Product> {
  const { data } = await api.get<{ data: Product }>(`/api/products/${id}`)
  return data.data
}

async function fetchProductCategories(): Promise<string[]> {
  const { data } = await api.get<{ data: string[] }>('/api/products/categories')
  return data.data
}

async function fetchProductPrice(productId: number, priceListId: number | undefined): Promise<ResolvedProductPrice> {
  const { data } = await api.get<{ data: ResolvedProductPrice }>(`/api/products/${productId}/price`, {
    params: { price_list_id: priceListId },
  })
  return data.data
}

async function createProductRequest(payload: ProductPayload): Promise<Product> {
  const { data } = await api.post<{ data: Product }>('/api/products', payload)
  return data.data
}

async function updateProductRequest(id: number, payload: Partial<ProductPayload>): Promise<Product> {
  const { data } = await api.patch<{ data: Product }>(`/api/products/${id}`, payload)
  return data.data
}

async function deleteProductRequest(id: number): Promise<void> {
  await api.delete(`/api/products/${id}`)
}

export function useProducts(query: ProductsQuery) {
  return useQuery({
    queryKey: productsKeys.list(query),
    queryFn: () => fetchProducts(query),
    placeholderData: keepPreviousData,
  })
}

export function useProduct(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: productsKeys.detail(id ?? -1),
    queryFn: () => fetchProduct(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

export function useProductCategories() {
  return useQuery({
    queryKey: productsKeys.categories,
    queryFn: fetchProductCategories,
    staleTime: 5 * 60_000,
  })
}

/** Ürünün belirli bir fiyat listesindeki (veya listesizse katalog) çözümlenmiş fiyatı. */
export function useProductPrice(
  productId: number | undefined,
  priceListId: number | undefined,
  options?: { enabled?: boolean }
) {
  return useQuery({
    queryKey: productsKeys.price(productId ?? -1, priceListId),
    queryFn: () => fetchProductPrice(productId as number, priceListId),
    enabled: (options?.enabled ?? true) && productId !== undefined,
  })
}

function invalidateProductCaches(queryClient: ReturnType<typeof useQueryClient>, id?: number) {
  void queryClient.invalidateQueries({ queryKey: productsKeys.lists })
  void queryClient.invalidateQueries({ queryKey: productsKeys.categories })
  if (id !== undefined) {
    void queryClient.invalidateQueries({ queryKey: productsKeys.detail(id) })
  }
}

export function useCreateProduct() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('products')
  return useMutation({
    mutationFn: createProductRequest,
    onSuccess: (product) => {
      invalidateProductCaches(queryClient, product.id)
      toast.success(t('toast.created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateProduct() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('products')
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<ProductPayload> }) => updateProductRequest(id, payload),
    onSuccess: (product) => {
      invalidateProductCaches(queryClient, product.id)
      toast.success(t('toast.updated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useDeleteProduct() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('products')
  return useMutation({
    mutationFn: (id: number) => deleteProductRequest(id),
    onSuccess: () => {
      invalidateProductCaches(queryClient)
      toast.success(t('toast.deleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
