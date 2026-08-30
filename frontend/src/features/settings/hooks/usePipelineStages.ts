// Pipeline aşamaları için TanStack Query hook'ları.
//
// `useUpdatePipelineStage` KASITLI OLARAK genel bir `onError` toast'ı GÖSTERMEZ: aşama
// pasifleştirmede 422 `STAGE_HAS_OPEN_DEALS` özel bir modal akışı tetikler (bkz.
// `PipelineStagesTab` + `DeactivateStageModal`), jenerik bir hata toast'ı bu akışla çakışırdı.
// Çağıran taraf `mutateAsync`'i kendi `try/catch`'i içinde çağırıp hem başarı mesajını (deals
// taşındıysa farklı bir metin) hem hata yollarını kendisi yönetir.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import i18n from '../../../i18n'
import {
  createPipelineStageRequest,
  fetchPipelineStages,
  reorderPipelineStagesRequest,
  settingsKeys,
  updatePipelineStageRequest,
} from '../api'
import type { PipelineStage, PipelineStageCreatePayload, PipelineStagePayload } from '../types'

export function usePipelineStages() {
  return useQuery({
    queryKey: settingsKeys.pipelineStages,
    queryFn: fetchPipelineStages,
  })
}

export function useCreatePipelineStage() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: PipelineStageCreatePayload) => createPipelineStageRequest(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.pipelineStages })
      toast.success(i18n.t('settings:toast.stageCreated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdatePipelineStage() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: PipelineStagePayload }) =>
      updatePipelineStageRequest(id, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: settingsKeys.pipelineStages })
    },
  })
}

/** Sürükle-bırak sıralama: bırakınca çağrılır, cache'i iyimser günceller, hata olursa geri alır. */
export function useReorderPipelineStages() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (orderedIds: number[]) => reorderPipelineStagesRequest(orderedIds),
    onMutate: async (orderedIds: number[]) => {
      await queryClient.cancelQueries({ queryKey: settingsKeys.pipelineStages })
      const previous = queryClient.getQueryData<PipelineStage[]>(settingsKeys.pipelineStages)
      if (previous) {
        const byId = new Map(previous.map((stage) => [stage.id, stage]))
        const next = orderedIds
          .map((id, index) => {
            const stage = byId.get(id)
            return stage ? { ...stage, position: index } : null
          })
          .filter((stage): stage is PipelineStage => stage !== null)
        queryClient.setQueryData(settingsKeys.pipelineStages, next)
      }
      return { previous }
    },
    onError: (error, _orderedIds, context) => {
      if (context?.previous) queryClient.setQueryData(settingsKeys.pipelineStages, context.previous)
      toast.error(getErrorMessage(error))
    },
    onSuccess: (stages) => {
      queryClient.setQueryData(settingsKeys.pipelineStages, stages)
    },
  })
}
