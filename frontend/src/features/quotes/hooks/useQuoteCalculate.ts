// `POST /api/quotes/calculate` için canlı toplam hook'u — debounce (~400ms) + yarış durumu
// çözümü.
//
// İLK SÜRÜM elle `useEffect` + `AbortController` + nesil sayacı (Faz 8'deki `usePageTracking`
// deseni) ile yazılmıştı, ama `setIsCalculating(true)`'ı effect gövdesinde SENKRON çağırmak bu
// projenin eslint kuralı `react-hooks/set-state-in-effect`'i (hard error) ihlal ediyordu. Bunun
// yerine burada `@tanstack/react-query`'nin `useQuery`'sine devredildi — AYNI üç garantiyi
// DAHA AZ elle yazılmış kodla verir:
//   - Yarış durumu: `queryKey`'e giren `debounced` değeri değiştiğinde react-query eskiyen
//     isteği kendi içinde iptal eder/yok sayar (queryFn'e verilen `signal` axios'a geçiliyor);
//     `usePageTracking`'deki nesil sayacının işlevini react-query'nin kendi sürüm takibi görür.
//   - Önceki toplamlar korunur: `placeholderData: keepPreviousData` — yeni istek uçarken eski
//     `data` DEĞİŞMEDEN kalır, sıfır gösterilmez (görev tanımı).
//   - "Hesaplanıyor" göstergesi: `isFetching` — manuel bir state/effect gerekmez.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { calculateQuote } from '../api/quotesApi'
import { getErrorMessage } from '../../../lib/axios'
import type { DiscountType, QuoteCalculateResult, QuoteItemInput } from '../types'
import { useDebouncedValue } from './useDebouncedValue'

const DEBOUNCE_MS = 400

export type QuoteCalculateInput = {
  items: QuoteItemInput[]
  discount_type: DiscountType
  discount_value: number
}

export type UseQuoteCalculateResult = {
  /** En son BAŞARILI hesap sonucu — yeni istek uçarken bir önceki değer korunur. */
  result: QuoteCalculateResult | null
  isCalculating: boolean
  error: string | null
}

export function useQuoteCalculate(input: QuoteCalculateInput, enabled = true): UseQuoteCalculateResult {
  // Girdi kararlı bir string'e indirgenip debounce edilir: nesnenin kendisi her render'da
  // yeniden yaratıldığından (çağıran taraf items dizisini state'ten türetiyor), referans
  // eşitliğine güvenmek yerine içerik eşitliğine güveniyoruz — `queryKey` bu string'i taşır.
  const serialized = JSON.stringify(input)
  const debounced = useDebouncedValue(serialized, DEBOUNCE_MS)

  const query = useQuery({
    queryKey: ['quotes', 'calculate', debounced],
    queryFn: ({ signal }) => calculateQuote(JSON.parse(debounced) as QuoteCalculateInput, { signal }),
    enabled,
    placeholderData: keepPreviousData,
    retry: false,
    staleTime: 0,
    gcTime: 60_000,
  })

  return {
    result: query.data ?? null,
    isCalculating: query.isFetching,
    error: query.isError ? getErrorMessage(query.error) : null,
  }
}
