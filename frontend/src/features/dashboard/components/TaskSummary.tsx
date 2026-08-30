// Görev özeti — `App\Http\Resources\Reports\TaskSummaryResource` ile birebir: dört sayaç
// (`open_count`/`overdue_count`/`due_today_count`/`completed_today_count`, tarih parametresi
// almaz, daima "şu an" anlık görüntüsü) + öncelik dağılımı (`by_priority`). Grafik değil
// (choosing-a-form.md: "A handful of headline numbers → KPI row of stat tiles"). Gecikmiş görev
// durum rengini taşır (`danger`) — durum rengi yalnızca gerçekten bir durumu ifade ettiğinde
// kullanılır (color-formula.md "Status is fixed").
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { AlertTriangle, CalendarClock, CheckCircle2, ListTodo } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { formatNumber } from '../../../lib/money'
import type { TaskPriority, TaskSummary as TaskSummaryData } from '../types'

type Row = {
  key: keyof Omit<TaskSummaryData, 'by_priority'>
  label: string
  icon: LucideIcon
  tone: 'danger' | 'warning' | 'neutral' | 'success'
}

function rows(t: TFunction): Row[] {
  return [
    { key: 'overdue_count', label: t('dashboard:taskSummary.overdue'), icon: AlertTriangle, tone: 'danger' },
    { key: 'due_today_count', label: t('dashboard:taskSummary.dueToday'), icon: CalendarClock, tone: 'warning' },
    { key: 'open_count', label: t('dashboard:taskSummary.open'), icon: ListTodo, tone: 'neutral' },
    { key: 'completed_today_count', label: t('dashboard:taskSummary.completedToday'), icon: CheckCircle2, tone: 'success' },
  ]
}

const TONE_CLASSES: Record<Row['tone'], string> = {
  danger: 'bg-danger-tint text-danger',
  warning: 'bg-warning-tint text-warning',
  neutral: 'bg-surface-2 text-fg-muted',
  success: 'bg-success-tint text-success',
}

/** `enums:task.priority.*` — Görevler modülüyle aynı çeviri anahtarları (bkz. görev tanımı;
 *  bu değerler backend enum'u, kopya bir etiket sözlüğü İCAT EDİLMEZ). */
const PRIORITY_LABEL_KEY: Record<TaskPriority, string> = {
  low: 'task.priority.low',
  normal: 'task.priority.normal',
  high: 'task.priority.high',
  urgent: 'task.priority.urgent',
}

const PRIORITY_ORDER: TaskPriority[] = ['urgent', 'high', 'normal', 'low']

export type TaskSummaryProps = {
  summary: TaskSummaryData | undefined
  isLoading: boolean
}

export function TaskSummary({ summary, isLoading }: TaskSummaryProps) {
  const { t } = useTranslation(['dashboard', 'enums'])
  const ROWS = rows(t)
  return (
    <div className="flex flex-col gap-4">
      <ul className="flex flex-col gap-3">
        {ROWS.map((row) => (
          <li key={row.key} className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-2.5">
              <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-md', TONE_CLASSES[row.tone])}>
                <row.icon className="size-4" aria-hidden="true" />
              </span>
              <span className="text-sm text-fg-secondary">{row.label}</span>
            </div>
            {isLoading || !summary ? (
              <Skeleton variant="text" width={28} />
            ) : (
              <span className="text-sm font-semibold text-fg">{formatNumber(summary[row.key])}</span>
            )}
          </li>
        ))}
      </ul>

      <div className="flex flex-wrap gap-1.5 border-t border-border-subtle pt-3">
        {PRIORITY_ORDER.map((priority) => (
          <span
            key={priority}
            className="inline-flex items-center gap-1 rounded-md bg-surface-2 px-2 py-1 text-xs text-fg-muted"
          >
            {t(PRIORITY_LABEL_KEY[priority], { ns: 'enums' })}
            <span className="font-semibold text-fg">
              {isLoading || !summary ? '—' : formatNumber(summary.by_priority[priority])}
            </span>
          </span>
        ))}
      </div>
    </div>
  )
}
