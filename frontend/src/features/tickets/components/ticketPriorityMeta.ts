// Talep önceliği sabitleri — `TicketPriorityBadge.tsx`'ten AYRI tutulur ki o dosya yalnızca bir
// component export etsin (react-refresh/only-export-components uyarısını önler, bkz. token
// sözleşmesi doğrulama adımı "npm run lint → temiz"; desen `tasks/components/priorityMeta.ts`
// ile aynı). Görev tanımı: low=neutral, normal=primary, high=warning, urgent=danger.
//
// Etiket metinleri `enums:ticket.priority.*` anahtarındadır (Faz 14 / İz D). Bu dosya bir React
// component'i DEĞİL — `t` fonksiyonu çağıran component'ten (bkz. `TicketPriorityBadge`,
// `TicketFormModal`, `TicketsListPage`) parametre olarak geçirilir, böylece dil değişince
// çağıran component'in kendi `useTranslation` aboneliği üzerinden YENİDEN render edilir
// (desen `tasks/components/priorityMeta.ts` ile birebir aynı).
import type { TFunction } from 'i18next'
import type { TicketPriority } from '../types'
import type { BadgeProps } from '../../../components/ui'

export const PRIORITY_VARIANT: Record<TicketPriority, NonNullable<BadgeProps['variant']>> = {
  low: 'neutral',
  normal: 'primary',
  high: 'warning',
  urgent: 'danger',
}

const PRIORITY_ORDER: TicketPriority[] = ['low', 'normal', 'high', 'urgent']

export function priorityLabel(priority: TicketPriority, t: TFunction): string {
  return t(`enums:ticket.priority.${priority}`)
}

export function priorityOptions(t: TFunction) {
  return PRIORITY_ORDER.map((value) => ({ value, label: priorityLabel(value, t) }))
}
