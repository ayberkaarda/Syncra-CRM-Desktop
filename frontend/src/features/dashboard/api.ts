// Dashboard API katmanı — beş salt-okunur uç. Hata gövdesi diğer modüllerle aynı
// (`{ errors: { message, code, fields? } }`, bkz. `lib/axios.ts`). Sorgu anahtarları `dashboardKeys`
// altında toplanır; `*Prefix` alt anahtarları `useDashboardSocket`in seçici invalidation'ı için
// vardır (tarih aralığından bağımsız, o anahtarla başlayan HER sorguyu geçersiz kılar).
import { api } from '../../lib/axios'
import type {
  DashboardFunnelResponse,
  DashboardKpisResponse,
  DashboardRecentActivitiesResponse,
  DashboardRevenueTrendResponse,
  DashboardTaskSummaryResponse,
  DateRangeParams,
  RevenueTrendGroupBy,
} from './types'

export const dashboardKeys = {
  all: ['dashboard'] as const,
  kpisPrefix: ['dashboard', 'kpis'] as const,
  kpis: (params: DateRangeParams) => ['dashboard', 'kpis', params] as const,
  funnelPrefix: ['dashboard', 'funnel'] as const,
  funnel: (params: DateRangeParams) => ['dashboard', 'funnel', params] as const,
  revenueTrendPrefix: ['dashboard', 'revenue-trend'] as const,
  revenueTrend: (params: DateRangeParams & { group_by: RevenueTrendGroupBy }) =>
    ['dashboard', 'revenue-trend', params] as const,
  recentActivitiesPrefix: ['dashboard', 'recent-activities'] as const,
  recentActivities: (limit: number) => ['dashboard', 'recent-activities', limit] as const,
  taskSummaryPrefix: ['dashboard', 'task-summary'] as const,
}

export async function fetchDashboardKpis(params: DateRangeParams): Promise<DashboardKpisResponse> {
  const { data } = await api.get<DashboardKpisResponse>('/api/dashboard/kpis', { params })
  return data
}

export async function fetchDashboardFunnel(
  params: DateRangeParams,
): Promise<DashboardFunnelResponse> {
  const { data } = await api.get<DashboardFunnelResponse>('/api/dashboard/funnel', { params })
  return data
}

export async function fetchDashboardRevenueTrend(
  params: DateRangeParams & { group_by: RevenueTrendGroupBy },
): Promise<DashboardRevenueTrendResponse> {
  const { data } = await api.get<DashboardRevenueTrendResponse>('/api/dashboard/revenue-trend', {
    params,
  })
  return data
}

export async function fetchDashboardRecentActivities(
  limit: number,
): Promise<DashboardRecentActivitiesResponse> {
  const { data } = await api.get<DashboardRecentActivitiesResponse>(
    '/api/dashboard/recent-activities',
    { params: { limit } },
  )
  return data
}

export async function fetchDashboardTaskSummary(): Promise<DashboardTaskSummaryResponse> {
  const { data } = await api.get<DashboardTaskSummaryResponse>('/api/dashboard/task-summary')
  return data
}
