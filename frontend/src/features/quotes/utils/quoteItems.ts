// Kalem editörünün saf yardımcı fonksiyonları/tipleri — `components/QuoteItemsEditor.tsx`dan
// AYRI bir dosyada: o dosya hem `QuoteItemsEditor` bileşenini hem bu yardımcıları export ederse
// `react-refresh/only-export-components` uyarısı üretir (bileşen + bileşen-olmayan export
// karışımı fast-refresh'i bozar). Saf mantık burada, render burada YOK.
import type { Product } from '../../products/types'
import type { QuoteItem, QuoteItemInput } from '../types'

export type EditableQuoteItem = {
  /** İstemci-yerel kararlı anahtar (React listesi için) — backend'e gönderilmez. */
  key: string
  /** Mevcut kayıtlı kalem düzenleniyorsa dolu; yeni satırda yok. */
  id?: number
  product_id: number | null
  name: string
  description: string
  quantity: string
  unit_price: string
  discount_percent: string
  tax_rate: string
}

export function makeItemKey(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID()
  return `item-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

/** Serbest (ürünsüz) yeni satır — KDV varsayılanı %20 (docs/QUOTE-FINANCIALS.md). */
export function createFreeItem(): EditableQuoteItem {
  return {
    key: makeItemKey(),
    product_id: null,
    name: '',
    description: '',
    quantity: '1',
    unit_price: '0',
    discount_percent: '0',
    tax_rate: '20',
  }
}

/**
 * Ürün seçilince: `unit_price`/`tax_rate` KATALOG değerleriyle başlar; asıl fiyat listesine
 * göre çözümlenmiş değer `QuoteItemsEditor`'ın `handleAddProduct`'ında (`resolveProductPrice`
 * ile) ÜZERİNE YAZILIR — görev tanımı: "GET /api/products/{id}/price?price_list_id= ile fiyat
 * çözülüp unit_price ve tax_rate otomatik doldurulsun". Kopyalanan değer VARSAYILANDIR, kilit
 * değildir — kullanıcı satırda ezebilir.
 */
export function createProductItem(product: Product): EditableQuoteItem {
  return {
    key: makeItemKey(),
    product_id: product.id,
    name: product.name,
    description: '',
    quantity: '1',
    unit_price: String(product.unit_price),
    discount_percent: '0',
    tax_rate: String(product.tax_rate),
  }
}

/** Kayıtlı bir `QuoteItem`'ı (düzenleme modunda formu doldururken) düzenlenebilir satıra çevirir. */
export function toEditableItem(item: QuoteItem): EditableQuoteItem {
  return {
    key: makeItemKey(),
    id: item.id,
    product_id: item.product_id,
    name: item.name,
    description: item.description ?? '',
    quantity: String(item.quantity),
    unit_price: String(item.unit_price),
    discount_percent: String(item.discount_percent),
    tax_rate: String(item.tax_rate),
  }
}

export function toQuoteItemInput(item: EditableQuoteItem): QuoteItemInput {
  return {
    product_id: item.product_id,
    name: item.name || undefined,
    description: item.description || undefined,
    quantity: item.quantity === '' ? 0 : Number(item.quantity),
    unit_price: item.unit_price === '' ? 0 : Number(item.unit_price),
    discount_percent: item.discount_percent === '' ? 0 : Number(item.discount_percent),
    tax_rate: item.tax_rate === '' ? 0 : Number(item.tax_rate),
  }
}

/** Satır toplamı, KDV HARİÇ — sunucudaki `round2(quantity*unit_price*(1-discount/100))` ile aynı
 * formül, düşük riskli (görev tanımı). `subtotal`/`tax_amount`/`total` ASLA burada türetilmez. */
export function clientLineTotal(item: EditableQuoteItem): number {
  const quantity = Number(item.quantity) || 0
  const unitPrice = Number(item.unit_price) || 0
  const discountPercent = Number(item.discount_percent) || 0
  const raw = quantity * unitPrice * (1 - discountPercent / 100)
  return Math.round((raw + Number.EPSILON) * 100) / 100
}
