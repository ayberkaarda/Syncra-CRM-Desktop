// Takvimde bir güne tıklanınca açılan panel — o günün görevlerini listeler, hızlı tamamlama ve
// "+ Görev Ekle" sunar. Boş bir güne tıklanınca `TasksPage` bu modalı hiç açmaz, doğrudan
// `TaskFormModal`'ı o tarihle önceden doldurulmuş açar (bkz. görev tanımı).
import { Link } from 'react-router-dom'
import { CalendarPlus, Pencil, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Avatar, Button, Checkbox, Modal } from '../../../../components/ui'
import { cn } from '../../../../lib/cn'
import { formatTime } from '../../../../lib/datetime'
import { getIntlLocale } from '../../../../i18n'
import { usePermission } from '../../../auth/hooks/usePermission'
import { PriorityBadge } from '../PriorityBadge'
import { relatedRecordMeta } from '../relatedRecordMeta'
import type { Task } from '../../types'

export type DayTasksModalProps = {
  open: boolean
  onClose: () => void
  date: Date | null
  tasks: Task[]
  onAddNew: () => void
  onEdit: (task: Task) => void
  onDelete: (task: Task) => void
  onToggleComplete: (task: Task, completed: boolean) => void
  completingIds: Set<number>
}

export function DayTasksModal({
  open,
  onClose,
  date,
  tasks,
  onAddNew,
  onEdit,
  onDelete,
  onToggleComplete,
  completingIds,
}: DayTasksModalProps) {
  const { t } = useTranslation('tasks')
  const { can } = usePermission()
  const title = date
    ? new Intl.DateTimeFormat(getIntlLocale(), { day: 'numeric', month: 'long', year: 'numeric', weekday: 'long' }).format(date)
    : ''

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      description={t('dayModal.taskCount', { count: tasks.length })}
      size="md"
      footer={
        can('tasks.create') ? (
          <div className="flex justify-end">
            <Button leftIcon={<CalendarPlus className="size-4" aria-hidden="true" />} onClick={onAddNew}>
              {t('dayModal.addTask')}
            </Button>
          </div>
        ) : undefined
      }
    >
      {tasks.length === 0 ? (
        <p className="py-6 text-center text-sm text-fg-muted">{t('dayModal.empty')}</p>
      ) : (
        <ul className="flex flex-col divide-y divide-border-subtle">
          {tasks.map((task) => {
            const meta = task.taskable ? relatedRecordMeta(task.taskable.type, t) : null
            const Icon = meta?.icon
            const isCompleting = completingIds.has(task.id)
            return (
              <li key={task.id} className="flex items-start gap-3 py-3">
                {/* Faz 13: bkz. `TasksPage.tsx`teki aynı gerekçe — `can.complete` false ise
                    (sahiplik) kutu gizlenmez, devre dışı + tooltip gösterilir. */}
                {can('tasks.update') && (
                  <div className="pt-0.5">
                    <Checkbox
                      checked={task.status === 'completed'}
                      disabled={task.status === 'cancelled' || isCompleting || !task.can.complete}
                      onChange={(e) => onToggleComplete(task, e.target.checked)}
                      aria-label={t('row.completeAria', { title: task.title })}
                      title={task.can.complete ? undefined : t('row.completeDisabledTitle')}
                    />
                  </div>
                )}
                <div className="min-w-0 flex-1">
                  <p
                    className={cn(
                      'truncate text-sm font-medium text-fg',
                      task.status === 'completed' && 'text-fg-muted line-through'
                    )}
                  >
                    {task.title}
                  </p>
                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-fg-muted">
                    <PriorityBadge priority={task.priority} size="sm" />
                    <span className={cn(task.is_overdue && 'font-medium text-danger')}>{formatTime(task.due_at)}</span>
                    {task.assignee && (
                      <span className="inline-flex items-center gap-1.5">
                        <Avatar name={task.assignee.name} size="xs" />
                        {task.assignee.name}
                      </span>
                    )}
                    {task.taskable && meta && Icon && (
                      <Link to={meta.path(task.taskable.id)} className="inline-flex items-center gap-1 hover:text-primary hover:underline">
                        <Icon className="size-3.5" aria-hidden="true" />
                        {task.taskable.label ?? meta.label}
                      </Link>
                    )}
                  </div>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  {can('tasks.update') && (
                    <button
                      type="button"
                      onClick={() => onEdit(task)}
                      disabled={!task.can.update}
                      aria-label={t('row.edit')}
                      title={task.can.update ? t('row.edit') : t('row.editDisabledTitle')}
                      className={cn(
                        'inline-flex size-7 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
                        'disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-fg-muted'
                      )}
                    >
                      <Pencil className="size-3.5" aria-hidden="true" />
                    </button>
                  )}
                  {/* `tasks.delete` saf izin kontrolüdür — sahiplik boyutu yok, gizlemek yeterli. */}
                  {can('tasks.delete') && task.can.delete && (
                    <button
                      type="button"
                      onClick={() => onDelete(task)}
                      aria-label={t('row.delete')}
                      title={t('row.delete')}
                      className="inline-flex size-7 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-danger"
                    >
                      <Trash2 className="size-3.5" aria-hidden="true" />
                    </button>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </Modal>
  )
}
