// Görev önceliği sabitleri — `PriorityBadge.tsx`'ten AYRI tutulur ki o dosya yalnızca bir
// component export etsin (react-refresh/only-export-components uyarısını önler, bkz. token
// sözleşmesi doğrulama adımı "npm run lint → temiz").
//
// Etiket metinleri `enums:task.priority.*` anahtarındadır (Faz 14 / İz D). Bu dosya bir React
// component'i DEĞİL — `t` fonksiyonu çağıran component'ten (bkz. `PriorityBadge`, `TaskFormModal`,
// `TasksPage`) parametre olarak geçirilir, böylece dil değişince çağıran component'in kendi
// `useTranslation` aboneliği üzerinden YENİDEN render edilir.
import type { TFunction } from 'i18next'
import type { TaskPriority } from '../types'
import type { BadgeProps } from '../../../components/ui'

export const PRIORITY_VARIANT: Record<TaskPriority, NonNullable<BadgeProps['variant']>> = {
  low: 'neutral',
  normal: 'primary',
  high: 'warning',
  urgent: 'danger',
}

const PRIORITY_ORDER: TaskPriority[] = ['low', 'normal', 'high', 'urgent']

export function priorityLabel(priority: TaskPriority, t: TFunction): string {
  return t(`enums:task.priority.${priority}`)
}

export function priorityOptions(t: TFunction) {
  return PRIORITY_ORDER.map((value) => ({ value, label: priorityLabel(value, t) }))
}

/** Takvim ızgarasındaki öncelik noktası için — `Badge` yerine, hücreye sığan minik bir nokta. */
export const PRIORITY_DOT_CLASS: Record<TaskPriority, string> = {
  low: 'bg-fg-muted',
  normal: 'bg-primary',
  high: 'bg-warning',
  urgent: 'bg-danger',
}
