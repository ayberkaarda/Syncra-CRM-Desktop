// Fırsat durumu rozeti — görev tanımı: open=primary, won=success, lost=danger.
import { useTranslation } from 'react-i18next'
import { Badge } from '../../../components/ui'
import type { BadgeProps } from '../../../components/ui'
import type { DealStatus } from '../types'

const STATUS_VARIANT: Record<DealStatus, NonNullable<BadgeProps['variant']>> = {
  open: 'primary',
  won: 'success',
  lost: 'danger',
}

export type DealStatusBadgeProps = {
  status: DealStatus
}

export function DealStatusBadge({ status }: DealStatusBadgeProps) {
  const { t } = useTranslation('enums')
  return <Badge variant={STATUS_VARIANT[status]}>{t(`deal.status.${status}`)}</Badge>
}

export { STATUS_VARIANT as DEAL_STATUS_VARIANT }
