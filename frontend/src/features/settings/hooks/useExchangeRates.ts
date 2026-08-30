// Kur (döviz) — Faz 14 / İz E (docs/PHASE-INTL.md §2.1, §2.6) TanStack Query hook'ları.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import i18n from '../../../i18n'
import { createManualExchangeRateRequest, fetchExchangeRates, settingsKeys } from '../api'
import type { ManualExchangeRatePayload } from '../types'

export function useExchangeRates() {
  return useQuery({
    queryKey: settingsKeys.exchangeRates,
    queryFn: fetchExchangeRates,
  })
}

/** Aynı gün + aynı para birimi için ikinci giriş backend'de UPSERT'tir — burada başarıda
 *  yalnızca cache invalidate edilir, iyimser güncelleme yapılmaz (bayatlık hesabı sunucuda). */
export function useCreateManualExchangeRate() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: ManualExchangeRatePayload) => createManualExchangeRateRequest(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.exchangeRates })
      toast.success(i18n.t('settings:toast.exchangeRateSaved'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
