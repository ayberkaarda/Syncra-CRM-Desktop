// Ürün kataloğu modülü paylaşılan tipleri — backend `ProductResource` ile birebir eşleşir.
//
// ÖNEMLİ (`sku`): benzersiz ama nullable — birden fazla ürün SKU'suz var olabilir
// (`unique:products,sku` yalnızca dolu değerler için tetiklenir).
// ÖNEMLİ (`stock_quantity`): null "stok takibi yok" demektir, 0'dan FARKLIDIR — arayüz
// null'ı "—", 0'ı ise stok tükendi uyarısıyla ayrı göstermelidir.
// ÖNEMLİ (`tax_rate`/`currency`): her zaman ÜRÜNDEN gelir; fiyat listesi yalnızca satış
// fiyatını (`unit_price`) ezer, bu iki alanı asla değiştirmez.

export type ProductTag = { id: number; name: string; color: string | null }

export type Product = {
  id: number
  name: string
  sku: string | null
  description: string | null
  category: string | null
  unit_price: number
  currency: string
  tax_rate: number
  unit: string
  stock_quantity: number | null
  is_active: boolean
  tags: ProductTag[]
  custom_fields: Record<string, string>
  created_at: string | null
  updated_at: string | null
}

/**
 * `GET /api/products/{id}/price?price_list_id=` yanıtı. `source` hangi fiyatın
 * kullanıldığını belirtir: bir fiyat listesinden mi (`price_list` alanı doludur) yoksa
 * ürünün kendi katalog fiyatından mı (`price_list: null`) geldi.
 */
export type ResolvedProductPrice = {
  product_id: number
  unit_price: number
  tax_rate: number
  currency: string
  source: 'price_list' | 'catalog'
  price_list: { id: number; name: string } | null
}
