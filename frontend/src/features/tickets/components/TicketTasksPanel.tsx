// Talebe bağlı görevler bölümü — C şeridin `features/tasks/api/tasksApi.ts` (`useTasks`) ve
// `TaskStatusBadge`/`PriorityBadge` bileşenleri DOĞRUDAN kullanılır (kopya YAZILMAZ).
// `GET /api/tasks?filter[taskable_type]=ticket&filter[taskable_id]={id}`.
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ListPlus } from 'lucide-react'
import { Avatar, Button, Card, CardBody, CardHeader, Skeleton } from '../../../components/ui'
import { formatDateTime } from '../../../lib/datetime'
import { useTasks } from '../../tasks/api/tasksApi'
import { PriorityBadge } from '../../tasks/components/PriorityBadge'
import { TaskStatusBadge } from '../../tasks/components/TaskStatusBadge'
import { TicketTaskFormModal } from './TicketTaskFormModal'
import type { Ticket } from '../types'

export function TicketTasksPanel({ ticket }: { ticket: Ticket }) {
  const { t } = useTranslation('tickets')
  const { data, isLoading, isError, refetch } = useTasks({ taskable_type: 'ticket', taskable_id: ticket.id, per_page: 50, sort: 'due_at' })
  const [addOpen, setAddOpen] = useState(false)

  const tasks = data?.data ?? []

  return (
    <>
      <Card>
        <CardHeader
          title={t('tasks.title')}
          subtitle={t('tasks.subtitle', { count: tasks.length })}
          action={
            <Button size="sm" leftIcon={<ListPlus className="size-4" aria-hidden="true" />} onClick={() => setAddOpen(true)}>
              {t('tasks.add')}
            </Button>
          }
        />
        <CardBody className="flex flex-col gap-3">
          {isLoading ? (
            <Skeleton variant="text" lines={3} />
          ) : isError ? (
            <div className="flex items-center justify-between gap-2">
              <p className="text-sm text-fg-muted">{t('tasks.loadError')}</p>
              <Button variant="secondary" size="sm" onClick={() => refetch()}>
                {t('tasks.retry')}
              </Button>
            </div>
          ) : tasks.length === 0 ? (
            <p className="text-sm text-fg-muted">{t('tasks.empty')}</p>
          ) : (
            tasks.map((task) => (
              <div key={task.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border-subtle p-3">
                <div className="flex min-w-0 flex-col gap-1">
                  <span className="truncate text-sm font-medium text-fg">{task.title}</span>
                  <div className="flex flex-wrap items-center gap-2">
                    <PriorityBadge priority={task.priority} size="sm" />
                    <TaskStatusBadge status={task.status} size="sm" />
                    <span className={task.is_overdue ? 'text-xs font-medium text-danger' : 'text-xs text-fg-muted'}>
                      {t('tasks.dueLabel', { value: formatDateTime(task.due_at) })}
                    </span>
                  </div>
                </div>
                {task.assignee && (
                  <div className="flex shrink-0 items-center gap-2">
                    <Avatar name={task.assignee.name} size="xs" />
                    <span className="text-sm text-fg-secondary">{task.assignee.name}</span>
                  </div>
                )}
              </div>
            ))
          )}
        </CardBody>
      </Card>

      <TicketTaskFormModal open={addOpen} onClose={() => setAddOpen(false)} ticket={ticket} />
    </>
  )
}
