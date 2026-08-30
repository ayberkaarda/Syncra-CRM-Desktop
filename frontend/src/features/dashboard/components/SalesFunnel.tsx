// Satış hunisi — pipeline aşamalarına göre yatay bar grafiği. Aşama sırası backend'den geldiği
// gibi korunur (istemci yeniden SIRALAMAZ — huni okunuşu sıraya bağlı). Renk kimliği kategorik bir
// palet İCAT ETMEK yerine `pipeline_stages.color`daki mevcut semantik token'ı yeniden kullanır
// (bkz. `utils/chartTheme.ts` — Kanban panosundaki `boardUtils.ts` ile aynı yaklaşım). Her barın
// kimliği zaten Y ekseni etiketinde (aşama adı) doğrudan yazılı olduğundan ayrı bir lejant
// GEREKMEZ (choosing-a-form.md: tek eksende doğrudan etiketlenen kategorik barlar).
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { TooltipContentProps } from 'recharts'
import { EmptyState, Skeleton } from '../../../components/ui'
import { formatMoney, formatMoneyCompact, formatNumber } from '../../../lib/money'
import { useChartTheme } from '../utils/chartTheme'
import { stageLabel } from '../../deals/utils/stageLabel'
import type { FunnelStage } from '../types'

const CHART_HEIGHT = 280

/** Recharts eksen/bar uzunluğu hesaplaması sayısal bir alan gerektirir; `value` API
 * sözleşmesinde string (bkz. görev tanımı). Bu yalnızca ÇİZİM için bir dönüşümdür — görüntülenen
 * metin her yerde orijinal string'ten `formatMoney`/`formatMoneyCompact` ile üretilir, backend'in
 * topladığı tutar üzerinde YENİDEN toplama yapılmaz.
 *
 * `_label`: Recharts'ın `dataKey` API'si bir ALAN ADI bekler, fonksiyon KABUL ETMEZ — bu yüzden
 * çevrilmiş etiket (`stageLabel`) burada, veri hazırlanırken önceden hesaplanıp veriye eklenir
 * (bkz. `stage_name` yerine `_label` kullanan Y ekseni aşağıda). Modül seviyesinde `t()`
 * SAKLANMAZ: bu fonksiyon her render'da güncel `t` ile YENİDEN çağrılır (bkz. `useMemo` deps). */
type FunnelChartDatum = FunnelStage & { _numericValue: number; _label: string }

function toChartData(stages: FunnelStage[], t: TFunction): FunnelChartDatum[] {
  return stages.map((stage) => ({
    ...stage,
    _numericValue: Number(stage.value) || 0,
    _label: stageLabel(t, { name: stage.stage_name, name_key: stage.stage_name_key }),
  }))
}

export type SalesFunnelProps = {
  stages: FunnelStage[] | undefined
  isLoading: boolean
  /** Görüntü para birimi (`rate_info.display_currency`) — Faz 14 / İz E. Backend zaten bu
   *  birimde döner, burada yeniden dönüştürme yapılmaz. `rate_info` yoksa (ilk yükleme) temel
   *  para birimine (TRY) düşülür. */
  currency?: string
}

// Recharts 3.x'te `content={<FunnelTooltip />}` (element) artık `TooltipContentProps`e karşı tip
// kontrolü geçmiyor — Recharts'ın enjekte ettiği prop'lar (`active`/`payload`/`coordinate`/...)
// element üzerinde ZORUNLU alanlar olarak görünüyor. Çözüm: `content`e ReactElement değil bir
// render FONKSİYONU verilir (aşağıda `<Tooltip content={(props) => <FunnelTooltip {...props} />} />`),
// ve bileşenin kendi prop tipi `Partial<TooltipContentProps>` yapılır — Recharts'ın enjekte ettiği
// alanlar burada zaten hepsi opsiyonel tanımlı (bkz. `node_modules/recharts/types/component/
// Tooltip.d.ts`), `Partial` yalnızca aynı opsiyonelliği bizim tipimizde de garanti eder.
type FunnelTooltipProps = Partial<TooltipContentProps> & {
  /** Görüntü para birimi (`rate_info.display_currency`) — Faz 14 / İz E. */
  currency: string
}

function FunnelTooltip({ active, payload, currency }: FunnelTooltipProps) {
  const { t } = useTranslation('dashboard')
  const theme = useChartTheme()
  if (!active || !payload?.length) return null
  const stage = payload[0]?.payload as FunnelChartDatum | undefined
  if (!stage) return null

  return (
    <div
      className="rounded-md border px-3 py-2 text-xs shadow-popover"
      style={{ background: theme.surface, borderColor: theme.border, color: theme.fg }}
    >
      <p className="mb-1 font-medium">{stage._label}</p>
      <p>
        <span className="font-semibold">{formatMoney(stage.value, currency)}</span>
        <span style={{ color: theme.fgMuted }}>
          {' '}
          · {t('dashboard:salesFunnel.tooltipDealsCount', { value: formatNumber(stage.count) })}
        </span>
      </p>
    </div>
  )
}

export function SalesFunnel({ stages, isLoading, currency = 'TRY' }: SalesFunnelProps) {
  const { t, i18n } = useTranslation('dashboard')
  const theme = useChartTheme()
  // `i18n.language` deps'te KASITLI: `t` referansı dil değişiminde her zaman değişmeyebilir,
  // `_label`in donmaması (bu fazda tekrarlanan hata sınıfı) yalnızca bu sayede garanti olur.
  const chartData = useMemo(() => toChartData(stages ?? [], t), [stages, t, i18n.language])

  if (isLoading) {
    return <Skeleton variant="rect" height={CHART_HEIGHT} className="w-full" />
  }

  if (!stages || stages.length === 0) {
    return (
      <div style={{ height: CHART_HEIGHT }} className="flex items-center justify-center">
        <EmptyState
          title={t('dashboard:salesFunnel.emptyTitle')}
          description={t('dashboard:salesFunnel.emptyDescription')}
        />
      </div>
    )
  }

  return (
    <div className="w-full overflow-x-auto">
      <div style={{ height: CHART_HEIGHT, minWidth: 420 }}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart
            data={chartData}
            layout="vertical"
            margin={{ top: 4, right: 56, bottom: 4, left: 4 }}
            barCategoryGap={10}
          >
            <XAxis type="number" hide />
            <YAxis
              type="category"
              dataKey="_label"
              width={140}
              tickLine={false}
              axisLine={false}
              tick={{ fill: theme.axisText, fontSize: 12 }}
            />
            <Tooltip cursor={{ fill: theme.grid }} content={(props) => <FunnelTooltip {...props} currency={currency} />} />
            <Bar dataKey="_numericValue" radius={[0, 4, 4, 0]} maxBarSize={24} isAnimationActive={false}>
              {chartData.map((stage) => (
                <Cell key={stage.stage_id} fill={theme.token(stage.color)} />
              ))}
              {/* "Bars → value at the tip" (marks-and-anatomy.md) — para olduğundan ham sayı
                  değil, `formatMoneyCompact` ile basılır; etiket metni veri rengini DEĞİL metin
                  tonunu giyer (`theme.fgMuted`). */}
              <LabelList
                dataKey="_numericValue"
                position="right"
                formatter={(value: unknown) => formatMoneyCompact(value as number, currency)}
                style={{ fill: theme.fgMuted, fontSize: 12 }}
              />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  )
}
