// Aktivite türü sabitleri — `ActivityTypeBadge.tsx`'ten AYRI tutulur ki o dosya yalnızca bir
// component export etsin (bkz. `tasks/components/priorityMeta.ts` başındaki aynı gerekçe).
//
// Faz 14 / İz D: etiketler ARTIK METİN DEĞİL, `enums` namespace'indeki ANAHTAR taşır (bkz.
// `layout/Sidebar.tsx` NAV_SECTIONS'taki aynı gerekçe) — bir modül sabiti değerlendirme anında
// `t()` çağırsaydı metin ilk yüklenen dile donardı. Tüketiciler `activityTypeOptions(t)` ile
// (Select seçenekleri) ya da doğrudan `t(TYPE_LABEL_KEY[type], { ns: 'enums' })` ile çözer.
import { HelpCircle, Mail, Phone, StickyNote, Users } from 'lucide-react'
import type { ComponentType, SVGProps } from 'react'
import type { TFunction } from 'i18next'
import type { BadgeProps } from '../../../components/ui'
import type { ActivityType } from '../types'

export const TYPE_VARIANT: Record<ActivityType, NonNullable<BadgeProps['variant']>> = {
  call: 'primary',
  meeting: 'warning',
  email: 'neutral',
  note: 'success',
}

/** `enums` namespace anahtarı (önek `activity.type.*` — bkz. docs/PHASE-INTL.md §1.3/§1.5). */
export const TYPE_LABEL_KEY: Record<ActivityType, string> = {
  call: 'activity.type.call',
  meeting: 'activity.type.meeting',
  email: 'activity.type.email',
  note: 'activity.type.note',
}

export const TYPE_ICON: Record<ActivityType, ComponentType<SVGProps<SVGSVGElement>>> = {
  call: Phone,
  meeting: Users,
  email: Mail,
  note: StickyNote,
}

/**
 * Faz 14 denetim bulgusu: backend'in KABUL ETTİĞİ küme yalnız bu 4 değerdir, ama DB'de bunun
 * dışında (`'visit'` gibi) eski/bypass'lı değerler kalabilir (seed verisi geçmişte backend
 * validasyonunu bypass ederek yazmıştı — bkz. `DemoDataSeeder::ACTIVITY_TYPES`). Böyle bir
 * değer `ActivityType` union'ına EKLENMEZ (backend zaten reddediyor, eklemek geçersiz bir
 * değeri meşrulaştırır) — bunun yerine yukarıdaki literal eşlemelerde (`TYPE_ICON` vb.)
 * karşılığı olmayan HER değer için bu nötr düşüş kullanılır: nötr bir ikon + "Diğer" gibi
 * makul ama ham anahtar GİBİ GÖRÜNMEYEN bir etiket (`enums:activity.type.unknown`). Ham
 * değeri (`'visit'`) doğrudan basmak da tercih edilebilirdi, ama teknik bir DB literalini
 * kullanıcıya göstermemek için bu tercih edildi.
 */
export const UNKNOWN_TYPE_ICON: ComponentType<SVGProps<SVGSVGElement>> = HelpCircle
export const UNKNOWN_TYPE_VARIANT: NonNullable<BadgeProps['variant']> = 'neutral'
export const UNKNOWN_TYPE_LABEL_KEY = 'activity.type.unknown'

export function activityTypeOptions(t: TFunction): { value: ActivityType; label: string }[] {
  return (Object.keys(TYPE_LABEL_KEY) as ActivityType[]).map((value) => ({
    value,
    label: t(TYPE_LABEL_KEY[value], { ns: 'enums' }),
  }))
}

export function activityTypeIcon(type: ActivityType) {
  return TYPE_ICON[type]
}

/**
 * Faz 14 takip düzeltmesi: `ActivityTypeBadge` ve dashboard `RecentActivities` aynı
 * "bilinmeyen `type`" durumuyla karşı karşıya (bkz. yukarıdaki denetim bulgusu) ama iki farklı
 * şekilde ele alıyorlardı — biri nötr düşüşü kullanıyor, diğeri ham `type` metnini basıyordu.
 * Ortak sözleşme burada tek yerde tutulur: girdi backend'in HENÜZ doğrulamadığı/eski bir değer
 * olabileceği için `string` alınır, bilinen bir `ActivityType` ise literal eşleme, değilse
 * `UNKNOWN_TYPE_*` düşüşü döner. Tüketiciler (badge, liste, vb.) bunu kopyalamak yerine
 * çağırır.
 */
function isKnownActivityType(type: string): type is ActivityType {
  return type in TYPE_ICON
}

export function resolveActivityTypeIcon(type: string): ComponentType<SVGProps<SVGSVGElement>> {
  return isKnownActivityType(type) ? TYPE_ICON[type] : UNKNOWN_TYPE_ICON
}

export function resolveActivityTypeLabelKey(type: string): string {
  return isKnownActivityType(type) ? TYPE_LABEL_KEY[type] : UNKNOWN_TYPE_LABEL_KEY
}
