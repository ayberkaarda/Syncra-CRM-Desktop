// React Query kancaları — yalnızca aktif sekmenin sorgusu ağa çıksın diye `enabled` alır (dört
// sekme aynı anda mount edilmiyor ama hook'lar `ReportsPage`de koşulsuz çağrılıyor — Loglar
// modülüyle aynı desen, bkz. `features/logs/api/logsApi.ts`). `keepPreviousData` tarih aralığı
// değiştiğinde grafiğin çökmesini önler.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  fetchConversion,
  fetchReportUserOptions,
  fetchSalesPerformance,
  fetchSourceAnalysis,
  fetchUserPerformance,
  reportsKeys,
} from '../api'
import type { DateRangeParams, SalesPerformanceGroupBy } from '../types'

export function useSalesPerformance(
  params: DateRangeParams & { group_by: SalesPerformanceGroupBy; user_id?: number },
  enabled = true,
) {
  return useQuery({
    queryKey: reportsKeys.salesPerformance(params),
    queryFn: () => fetchSalesPerformance(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useUserPerformance(params: DateRangeParams, enabled = true) {
  return useQuery({
    queryKey: reportsKeys.userPerformance(params),
    queryFn: () => fetchUserPerformance(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useSourceAnalysis(params: DateRangeParams, enabled = true) {
  return useQuery({
    queryKey: reportsKeys.sourceAnalysis(params),
    queryFn: () => fetchSourceAnalysis(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useConversion(params: DateRangeParams, enabled = true) {
  return useQuery({
    queryKey: reportsKeys.conversion(params),
    queryFn: () => fetchConversion(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

/** `retry: false` — 403/izin hatasında hemen `isError`'a düşsün, sayfa bunu görüp kullanıcı
 * filtresini sessizce gizlesin (Loglar modülündeki `useLogUserOptions` ile aynı desen). */
export function useReportUserOptions() {
  return useQuery({
    queryKey: ['reports', 'user-options'] as const,
    queryFn: fetchReportUserOptions,
    staleTime: 5 * 60_000,
    retry: false,
  })
}
