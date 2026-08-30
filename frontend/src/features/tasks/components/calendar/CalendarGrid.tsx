// Aylık takvim ızgarası — kendi düzenimiz (harici takvim kütüphanesi YOK, bkz. görev tanımı).
// Tasarım deseni `docs/DESIGN-SYSTEM.md` §7 "Takvim": ay navigasyonu, hafta günü başlıkları, gün
// hücreleri (hafta sonu hafifçe ayrışır), bugünün hücresi belirgin.
import { useTranslation } from 'react-i18next'
import { cn } from '../../../../lib/cn'
import { PRIORITY_DOT_CLASS } from '../priorityMeta'
import { WEEKDAY_KEYS } from './calendarUtils'
import type { CalendarDay } from './calendarUtils'
import type { Task } from '../../types'

const MAX_BADGES = 3

export type CalendarGridProps = {
  days: CalendarDay[]
  tasksByDay: Record<string, Task[]>
  onDayClick: (day: CalendarDay) => void
}

export function CalendarGrid({ days, tasksByDay, onDayClick }: CalendarGridProps) {
  const { t } = useTranslation('tasks')
  return (
    <div className="flex flex-col gap-px overflow-hidden rounded-lg border border-border-subtle bg-border-subtle">
      <div className="grid grid-cols-7 gap-px">
        {WEEKDAY_KEYS.map((key, index) => (
          <div
            key={key}
            className={cn(
              'bg-surface-1 px-2 py-2 text-center text-xs font-medium text-fg-muted',
              index >= 5 && 'text-fg-secondary'
            )}
          >
            {t(`calendar.weekday.${key}`)}
          </div>
        ))}
      </div>
      <div className="grid grid-cols-7 gap-px">
        {days.map((day) => {
          const dayTasks = tasksByDay[day.ymd] ?? []
          const visible = dayTasks.slice(0, MAX_BADGES)
          const overflow = dayTasks.length - visible.length

          return (
            <button
              key={day.ymd}
              type="button"
              onClick={() => onDayClick(day)}
              className={cn(
                'flex min-h-24 flex-col items-stretch gap-1 p-1.5 text-left',
                'transition-colors duration-150 motion-reduce:transition-none',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset',
                day.inCurrentMonth ? 'bg-surface-1' : 'bg-surface-0',
                day.isWeekend && day.inCurrentMonth && 'bg-surface-2',
                'hover:bg-surface-3'
              )}
            >
              <span
                className={cn(
                  'inline-flex size-6 items-center justify-center self-start rounded-md text-xs font-medium',
                  day.isToday
                    ? 'bg-primary text-primary-fg'
                    : day.inCurrentMonth
                      ? 'text-fg'
                      : 'text-fg-disabled'
                )}
              >
                {day.date.getDate()}
              </span>
              <div className="flex flex-col gap-0.5">
                {visible.map((task) => (
                  <span
                    key={task.id}
                    className={cn(
                      'flex items-center gap-1 truncate rounded-sm px-1 py-0.5 text-xs leading-tight',
                      'bg-surface-2 text-fg-secondary',
                      task.is_overdue && 'text-danger'
                    )}
                    title={task.title}
                  >
                    <span className={cn('size-1.5 shrink-0 rounded-full', PRIORITY_DOT_CLASS[task.priority])} aria-hidden="true" />
                    <span className="truncate">{task.title}</span>
                  </span>
                ))}
                {overflow > 0 && (
                  <span className="px-1 text-xs text-fg-muted">{t('calendar.overflowMore', { count: overflow })}</span>
                )}
              </div>
            </button>
          )
        })}
      </div>
    </div>
  )
}
