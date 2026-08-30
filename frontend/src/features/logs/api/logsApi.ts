// Loglar API katmanı — üç listeleme ucu + dışa aktarma URL'i + filtre dropdown'ı için
// kullanıcı listesi. Hata gövdesi diğer uçlarla aynı: `{ errors: { message, code, fields? } }`
// (bkz. `lib/axios.ts`). Sorgular salt-okunur; mutasyon/toast yok.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/axios'
import type {
  ActivitiesQuery,
  ActivityLog,
  ExportFormat,
  ExportType,
  LogsListResponse,
  LogUserOption,
  PageVisitLog,
  PageVisitsQuery,
  SessionLog,
  SessionsQuery,
} from '../types'

export const logsKeys = {
  all: ['logs'] as const,
  sessions: (query: SessionsQuery) => ['logs', 'sessions', query] as const,
  pageVisits: (query: PageVisitsQuery) => ['logs', 'page-visits', query] as const,
  activities: (query: ActivitiesQuery) => ['logs', 'activities', query] as const,
  userOptions: ['logs', 'user-options'] as const,
}

function commonParams(query: SessionsQuery | PageVisitsQuery | ActivitiesQuery) {
  return {
    page: query.page,
    per_page: query.per_page,
    sort: query.sort || undefined,
    q: query.q || undefined,
    'filter[user_id]': query.user_id,
    'filter[from]': query.from || undefined,
    'filter[to]': query.to || undefined,
  }
}

async function fetchSessions(query: SessionsQuery): Promise<LogsListResponse<SessionLog>> {
  const { data } = await api.get<LogsListResponse<SessionLog>>('/api/logs/sessions', {
    params: {
      ...commonParams(query),
      'filter[event]': query.event || undefined,
    },
  })
  return data
}

async function fetchPageVisits(query: PageVisitsQuery): Promise<LogsListResponse<PageVisitLog>> {
  const { data } = await api.get<LogsListResponse<PageVisitLog>>('/api/logs/page-visits', {
    params: commonParams(query),
  })
  return data
}

async function fetchActivities(query: ActivitiesQuery): Promise<LogsListResponse<ActivityLog>> {
  const { data } = await api.get<LogsListResponse<ActivityLog>>('/api/logs/activities', {
    params: {
      ...commonParams(query),
      'filter[event]': query.event || undefined,
      'filter[subject_type]': query.subject_type || undefined,
    },
  })
  return data
}

// `enabled` — yalnızca aktif sekmenin sorgusu ağa çıksın diye (4 sekme aynı anda mount
// edilmiyor ama hook'lar `LogsPage`'de koşulsuz çağrılıyor; React hook kuralları gereği).
export function useSessionLogs(query: SessionsQuery, enabled = true) {
  return useQuery({
    queryKey: logsKeys.sessions(query),
    queryFn: () => fetchSessions(query),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function usePageVisitLogs(query: PageVisitsQuery, enabled = true) {
  return useQuery({
    queryKey: logsKeys.pageVisits(query),
    queryFn: () => fetchPageVisits(query),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useActivityLogs(query: ActivitiesQuery, enabled = true) {
  return useQuery({
    queryKey: logsKeys.activities(query),
    queryFn: () => fetchActivities(query),
    placeholderData: keepPreviousData,
    enabled,
  })
}

type UsersIndexResponse = {
  data: Array<{ id: number; name: string; email: string }>
}

async function fetchLogUserOptions(): Promise<LogUserOption[]> {
  // `users.view` izni olmayabilir — 403 burada fırlatılır, çağıran taraf
  // (`useLogUserOptions`) `isError`'ı görüp filtreyi sessizce gizler.
  const { data } = await api.get<UsersIndexResponse>('/api/users', {
    params: { per_page: 100, sort: 'name' },
  })
  return data.data.map((user) => ({ id: user.id, name: user.name, email: user.email }))
}

/**
 * Filtre çubuğundaki "Kullanıcı" seçicisi için tüm kullanıcı listesi.
 * `retry: false` — 403/izin hatasında hemen `isError`'a düşsün, gereksiz
 * yeniden deneme yapmasın; sayfa bunu görüp seçiciyi gizler.
 */
export function useLogUserOptions() {
  return useQuery({
    queryKey: logsKeys.userOptions,
    queryFn: fetchLogUserOptions,
    staleTime: 5 * 60_000,
    retry: false,
  })
}

export type ExportFilters = {
  q?: string
  sort?: string
  user_id?: number
  from?: string
  to?: string
  event?: string
  subject_type?: string
}

/**
 * `GET /api/logs/export` tam URL'i — cookie tabanlı kimlik doğrulama
 * kullandığımızdan `fetch`/blob yerine normal bir GET navigasyonuyla
 * (gizli `<iframe>`, bkz. `ExportMenu.tsx`) tetiklenir.
 */
export function buildExportUrl(
  type: ExportType,
  format: ExportFormat,
  filters: ExportFilters,
): string {
  const params = new URLSearchParams()
  params.set('type', type)
  params.set('format', format)
  if (filters.q) params.set('q', filters.q)
  if (filters.sort) params.set('sort', filters.sort)
  if (filters.user_id) params.set('filter[user_id]', String(filters.user_id))
  if (filters.from) params.set('filter[from]', filters.from)
  if (filters.to) params.set('filter[to]', filters.to)
  if (filters.event) params.set('filter[event]', filters.event)
  if (type === 'activities' && filters.subject_type)
    params.set('filter[subject_type]', filters.subject_type)

  const base = api.defaults.baseURL ?? ''
  return `${base}/api/logs/export?${params.toString()}`
}
