// Dashboard modülü barrel export'u.
export * from './types'
export * from './api'

export { useDashboardKpis, useDashboardFunnel, useDashboardRevenueTrend, useDashboardRecentActivities, useDashboardTaskSummary } from './hooks/useDashboard'
export { useDashboardSocket } from './hooks/useDashboardSocket'

export { KpiCard, KpiCardGrid, KpiCardSkeleton } from './components/KpiCard'
export type { KpiCardProps, KpiCardGridProps } from './components/KpiCard'
export { SalesFunnel } from './components/SalesFunnel'
export type { SalesFunnelProps } from './components/SalesFunnel'
export { RevenueTrendChart } from './components/RevenueTrendChart'
export type { RevenueTrendChartProps } from './components/RevenueTrendChart'
export { RecentActivities } from './components/RecentActivities'
export type { RecentActivitiesProps } from './components/RecentActivities'
export { TaskSummary } from './components/TaskSummary'
export type { TaskSummaryProps } from './components/TaskSummary'

export { useChartTheme, formatRelativeTime } from './utils/chartTheme'
export type { ChartTheme, SemanticColorToken } from './utils/chartTheme'
