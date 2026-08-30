// Görev durumu rozeti — literal eşleme (bkz. `PriorityBadge` ile aynı gerekçe). Sabitler
// `taskStatusMeta.ts`'te.
import { useTranslation } from 'react-i18next'
import { Badge } from '../../../components/ui'
import type { BadgeProps } from '../../../components/ui'
import { STATUS_VARIANT, statusLabel } from './taskStatusMeta'
import type { TaskStatus } from '../types'

export function TaskStatusBadge({ status, size }: { status: TaskStatus; size?: BadgeProps['size'] }) {
  const { t } = useTranslation('enums')
  return (
    <Badge variant={STATUS_VARIANT[status]} size={size}>
      {statusLabel(status, t)}
    </Badge>
  )
}
