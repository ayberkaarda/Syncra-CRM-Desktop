// Talep önceliği rozeti — literal eşleme (bkz. `components/shared/tokenBadgeVariant.ts`
// deseni/gerekçesi, token sözleşmesi §"Öncelik/durum renkleri için literal eşleme kullan").
// Sabitler `ticketPriorityMeta.ts`'te.
import { useTranslation } from 'react-i18next'
import { Badge } from '../../../components/ui'
import type { BadgeProps } from '../../../components/ui'
import { PRIORITY_VARIANT, priorityLabel } from './ticketPriorityMeta'
import type { TicketPriority } from '../types'

export function TicketPriorityBadge({ priority, size }: { priority: TicketPriority; size?: BadgeProps['size'] }) {
  const { t } = useTranslation('enums')
  return (
    <Badge variant={PRIORITY_VARIANT[priority]} size={size}>
      {priorityLabel(priority, t)}
    </Badge>
  )
}
