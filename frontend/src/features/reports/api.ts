// Raporlar API katmanı — dört salt-okunur rapor ucu + dışa aktarma URL'i. Hata gövdesi diğer
// modüllerle aynı (`{ errors: { message, code, fields? } }`, bkz. `lib/axios.ts`).
import { api } from '../../lib/axios'
import type {
  ConversionResponse,
  DateRangeParams,
  ReportExportFormat,
  ReportSlug,
  SalesPerformanceGroupBy,
  SalesPerformanceResponse,
  SourceAnalysisResponse,
  UserPerformanceResponse,
} from './types'

export const reportsKeys = {
  all: ['reports'] as const,
  salesPerformance: (params: DateRangeParams & { group_by: SalesPerformanceGroupBy; user_id?: number }) =>
    ['reports', 'sales-performance', params] as const,
  userPerformance: (params: DateRangeParams) => ['reports', 'user-performance', params] as const,
  sourceAnalysis: (params: DateRangeParams) => ['reports', 'source-analysis', params] as const,
  conversion: (params: DateRangeParams) => ['reports', 'conversion', params] as const,
}

export async function fetchSalesPerformance(
  params: DateRangeParams & { group_by: SalesPerformanceGroupBy; user_id?: number },
): Promise<SalesPerformanceResponse> {
  const { data } = await api.get<SalesPerformanceResponse>('/api/reports/sales-performance', {
    params,
  })
  return data
}

export async function fetchUserPerformance(
  params: DateRangeParams,
): Promise<UserPerformanceResponse> {
  const { data } = await api.get<UserPerformanceResponse>('/api/reports/user-performance', {
    params,
  })
  return data
}

export async function fetchSourceAnalysis(
  params: DateRangeParams,
): Promise<SourceAnalysisResponse> {
  const { data } = await api.get<SourceAnalysisResponse>('/api/reports/source-analysis', {
    params,
  })
  return data
}

export async function fetchConversion(params: DateRangeParams): Promise<ConversionResponse> {
  const { data } = await api.get<ConversionResponse>('/api/reports/conversion', { params })
  return data
}

export type ReportUserOption = {
  id: number
  name: string
}

type UsersIndexResponse = {
  data: Array<{ id: number; name: string; email: string }>
}

/**
 * Satış Performansı sekmesindeki "Kullanıcı" filtresi için kullanıcı listesi. `users.view` izni
 * olmayabilir — 403 burada fırlatılır, `useReportUserOptions` `isError`ı görüp filtreyi sessizce
 * gizler (Loglar modülündeki `useLogUserOptions` ile aynı desen).
 */
export async function fetchReportUserOptions(): Promise<ReportUserOption[]> {
  const { data } = await api.get<UsersIndexResponse>('/api/users', {
    params: { per_page: 100, sort: 'name' },
  })
  return data.data.map((user) => ({ id: user.id, name: user.name }))
}

export type ReportExportFilters = DateRangeParams & {
  group_by?: SalesPerformanceGroupBy
  user_id?: number
}

/**
 * `GET /api/reports/export` tam URL'i — Loglar modülündeki dışa aktarma deseniyle AYNI
 * (`features/logs/api/logsApi.ts` → `buildExportUrl`): cookie tabanlı kimlik doğrulama
 * kullandığımızdan `fetch`/blob yerine normal bir GET navigasyonuyla (gizli `<iframe>`, bkz.
 * `components/ExportButton.tsx`) tetiklenir.
 */
export function buildReportExportUrl(
  report: ReportSlug,
  format: ReportExportFormat,
  filters: ReportExportFilters,
): string {
  const params = new URLSearchParams()
  params.set('report', report)
  params.set('format', format)
  params.set('from', filters.from)
  params.set('to', filters.to)
  if (filters.group_by) params.set('group_by', filters.group_by)
  if (filters.user_id) params.set('user_id', String(filters.user_id))

  const base = api.defaults.baseURL ?? ''
  return `${base}/api/reports/export?${params.toString()}`
}
