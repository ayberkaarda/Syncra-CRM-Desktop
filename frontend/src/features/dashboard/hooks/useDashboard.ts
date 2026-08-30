// React Query kancaları — `api.ts`teki fetcher'ları sarar. `placeholderData: keepPreviousData`
// tarih aralığı değiştiğinde grafiklerin çökmesini önler (interaction.md §"Refetch keeps the
// frame" — yeniden yüklenirken önceki render azaltılmış opaklıkla kalır, iskelete düşmez);
// bileşenler bunun için `isFetching` (arka plan yenilemesi) ile `isLoading` (ilk yükleme) ayrımını
// kullanır.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  dashboardKeys,
  fetchDashboardFunnel,
  fetchDashboardKpis,
  fetchDashboardRecentActivities,
  fetchDashboardRevenueTrend,
  fetchDashboardTaskSummary,
} from '../api'
import type { DateRangeParams, RevenueTrendGroupBy } from '../types'

export function useDashboardKpis(params: DateRangeParams) {
  return useQuery({
    queryKey: dashboardKeys.kpis(params),
    queryFn: () => fetchDashboardKpis(params),
    placeholderData: keepPreviousData,
  })
}

export function useDashboardFunnel(params: DateRangeParams) {
  return useQuery({
    queryKey: dashboardKeys.funnel(params),
    queryFn: () => fetchDashboardFunnel(params),
    placeholderData: keepPreviousData,
  })
}

export function useDashboardRevenueTrend(
  params: DateRangeParams,
  groupBy: RevenueTrendGroupBy = 'day',
) {
  return useQuery({
    queryKey: dashboardKeys.revenueTrend({ ...params, group_by: groupBy }),
    queryFn: () => fetchDashboardRevenueTrend({ ...params, group_by: groupBy }),
    placeholderData: keepPreviousData,
  })
}

export function useDashboardRecentActivities(limit = 10) {
  return useQuery({
    queryKey: dashboardKeys.recentActivities(limit),
    queryFn: () => fetchDashboardRecentActivities(limit),
    placeholderData: keepPreviousData,
  })
}

export function useDashboardTaskSummary() {
  return useQuery({
    queryKey: dashboardKeys.taskSummaryPrefix,
    queryFn: fetchDashboardTaskSummary,
    placeholderData: keepPreviousData,
  })
}
