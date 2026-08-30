// Görev (Task) CRUD veri katmanı — liste, takvim, oluşturma/güncelleme/silme, tamamlama ve
// atama. Hata gövdesi tüm uçlarda `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import i18n from '../../../i18n'
import type {
  Task,
  TaskPayload,
  TasksCalendarQuery,
  TasksCalendarResponse,
  TasksListResponse,
  TasksQuery,
  UserOption,
} from '../types'

export const tasksKeys = {
  all: ['tasks'] as const,
  lists: ['tasks', 'list'] as const,
  list: (query: TasksQuery) => ['tasks', 'list', query] as const,
  calendarAll: ['tasks', 'calendar'] as const,
  calendar: (query: TasksCalendarQuery) => ['tasks', 'calendar', query] as const,
  detail: (id: number) => ['tasks', 'detail', id] as const,
}

export const taskUserOptionsKeys = { all: ['tasks', 'user-options'] as const }

async function fetchTasks(query: TasksQuery): Promise<TasksListResponse> {
  const { data } = await api.get<TasksListResponse>('/api/tasks', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[status]': query.status || undefined,
      'filter[priority]': query.priority || undefined,
      'filter[assigned_to]': query.assigned_to,
      'filter[created_by]': query.created_by,
      'filter[taskable_type]': query.taskable_type || undefined,
      'filter[taskable_id]': query.taskable_id,
      'filter[from]': query.from || undefined,
      'filter[to]': query.to || undefined,
      'filter[overdue]': query.overdue ? 1 : undefined,
    },
  })
  return data
}

/** `GET /api/tasks/calendar` — sayfalama YOK, `from`/`to` ZORUNLU (backend 90 gün sınırı uygular). */
async function fetchTasksCalendar(query: TasksCalendarQuery): Promise<TasksCalendarResponse> {
  const { data } = await api.get<TasksCalendarResponse>('/api/tasks/calendar', {
    params: {
      from: query.from,
      to: query.to,
      'filter[assigned_to]': query.assigned_to,
      'filter[status]': query.status || undefined,
      'filter[priority]': query.priority || undefined,
    },
  })
  return data
}

async function fetchTask(id: number): Promise<Task> {
  const { data } = await api.get<{ data: Task }>(`/api/tasks/${id}`)
  return data.data
}

async function createTaskRequest(payload: TaskPayload): Promise<Task> {
  const { data } = await api.post<{ data: Task }>('/api/tasks', payload)
  return data.data
}

async function updateTaskRequest(id: number, payload: Partial<TaskPayload>): Promise<Task> {
  const { data } = await api.patch<{ data: Task }>(`/api/tasks/${id}`, payload)
  return data.data
}

async function deleteTaskRequest(id: number): Promise<void> {
  await api.delete(`/api/tasks/${id}`)
}

async function completeTaskRequest(id: number, completed: boolean): Promise<Task> {
  const { data } = await api.patch<{ data: Task }>(`/api/tasks/${id}/complete`, { completed })
  return data.data
}

async function assignTaskRequest(id: number, assignedTo: number | null): Promise<Task> {
  const { data } = await api.patch<{ data: Task }>(`/api/tasks/${id}/assign`, { assigned_to: assignedTo })
  return data.data
}

export function useTasks(query: TasksQuery) {
  return useQuery({
    queryKey: tasksKeys.list(query),
    queryFn: () => fetchTasks(query),
    placeholderData: keepPreviousData,
  })
}

export function useTasksCalendar(query: TasksCalendarQuery, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: tasksKeys.calendar(query),
    queryFn: () => fetchTasksCalendar(query),
    placeholderData: keepPreviousData,
    enabled: options?.enabled ?? true,
  })
}

export function useTask(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: tasksKeys.detail(id ?? -1),
    queryFn: () => fetchTask(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

function invalidateTaskCaches(queryClient: ReturnType<typeof useQueryClient>, id?: number) {
  void queryClient.invalidateQueries({ queryKey: tasksKeys.all })
  if (id !== undefined) {
    void queryClient.invalidateQueries({ queryKey: tasksKeys.detail(id) })
  }
}

export function useCreateTask() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: createTaskRequest,
    onSuccess: (task) => {
      invalidateTaskCaches(queryClient, task.id)
      toast.success(i18n.t('tasks:toast.created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateTask() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<TaskPayload> }) => updateTaskRequest(id, payload),
    onSuccess: (task) => {
      invalidateTaskCaches(queryClient, task.id)
      toast.success(i18n.t('tasks:toast.updated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useDeleteTask() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => deleteTaskRequest(id),
    onSuccess: () => {
      invalidateTaskCaches(queryClient)
      toast.success(i18n.t('tasks:toast.deleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

/**
 * Hızlı tamamlama (liste satırındaki checkbox) BU hook'u KULLANMAZ — iyimser güncelleme
 * `TasksListPage` içinde `queryClient.setQueryData` ile elle yapılır (bkz. sayfa dosyasındaki
 * yorum). Bu hook, hızlı checkbox dışındaki tamamlama tetikleyicileri (takvim panelindeki
 * "tamamlandı" butonu gibi) için standart mutasyon-toast akışını sağlar.
 */
export function useCompleteTask() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, completed }: { id: number; completed: boolean }) => completeTaskRequest(id, completed),
    onSuccess: (task) => {
      invalidateTaskCaches(queryClient, task.id)
      toast.success(task.status === 'completed' ? i18n.t('tasks:toast.completed') : i18n.t('tasks:toast.uncompleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useAssignTask() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, assignedTo }: { id: number; assignedTo: number | null }) => assignTaskRequest(id, assignedTo),
    onSuccess: (task) => {
      invalidateTaskCaches(queryClient, task.id)
      toast.success(i18n.t('tasks:toast.assigned'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

/**
 * Satır içi hızlı tamamlama içindir — toast/invalidate YAPMAZ, ham promise döner. Çağıran taraf
 * (`TasksListPage`) cache'i iyimser günceller ve hata olursa kendi geri alma mantığını uygular;
 * standart mutasyon burada başarı toast'ı gösterip TÜM listeyi invalidate ederdi, bu da
 * iyimser güncellemenin amacını (anında, titremesiz geri bildirim) boşa çıkarırdı.
 */
export async function completeTaskQuiet(id: number, completed: boolean): Promise<Task> {
  return completeTaskRequest(id, completed)
}

async function fetchUserOptions(): Promise<UserOption[]> {
  const { data } = await api.get<{ data: UserOption[] }>('/api/users', { params: { per_page: 100 } })
  return data.data
}

/** Atanan/oluşturan filtresi ve form Select'i için kullanıcı listesi. 403 → `isForbidden`. */
export function useTaskUserOptions() {
  const query = useQuery({
    queryKey: taskUserOptionsKeys.all,
    queryFn: fetchUserOptions,
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}
