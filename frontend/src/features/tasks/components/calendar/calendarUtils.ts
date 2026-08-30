// Aylık ızgara için SAF tarih yardımcıları — harici takvim/tarih kütüphanesi YOK (bkz. görev
// tanımı KESİN YASAKLAR), yalnızca native `Date` ve `Intl`. Hafta Pazartesi'den başlar (TR
// takvim geleneği).
import { getIntlLocale } from '../../../../i18n'
export type CalendarDay = {
  date: Date
  /** Yerel (tarayıcı saat dilimi) `YYYY-MM-DD` — `due_at`'in yerel gün karşılığıyla eşleştirmede
   * anahtar olarak kullanılır. `toISOString().slice(0,10)` KULLANILMAZ: o UTC gündür ve yerel
   * akşam saatlerinde bir gün kayardı. */
  ymd: string
  inCurrentMonth: boolean
  isToday: boolean
  isWeekend: boolean
}

// Etiketler `tasks:calendar.weekday.*` anahtarındadır (Faz 14 / İz D) — bu dosya saf/i18n'siz
// kalır (bkz. dosya başı notu), çağıran component (`CalendarGrid`) kendi `t`'siyle çevirir.
export const WEEKDAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

/** Yerel tarih -> `YYYY-MM-DD`. */
export function toLocalYmd(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`
}

/** Pazartesi=0 ... Pazar=6 olacak şekilde JS'in Pazar=0 haftasını çevirir. */
function mondayFirstWeekday(date: Date): number {
  return (date.getDay() + 6) % 7
}

/**
 * Verilen ay için 6 haftalık (42 hücre) ızgara üretir — önceki/sonraki ayın taşan günleri
 * `inCurrentMonth: false` ile dahil edilir (ızgara satırları hep tam görünsün diye) ama
 * bu günler için görev İSTENMEZ (bkz. `TasksPage`: `from`/`to` yalnızca gösterilen AYI kapsar,
 * bu yüzden taşan hücreler her zaman boş görünür — 90 günlük backend sınırına asla yaklaşmayan
 * kasıtlı bir sadeleştirme).
 */
export function buildMonthGrid(year: number, month: number): CalendarDay[] {
  const firstOfMonth = new Date(year, month, 1)
  const startOffset = mondayFirstWeekday(firstOfMonth)
  const gridStart = new Date(year, month, 1 - startOffset)

  const today = new Date()
  const todayYmd = toLocalYmd(today)

  const days: CalendarDay[] = []
  for (let i = 0; i < 42; i++) {
    const date = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i)
    const weekday = mondayFirstWeekday(date)
    days.push({
      date,
      ymd: toLocalYmd(date),
      inCurrentMonth: date.getMonth() === month,
      isToday: toLocalYmd(date) === todayYmd,
      isWeekend: weekday === 5 || weekday === 6,
    })
  }
  return days
}

/** Gösterilen ayın ilk/son günü — `GET /api/tasks/calendar` `from`/`to` parametreleri. */
export function monthRange(year: number, month: number): { from: string; to: string } {
  const from = new Date(year, month, 1)
  const to = new Date(year, month + 1, 0)
  return { from: toLocalYmd(from), to: toLocalYmd(to) }
}

export function monthLabel(year: number, month: number): string {
  return new Intl.DateTimeFormat(getIntlLocale(), { month: 'long', year: 'numeric' }).format(new Date(year, month, 1))
}

/** `due_at` (ISO) -> yerel `YYYY-MM-DD`, ızgara hücresiyle eşleştirmek için. */
export function dueAtToLocalYmd(dueAt: string): string | null {
  const date = new Date(dueAt)
  if (Number.isNaN(date.getTime())) return null
  return toLocalYmd(date)
}
