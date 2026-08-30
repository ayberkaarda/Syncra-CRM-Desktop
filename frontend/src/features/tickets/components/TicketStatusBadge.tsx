// Talep durumu rozeti — literal eşleme (bkz. `TicketPriorityBadge` ile aynı gerekçe). Sabitler
// `ticketStatusMeta.ts`'te.
import { useTranslation } from 'react-i18next'
import { Badge } from '../../../components/ui'
import type { BadgeProps } from '../../../components/ui'
import { STATUS_VARIANT, statusLabel } from './ticketStatusMeta'
import type { TicketStatus } from '../types'

export function TicketStatusBadge({ status, size }: { status: TicketStatus; size?: BadgeProps['size'] }) {
  const { t } = useTranslation('enums')
  return (
    <Badge variant={STATUS_VARIANT[status]} size={size}>
      {statusLabel(status, t)}
    </Badge>
  )
}
