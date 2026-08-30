// Dashboard modülü tipleri — Faz 11 backend sözleşmesiyle birebir eşleşir (bkz. görev tanımı
// §BACKEND SÖZLEŞMESİ). Para alanları HER ZAMAN string (2 ondalık, nokta ayraçlı) — aritmetik
// için `Number()`e çevrilmez, yalnızca `lib/money.ts` ile biçimlendirilir.
//
// `RateInfo` reports modülünde TANIMLI (Faz 14 / İz E — docs/PHASE-INTL.md §2.4): backend
// sözleşmesi `kpis`/`funnel`/`revenue-trend` ile `sales-performance`/`user-performance`/
// `source-analysis` için BİREBİR aynı şekli taşır (tek kaynak `ReportCurrencyContext::rateInfo()`).
// Burada YENİDEN TANIMLANMAZ — tip sürüklenmesini önlemek için reports'tan import edilir.
import type { RateInfo } from '../reports/types'

export type { RateInfo }

/** `?from=Y-m-d&to=Y-m-d` — varsayılan son 30 gün, `to` gün sonu dahil. */
export type DateRangeParams = {
  from: string
  to: string
}

export type RevenueTrendGroupBy = 'day' | 'week' | 'month'

/** `kpis` nesnesindeki her alanın ortak şekli. `delta_pct: null` → önceki dönem verisi yok,
 * rozet HİÇ gösterilmez (0% yanıltıcı olduğu için basılmaz).
 *
 * NOT — SÖZLEŞME SAPMASI: Görev tanımı `value`/`previous`in HER ZAMAN string olduğunu söylüyor,
 * ancak gerçek backend (`App\Http\Resources\Reports\KpiCollectionResource::toArray`, dönüş tipi
 * bilinçli olarak `mixed`) yalnızca para alanlarını (`revenue`/`open_deals_value`/
 * `avg_deal_size`) string basıyor; sayaçlar (`open_deals_count`/`activities_count`/`won_count`/
 * `lost_count`) ham `int`, `conversion_rate` ham `float` olarak geliyor. Tip burada `number |
 * string` olarak GENİŞLETİLDİ ki gerçek API ile eşleşsin; `lib/money.ts`teki tüm formatter'lar
 * (`formatMoney`/`formatMoneyCompact`/`formatNumber`) zaten `number | string | null | undefined`
 * kabul ediyor, bu yüzden `KpiCard.tsx` bu sapmadan etkilenmiyor. Backend normalize ederse (tüm
 * alanları string'e çevirirse) bu tip daraltılabilir ama geriye dönük uyumlu kalır. */
export type KpiMetric = {
  value: number | string
  previous: number | string
  delta_pct: number | null
}

export type DashboardKpis = {
  revenue: KpiMetric
  open_deals_count: KpiMetric
  open_deals_value: KpiMetric
  conversion_rate: KpiMetric
  activities_count: KpiMetric
  won_count: KpiMetric
  lost_count: KpiMetric
  avg_deal_size: KpiMetric
}

export type DashboardKpisResponse = {
  data: DashboardKpis
  rate_info: RateInfo
}

/** Aşama sırasına göre dizilir — backend sırası korunur, istemci yeniden SIRALAMAZ. `color`
 * pipeline_stages.color ile aynı sabit token sözlüğü (`primary`/`success`/`danger`/`warning`/
 * `neutral`/`info`) — hex DEĞİL (bkz. `components/shared/tokenBadgeVariant.ts`). */
export type FunnelStage = {
  stage_id: number
  stage_name: string
  /** Bkz. `features/deals/types.ts` `PipelineStage.name_key` — aynı DOLU/NULL sözleşmesi. */
  stage_name_key: string | null
  color: string | null
  count: number
  value: string
}

export type DashboardFunnelResponse = {
  data: FunnelStage[]
  rate_info: RateInfo
}

export type RevenueTrendPoint = {
  /** `group_by`e göre `"2026-08-24"` (day) / `"2026-W34"` (week) / `"2026-08"` (month). */
  period: string
  revenue: string
  won_count: number
}

export type DashboardRevenueTrendResponse = {
  data: RevenueTrendPoint[]
  rate_info: RateInfo
}

/** `App\Http\Resources\Reports\RecentActivityResource` ile birebir — Loglar modülündeki
 * `ActivityLog`dan AYRI bir kaynak (`App\Models\Activity`, farklı model): kasıtlı olarak
 * `LogRepository::SUBJECT_TYPE_MAP`ten bağımsız (bkz. `DashboardService::shortSubjectType`). */
export type RecentActivity = {
  id: number
  /** Serbest metin aktivite tipi (`Activity.type` kolonu) — sabit bir enum değil. */
  type: string
  /** Aktivitenin kendi serbest metin açıklaması (`Activity.subject` kolonu). */
  subject: string | null
  occurred_at: string | null
  user: { id: number; name: string } | null
  related: { type: string; id: number; label: string | null } | null
}

export type DashboardRecentActivitiesResponse = {
  data: RecentActivity[]
}

export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent'

/** `App\Http\Resources\Reports\TaskSummaryResource` ile birebir — tarih parametresi almaz,
 * daima "şu an" anlık görüntüsü. */
export type TaskSummary = {
  open_count: number
  overdue_count: number
  due_today_count: number
  completed_today_count: number
  by_priority: Record<TaskPriority, number>
}

export type DashboardTaskSummaryResponse = {
  data: TaskSummary
}

/** Echo `private-dashboard` kanalı → `.dashboard.invalidate` payload'ı. `keys` yalnızca
 * bayatlayan sorguların anahtarlarını taşır (ör. `['kpis','funnel']`) — tüm dashboard değil. */
export type DashboardInvalidateKey =
  | 'kpis'
  | 'funnel'
  | 'revenue-trend'
  | 'recent-activities'
  | 'task-summary'

export type DashboardInvalidateEvent = {
  keys: DashboardInvalidateKey[]
}
