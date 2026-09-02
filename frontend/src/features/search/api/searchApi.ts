// Global arama API katmanı (`GET /api/search?q=`) — Faz 14 / İz F / C1.
// Hata gövdesi tüm uçlarda: `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
//
// GÜVENLİK NOTU (docs/PHASE-AUDIT.md §5.4): yetki filtresi SUNUCUDADIR
// (`GlobalSearchService`) — bu katman yanıtı OLDUĞU GİBİ döner, ikinci bir istemci-taraflı
// filtre YOK. İzinsiz bir modülün anahtarı yanıtta hiç bulunmaz; `SearchResponse` (types.ts)
// bunu `Partial<Record<...>>` ile modeller.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/axios'
import type { SearchResponse } from '../types'
import { getPlatform } from '../../../platform'

/**
 * `SearchRequest::rules()` (`'q' => ['required','string','min:2','max:100']`) İLE BİREBİR AYNI
 * alt sınır — backend'in min uzunluk gerekçesi (ilk karakterde tüm tabloları taramamak) burada
 * TEKRAR İCAT EDİLMEDİ, tek sabit olarak paylaşılıyor. Palet bu sınırın altında istek ATMAZ
 * (`enabled` koşulu), boşuna 422 denemez.
 */
export const MIN_QUERY_LENGTH = 2

export const searchKeys = {
  all: ['global-search'] as const,
  query: (term: string) => ['global-search', term] as const,
}

export async function fetchGlobalSearch(term: string): Promise<SearchResponse> {
  const { data } = await api.get<{ data: SearchResponse }>('/api/search', {
    params: { q: term },
  })
  return data.data
}

/**
 * `term` boş/çok kısaysa sorgu hiç ATILMAZ (`enabled: false`) — bileşen katmanı bunun
 * için ayrı bir "yükleniyor" durumu göstermez, `MIN_QUERY_LENGTH` sabitine göre kendi
 * "yazmaya devam et" istemini gösterir (bkz. `CommandPalette.tsx`).
 *
 * `keepPreviousData`: bir tuş vuruşundan diğerine liste TAMAMEN boşalıp yeniden dolmaz —
 * önceki sonuçlar ekranda kalır, arka planda yeni sorgu biter bitmez yerini alır. Komut
 * paletinin HER TUŞ VURUŞUNDA yeniden sorgulandığı düşünülürse (debounce'lu da olsa) bu,
 * gözle görülür bir titremeyi (flicker) önler.
 */
export function useGlobalSearch(term: string) {
  return useQuery({
    queryKey: searchKeys.query(term),
    queryFn: () => getPlatform().data.search.query(term),
    enabled: term.length >= MIN_QUERY_LENGTH,
    placeholderData: keepPreviousData,
  })
}
