// Görevler (Tasks) modülü tipleri — backend `TaskResource` ile birebir eşleşir (bkz. Faz 8 / C
// görev tanımı). `taskable`/`activityable` KISA AD taşır (`deal`, `contact`, ...) — FQCN asla
// gelmez, bkz. backend `App\Support\MorphTargets`.

export const TASK_PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const
export type TaskPriority = (typeof TASK_PRIORITIES)[number]

export const TASK_STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'] as const
export type TaskStatus = (typeof TASK_STATUSES)[number]

/**
 * İlgili kayıt (taskable/activityable) kısa tipi — backend `MorphTargets::TARGETS` beyaz
 * listesiyle birebir. `ticket` beyaz listede olsa da backend'de henüz `/api/tickets` ucu YOK
 * (Faz 8 sırasında ticket route'ları karara bağlanmadı) — bu yüzden ilgili-kayıt SEÇİCİSİ bu
 * tipi sunmaz, yalnızca GÖRÜNTÜLEME (ikon/bağlantı) tarafı destekler (bkz. `RelatedRecordPicker`
 * ve `relatedRecordMeta.ts` içindeki notlar).
 */
export type TaskableType = 'deal' | 'lead' | 'contact' | 'company' | 'ticket'

export type TaskableRef = {
  type: TaskableType
  id: number
  label: string | null
}

export type TaskUserRef = { id: number; name: string }

/**
 * Faz 13 — yatay yazma izolasyonu. Backend `ExposesAbilities` trait'iyle HER ZAMAN üretir
 * (kullanıcı yoksa tümü `false`) — opsiyonel DEĞİL. Arayüz bu bileşik kararı (izin + sahiplik)
 * KENDİ BAŞINA yeniden kurmaz, bkz. `backend/app/Http/Resources/TaskResource.php`.
 */
export type TaskAbilities = { update: boolean; complete: boolean; delete: boolean; assign: boolean }

export type Task = {
  id: number
  title: string
  description: string | null
  due_at: string | null
  reminder_at: string | null
  priority: TaskPriority
  status: TaskStatus
  completed_at: string | null
  /** SUNUCU hesaplar — istemcide asla yeniden hesaplanmaz. */
  is_overdue: boolean
  assignee: TaskUserRef | null
  creator: TaskUserRef | null
  taskable: TaskableRef | null
  created_at: string | null
  updated_at: string | null
  can: TaskAbilities
}

export type Pagination = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type TasksListResponse = {
  data: Task[]
  meta: { pagination: Pagination }
}

export type TasksCalendarResponse = {
  data: Task[]
  meta: { from: string; to: string; count: number }
}

export type TasksQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  status?: TaskStatus
  priority?: TaskPriority
  assigned_to?: number
  created_by?: number
  taskable_type?: TaskableType
  taskable_id?: number
  from?: string
  to?: string
  overdue?: boolean
}

export type TasksCalendarQuery = {
  from: string
  to: string
  assigned_to?: number
  status?: TaskStatus
  priority?: TaskPriority
}

export type TaskPayload = {
  title: string
  description?: string | null
  due_at?: string | null
  reminder_at?: string | null
  priority?: TaskPriority
  status?: TaskStatus
  assigned_to?: number | null
  taskable_type?: TaskableType | null
  taskable_id?: number | null
}

/** `private-user.{id}` kanalındaki `.task.reminder` yükü — düz skalerler. */
export type TaskReminderEvent = {
  task_id: number
  title: string
  due_at: string
  priority: TaskPriority
  taskable_type: TaskableType | null
  taskable_id: number | null
  taskable_label: string | null
}

export type UserOption = { id: number; name: string }
