// Teklif modülü tipleri — backend sözleşmesi `docs/QUOTE-FINANCIALS.md` ve
// `App\Http\Resources\QuoteResource` / `QuoteItemResource` ile birebir.
import type { RelatedGroupData } from '../related/types'

export type QuoteStatus = 'draft' | 'sent' | 'accepted' | 'rejected' | 'expired'

/** `PATCH /api/quotes/{id}/status` bu uçtan yalnızca şu üçünü kabul eder ("sent" ayrı bir uçtan gelir). */
export const MANUAL_QUOTE_STATUSES: QuoteStatus[] = ['accepted', 'rejected', 'expired']

export type DiscountType = 'amount' | 'percent'

export type QuoteRefDeal = { id: number; title: string }
export type QuoteRefCompany = { id: number; name: string }
export type QuoteRefContact = { id: number; full_name: string }
export type QuoteRefUser = { id: number; name: string }

export type QuoteItem = {
  id: number
  product_id: number | null
  name: string
  description: string | null
  quantity: number
  unit_price: number
  discount_percent: number
  tax_rate: number
  /** KDV HARİÇ (sözleşme §5b). */
  line_total: number
  /** KDV DAHİL — türetilmiş gösterim değeri, kolonu yok. Dipnot toplamları asla bundan türetilmez. */
  line_gross: number
  position: number
}

/**
 * Oran bazlı KDV matrah özeti satırı — `QuoteCalculator::calculate()`'ın ürettiği
 * `tax_breakdown` dizisinin BİREBİR anahtarları. DİKKAT: bunlar `rate/net/discount/base/tax`
 * dır — `tax_rate/allocated_discount/tax_base/tax_amount` DEĞİL (ilk görev tanımındaki örnek
 * yanlıştı, koordinatör sonradan düzeltti). `POST /api/quotes/calculate` ve
 * `GET /api/quotes/{id}` AYNI şekli döndürür, tek bir render bileşeni ikisi için de kullanılır.
 */
export type QuoteTaxBreakdownRow = {
  /** KDV oranı (%). */
  rate: number
  /** Bu orandaki kalemlerin indirim ÖNCESİ net toplamı. */
  net: number
  /** Bu gruba dağıtılan teklif geneli indirim payı. */
  discount: number
  /** Matrah = net - discount. */
  base: number
  /** Bu grubun KDV tutarı. */
  tax: number
}

export type Quote = {
  id: number
  quote_number: string
  title: string
  status: QuoteStatus
  valid_until: string | null
  is_expired: boolean
  subtotal: number
  discount_type: DiscountType
  discount_value: number
  discount_amount: number
  tax_amount: number
  total: number
  currency: string
  revision: number
  parent_quote_id: number | null
  notes: string | null
  terms: string | null
  sent_at: string | null
  accepted_at: string | null
  rejected_at: string | null
  deal: QuoteRefDeal | null
  company: QuoteRefCompany | null
  contact: QuoteRefContact | null
  creator: QuoteRefUser | null
  /** Yalnızca detay ucunda dolu — liste ucunda `null` (bkz. QuoteResource dokümanı). */
  items: QuoteItem[] | null
  /** Yalnızca detay ucunda dolu. */
  tax_breakdown: QuoteTaxBreakdownRow[] | null
  items_count: number
  /**
   * Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3).
   * Yalnızca detay ucunda (`GET /api/quotes/{id}`) dolu ve yalnızca ilgili
   * modülün izni varsa alt-anahtar mevcuttur — izinsiz modülün anahtarı
   * `related` nesnesinde HİÇ YOKTUR (bkz. `QuoteController::loadRelatedRecords()`).
   * Liste ucunda alan tamamen `undefined`'dır.
   */
  related?: {
    company?: RelatedGroupData<{ id: number; name: string }>
    deal?: RelatedGroupData<{ id: number; title: string }>
    contact?: RelatedGroupData<{ id: number; full_name: string }>
  }
  created_at: string
  updated_at: string
}

export type QuotesQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  status?: QuoteStatus
  deal_id?: number
  company_id?: number
  contact_id?: number
  from?: string
  to?: string
  expired?: boolean
}

export type QuotesListResponse = {
  data: Quote[]
  meta: {
    pagination: { current_page: number; per_page: number; total: number; last_page: number }
  }
}

/** `POST /api/quotes` ve `PATCH /api/quotes/{id}` gövdesindeki kalem girdisi. */
export type QuoteItemInput = {
  product_id?: number | null
  name?: string | null
  description?: string | null
  quantity?: number | null
  unit_price?: number | null
  discount_percent?: number | null
  tax_rate?: number | null
}

export type QuotePayload = {
  title?: string
  deal_id?: number | null
  company_id?: number | null
  contact_id?: number | null
  valid_until?: string | null
  discount_type?: DiscountType
  discount_value?: number
  notes?: string | null
  terms?: string | null
  items?: QuoteItemInput[]
}

export type QuoteCalculateItemResult = QuoteItemInput & { line_total: number }

/** `POST /api/quotes/calculate` yanıtı (`data`). Hiçbir şey kaydetmez, girdinin saf fonksiyonudur. */
export type QuoteCalculateResult = {
  items: QuoteCalculateItemResult[]
  subtotal: number
  discount_type: DiscountType
  discount_value: number
  discount_amount: number
  tax_amount: number
  total: number
  tax_breakdown: QuoteTaxBreakdownRow[]
}
