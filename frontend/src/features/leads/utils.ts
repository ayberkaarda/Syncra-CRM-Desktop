// Leads modülü için paylaşılan sabitler/yardımcılar — liste, form, detay ve
// dönüştürme modalı arasında tekrarı önler.
//
// Faz 14 / İz D: enum etiketleri ARTIK METİN DEĞİL, `enums` namespace'indeki ANAHTAR taşır (bkz.
// `features/activities/components/activityTypeMeta.ts`'teki aynı gerekçe) — bir modül sabiti
// değerlendirme anında `t()` çağırsaydı metin ilk yüklenen dile donardı. Tüketiciler
// `leadSourceOptions(t)`/`leadStatusOptions(t)` ile (Select seçenekleri) ya da doğrudan
// `t(SOURCE_LABEL_KEY[source], { ns: 'enums' })` ile çözer. `MATCH_REASON_LABEL_KEY`/
// `DUPLICATE_LEVEL_LABEL_KEY` ise `leads` namespace'inde kalır (backend enum'u değil, yalnızca
// bu modüle özgü duplicate-tespiti metni).
import type { TFunction } from 'i18next'
import type { BadgeProps } from '../../components/ui'
import type { DuplicateLevel, DuplicateMatchReason, LeadSource, LeadStatus } from './types'

/** `enums` namespace anahtarı (önek `lead.source.*` — bkz. docs/PHASE-INTL.md §1.3/§1.5). */
export const SOURCE_LABEL_KEY: Record<LeadSource, string> = {
  website: 'lead.source.website',
  referral: 'lead.source.referral',
  cold_call: 'lead.source.cold_call',
  email_campaign: 'lead.source.email_campaign',
  social_media: 'lead.source.social_media',
  event: 'lead.source.event',
  other: 'lead.source.other',
}

export function leadSourceOptions(t: TFunction): Array<{ value: LeadSource; label: string }> {
  return (Object.keys(SOURCE_LABEL_KEY) as LeadSource[]).map((value) => ({
    value,
    label: t(SOURCE_LABEL_KEY[value], { ns: 'enums' }),
  }))
}

/**
 * Faz 14 takip düzeltmesi: raporlama tarafı (`SourceAnalysisReport::run`) `leads.source`
 * kolonunu DOĞRUDAN `GROUP BY` ile okur, `StoreLeadRequest`in `Rule::in()` beyaz listesiyle
 * yeniden doğrulamaz — dolayısıyla `SourceAnalysisRow.source` (bkz. `reports/types.ts`) geniş
 * `string` kalır ve teorik olarak `LeadSource` dışında eski/geçersiz bir değer taşıyabilir
 * (`activityTypeMeta.ts`teki `'visit'` bulgusuyla AYNI sınıf risk). Bu yüzden ham değer hâlâ
 * `SOURCE_LABEL_KEY`e karşı ÇÖZÜLÜR ama bilinmeyen bir değer için (roleLabel/ticketCategoryLabel
 * deseninde olduğu gibi) ham metne düşülür, çökmez.
 */
export function leadSourceLabel(value: string, t: TFunction): string {
  const key = SOURCE_LABEL_KEY[value as LeadSource]
  if (!key) return value
  return t(key, { ns: 'enums', defaultValue: value })
}

/** `enums` namespace anahtarı (önek `lead.status.*`). */
export const STATUS_LABEL_KEY: Record<LeadStatus, string> = {
  new: 'lead.status.new',
  contacted: 'lead.status.contacted',
  qualified: 'lead.status.qualified',
  unqualified: 'lead.status.unqualified',
  converted: 'lead.status.converted',
}

/** Formdaki durum seçiminde `converted` KASITLI olarak yok (bkz. görev tanımı). */
const EDITABLE_STATUSES: LeadStatus[] = ['new', 'contacted', 'qualified', 'unqualified']

export function editableLeadStatusOptions(t: TFunction): Array<{ value: LeadStatus; label: string }> {
  return EDITABLE_STATUSES.map((value) => ({ value, label: t(STATUS_LABEL_KEY[value], { ns: 'enums' }) }))
}

export const STATUS_BADGE_VARIANT: Record<LeadStatus, NonNullable<BadgeProps['variant']>> = {
  new: 'neutral',
  contacted: 'primary',
  qualified: 'success',
  unqualified: 'danger',
  converted: 'success',
}

export function scoreVariant(score: number): 'danger' | 'warning' | 'success' {
  if (score >= 67) return 'success'
  if (score >= 34) return 'warning'
  return 'danger'
}

/** `leads` namespace anahtarı (önek `matchReason.*`) — backend enum'u değil, yalnızca duplicate
 *  tespiti panelinin gösterdiği bir eşleşme nedeni etiketi. */
export const MATCH_REASON_LABEL_KEY: Record<DuplicateMatchReason, string> = {
  email: 'leads:matchReason.email',
  phone: 'leads:matchReason.phone',
  name: 'leads:matchReason.name',
}

/** `leads` namespace anahtarı (önek `duplicateLevel.*`). */
export const DUPLICATE_LEVEL_LABEL_KEY: Record<DuplicateLevel, string> = {
  strong: 'leads:duplicateLevel.strong',
  possible: 'leads:duplicateLevel.possible',
}
