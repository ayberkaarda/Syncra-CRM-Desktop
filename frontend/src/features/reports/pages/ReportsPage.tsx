// Raporlar sayfası — dört sekme (Satış Performansı · Kullanıcı Performansı · Kaynak Analizi ·
// Dönüşüm), ortak tarih filtresi, sekme durumu URL'de (`/reports?tab=conversion`). Desen
// `features/logs/pages/LogsPage.tsx` ile aynı: tüm filtre durumu URL query string'inde tutulur,
// sekme değişince o sekmeye özel filtreler (`group_by`, `user_id`) sıfırlanır ama ortak tarih
// aralığı korunur.
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Card, CardBody, CardHeader, Tab, TabList, TabPanel, Tabs } from '../../../components/ui'
import { usePermission } from '../../auth/hooks/usePermission'
import { DateRangeFilter } from '../components/DateRangeFilter'
import { ExportButton } from '../components/ExportButton'
import { ConversionTab } from '../components/ConversionTab'
import { SalesPerformanceTab } from '../components/SalesPerformanceTab'
import { SourceAnalysisTab } from '../components/SourceAnalysisTab'
import { UserPerformanceTab } from '../components/UserPerformanceTab'
import { defaultDateRange } from '../utils'
import type { ReportSlug, SalesPerformanceGroupBy } from '../types'

const VALID_TABS: ReportSlug[] = ['sales-performance', 'user-performance', 'source-analysis', 'conversion']

function tabLabels(t: TFunction): Record<ReportSlug, string> {
  return {
    'sales-performance': t('reports:tabs.salesPerformance'),
    'user-performance': t('reports:tabs.userPerformance'),
    'source-analysis': t('reports:tabs.sourceAnalysis'),
    conversion: t('reports:tabs.conversion'),
  }
}

export function ReportsPage() {
  const { t } = useTranslation('reports')
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()
  const fallback = defaultDateRange()

  const rawTab = searchParams.get('tab')
  const tab: ReportSlug = (VALID_TABS as string[]).includes(rawTab ?? '')
    ? (rawTab as ReportSlug)
    : 'sales-performance'

  const from = searchParams.get('from') || fallback.from
  const to = searchParams.get('to') || fallback.to
  const groupBy = (searchParams.get('group_by') as SalesPerformanceGroupBy) || 'day'
  const userId = searchParams.get('user_id') ?? ''

  function updateParams(patch: Record<string, string | null>) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      return next
    })
  }

  function switchTab(nextTab: string) {
    if (nextTab === tab) return
    // Sekmeye özel filtreler sıfırlanır (bir sonraki sekmenin beklemediği bir parametre 422'ye
    // düşmesin diye) — ortak tarih aralığı korunur. Loglar modülüyle aynı desen.
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      next.set('tab', nextTab)
      next.delete('group_by')
      next.delete('user_id')
      return next
    })
  }

  const dateRange = { from, to }

  const exportFilters =
    tab === 'sales-performance'
      ? { ...dateRange, group_by: groupBy, user_id: userId ? Number(userId) : undefined }
      : dateRange

  const TAB_LABELS = tabLabels(t)

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('reports:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('reports:breadcrumb.reports')}</span>
      </nav>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <DateRangeFilter from={from} to={to} onChange={(range) => updateParams({ from: range.from, to: range.to })} />
        {can('reports.export') && <ExportButton report={tab} filters={exportFilters} />}
      </div>

      <Card>
        <CardHeader title={t('reports:page.title')} subtitle={t('reports:page.subtitle')} />

        <Tabs value={tab} onValueChange={switchTab}>
          <TabList className="px-5 pt-3">
            {VALID_TABS.map((value) => (
              <Tab key={value} value={value}>
                {TAB_LABELS[value]}
              </Tab>
            ))}
          </TabList>

          <CardBody>
            <TabPanel value="sales-performance">
              <SalesPerformanceTab
                dateRange={dateRange}
                groupBy={groupBy}
                onGroupByChange={(v) => updateParams({ group_by: v })}
                userId={userId}
                onUserIdChange={(v) => updateParams({ user_id: v || null })}
              />
            </TabPanel>
            <TabPanel value="user-performance">
              <UserPerformanceTab dateRange={dateRange} />
            </TabPanel>
            <TabPanel value="source-analysis">
              <SourceAnalysisTab dateRange={dateRange} />
            </TabPanel>
            <TabPanel value="conversion">
              <ConversionTab dateRange={dateRange} />
            </TabPanel>
          </CardBody>
        </Tabs>
      </Card>
    </div>
  )
}
