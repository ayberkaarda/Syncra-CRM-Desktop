// Yardımcı (tamamlayıcı) tip + API katmanı — ürün formunun ihtiyaç duyduğu, `productsApi.ts`
// dışındaki lookup'lar: etiketler ve özel alan tanımları. Aynı desen `deals/components/
// dealsShared.ts` içinde de var; bu ayrı kopya `entity_type=products` kullanır.
import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/axios'
import type { ProductTag } from '../types'

export const CUSTOM_FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'boolean'] as const
export type CustomFieldType = (typeof CUSTOM_FIELD_TYPES)[number]

export type ProductCustomField = {
  id: number
  entity_type: string
  name: string
  key: string
  type: CustomFieldType
  options: string[] | null
  is_required: boolean
  position: number
}

export const productTagsKeys = { all: ['tags'] as const }
export const productCustomFieldsKeys = { products: ['custom-fields', 'products'] as const }

async function fetchProductTags(): Promise<ProductTag[]> {
  const { data } = await api.get<{ data: ProductTag[] }>('/api/tags')
  return data.data
}

async function fetchProductCustomFields(): Promise<ProductCustomField[]> {
  const { data } = await api.get<{ data: ProductCustomField[] }>('/api/custom-fields', {
    params: { entity_type: 'products' },
  })
  return data.data
}

export function useProductTags() {
  return useQuery({
    queryKey: productTagsKeys.all,
    queryFn: fetchProductTags,
    staleTime: 5 * 60_000,
  })
}

export function useProductCustomFields() {
  return useQuery({
    queryKey: productCustomFieldsKeys.products,
    queryFn: fetchProductCustomFields,
    staleTime: 5 * 60_000,
  })
}
