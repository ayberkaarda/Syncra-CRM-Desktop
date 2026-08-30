// Otomasyon kuralları — Faz 14 / İz F, Attio C4 (docs/PHASE-INTL.md §3) TanStack Query
// hook'ları. Diğer Ayarlar sekmeleriyle (`useExchangeRates`/`useEmailTemplates`) AYNI desen.
import axios from 'axios'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import i18n from '../../../i18n'
import {
  createAutomationRuleRequest,
  deleteAutomationRuleRequest,
  fetchAutomationRules,
  fetchAutomationUserOptions,
  settingsKeys,
  updateAutomationRuleRequest,
} from '../api'
import type { AutomationRuleCreatePayload, AutomationRuleUpdatePayload } from '../types'

export function useAutomationRules() {
  return useQuery({
    queryKey: settingsKeys.automationRules,
    queryFn: fetchAutomationRules,
  })
}

export function useCreateAutomationRule() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: AutomationRuleCreatePayload) => createAutomationRuleRequest(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.automationRules })
      toast.success(i18n.t('settings:toast.automationRuleCreated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateAutomationRule() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: AutomationRuleUpdatePayload }) =>
      updateAutomationRuleRequest(id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.automationRules })
      toast.success(i18n.t('settings:toast.automationRuleUpdated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

/** Yalnızca aç/kapa — ayrı bir mutasyon değil, `useUpdateAutomationRule`'in başarı toast'ı
 *  farklı olduğu için `AutomationRulesTab` bu iki hook'u ayrı kullanır. */
export function useToggleAutomationRule() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, isActive }: { id: number; isActive: boolean }) =>
      updateAutomationRuleRequest(id, { is_active: isActive }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.automationRules })
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

/** GERÇEK silme (204), geri alınamaz — çağıran taraf bir onay modalıyla korumalı. */
export function useDeleteAutomationRule() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => deleteAutomationRuleRequest(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.automationRules })
      toast.success(i18n.t('settings:toast.automationRuleDeleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

/** "Sabit kullanıcı" seçicisi. `users.view` izni yoksa 403 — `isForbidden` ile çağıran
 *  taraf "sabit kullanıcı" seçeneğini tamamen gizler (bkz. `useDealOwnerOptions` ile AYNI
 *  desen, `features/deals/api/boardApi.ts`). */
export function useAutomationUserOptions() {
  const query = useQuery({
    queryKey: settingsKeys.automationRuleUserOptions,
    queryFn: fetchAutomationUserOptions,
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}
