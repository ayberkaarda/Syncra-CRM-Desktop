// Gösterge paneli — Faz 11. KPI kartları satırı, satış hunisi, gelir trendi, son aktiviteler ve
// görev özeti; tek bir tarih aralığı seçici tüm bileşenleri besler (görev tanımı §ÜRETECEKLERİN).
// `useDashboardSocket` burada çağrılır: dashboard sorguları yalnızca bu sayfa mount'luyken
// abone kalır, sayfadan ayrılınca hook'un temizleyicisi `releaseChannel()` çağırır — kanalın
// kendisi PAYLAŞILAN, referans sayan `src/lib/channelRegistry.ts` üzerinden yönetilir (diğer
// canlılık kancalarıyla aynı desen, bkz. `features/dashboard/hooks/useDashboardSocket.ts`).
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, CardHeader, Tab, TabList, Tabs } from '../components/ui'
import {
  KpiCardGrid,
  RecentActivities,
  RevenueTrendChart,
  SalesFunnel,
  TaskSummary,
  useDashboardFunnel,
  useDashboardKpis,
  useDashboardRecentActivities,
  useDashboardRevenueTrend,
  useDashboardSocket,
  useDashboardTaskSummary,
} from '../features/dashboard'
import type { RevenueTrendGroupBy } from '../features/dashboard'
import { DateRangeFilter, defaultDateRange, RateInfoNote } from '../features/reports'

export function DashboardPage() {
  const { t } = useTranslation('common')
  useDashboardSocket()

  const [dateRange, setDateRange] = useState(defaultDateRange)
  const [groupBy, setGroupBy] = useState<RevenueTrendGroupBy>('day')

  const kpisResult = useDashboardKpis(dateRange)
  const funnelResult = useDashboardFunnel(dateRange)
  const revenueTrendResult = useDashboardRevenueTrend(dateRange, groupBy)
  const recentActivitiesResult = useDashboardRecentActivities(10)
  const taskSummaryResult = useDashboardTaskSummary()

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-fg">{t('nav.dashboard')}</h1>
        <DateRangeFilter from={dateRange.from} to={dateRange.to} onChange={setDateRange} />
      </div>

      <KpiCardGrid
        kpis={kpisResult.data?.data}
        isLoading={kpisResult.isLoading}
        currency={kpisResult.data?.rate_info.display_currency}
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="min-w-0 lg:col-span-2">
          <CardHeader
            title={t('pages.dashboard.revenueTrend.title')}
            subtitle={t('pages.dashboard.revenueTrend.subtitle')}
            action={
              <Tabs value={groupBy} onValueChange={(v) => setGroupBy(v as RevenueTrendGroupBy)} variant="segment">
                <TabList>
                  <Tab value="day">{t('pages.dashboard.revenueTrend.day')}</Tab>
                  <Tab value="week">{t('pages.dashboard.revenueTrend.week')}</Tab>
                  <Tab value="month">{t('pages.dashboard.revenueTrend.month')}</Tab>
                </TabList>
              </Tabs>
            }
          />
          <CardBody>
            <RevenueTrendChart
              points={revenueTrendResult.data?.data}
              isLoading={revenueTrendResult.isLoading}
              groupBy={groupBy}
              currency={revenueTrendResult.data?.rate_info.display_currency}
            />
          </CardBody>
        </Card>

        <Card className="min-w-0">
          <CardHeader
            title={t('pages.dashboard.salesFunnel.title')}
            subtitle={t('pages.dashboard.salesFunnel.subtitle')}
          />
          <CardBody>
            <SalesFunnel
              stages={funnelResult.data?.data}
              isLoading={funnelResult.isLoading}
              currency={funnelResult.data?.rate_info.display_currency}
            />
          </CardBody>
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="min-w-0 lg:col-span-2">
          <CardHeader title={t('pages.dashboard.recentActivities.title')} />
          <CardBody>
            <RecentActivities activities={recentActivitiesResult.data?.data} isLoading={recentActivitiesResult.isLoading} />
          </CardBody>
        </Card>

        <Card className="min-w-0">
          <CardHeader title={t('pages.dashboard.taskSummary.title')} />
          <CardBody>
            <TaskSummary summary={taskSummaryResult.data?.data} isLoading={taskSummaryResult.isLoading} />
          </CardBody>
        </Card>
      </div>

      {/* Faz 14 / İz E (§2.4/§2.6) — dashboard'un ALTINDA TEK bir kur dipnotu: KPI kartları
          bu sayfanın "hangi rakama önce bakılır" özeti olduğundan `kpisResult.rate_info`
          kaynak alınır. Funnel/gelir-trendi kendi `rate_info`sunu taşır ama aynı tarih
          aralığı + aynı `preferred_currency`den geldiği için pratikte aynı kur/tarihi
          yansıtır — ayrı bir dipnot tekrarı okunabilirliği azaltırdı. */}
      <RateInfoNote rateInfo={kpisResult.data?.rate_info} />
    </div>
  )
}
