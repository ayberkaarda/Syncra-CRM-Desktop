// Özel alanlar için TanStack Query hook'ları.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import i18n from '../../../i18n'
import {
  createCustomFieldRequest,
  deactivateCustomFieldRequest,
  fetchCustomFields,
  settingsKeys,
  updateCustomFieldRequest,
} from '../api'
import type { CustomFieldCreatePayload, CustomFieldUpdatePayload } from '../types'

export function useCustomFields() {
  return useQuery({
    queryKey: settingsKeys.customFields,
    queryFn: fetchCustomFields,
  })
}

export function useCreateCustomField() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: CustomFieldCreatePayload) => createCustomFieldRequest(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.customFields })
      toast.success(i18n.t('settings:toast.customFieldCreated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateCustomField() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: CustomFieldUpdatePayload }) =>
      updateCustomFieldRequest(id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.customFields })
      toast.success(i18n.t('settings:toast.customFieldUpdated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

/** Silme DEĞİL pasifleştirme (`DELETE` ucu backend'de `is_active=false` yapar). */
export function useDeactivateCustomField() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => deactivateCustomFieldRequest(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.customFields })
      toast.success(i18n.t('settings:toast.customFieldDeactivated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
