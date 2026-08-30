// Teklif formunun ihtiyaç duyduğu YAN kataloglar için salt-okunur arama/lookup'lar: firma,
// kişi, fırsat ve (imperatif) fiyat çözümleme. Ürün ARAMA ve fiyat listesi LİSTELEME artık D
// şeridin `features/products/` ve `features/price-lists/` modüllerinden DOĞRUDAN yeniden
// kullanılıyor (`useProducts`, `ProductPickerCombobox`, `usePriceLists` — bkz.
// `components/QuoteItemsEditor.tsx` ve `pages/QuoteFormPage.tsx`), burada TEKRARLANMIYOR.
//
// Firma/kişi/fırsat için AYRI bir modül gerekiyor çünkü D'nin kapsamı yalnızca ürün/fiyat
// listesi — bu üçü `dealsShared.ts`/`ticketsShared.ts` ile AYNI desende (page/per_page/sort/q/
// filter, `isForbidden`) burada tanımlı.
import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { api } from '../../../lib/axios'
import type { ResolvedProductPrice } from '../../products/types'

// ---------------------------------------------------------------------------
// Firma / Kişi / Fırsat — deals/tickets modüllerindeki arama desenleriyle aynı.
// ---------------------------------------------------------------------------

export type CompanyOption = { id: number; name: string }
export type ContactOption = { id: number; full_name: string }
export type DealOption = { id: number; title: string }

async function fetchCompanyOptions(q: string): Promise<CompanyOption[]> {
  const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', {
    params: { q: q || undefined, per_page: 20, sort: 'name' },
  })
  return data.data
}

async function fetchContactOptions(companyId: number | undefined, q: string): Promise<ContactOption[]> {
  const { data } = await api.get<{ data: ContactOption[] }>('/api/contacts', {
    params: { q: q || undefined, per_page: 20, sort: 'last_name', 'filter[company_id]': companyId || undefined },
  })
  return data.data
}

async function fetchDealOptions(q: string): Promise<DealOption[]> {
  const { data } = await api.get<{ data: DealOption[] }>('/api/deals', {
    params: { q: q || undefined, per_page: 20 },
  })
  return data.data
}

export function useCompanyOptionsSearch(q: string, options?: { enabled?: boolean }) {
  const query = useQuery({
    queryKey: ['quotes', 'company-options', q],
    queryFn: () => fetchCompanyOptions(q),
    enabled: options?.enabled ?? true,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}

export function useContactOptionsSearch(companyId: number | undefined, q: string, options?: { enabled?: boolean }) {
  const query = useQuery({
    queryKey: ['quotes', 'contact-options', companyId ?? null, q],
    queryFn: () => fetchContactOptions(companyId, q),
    enabled: options?.enabled ?? true,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}

export function useDealOptionsSearch(q: string, options?: { enabled?: boolean }) {
  const query = useQuery({
    queryKey: ['quotes', 'deal-options', q],
    queryFn: () => fetchDealOptions(q),
    enabled: options?.enabled ?? true,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}

// ---------------------------------------------------------------------------
// Liste filtre dropdown'ları — arama YOK, sabit üst-100 (tickets/deals modüllerindeki
// `useTicketCompanyOptions`/`useDealOwnerOptions` ile aynı desen). Formdaki ARANABİLİR
// combobox'lardan (yukarıda) AYRIDIR: filtre çubuğunda debounce'lu arama yerine düz bir
// `<Select>` yeterli ve daha basit.
// ---------------------------------------------------------------------------

async function fetchCompanyFilterOptions(): Promise<CompanyOption[]> {
  const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', { params: { per_page: 100, sort: 'name' } })
  return data.data
}

async function fetchDealFilterOptions(): Promise<DealOption[]> {
  const { data } = await api.get<{ data: DealOption[] }>('/api/deals', { params: { per_page: 100 } })
  return data.data
}

export function useCompanyFilterOptions() {
  const query = useQuery({
    queryKey: ['quotes', 'company-options', 'all'],
    queryFn: fetchCompanyFilterOptions,
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}

export function useDealFilterOptions() {
  const query = useQuery({
    queryKey: ['quotes', 'deal-options', 'all'],
    queryFn: fetchDealFilterOptions,
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}

// ---------------------------------------------------------------------------
// Fiyat çözümleme — imperatif.
// ---------------------------------------------------------------------------

/**
 * `GET /api/products/{id}/price?price_list_id=` — imperatif (react-query hook DEĞİL): kalem
 * editöründe "ürün seçildi" veya "fiyat listesi değişti, mevcut kalemleri güncelle" anlarında
 * tek seferlik çağrılır, `Promise.all` ile paralel de kullanılabilir. D şeridin
 * `features/products/api/productsApi.ts` içindeki `useProductPrice` bir React Query HOOK'u
 * (bileşen gövdesinde koşulsuz çağrılmalı) — bir dizi kalem üzerinde `Promise.all` ile döngü
 * kurmaya uygun değil, bu yüzden burada AYNI uca ince bir imperatif sarmalayıcı tutulur.
 * `ResolvedProductPrice` tipi D'nin `features/products/types.ts` dosyasından İTHAL EDİLİR
 * (birebir aynı yanıt şekli, ikinci bir tanım açılmaz).
 */
export async function resolveProductPrice(
  productId: number,
  priceListId: number | null,
): Promise<ResolvedProductPrice> {
  const { data } = await api.get<{ data: ResolvedProductPrice }>(`/api/products/${productId}/price`, {
    params: { price_list_id: priceListId ?? undefined },
  })
  return data.data
}
