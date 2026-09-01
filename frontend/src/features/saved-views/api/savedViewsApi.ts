// Kayıtlı Görünümler veri katmanı — Faz 14 / İz F, Attio C2. Hata gövdesi tüm uçlarda
// `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import i18n from '../../../i18n'
import { onlineOnlyMessage } from '../../../components/shared/onlineOnlyMessage'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import type { SavedView, SavedViewModule, SavedViewPayload, UpdateSavedViewPayload } from '../types'
import { getPlatform } from '../../../platform'

export const savedViewsKeys = {
  all: ['saved-views'] as const,
  list: (module: SavedViewModule) => ['saved-views', 'list', module] as const,
}

export async function fetchSavedViews(module: SavedViewModule): Promise<SavedView[]> {
  const { data } = await api.get<{ data: SavedView[] }>('/api/saved-views', { params: { module } })
  return data.data
}

/**
 * `enabled` varsayılan `true`: sayfa yüklendiği anda listelenir. Sayfa modülü için görme
 * izni yoksa backend zaten 403 döner (bkz. `SavedViewPolicy::viewAny`) — bu normalde
 * OLMAMALIDIR çünkü kullanıcı zaten o liste sayfasına ULAŞABİLDİĞİ için modül iznine
 * sahiptir; yine de `isError` durumunda `SavedViewsBar` kendini sessizce gizler (görev
 * tanımı: ikincil bir kontrol, sayfanın ana işlevini bozmamalı).
 */
export function useSavedViews(module: SavedViewModule) {
  return useQuery({
    queryKey: savedViewsKeys.list(module),
    queryFn: () => getPlatform().data.savedViews.list(module),
  })
}

export async function createSavedViewRequest(payload: SavedViewPayload): Promise<SavedView> {
  const { data } = await api.post<{ data: SavedView }>('/api/saved-views', payload)
  return data.data
}

export function useCreateSavedView(module: SavedViewModule) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: SavedViewPayload) => getPlatform().data.savedViews.create(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: savedViewsKeys.list(module) })
      toast.success(i18n.t('common:toast.saveSuccess'))
    },
    // Ad benzersizliği (422) `SavedViewsBar`de `getFieldErrors()` ile SATIR İÇİ gösterilir —
    // bu jenerik toast onunla ÇELİŞMEZ, yalnızca genel bir "kaydetme başarısız" bildirimidir.
    onError: (error) => toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error)),
  })
}

export async function updateSavedViewRequest(id: number, payload: UpdateSavedViewPayload): Promise<SavedView> {
  const { data } = await api.patch<{ data: SavedView }>(`/api/saved-views/${id}`, payload)
  return data.data
}

export function useUpdateSavedView(module: SavedViewModule) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: UpdateSavedViewPayload }) => getPlatform().data.savedViews.update(id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: savedViewsKeys.list(module) })
      toast.success(i18n.t('common:toast.saveSuccess'))
    },
    onError: (error) => toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error)),
  })
}

export async function deleteSavedViewRequest(id: number): Promise<void> {
  await api.delete(`/api/saved-views/${id}`)
}

export function useDeleteSavedView(module: SavedViewModule) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => getPlatform().data.savedViews.delete(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: savedViewsKeys.list(module) })
      toast.success(i18n.t('common:toast.deleteSuccess'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
