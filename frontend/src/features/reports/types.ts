// Raporlar modülü tipleri — backend `App\Http\Resources\Reports\*` (SalesPerformanceResource,
// UserPerformanceResource, SourceAnalysisResource, ConversionResource) ve
// `App\Services\Reports\*Report::run()` çıktılarıyla birebir eşleşir.
//
// SARMALAMA NOTU: Bu dört uç, Laravel'in TEKİL `JsonResource` (koleksiyon DEĞİL) sarmalamasını
// kullanıyor ve bu sınıflar `withoutWrapping()` çağırmıyor — bu yüzden `toArray()`in döndürdüğü
// `{from, to, data, ...}` gövdesinin TAMAMI yine bir üst `"data"` anahtarının içine sarılıyor.
// Sonuç: `sales-performance` için satırlar `response.data.data[...]`de, `conversion` için özet
// `response.data.total_leads`de (satır dizisi YOK — bkz. `ReportApiTest.php`
// `assertJsonPath('data.data.0.period', ...)` / `assertJsonPath('data.total_leads', ...)`).
import type { LeadStatus } from '../leads/types'

export type { LeadStatus }

export type DateRangeParams = {
  from: string
  to: string
}

export type ReportSlug = 'sales-performance' | 'user-performance' | 'source-analysis' | 'conversion'
export type ReportExportFormat = 'csv' | 'xlsx'
export type SalesPerformanceGroupBy = 'day' | 'week' | 'month'

// ---------------------------------------------------------------------------
// KUR ŞEFFAFLIĞI (Faz 14 / İz E — docs/PHASE-INTL.md §2.4/§2.6). Backend sözleşmesinin TEK
// kaynağı `App\Services\Reports\Support\ReportCurrencyContext::rateInfo()` docblock'udur —
// aşağıdaki alan adları/anlamları oradan TAHMİN YÜRÜTMEDEN birebir alınmıştır. `rate_info`,
// `sales-performance`/`user-performance`/`source-analysis` (bu dosyada) ve dashboard'un
// `kpis`/`funnel`/`revenue-trend` uçlarında (bkz. `features/dashboard/types.ts`) `data`'nın
// KARDEŞİ olarak döner; para taşımayan `conversion` ucunda YOKTUR (`ConversionResponse`
// bilerek bu alanı taşımaz).
export type RateInfoClosedBasis = 'frozen_base' | 'frozen_base_converted'
export type RateInfoOpenBasis = 'current_rate'

/** Dönüşümde kullanılan bir kova — kaynak para birimi başına bir satır. */
export type RateInfoBucket = {
  currency: string
  /** Kaynak para biriminde toplam tutar (decimal string, ör. "12500.00"). */
  amount: string
  /** Kullanılan kur (decimal string, ör. "41.250000"). */
  rate: string
  /** O kurun yayın tarihi (`Y-m-d`) — kur okunmadıysa (temel para birimi) `null` olabilir. */
  rate_date: string | null
  /** Görüntü para birimindeki karşılık (decimal string). */
  converted: string
}

/** Kuru bulunamadığı için çevrilemeyen kova — tutar toplamlara 0 olarak girmiştir. */
export type RateInfoUnconvertedBucket = {
  currency: string
  amount: string
}

export type RateInfo = {
  /** Rakamların gösterildiği para birimi (`users.preferred_currency`, ISO 4217). */
  display_currency: string
  /** Temel para birimi — donmuş değerlerin saklandığı birim (`"TRY"`). */
  base_currency: string
  /** `"frozen_base"`: kapanmış fırsatlar donmuş TRY, dönüşüm yok, rakam KARARLI.
   *  `"frozen_base_converted"`: donmuş TRY, gösterim için güncel kurla çevrildi (gün gün değişir). */
  closed_basis: RateInfoClosedBasis
  /** Açık fırsatlar için daima `"current_rate"`. */
  open_basis: RateInfoOpenBasis
  /** Dönüşümde kullanılan kurların EN ESKİ yayın tarihi (`Y-m-d`). Hiç dönüşüm yapılmadıysa
   *  `null` — arayüz o zaman kur dipnotunu HİÇ basmaz (sahte dipnot yok). */
  as_of: string | null
  /** `as_of` 4 takvim gününden eski mi (§2.6) — amber bayatlık uyarısının tetikleyicisi. */
  is_stale: boolean
  /** `as_of` kaç gün eski (dönüşüm yoksa 0). */
  days_stale: number
  /** Çevrilen kovalar — para birimi koduna göre alfabetik, kova yoksa boş dizi. */
  converted_buckets: RateInfoBucket[]
  /** Kuru bulunamadığı için çevrilemeyen açık-fırsat kovaları — bu tutarlar toplamlara 0
   *  olarak girmiştir, arayüz GÖRÜNÜR bir uyarı göstermelidir. */
  unconverted_open: RateInfoUnconvertedBucket[]
  /** Kapanmış olduğu hâlde donmuş temel tutarı OLMAYAN fırsat sayısı — gelire dahil değildir. */
  unconverted_closed_count: number
}

export type SalesPerformanceRow = {
  /** `group_by`e göre `"2026-08-24"` (day) / `"2026-W34"` (week) / `"2026-08"` (month). */
  period: string
  revenue: string
  won_count: number
  lost_count: number
  deals_count: number
}

export type SalesPerformanceTotals = {
  revenue: string
  won_count: number
  lost_count: number
  deals_count: number
}

export type SalesPerformanceBody = {
  from: string
  to: string
  group_by: SalesPerformanceGroupBy
  data: SalesPerformanceRow[]
  totals: SalesPerformanceTotals
}

export type SalesPerformanceResponse = {
  data: SalesPerformanceBody
  rate_info: RateInfo
}

export type UserPerformanceRow = {
  user_id: number
  /** Kullanıcı silinmişse `null` (`UserPerformanceReport::run` — `$names[$ownerId] ?? null`). */
  user_name: string | null
  revenue: string
  won_count: number
  lost_count: number
  conversion_rate: number
  avg_deal_size: string
  open_deals_count: number
  open_deals_value: string
  activities_count: number
}

export type UserPerformanceBody = {
  from: string
  to: string
  data: UserPerformanceRow[]
}

export type UserPerformanceResponse = {
  data: UserPerformanceBody
  rate_info: RateInfo
}

export type SourceAnalysisRow = {
  source: string
  leads_count: number
  converted_count: number
  conversion_rate: number
  revenue: string
}

export type SourceAnalysisBody = {
  from: string
  to: string
  data: SourceAnalysisRow[]
}

export type SourceAnalysisResponse = {
  data: SourceAnalysisBody
  rate_info: RateInfo
}

/** Dönem içinde OLUŞTURULMUŞ lead kohortunun durum dağılımı — aşama başına satır DEĞİL, tek bir
 * özet nesnesi (`ConversionReport::run`). `by_status`taki sıra `LEAD_STATUS_ORDER`de sabitlenir. */
export type ConversionBody = {
  from: string
  to: string
  total_leads: number
  converted_count: number
  conversion_rate: number
  avg_days_to_convert: number | null
  by_status: Record<LeadStatus, number>
}

export type ConversionResponse = {
  data: ConversionBody
}
