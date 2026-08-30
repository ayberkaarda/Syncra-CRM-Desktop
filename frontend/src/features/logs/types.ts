// Loglar modülü tipleri — backend sözleşmesiyle birebir eşleşir (bkz. Faz 5 / E görev tanımı).
// `ActivityLogResource.properties` sözleşmesi (D şeridinin düzeltmesinden sonra):
//   - `_truncated?: string[]`  — kırpılan ALAN ADLARI (ör. `["description"]`), boolean DEĞİL.
//   - `_response_truncated?: boolean` — ayrı güvenlik ağı: yanıt JSON'u 5000 karakteri aştıysa.
//   - `_context?: LogContext` — artık REST yanıtında da var (önceden yalnızca `private-logs`
//     broadcast payload'ındaydı); `causer === null` satırlarda Sistem/Konsol/Kuyruk ayrımı için.

export type Pagination = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type LogsListResponse<T> = {
  data: T[]
  meta: { pagination: Pagination }
}

export type SessionEvent = 'login' | 'logout' | 'failed_login' | 'locked_out'

export type SessionLog = {
  id: number
  user: { id: number; name: string; email: string } | null
  email: string
  event: SessionEvent
  ip_address: string | null
  device: string | null
  browser: string | null
  platform: string | null
  logged_in_at: string | null
  logged_out_at: string | null
  duration_seconds: number | null
  created_at: string
}

export type PageVisitLog = {
  id: number
  user: { id: number; name: string } | null
  route: string | null
  path: string
  title: string | null
  entered_at: string
  last_heartbeat_at: string | null
  duration_seconds: number | null
  ip_address: string | null
  created_at: string
}

export type ActivityEvent = 'created' | 'updated' | 'deleted' | 'restored'
export type ActivityFilterEvent = 'created' | 'updated' | 'deleted' | 'restored'

export type ActivityLogProperties = {
  old: Record<string, unknown>
  attributes: Record<string, unknown>
  /** Kırpılan ALAN ADLARININ dizisi (ör. `["description"]`) — alan bazlı diff notu için. */
  _truncated?: string[]
  /** Yanıt JSON'unun tamamı 5000 karakteri aştıysa true — ayrı, alan-bağımsız güvenlik ağı. */
  _response_truncated?: boolean
  _context?: LogContext
}

export type ActivityLog = {
  id: number
  log_name: string | null
  description: string | null
  event: ActivityEvent | string
  subject_type: string | null
  subject_id: number | null
  subject_label: string | null
  causer: { id: number; name: string } | null
  properties: ActivityLogProperties
  created_at: string
}

export type LogContext = 'http' | 'console' | 'queue' | 'seed' | 'system' | 'test'

/** `private-logs` kanalı → `.activity.logged` payload'ı (diff İÇERMEZ). */
export type LiveActivityEntry = {
  id: number
  log_name: string | null
  description: string | null
  event: ActivityEvent | string
  subject_type: string | null
  subject_id: number | null
  subject_label: string | null
  causer_id: number | null
  causer_name: string | null
  context: LogContext | string
  created_at: string
}

export type LogsTab = 'sessions' | 'page-visits' | 'activities' | 'live'

/** Sekmeler arası ortak filtreler — URL query string'inde tutulur. */
export type CommonLogQuery = {
  page: number
  per_page: number
  sort?: string
  q?: string
  user_id?: number
  from?: string
  to?: string
}

export type SessionsQuery = CommonLogQuery & { event?: SessionEvent }
export type PageVisitsQuery = CommonLogQuery
export type ActivitiesQuery = CommonLogQuery & {
  event?: ActivityFilterEvent
  subject_type?: string
}

export type LogUserOption = {
  id: number
  name: string
  email: string
}

export type ExportType = 'sessions' | 'page-visits' | 'activities'
export type ExportFormat = 'csv' | 'xlsx'
