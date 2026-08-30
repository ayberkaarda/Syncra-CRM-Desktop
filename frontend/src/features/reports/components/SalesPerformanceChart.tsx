// Satış performansı — dönem başına gelir trendi. Tek seri (choosing-a-form.md: "Trend over time
// → line/area"), lejant kutusu yok (marks-and-anatomy.md: tek seri kart başlığından okunur).
// `RevenueTrendChart` (dashboard) ile aynı görsel dil, ayrı dosya: satır şekli farklı
// (`SalesPerformanceRow` `lost_count`/`deals_count` de taşıyor, `RevenueTrendPoint` taşımaz) ve
// iki modül birbirine YAZMIYOR (görev tanımı §dosya sahipliği) — küçük bir tekrar, gereksiz bir
// çapraz-özellik bağımlılığından iyidir.
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { TooltipContentProps } from 'recharts'
import { EmptyState, Skeleton } from '../../../components/ui'
import { useChartTheme } from '../../dashboard/utils/chartTheme'
import { formatMoney, formatMoneyCompact, formatNumber } from '../../../lib/money'
import type { SalesPerformanceGroupBy, SalesPerformanceRow } from '../types'

const CHART_HEIGHT = 280

function groupLabel(t: TFunction): Record<SalesPerformanceGroupBy, string> {
  return {
    day: t('reports:groupBy.day'),
    week: t('reports:groupBy.week'),
    month: t('reports:groupBy.month'),
  }
}

type ChartDatum = SalesPerformanceRow & { _numericRevenue: number }

export type SalesPerformanceChartProps = {
  rows: SalesPerformanceRow[] | undefined
  isLoading: boolean
  groupBy: SalesPerformanceGroupBy
  /** Görüntü para birimi (`rate_info.display_currency`) — Faz 14 / İz E. */
  currency: string
}

// Recharts 3.x'te `content={<PerformanceTooltip .../>}` (element) artık `TooltipContentProps`e
// karşı tip kontrolü geçmiyor — Recharts'ın enjekte ettiği prop'lar element üzerinde ZORUNLU
// alanlar olarak görünüyor. Çözüm: `content`e ReactElement değil bir render FONKSİYONU verilir
// (aşağıda `<Tooltip content={(props) => <PerformanceTooltip {...props} groupBy={groupBy} />} />`),
// ve bileşenin kendi prop tipi `Partial<TooltipContentProps>` yapılır (Recharts'ın enjekte ettiği
// alanların hepsi zaten opsiyonel — bkz. `node_modules/recharts/types/component/Tooltip.d.ts`);
// yalnızca kendi eklediğimiz `groupBy` ZORUNLU kalır.
type PerformanceTooltipProps = Partial<TooltipContentProps> & {
  groupBy: SalesPerformanceGroupBy
  /** Görüntü para birimi (`rate_info.display_currency`) — backend zaten bu birimde döner
   *  (§2.4), burada yeniden dönüştürülmez, yalnızca DOĞRU sembolle basılır. */
  currency: string
}

function PerformanceTooltip({ active, payload, label, groupBy, currency }: PerformanceTooltipProps) {
  const { t } = useTranslation('reports')
  const theme = useChartTheme()
  if (!active || !payload?.length) return null
  const point = payload[0]?.payload as SalesPerformanceRow | undefined
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
        {t('reports:salesPerformance.tooltipWonLost', {
          won: formatNumber(point.won_count),
          lost: formatNumber(point.lost_count),
        })}
      </p>
    </div>
  )
}

export function SalesPerformanceChart({ rows, isLoading, groupBy, currency }: SalesPerformanceChartProps) {
  const { t } = useTranslation('reports')
  const theme = useChartTheme()
  const chartData = useMemo<ChartDatum[]>(
    () => (rows ?? []).map((row) => ({ ...row, _numericRevenue: Number(row.revenue) || 0 })),
    [rows],
  )
  const gradientId = useMemo(() => `sales-perf-fill-${Math.random().toString(36).slice(2)}`, [])

  if (isLoading) {
    return <Skeleton variant="rect" height={CHART_HEIGHT} className="w-full" />
  }

  if (chartData.length === 0) {
    return (
      <div style={{ height: CHART_HEIGHT }} className="flex items-center justify-center">
        <EmptyState
          title={t('reports:salesPerformance.emptyTitle')}
          description={t('reports:salesPerformance.emptyDescription')}
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
            <XAxis dataKey="period" tickLine={false} axisLine={false} tick={{ fill: theme.axisText, fontSize: 12 }} />
            <YAxis
              tickLine={false}
              axisLine={false}
              tick={{ fill: theme.axisText, fontSize: 12 }}
              tickFormatter={(value: number) => formatMoneyCompact(value, currency)}
              width={72}
            />
            <Tooltip
              cursor={{ stroke: theme.border, strokeWidth: 1 }}
              content={(props) => <PerformanceTooltip {...props} groupBy={groupBy} currency={currency} />}
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
