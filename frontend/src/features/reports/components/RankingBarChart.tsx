// Sıralama bar grafiği — Kullanıcı Performansı, Kaynak Analizi ve Dönüşüm sekmelerinin ortak
// gövdesi. choosing-a-form.md: "Compare magnitude, low → high → bar/column". Barlar TEK bir
// kimliği paylaşan bir seri olduğundan (ya tümü aynı vurgu tonu, ya da Dönüşüm'de olduğu gibi
// iş mantığının zaten atadığı aşama token'ı) ayrı bir kategorik palet İCAT EDİLMEZ — kimlik zaten
// Y ekseni etiketinde doğrudan yazılı (bkz. `dashboard/components/SalesFunnel.tsx` ile aynı
// gerekçe). Değer barın ucunda etiketlenir (marks-and-anatomy.md "Bars → value at the tip").
import type { ReactNode } from 'react'
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { EmptyState, Skeleton } from '../../../components/ui'
import { useChartTheme } from '../../dashboard/utils/chartTheme'

const ROW_HEIGHT = 36
const MIN_HEIGHT = 120

export type RankingBarChartProps<T> = {
  data: T[] | undefined
  isLoading: boolean
  getKey: (row: T) => string | number
  getLabel: (row: T) => string
  getValue: (row: T) => number
  formatValue: (numericValue: number) => string
  getColor?: (row: T) => string
  /** `false` ise backend'in verdiği sıra korunur (ör. Dönüşüm'de aşama sırası — ordinal, yeniden
   * sıralanamaz). Varsayılan `true`: değere göre büyükten küçüğe. */
  sort?: boolean
  emptyTitle: string
  emptyDescription?: string
  tooltipExtra?: (row: T) => ReactNode
}

export function RankingBarChart<T>({
  data,
  isLoading,
  getKey,
  getLabel,
  getValue,
  formatValue,
  getColor,
  sort = true,
  emptyTitle,
  emptyDescription,
  tooltipExtra,
}: RankingBarChartProps<T>) {
  const theme = useChartTheme()

  if (isLoading) {
    return <Skeleton variant="rect" height={220} className="w-full" />
  }

  if (!data || data.length === 0) {
    return (
      <div style={{ height: 220 }} className="flex items-center justify-center">
        <EmptyState title={emptyTitle} description={emptyDescription} />
      </div>
    )
  }

  const rows = sort ? [...data].sort((a, b) => getValue(b) - getValue(a)) : data
  const chartData = rows.map((row) => ({
    __key: getKey(row),
    __label: getLabel(row),
    __value: getValue(row),
    __row: row,
  }))
  const height = Math.max(MIN_HEIGHT, chartData.length * ROW_HEIGHT)

  return (
    <div className="w-full overflow-x-auto">
      <div style={{ height, minWidth: 420 }}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart
            data={chartData}
            layout="vertical"
            margin={{ top: 4, right: 56, bottom: 4, left: 4 }}
            barCategoryGap={8}
          >
            <XAxis type="number" hide />
            <YAxis
              type="category"
              dataKey="__label"
              width={160}
              tickLine={false}
              axisLine={false}
              tick={{ fill: theme.axisText, fontSize: 12 }}
            />
            <Tooltip
              cursor={{ fill: theme.grid }}
              content={({ active, payload }) => {
                if (!active || !payload?.length) return null
                const point = payload[0]?.payload as (typeof chartData)[number] | undefined
                if (!point) return null
                return (
                  <div
                    className="rounded-md border px-3 py-2 text-xs shadow-popover"
                    style={{ background: theme.surface, borderColor: theme.border, color: theme.fg }}
                  >
                    <p className="mb-1 font-medium">{point.__label}</p>
                    <p className="font-semibold">{formatValue(point.__value)}</p>
                    {tooltipExtra && (
                      <div style={{ color: theme.fgMuted }}>{tooltipExtra(point.__row)}</div>
                    )}
                  </div>
                )
              }}
            />
            <Bar dataKey="__value" radius={[0, 4, 4, 0]} maxBarSize={22} isAnimationActive={false}>
              {chartData.map((point) => (
                <Cell key={point.__key} fill={getColor ? getColor(point.__row) : theme.accent} />
              ))}
              <LabelList
                dataKey="__value"
                position="right"
                formatter={(value: unknown) => formatValue(value as number)}
                style={{ fill: theme.fgMuted, fontSize: 12 }}
              />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  )
}
