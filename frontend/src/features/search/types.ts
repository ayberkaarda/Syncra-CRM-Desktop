// Global arama (`GET /api/search`) — Faz 14 / İz F / C1.
// Tipler `App\Support\Search\SearchResult` / `SearchResultResource` (backend) ile BİREBİR
// aynı alan adlarını taşır — bkz. docs/PHASE-INTL.md §3 ve backend dosyaları (DEĞİŞTİRİLMEDİ,
// yalnız okundu).

/** Backend `GlobalSearchService::MODULES` kısa adlarıyla BİREBİR aynı küme. */
export type SearchResultType = 'deal' | 'lead' | 'contact' | 'company' | 'quote' | 'ticket' | 'user'

/** `SearchResultResource::toArray()` şekli — dört alan, hepsi zorunlu (subtitle hariç). */
export type SearchResultItem = {
  type: SearchResultType
  id: number
  title: string
  subtitle: string | null
  link: string
}

/**
 * Backend `GlobalSearchService::RESPONSE_KEYS` çoğul anahtarları. `Partial` KASITLI: izinsiz
 * bir modülün anahtarı yanıtta HİÇ bulunmaz (boş dizi bile değil — bkz. GlobalSearchService
 * sınıf dokümanı "İZİNSİZ MODÜLÜN ANAHTARI..." ve docs/PHASE-AUDIT.md §5.4). Bu yüzden
 * `SearchGroupKey` anahtarlarının hiçbiri `SearchResponse` üzerinde zorunlu değildir; bir
 * anahtarın YOKLUĞU "bu kullanıcı bu modülü göremiyor" anlamına gelir, `[]` ile KARIŞTIRILMAMALI.
 */
export type SearchGroupKey = 'deals' | 'leads' | 'contacts' | 'companies' | 'quotes' | 'tickets' | 'users'

export type SearchResponse = Partial<Record<SearchGroupKey, SearchResultItem[]>>
