// Satış Performansı sekmesi — dönem grubu seçici (Gün/Hafta/Ay) + kullanıcı filtresi + gelir
// trendi grafiği + tablo.
import { useTranslation } from 'react-i18next'
import { Select, Table, Tab, TabList, Tabs, TBody, Td, THead, Th, Tr } from '../../../components/ui'
import { formatMoney, formatNumber } from '../../../lib/money'
import { useReportUserOptions, useSalesPerformance } from '../hooks/useReports'
import { SalesPerformanceChart } from './SalesPerformanceChart'
import { RateInfoNote } from './RateInfoNote'
import type { DateRangeParams, SalesPerformanceGroupBy } from '../types'

export type SalesPerformanceTabProps = {
  dateRange: DateRangeParams
  groupBy: SalesPerformanceGroupBy
  onGroupByChange: (groupBy: SalesPerformanceGroupBy) => void
  userId: string
  onUserIdChange: (userId: string) => void
}

export function SalesPerformanceTab({
  dateRange,
  groupBy,
  onGroupByChange,
  userId,
  onUserIdChange,
}: SalesPerformanceTabProps) {
  const { t } = useTranslation('reports')
  const userOptionsQuery = useReportUserOptions()
  const userOptions = userOptionsQuery.data ?? []
  const showUserFilter = !userOptionsQuery.isError

  const result = useSalesPerformance({
    ...dateRange,
    group_by: groupBy,
    user_id: userId ? Number(userId) : undefined,
  })
  // Tek `JsonResource` sarmalaması (bkz. `types.ts` başındaki SARMALAMA NOTU): satırlar
  // `result.data.data.data`de, toplamlar `result.data.data.totals`de.
  const body = result.data?.data
  const rows = body?.data ?? []
  const totals = body?.totals
  // Backend zaten bu para biriminde döner (§2.4) — burada YENİDEN dönüştürme yapılmaz,
  // yalnızca `formatMoney`ye DOĞRU sembolü söylüyoruz. `rate_info` yoksa (ilk yükleme) temel
  // para birimine (TRY) düşülür — `formatMoney`nin kendi varsayılanıyla aynı.
  const currency = result.data?.rate_info.display_currency ?? 'TRY'

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-3">
        <Tabs value={groupBy} onValueChange={(v) => onGroupByChange(v as SalesPerformanceGroupBy)} variant="segment">
          <TabList>
            <Tab value="day">{t('reports:groupBy.day')}</Tab>
            <Tab value="week">{t('reports:groupBy.week')}</Tab>
            <Tab value="month">{t('reports:groupBy.month')}</Tab>
          </TabList>
        </Tabs>

        {showUserFilter && (
          <div className="w-full sm:w-56">
            <Select
              value={userId}
              onChange={(e) => onUserIdChange(e.target.value)}
              options={[
                { value: '', label: t('reports:salesPerformance.allUsers') },
                ...userOptions.map((u) => ({ value: String(u.id), label: u.name })),
              ]}
              aria-label={t('reports:salesPerformance.userFilterAria')}
            />
          </div>
        )}
      </div>

      <SalesPerformanceChart rows={rows} isLoading={result.isLoading} groupBy={groupBy} currency={currency} />

      <Table>
        <THead>
          <Tr>
            <Th>{t('reports:salesPerformance.columns.period')}</Th>
            <Th align="right">{t('reports:salesPerformance.columns.revenue')}</Th>
            <Th align="right">{t('reports:salesPerformance.columns.won')}</Th>
            <Th align="right">{t('reports:salesPerformance.columns.lost')}</Th>
            <Th align="right">{t('reports:salesPerformance.columns.deals')}</Th>
          </Tr>
        </THead>
        <TBody>
          {rows.map((row) => (
            <Tr key={row.period}>
              <Td>{row.period}</Td>
              <Td align="right">{formatMoney(row.revenue, currency)}</Td>
              <Td align="right">{formatNumber(row.won_count)}</Td>
              <Td align="right">{formatNumber(row.lost_count)}</Td>
              <Td align="right">{formatNumber(row.deals_count)}</Td>
            </Tr>
          ))}
          {!result.isLoading && rows.length === 0 && (
            <Tr>
              <Td colSpan={5} align="center" className="py-8 text-fg-muted">
                {t('reports:noRecords')}
              </Td>
            </Tr>
          )}
          {totals && rows.length > 0 && (
            <Tr className="bg-surface-2 font-medium hover:bg-surface-2">
              <Td>{t('reports:salesPerformance.totalsRow')}</Td>
              <Td align="right">{formatMoney(totals.revenue, currency)}</Td>
              <Td align="right">{formatNumber(totals.won_count)}</Td>
              <Td align="right">{formatNumber(totals.lost_count)}</Td>
              <Td align="right">{formatNumber(totals.deals_count)}</Td>
            </Tr>
          )}
        </TBody>
      </Table>

      <RateInfoNote rateInfo={result.data?.rate_info} />
    </div>
  )
}
