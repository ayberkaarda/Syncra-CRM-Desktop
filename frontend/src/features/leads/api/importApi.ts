// Lead CSV içe aktarma API katmanı — `POST /api/leads/import` senkron (200,
// sonuç doğrudan) veya asenkron (202, `batch_id` ile durum sorgulanır)
// dönebilir. Kuyruklu senaryoda durum `GET /api/leads/import/{batch}` ile
// makul aralıklarla, ÜST SINIRLI olarak sorgulanır — polling döngüsü
// `ImportLeadsModal` içinde `pollImportBatch()` yardımcısıyla yürütülür
// (bkz. o dosyadaki `MAX_POLL_ATTEMPTS` açıklaması).
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import { leadsKeys } from './leadsApi'
import type { ImportBatchStatus, ImportDuplicateMode, ImportResult } from '../types'

/**
 * Şablon indirme URL'i — `ExportMenu`'deki gizli-iframe deseniyle aynı
 * mantık (bkz. `features/logs/components/ExportMenu.tsx`): kimlik doğrulama
 * cookie tabanlı olduğundan `<a href>` yeterli, ama backend 403/422 dönerse
 * SPA sekmesi terk edilmesin diye çağıran taraf (`ImportLeadsModal`) bu URL'i
 * görünmez bir iframe'e yükler.
 */
export function buildImportTemplateUrl(): string {
  const base = api.defaults.baseURL ?? ''
  return `${base}/api/leads/import/template`
}

export type ImportOutcome =
  | { kind: 'sync'; result: ImportResult }
  | { kind: 'async'; batchId: string }

async function uploadImportRequest(file: File, duplicateMode: ImportDuplicateMode): Promise<ImportOutcome> {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('duplicate_mode', duplicateMode)

  const response = await api.post<{ data: ImportResult | { batch_id: string } }>('/api/leads/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })

  if (response.status === 202) {
    const data = response.data.data as { batch_id: string }
    return { kind: 'async', batchId: data.batch_id }
  }

  return { kind: 'sync', result: response.data.data as ImportResult }
}

export async function fetchImportBatchStatus(batchId: string): Promise<ImportBatchStatus> {
  const { data } = await api.get<{ data: ImportBatchStatus }>(`/api/leads/import/${batchId}`)
  return data.data
}

export function useImportLeads() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ file, duplicateMode }: { file: File; duplicateMode: ImportDuplicateMode }) =>
      uploadImportRequest(file, duplicateMode),
    onSuccess: (outcome) => {
      if (outcome.kind === 'sync') {
        void queryClient.invalidateQueries({ queryKey: leadsKeys.all })
      }
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })
}

export const IMPORT_POLL_INTERVAL_MS = 2000
/** 60 x 2sn ≈ 2 dakika sonra vazgeçilir — sonsuz sorgulama YASAK. */
export const IMPORT_POLL_MAX_ATTEMPTS = 60

const TERMINAL_STATUSES = new Set<ImportBatchStatus['status']>(['completed', 'failed'])

export type PollOutcome =
  | { kind: 'done'; status: ImportBatchStatus }
  | { kind: 'timed_out' }
  | { kind: 'cancelled' }

/**
 * `batch_id` durumunu `completed`/`failed` olana kadar `IMPORT_POLL_INTERVAL_MS`
 * aralıkla sorgular; `IMPORT_POLL_MAX_ATTEMPTS` denemesinde hâlâ sonuçlanmadıysa
 * `timed_out` döner (çağıran taraf "işlem devam ediyor, daha sonra kontrol
 * edin" mesajı gösterir) — sonsuz döngü kurulmaz. `isCancelled()` true
 * dönerse (ör. modal kapandı) döngü hemen `cancelled` ile biter.
 */
export async function pollImportBatch(batchId: string, isCancelled: () => boolean): Promise<PollOutcome> {
  for (let attempt = 0; attempt < IMPORT_POLL_MAX_ATTEMPTS; attempt++) {
    if (isCancelled()) return { kind: 'cancelled' }

    const status = await fetchImportBatchStatus(batchId)
    if (TERMINAL_STATUSES.has(status.status)) {
      return { kind: 'done', status }
    }

    if (isCancelled()) return { kind: 'cancelled' }
    await new Promise((resolve) => setTimeout(resolve, IMPORT_POLL_INTERVAL_MS))
  }

  return { kind: 'timed_out' }
}
