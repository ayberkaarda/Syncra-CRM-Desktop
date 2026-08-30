// Aktivite (Activity) CRUD veri katmanı. Hata gövdesi tüm uçlarda
// `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import i18n from '../../../i18n'
import type { Activity, ActivitiesListResponse, ActivitiesQuery, ActivityPayload } from '../types'

export const activitiesKeys = {
  all: ['activities'] as const,
  lists: ['activities', 'list'] as const,
  list: (query: ActivitiesQuery) => ['activities', 'list', query] as const,
  detail: (id: number) => ['activities', 'detail', id] as const,
}

async function fetchActivities(query: ActivitiesQuery): Promise<ActivitiesListResponse> {
  const { data } = await api.get<ActivitiesListResponse>('/api/activities', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[type]': query.type || undefined,
      'filter[user_id]': query.user_id,
      'filter[activityable_type]': query.activityable_type || undefined,
      'filter[activityable_id]': query.activityable_id,
      'filter[from]': query.from || undefined,
      'filter[to]': query.to || undefined,
    },
  })
  return data
}

async function createActivityRequest(payload: ActivityPayload): Promise<Activity> {
  const { data } = await api.post<{ data: Activity }>('/api/activities', payload)
  return data.data
}

async function updateActivityRequest(id: number, payload: Partial<ActivityPayload>): Promise<Activity> {
  const { data } = await api.patch<{ data: Activity }>(`/api/activities/${id}`, payload)
  return data.data
}

async function deleteActivityRequest(id: number): Promise<void> {
  await api.delete(`/api/activities/${id}`)
}

export function useActivities(query: ActivitiesQuery) {
  return useQuery({
    queryKey: activitiesKeys.list(query),
    queryFn: () => fetchActivities(query),
    placeholderData: keepPreviousData,
  })
}

function invalidateActivityCaches(queryClient: ReturnType<typeof useQueryClient>, id?: number) {
  void queryClient.invalidateQueries({ queryKey: activitiesKeys.all })
  if (id !== undefined) {
    void queryClient.invalidateQueries({ queryKey: activitiesKeys.detail(id) })
  }
}

export function useCreateActivity() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: createActivityRequest,
    onSuccess: () => {
      invalidateActivityCaches(queryClient)
      toast.success(i18n.t('activities:toast.created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateActivity() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<ActivityPayload> }) =>
      updateActivityRequest(id, payload),
    onSuccess: (activity) => {
      invalidateActivityCaches(queryClient, activity.id)
      toast.success(i18n.t('activities:toast.updated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useDeleteActivity() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => deleteActivityRequest(id),
    onSuccess: () => {
      invalidateActivityCaches(queryClient)
      toast.success(i18n.t('activities:toast.deleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
