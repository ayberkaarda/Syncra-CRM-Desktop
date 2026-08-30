// Tarih aralığı yardımcıları — `DateRangeFilter` ve varsayılan aralık (son 30 gün) burada
// toplanır ki Dashboard ve Raporlar aynı hesaplamayı paylaşsın.
import type { TFunction } from 'i18next'
import { formatDate } from '../../lib/datetime'

function toIsoDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function daysAgo(days: number): Date {
  const date = new Date()
  date.setHours(0, 0, 0, 0)
  date.setDate(date.getDate() - days)
  return date
}

export function todayIso(): string {
  return toIsoDate(new Date())
}

/** Varsayılan aralık: son 30 gün (görev tanımı §Veri sözleşmesi — kesin). */
export function defaultDateRange(): { from: string; to: string } {
  return { from: toIsoDate(daysAgo(29)), to: todayIso() }
}

export type DateRangePreset = {
  key: string
  label: string
  range: () => { from: string; to: string }
}

/** `range()` hesaplaması dilden bağımsızdır — `matchPreset` bu ham listeyi kullanır ki `t()`
 *  gerektirmesin. Etiket metni ayrı olarak `dateRangePresets(t)` ile eklenir. */
const PRESET_RANGES: Array<{ key: string; labelKey: string; range: () => { from: string; to: string } }> = [
  { key: 'today', labelKey: 'reports:dateRange.presets.today', range: () => ({ from: todayIso(), to: todayIso() }) },
  { key: 'last7', labelKey: 'reports:dateRange.presets.last7', range: () => ({ from: toIsoDate(daysAgo(6)), to: todayIso() }) },
  { key: 'last30', labelKey: 'reports:dateRange.presets.last30', range: () => ({ from: toIsoDate(daysAgo(29)), to: todayIso() }) },
  { key: 'last90', labelKey: 'reports:dateRange.presets.last90', range: () => ({ from: toIsoDate(daysAgo(89)), to: todayIso() }) },
]

/** Faz 14 / İz D: preset etiketleri `reports:dateRange.presets.*` çeviri anahtarından üretilir —
 *  modül sabiti DEĞİL, `t()` çağrısı gerektirdiğinden fonksiyon olarak sunulur (bkz.
 *  `features/activities/components/activityTypeMeta.ts`'teki aynı gerekçe). */
export function dateRangePresets(t: TFunction): DateRangePreset[] {
  return PRESET_RANGES.map((preset) => ({ key: preset.key, label: t(preset.labelKey), range: preset.range }))
}

/** Seçili `from`/`to` bir preset'e eşleniyorsa anahtarını döner, yoksa `null` (özel aralık). */
export function matchPreset(from: string, to: string): string | null {
  const preset = PRESET_RANGES.find((p) => {
    const r = p.range()
    return r.from === from && r.to === to
  })
  return preset?.key ?? null
}

/** `ConversionReport::STATUSES` ile aynı sıra — Dönüşüm sekmesindeki `by_status` dağılımı bu
 * sırayla çizilir (ordinal: sıra anlam taşır, yeniden sıralanmaz). */
export const LEAD_STATUS_ORDER = ['new', 'contacted', 'qualified', 'unqualified', 'converted'] as const

/** Aktif arayüz diline göre biçimlenir (bkz. `lib/datetime.ts` — Faz 14/İz D §1.8, sabit
 *  `Intl.DateTimeFormat('tr-TR')` borcu buradan devralınıp merkeze taşındı). */
export function formatDateLabel(iso: string): string {
  return formatDate(`${iso}T00:00:00`)
}
