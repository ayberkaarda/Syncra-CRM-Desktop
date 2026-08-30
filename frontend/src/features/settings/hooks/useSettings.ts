// Genel ayarlar (grup bazlı key/value) için TanStack Query hook'ları.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import i18n from '../../../i18n'
import { fetchSettings, settingsKeys, updateSettingsRequest } from '../api'
import type { SettingValue } from '../types'

export function useSettings() {
  return useQuery({
    queryKey: settingsKeys.settings,
    queryFn: fetchSettings,
  })
}

export function useUpdateSettings() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (patch: Record<string, SettingValue>) => updateSettingsRequest(patch),
    onSuccess: (response) => {
      // PATCH 200'de GET ile AYNI şekli (tüm liste + meta.groups) döner — invalidate + yeniden
      // fetch yerine doğrudan cache'e yazmak bir round-trip tasarruf ettirir.
      queryClient.setQueryData(settingsKeys.settings, response)
      toast.success(i18n.t('settings:toast.settingsSaved'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
