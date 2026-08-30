// Fiyat listeleri modülü paylaşılan tipleri — backend `PriceListResource` /
// `PriceListItemResource` ile birebir eşleşir.
//
// ÖNEMLİ (`is_default`): yalnızca BİR liste varsayılan olabilir — sunucu bir listeyi
// varsayılan yaparken diğerlerini otomatik `is_default=false` yapar (contacts'taki
// `is_primary` deseniyle aynı). Varsayılan liste SİLİNEMEZ (422).
// ÖNEMLİ (`catalog_price` / `unit_price`): `unit_price` bu listedeki satış fiyatı,
// `catalog_price` ürünün KENDİ `unit_price`'ıdır — arayüz ikisi arasındaki farkı gösterir.

export type PriceList = {
  id: number
  name: string
  code: string
  description: string | null
  currency: string
  is_default: boolean
  is_active: boolean
  valid_from: string | null
  valid_until: string | null
  items_count: number
  created_at: string | null
  updated_at: string | null
}

export type PriceListItem = {
  product_id: number
  product_name: string | null
  product_sku: string | null
  unit_price: number
  catalog_price: number | null
  created_at: string | null
}
