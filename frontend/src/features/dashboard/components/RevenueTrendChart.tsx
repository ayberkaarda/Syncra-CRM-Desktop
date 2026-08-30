// Gelir trendi — tek serili alan grafiği (choosing-a-form.md: "Trend over time → line; area for
// a single series"). Tek seri olduğundan lejant KUTUSU yok (marks-and-anatomy.md: "A single
// series needs no legend box"); kimlik kart başlığından okunur. Renk `--app-primary`den okunur,
// alan dolgusu ~%10 opaklıkta bir yıkama (asla doygun blok).
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { TooltipContentProps } from 'recharts'
import { EmptyState, Skeleton } from '../../../components/ui'
import { formatMoney, formatMoneyCompact, formatNumber } from '../../../lib/money'
import { useChartTheme } from '../utils/chartTheme'
import type { RevenueTrendGroupBy, RevenueTrendPoint } from '../types'

const CHART_HEIGHT = 280

function groupLabel(t: TFunction): Record<RevenueTrendGroupBy, string> {
  return {
    day: t('dashboard:revenueTrend.group.day'),
    week: t('dashboard:revenueTrend.group.week'),
    month: t('dashboard:revenueTrend.group.month'),
  }
}

/** Recharts'ın eksen/alan hesaplaması sayısal bir alan gerektirir; `revenue` API sözleşmesinde
 * string (bkz. görev tanımı). Yalnızca ÇİZİM için dönüştürülür — görüntülenen değerler her yerde
 * orijinal string'ten `formatMoney`/`formatMoneyCompact` ile üretilir. */
type RevenueChartDatum = RevenueTrendPoint & { _numericRevenue: number }

function toChartData(points: RevenueTrendPoint[]): RevenueChartDatum[] {
  return points.map((point) => ({ ...point, _numericRevenue: Number(point.revenue) || 0 }))
}

export type RevenueTrendChartProps = {
  points: RevenueTrendPoint[] | undefined
  isLoading: boolean
  groupBy: RevenueTrendGroupBy
  /** Görüntü para birimi (`rate_info.display_currency`) — Faz 14 / İz E. Backend zaten bu
   *  birimde döner, burada yeniden dönüştürme yapılmaz. `rate_info` yoksa (ilk yükleme) temel
   *  para birimine (TRY) düşülür. */
  currency?: string
}

// Recharts 3.x'te `content={<RevenueTooltip .../>}` (element) artık `TooltipContentProps`e karşı
// tip kontrolü geçmiyor — Recharts'ın enjekte ettiği prop'lar element üzerinde ZORUNLU alanlar
// olarak görünüyor. Çözüm: `content`e ReactElement değil bir render FONKSİYONU verilir (aşağıda
// `<Tooltip content={(props) => <RevenueTooltip {...props} groupBy={groupBy} />} />`), ve
// bileşenin kendi prop tipi `Partial<TooltipContentProps>` yapılır (Recharts'ın enjekte ettiği
// alanların hepsi zaten opsiyonel — bkz. `node_modules/recharts/types/component/Tooltip.d.ts`);
// yalnızca kendi eklediğimiz `groupBy` ZORUNLU kalır.
type RevenueTooltipProps = Partial<TooltipContentProps> & {
  groupBy: RevenueTrendGroupBy
  /** Görüntü para birimi (`rate_info.display_currency`) — Faz 14 / İz E. */
  currency: string
}

function RevenueTooltip({ active, payload, label, groupBy, currency }: RevenueTooltipProps) {
  const { t } = useTranslation('dashboard')
  const theme = useChartTheme()
  if (!active || !payload?.length) return null
  const point = payload[0]?.payload as RevenueTrendPoint | undefined
  if (!point) return null

  return (
    <div
      className="rounded-md border px-3 py-2 text-xs shadow-popover"
      style={{ background: theme.surface, borderColor: theme.border, color: theme.fg }}
    >
      <p className="mb-1" style={{ color: theme.fgMuted }}>
        {groupLabel(t)[groupBy]}: {String(label)}
      </p>
      <p className="font-semibold">{formatMoney(point.revenue, currency)}</p>
      <p style={{ color: theme.fgMuted }}>
        {t('dashboard:revenueTrend.tooltipWonDeals', { value: formatNumber(point.won_count) })}
      </p>
    </div>
  )
}

export function RevenueTrendChart({ points, isLoading, groupBy, currency = 'TRY' }: RevenueTrendChartProps) {
  const { t } = useTranslation('dashboard')
  const theme = useChartTheme()
  const chartData = useMemo(() => toChartData(points ?? []), [points])

  const gradientId = useMemo(
    () => `revenue-trend-fill-${Math.random().toString(36).slice(2)}`,
    [],
  )

  if (isLoading) {
    return <Skeleton variant="rect" height={CHART_HEIGHT} className="w-full" />
  }

  if (!points || points.length === 0) {
    return (
      <div style={{ height: CHART_HEIGHT }} className="flex items-center justify-center">
        <EmptyState
          title={t('dashboard:revenueTrend.emptyTitle')}
          description={t('dashboard:revenueTrend.emptyDescription')}
        />
      </div>
    )
  }

  return (
    <div className="w-full overflow-x-auto">
      <div style={{ height: CHART_HEIGHT, minWidth: 480 }}>
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={chartData} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
            <defs>
              <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={theme.accent} stopOpacity={0.1} />
                <stop offset="100%" stopColor={theme.accent} stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid vertical={false} stroke={theme.grid} strokeDasharray="0" />
            <XAxis
              dataKey="period"
              tickLine={false}
              axisLine={false}
              tick={{ fill: theme.axisText, fontSize: 12 }}
            />
            <YAxis
              tickLine={false}
              axisLine={false}
              tick={{ fill: theme.axisText, fontSize: 12 }}
              tickFormatter={(value: number) => formatMoneyCompact(value, currency)}
              width={72}
            />
            <Tooltip
              cursor={{ stroke: theme.border, strokeWidth: 1 }}
              content={(props) => <RevenueTooltip {...props} groupBy={groupBy} currency={currency} />}
            />
            <Area
              type="monotone"
              dataKey="_numericRevenue"
              stroke={theme.accent}
              strokeWidth={2}
              fill={`url(#${gradientId})`}
              activeDot={{ r: 4, fill: theme.accent, stroke: theme.surface, strokeWidth: 2 }}
              dot={false}
              isAnimationActive={false}
            />
          </AreaChart>
        </ResponsiveContainer>
      </div>
    </div>
  )
}
