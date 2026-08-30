// Görev önceliği rozeti — literal eşleme (bkz. `components/shared/tokenBadgeVariant.ts`
// deseni/gerekçesi, token sözleşmesi §"Öncelik/durum renkleri için literal eşleme kullan").
// Sabitler `priorityMeta.ts`'te (bkz. o dosyanın başındaki gerekçe).
import { useTranslation } from 'react-i18next'
import { Badge } from '../../../components/ui'
import type { BadgeProps } from '../../../components/ui'
import { PRIORITY_VARIANT, priorityLabel } from './priorityMeta'
import type { TaskPriority } from '../types'

export function PriorityBadge({ priority, size }: { priority: TaskPriority; size?: BadgeProps['size'] }) {
  const { t } = useTranslation('enums')
  return (
    <Badge variant={PRIORITY_VARIANT[priority]} size={size}>
      {priorityLabel(priority, t)}
    </Badge>
  )
}
