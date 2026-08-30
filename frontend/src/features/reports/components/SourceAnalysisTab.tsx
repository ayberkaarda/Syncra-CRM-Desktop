// Kaynak Analizi sekmesi — gelire göre sıralanmış kaynak bar grafiği + tam tablo.
import { useTranslation } from 'react-i18next'
import { Table, TBody, Td, THead, Th, Tr } from '../../../components/ui'
import { formatMoney, formatNumber } from '../../../lib/money'
import { leadSourceLabel } from '../../leads/utils'
import { useSourceAnalysis } from '../hooks/useReports'
import { RankingBarChart } from './RankingBarChart'
import { RateInfoNote } from './RateInfoNote'
import type { DateRangeParams, SourceAnalysisRow } from '../types'

export type SourceAnalysisTabProps = {
  dateRange: DateRangeParams
}

export function SourceAnalysisTab({ dateRange }: SourceAnalysisTabProps) {
  const { t } = useTranslation(['reports', 'enums'])
  const result = useSourceAnalysis(dateRange)
  // Tek `JsonResource` sarmalaması (bkz. `types.ts` başındaki SARMALAMA NOTU): satırlar
  // `result.data.data.data`de.
  const rows = result.data?.data.data ?? []
  const unknownSource = t('reports:sourceAnalysis.unknownSource')
  const sourceLabel = (source: string) => (source ? leadSourceLabel(source, t) : unknownSource)
  // Backend zaten bu para biriminde döner (§2.4) — bkz. `SalesPerformanceTab.tsx` aynı yorum.
  const currency = result.data?.rate_info.display_currency ?? 'TRY'

  return (
    <div className="flex flex-col gap-4">
      <RankingBarChart<SourceAnalysisRow>
        data={rows}
        isLoading={result.isLoading}
        getKey={(row) => row.source}
        getLabel={(row) => sourceLabel(row.source)}
        getValue={(row) => Number(row.revenue) || 0}
        formatValue={(value) => formatMoney(value, currency)}
        tooltipExtra={(row) => (
          <>
            {t('reports:sourceAnalysis.tooltipLeadsConversion', {
              leads: formatNumber(row.leads_count),
              rate: formatNumber(row.conversion_rate, 1),
            })}
          </>
        )}
        emptyTitle={t('reports:sourceAnalysis.emptyTitle')}
        emptyDescription={t('reports:sourceAnalysis.emptyDescription')}
      />

      <Table>
        <THead>
          <Tr>
            <Th>{t('reports:sourceAnalysis.columns.source')}</Th>
            <Th align="right">{t('reports:sourceAnalysis.columns.leads')}</Th>
            <Th align="right">{t('reports:sourceAnalysis.columns.converted')}</Th>
            <Th align="right">{t('reports:sourceAnalysis.columns.conversion')}</Th>
            <Th align="right">{t('reports:sourceAnalysis.columns.revenue')}</Th>
          </Tr>
        </THead>
        <TBody>
          {rows.map((row) => (
            <Tr key={row.source}>
              <Td>{sourceLabel(row.source)}</Td>
              <Td align="right">{formatNumber(row.leads_count)}</Td>
              <Td align="right">{formatNumber(row.converted_count)}</Td>
              <Td align="right">{formatNumber(row.conversion_rate, 1)}%</Td>
              <Td align="right">{formatMoney(row.revenue, currency)}</Td>
            </Tr>
          ))}
          {!result.isLoading && rows.length === 0 && (
            <Tr>
              <Td colSpan={5} align="center" className="py-8 text-fg-muted">
                {t('reports:noRecords')}
              </Td>
            </Tr>
          )}
        </TBody>
      </Table>

      <RateInfoNote rateInfo={result.data?.rate_info} />
    </div>
  )
}
