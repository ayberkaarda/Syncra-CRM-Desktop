// KPI kartı — değer + yön renkli delta rozeti. `marks-and-anatomy.md` "Stat tile" sözleşmesi:
// label (cümle içi, iki nokta yok) + value (yarı kalın) + delta (imzalı, yön × "artış iyi mi"
// birlikte renklenir). `delta_pct: null` → önceki dönem verisi yok, rozet HİÇ basılmaz (0%
// yanıltıcı olurdu, bkz. görev tanımı).
//
// `KpiCardGrid` sekiz `kpis` alanını tek yerde biçimlendirip yerleştirir — para alanları
// `formatMoney`/`formatMoneyCompact`, sayaçlar `formatNumber`, oran `formatNumber(...,2) + '%'`
// ile basılır; ARİTMETİK için `Number()`e çevrilmez (backend zaten toplamış).
import type { ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import {
  Activity,
  ArrowDown,
  ArrowUp,
  CheckCircle2,
  Minus,
  Percent,
  Target,
  TrendingUp,
  Wallet,
  XCircle,
} from 'lucide-react'
import { Card, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { formatMoneyCompact, formatNumber } from '../../../lib/money'
import type { DashboardKpis, KpiMetric } from '../types'

export type KpiCardProps = {
  label: string
  value: string
  deltaPct: number | null
  /** `false` ise artış kötü demektir (ör. kaybedilen anlaşma sayısı) — rozet rengi tersine döner. */
  increaseIsGood?: boolean
  icon: ComponentType<{ className?: string }>
}

export function KpiCard({ label, value, deltaPct, increaseIsGood = true, icon: Icon }: KpiCardProps) {
  const hasDelta = deltaPct !== null
  const isFlat = hasDelta && deltaPct === 0
  const isIncrease = hasDelta && deltaPct > 0
  const isGood = hasDelta && (isFlat ? null : increaseIsGood === isIncrease)

  const badgeClasses = isFlat
    ? 'bg-surface-2 text-fg-muted'
    : isGood
      ? 'bg-success-tint text-success'
      : 'bg-danger-tint text-danger'

  const DeltaIcon = isFlat ? Minus : isIncrease ? ArrowUp : ArrowDown

  return (
    <Card className="flex min-w-0 flex-col gap-3 p-5">
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-medium text-fg-muted">{label}</span>
        <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-tint text-primary">
          <Icon className="size-4" aria-hidden="true" />
        </span>
      </div>

      <div className="flex items-end justify-between gap-2">
        <span className="truncate text-2xl font-semibold text-fg">{value}</span>
        {hasDelta && (
          <span
            className={cn(
              'inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium',
              badgeClasses,
            )}
          >
            <DeltaIcon className="size-3" aria-hidden="true" />
            {formatNumber(Math.abs(deltaPct), 1)}%
          </span>
        )}
      </div>
    </Card>
  )
}

export function KpiCardSkeleton() {
  return (
    <Card className="flex flex-col gap-3 p-5" aria-busy="true">
      <div className="flex items-center justify-between gap-2">
        <Skeleton variant="text" width="60%" />
        <Skeleton variant="circle" width={32} height={32} />
      </div>
      <Skeleton variant="text" width="45%" height={28} />
    </Card>
  )
}

type KpiConfig = {
  key: keyof DashboardKpis
  label: string
  icon: ComponentType<{ className?: string }>
  increaseIsGood: boolean
  format: (metric: KpiMetric, currency: string) => string
}

const money = (metric: KpiMetric, currency: string) => formatMoneyCompact(metric.value, currency)
const count = (metric: KpiMetric) => formatNumber(metric.value)
const percent = (metric: KpiMetric) => `${formatNumber(metric.value, 1)}%`

function kpiConfig(t: TFunction): KpiConfig[] {
  return [
    { key: 'revenue', label: t('dashboard:kpi.revenue'), icon: Wallet, increaseIsGood: true, format: money },
    { key: 'open_deals_count', label: t('dashboard:kpi.openDealsCount'), icon: Target, increaseIsGood: true, format: count },
    { key: 'open_deals_value', label: t('dashboard:kpi.openDealsValue'), icon: Wallet, increaseIsGood: true, format: money },
    { key: 'conversion_rate', label: t('dashboard:kpi.conversionRate'), icon: Percent, increaseIsGood: true, format: percent },
    { key: 'activities_count', label: t('dashboard:kpi.activitiesCount'), icon: Activity, increaseIsGood: true, format: count },
    { key: 'won_count', label: t('dashboard:kpi.wonCount'), icon: CheckCircle2, increaseIsGood: true, format: count },
    { key: 'lost_count', label: t('dashboard:kpi.lostCount'), icon: XCircle, increaseIsGood: false, format: count },
    { key: 'avg_deal_size', label: t('dashboard:kpi.avgDealSize'), icon: TrendingUp, increaseIsGood: true, format: money },
  ]
}

export type KpiCardGridProps = {
  kpis: DashboardKpis | undefined
  isLoading: boolean
  /** Görüntü para birimi (`rate_info.display_currency`) — Faz 14 / İz E. Backend zaten bu
   *  birimde döner, burada yeniden dönüştürme yapılmaz. `rate_info` yoksa (ilk yükleme) temel
   *  para birimine (TRY) düşülür — `formatMoney`nin kendi varsayılanıyla aynı. */
  currency?: string
}

/** Sekiz KPI kartının tek satırlık ızgarası — `DashboardPage` bunu tek başına kullanır. */
export function KpiCardGrid({ kpis, isLoading, currency = 'TRY' }: KpiCardGridProps) {
  const { t } = useTranslation('dashboard')
  const KPI_CONFIG = kpiConfig(t)
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {isLoading || !kpis
        ? KPI_CONFIG.map((cfg) => <KpiCardSkeleton key={cfg.key} />)
        : KPI_CONFIG.map((cfg) => {
            const metric = kpis[cfg.key]
            return (
              <KpiCard
                key={cfg.key}
                label={cfg.label}
                value={cfg.format(metric, currency)}
                deltaPct={metric.delta_pct}
                increaseIsGood={cfg.increaseIsGood}
                icon={cfg.icon}
              />
            )
          })}
    </div>
  )
}
