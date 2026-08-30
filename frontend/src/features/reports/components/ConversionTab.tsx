// Dönüşüm sekmesi — `ConversionReport::run()` bir aşama listesi DEĞİL, dönem içinde
// OLUŞTURULMUŞ lead kohortunun tek bir özeti döner: `total_leads`, `converted_count`,
// `conversion_rate`, `avg_days_to_convert` (dönüşüm yoksa `null`) ve durum dağılımı
// (`by_status`). Üstte hero benzeri özet (choosing-a-form.md: "The one number a dashboard leads
// with"), altında durum dağılımı bar grafiği — sıra ORDİNAL (`LEAD_STATUS_ORDER`, `sort={false}`),
// renk/etiket kimliği Müşteri Adayları modülündeki `STATUS_LABELS`/`STATUS_BADGE_VARIANT`den
// YENİDEN KULLANILIR (kopyalanmaz) — aynı durum her yerde aynı renk/adı taşısın diye.
import { useTranslation } from 'react-i18next'
import { Badge, Table, TBody, Td, THead, Th, Tr } from '../../../components/ui'
import { STATUS_BADGE_VARIANT, STATUS_LABEL_KEY } from '../../leads/utils'
import { formatNumber } from '../../../lib/money'
import { useChartTheme } from '../../dashboard/utils/chartTheme'
import { useConversion } from '../hooks/useReports'
import { LEAD_STATUS_ORDER } from '../utils'
import { RankingBarChart } from './RankingBarChart'
import type { DateRangeParams, LeadStatus } from '../types'

export type ConversionTabProps = {
  dateRange: DateRangeParams
}

type StatusRow = {
  status: LeadStatus
  count: number
}

export function ConversionTab({ dateRange }: ConversionTabProps) {
  const { t } = useTranslation(['reports', 'enums'])
  const result = useConversion(dateRange)
  // Tek `JsonResource` sarmalaması (bkz. `types.ts` başındaki SARMALAMA NOTU): özet
  // `result.data.data`de — satır dizisi YOK.
  const body = result.data?.data
  const theme = useChartTheme()

  const statusRows: StatusRow[] = body
    ? LEAD_STATUS_ORDER.map((status) => ({ status, count: body.by_status[status] ?? 0 }))
    : []

  return (
    <div className="flex flex-col gap-4">
      {body && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <SummaryStat label={t('reports:conversion.totalLeads')} value={formatNumber(body.total_leads)} />
          <SummaryStat label={t('reports:conversion.converted')} value={formatNumber(body.converted_count)} />
          <SummaryStat label={t('reports:conversion.conversionRate')} value={`${formatNumber(body.conversion_rate, 1)}%`} />
          <SummaryStat
            label={t('reports:conversion.avgConversionTime')}
            value={
              body.avg_days_to_convert === null
                ? '—'
                : t('reports:conversion.avgConversionTimeValue', { days: formatNumber(body.avg_days_to_convert, 1) })
            }
          />
        </div>
      )}

      <RankingBarChart<StatusRow>
        data={statusRows}
        isLoading={result.isLoading}
        sort={false}
        getKey={(row) => row.status}
        getLabel={(row) => t(STATUS_LABEL_KEY[row.status], { ns: 'enums' })}
        getValue={(row) => row.count}
        formatValue={(value) => formatNumber(value)}
        getColor={(row) => theme.token(STATUS_BADGE_VARIANT[row.status])}
        tooltipExtra={(row) =>
          body && body.total_leads > 0
            ? <>{t('reports:conversion.tooltipShare', { value: formatNumber((row.count / body.total_leads) * 100, 1) })}</>
            : null
        }
        emptyTitle={t('reports:conversion.emptyTitle')}
        emptyDescription={t('reports:conversion.emptyDescription')}
      />

      <Table>
        <THead>
          <Tr>
            <Th>{t('reports:conversion.columns.status')}</Th>
            <Th align="right">{t('reports:conversion.columns.count')}</Th>
          </Tr>
        </THead>
        <TBody>
          {statusRows.map((row) => (
            <Tr key={row.status}>
              <Td>
                <Badge variant={STATUS_BADGE_VARIANT[row.status]}>{t(STATUS_LABEL_KEY[row.status], { ns: 'enums' })}</Badge>
              </Td>
              <Td align="right">{formatNumber(row.count)}</Td>
            </Tr>
          ))}
          {!result.isLoading && statusRows.length === 0 && (
            <Tr>
              <Td colSpan={2} align="center" className="py-8 text-fg-muted">
                {t('reports:noRecords')}
              </Td>
            </Tr>
          )}
        </TBody>
      </Table>
    </div>
  )
}

function SummaryStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-border-subtle bg-surface-2 px-4 py-3">
      <p className="text-xs text-fg-muted">{label}</p>
      <p className="text-xl font-semibold text-fg">{value}</p>
    </div>
  )
}
