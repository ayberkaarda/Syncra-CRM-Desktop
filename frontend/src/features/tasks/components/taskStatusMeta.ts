// Görev durumu sabitleri — `TaskStatusBadge.tsx`'ten AYRI (bkz. `priorityMeta.ts` başındaki
// aynı gerekçe, i18n dahil).
import type { TFunction } from 'i18next'
import type { TaskStatus } from '../types'
import type { BadgeProps } from '../../../components/ui'

export const STATUS_VARIANT: Record<TaskStatus, NonNullable<BadgeProps['variant']>> = {
  pending: 'neutral',
  in_progress: 'primary',
  completed: 'success',
  cancelled: 'danger',
}

const STATUS_ORDER: TaskStatus[] = ['pending', 'in_progress', 'completed', 'cancelled']

export function statusLabel(status: TaskStatus, t: TFunction): string {
  return t(`enums:task.status.${status}`)
}

export function statusOptions(t: TFunction) {
  return STATUS_ORDER.map((value) => ({ value, label: statusLabel(value, t) }))
}

/** Oluşturma/düzenleme formunda `completed` seçilemez — tamamlama ayrı bir akış (bkz. görev tanımı,
 * ve `TaskFormModal.tsx` başındaki genişletilmiş gerekçe: backend `/complete` dışındaki uçlardan
 * `completed_at`'i asla yazmaz). */
export function createStatusOptions(t: TFunction) {
  return statusOptions(t).filter((option) => option.value !== 'completed')
}
