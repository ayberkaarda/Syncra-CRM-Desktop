// Aktivite türü rozeti + ikon — literal eşleme (bkz. `tasks/components/PriorityBadge.tsx` ile
// aynı gerekçe). Sabitler `activityTypeMeta.ts`'te.
//
// Faz 14 denetim bulgusu: `type` sözleşme olarak `ActivityType` (4 değer) ama DB'de backend'in
// artık reddettiği eski değerler (`'visit'` vb., bkz. `DemoDataSeeder::ACTIVITY_TYPES`)
// KALMIŞ OLABİLİR — literal eşlemede (`TYPE_ICON` vd.) karşılığı olmayan bir değer geldiğinde
// önceden `TYPE_ICON[type]` `undefined` dönüp `<Icon/>` React'i çökertiyordu (`/activities`
// sayfası tamamen beyaz ekrandı). Karşılığı olmayan HER değer için artık ÇÖKMEK yerine nötr
// bir düşüş (`UNKNOWN_TYPE_*`) kullanılır — bkz. o sabitlerin gerekçesi.
import { useTranslation } from 'react-i18next'
import { Badge } from '../../../components/ui'
import type { BadgeProps } from '../../../components/ui'
import { TYPE_LABEL_KEY, TYPE_VARIANT, TYPE_ICON, UNKNOWN_TYPE_ICON, UNKNOWN_TYPE_VARIANT, UNKNOWN_TYPE_LABEL_KEY } from './activityTypeMeta'
import type { ActivityType } from '../types'

export function ActivityTypeBadge({ type, size }: { type: ActivityType; size?: BadgeProps['size'] }) {
  const { t } = useTranslation('enums')
  const Icon = TYPE_ICON[type] ?? UNKNOWN_TYPE_ICON
  const variant = TYPE_VARIANT[type] ?? UNKNOWN_TYPE_VARIANT
  const labelKey = TYPE_LABEL_KEY[type] ?? UNKNOWN_TYPE_LABEL_KEY
  return (
    <Badge variant={variant} size={size}>
      <Icon className="size-3.5" aria-hidden="true" />
      {t(labelKey)}
    </Badge>
  )
}
