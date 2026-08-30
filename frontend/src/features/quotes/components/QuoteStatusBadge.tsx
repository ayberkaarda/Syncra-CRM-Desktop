// Teklif durum rozeti — görev tanımındaki eşleme: draft=neutral, sent=primary,
// accepted=success, rejected=danger, expired=warning.
// Etiketler `enums:quote.status.*`ten gelir (Faz 14 / İz D — bu anahtarlar zaten
// ortak `enums` namespace'inde hazır, bkz. docs/PHASE-INTL.md §1.3).
import { useTranslation } from 'react-i18next'
import { Badge } from '../../../components/ui'
import type { QuoteStatus } from '../types'

const VARIANTS: Record<QuoteStatus, 'neutral' | 'primary' | 'success' | 'danger' | 'warning'> = {
  draft: 'neutral',
  sent: 'primary',
  accepted: 'success',
  rejected: 'danger',
  expired: 'warning',
}

export function QuoteStatusBadge({ status }: { status: QuoteStatus }) {
  const { t } = useTranslation()
  return <Badge variant={VARIANTS[status]}>{t(`enums:quote.status.${status}`)}</Badge>
}
