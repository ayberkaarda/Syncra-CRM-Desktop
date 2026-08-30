// Kullanıcı Performansı sekmesi — gelire göre sıralanmış bar grafiği + tam tablo.
import { useTranslation } from 'react-i18next'
import { Table, TBody, Td, THead, Th, Tr } from '../../../components/ui'
import { formatMoney, formatNumber } from '../../../lib/money'
import { useUserPerformance } from '../hooks/useReports'
import { RankingBarChart } from './RankingBarChart'
import { RateInfoNote } from './RateInfoNote'
import type { DateRangeParams, UserPerformanceRow } from '../types'

export type UserPerformanceTabProps = {
  dateRange: DateRangeParams
}

export function UserPerformanceTab({ dateRange }: UserPerformanceTabProps) {
  const { t } = useTranslation('reports')
  const result = useUserPerformance(dateRange)
  // Tek `JsonResource` sarmalaması (bkz. `types.ts` başındaki SARMALAMA NOTU): satırlar
  // `result.data.data.data`de.
  const rows = result.data?.data.data ?? []
  const unknownUser = t('reports:userPerformance.unknownUser')
  // Backend zaten bu para biriminde döner (§2.4) — bkz. `SalesPerformanceTab.tsx` aynı yorum.
  const currency = result.data?.rate_info.display_currency ?? 'TRY'

  return (
    <div className="flex flex-col gap-4">
      <RankingBarChart<UserPerformanceRow>
        data={rows}
        isLoading={result.isLoading}
        getKey={(row) => row.user_id}
        getLabel={(row) => row.user_name ?? unknownUser}
        getValue={(row) => Number(row.revenue) || 0}
        formatValue={(value) => formatMoney(value, currency)}
        tooltipExtra={(row) => (
          <>
            {t('reports:userPerformance.tooltipWonConversion', {
              won: formatNumber(row.won_count),
              rate: formatNumber(row.conversion_rate, 1),
            })}
          </>
        )}
        emptyTitle={t('reports:userPerformance.emptyTitle')}
        emptyDescription={t('reports:userPerformance.emptyDescription')}
      />

      <Table>
        <THead>
          <Tr>
            <Th>{t('reports:userPerformance.columns.user')}</Th>
            <Th align="right">{t('reports:userPerformance.columns.revenue')}</Th>
            <Th align="right">{t('reports:userPerformance.columns.won')}</Th>
            <Th align="right">{t('reports:userPerformance.columns.lost')}</Th>
            <Th align="right">{t('reports:userPerformance.columns.openDeals')}</Th>
            <Th align="right">{t('reports:userPerformance.columns.openDealsValue')}</Th>
            <Th align="right">{t('reports:userPerformance.columns.conversion')}</Th>
            <Th align="right">{t('reports:userPerformance.columns.avgDeal')}</Th>
            <Th align="right">{t('reports:userPerformance.columns.activities')}</Th>
          </Tr>
        </THead>
        <TBody>
          {rows.map((row) => (
            <Tr key={row.user_id}>
              <Td>{row.user_name ?? unknownUser}</Td>
              <Td align="right">{formatMoney(row.revenue, currency)}</Td>
              <Td align="right">{formatNumber(row.won_count)}</Td>
              <Td align="right">{formatNumber(row.lost_count)}</Td>
              <Td align="right">{formatNumber(row.open_deals_count)}</Td>
              <Td align="right">{formatMoney(row.open_deals_value, currency)}</Td>
              <Td align="right">{formatNumber(row.conversion_rate, 1)}%</Td>
              <Td align="right">{formatMoney(row.avg_deal_size, currency)}</Td>
              <Td align="right">{formatNumber(row.activities_count)}</Td>
            </Tr>
          ))}
          {!result.isLoading && rows.length === 0 && (
            <Tr>
              <Td colSpan={9} align="center" className="py-8 text-fg-muted">
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
