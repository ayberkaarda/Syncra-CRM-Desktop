// Kayıtlı Görünümler (Saved Views) — Faz 14 / İz F, Attio C2 (docs/PHASE-INTL.md §3).
// Backend `SavedViewResource`/`SavedViewController` ile birebir eşleşir. Bu, YENİ bir sorgu
// dili İCAT ETMEZ — mevcut Faz 6 liste sözleşmesinin (`?page&per_page&sort&filter[]&q`)
// üstünde bir kayıt/adlandırma katmanıdır (bkz. `components/SavedViewsBar.tsx`).
//
// GÜVENLİK NOTU (docs/PHASE-AUDIT.md §5.4): bu modül HİÇBİR ZAMAN kendi başına veri (deal/
// lead/... satırı) ÇEKMEZ. `query_json` yalnızca URL arama parametrelerine YAZILIR; gerçek
// veri her zaman ilgili modülün KENDİ liste ucundan (`useDeals()` vb.), açan kullanıcının
// kendi oturumu/izniyle çekilir.

/** Backend `App\Services\SavedViews\SavedViewModules::MODULES` ile birebir aynı küme. */
export const SAVED_VIEW_MODULES = [
  'deals',
  'leads',
  'contacts',
  'companies',
  'quotes',
  'tickets',
  'tasks',
  'products',
  'users',
] as const

export type SavedViewModule = (typeof SAVED_VIEW_MODULES)[number]

/**
 * Bir görünümün taşıdığı sorgu anlık görüntüsü. `filter` anahtarları modüle göre değişir
 * (bkz. sayfa bazlı `filterKeys` prop'u) — hepsi STRING olarak saklanır çünkü kaynağı zaten
 * URL arama parametreleridir (`URLSearchParams` her zaman string döner).
 */
export type SavedViewQuery = {
  q?: string | null
  sort?: string | null
  per_page?: number | null
  filter?: Record<string, string>
}

export type SavedView = {
  id: number
  module: SavedViewModule
  name: string
  query_json: SavedViewQuery
  is_shared: boolean
  /** Sunucu tarafı kolaylık alanı — gerçek yetki kararı yine sunucudadır (403/404). */
  is_mine: boolean
  owner_name: string | null
  created_at: string
  updated_at: string
}

export type SavedViewPayload = {
  module: SavedViewModule
  name: string
  query_json: SavedViewQuery
  is_shared?: boolean
}

export type UpdateSavedViewPayload = Partial<Pick<SavedViewPayload, 'name' | 'query_json' | 'is_shared'>>
