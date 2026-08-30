// Talebe bağlı görev oluşturma modalı — YENİ bir uç YOK: C şeridin `POST /api/tasks` ucu
// kullanılır, `taskable_type: 'ticket'` + `taskable_id` SABİTTİR (kullanıcı değiştiremez).
// Yalnızca OLUŞTURMA modu vardır (görev tanımı: "+ Görev Ekle ile ticket'a bağlı görev
// oluşturma") — mevcut bir görevi düzenlemek/taşımak genel `/tasks` sayfasının işidir.
//
// C şeridin `features/tasks/api/tasksApi.ts` (`useCreateTask`, `useTaskUserOptions`) ve
// `features/tasks/components/priorityMeta.ts` / `taskStatusMeta.ts` / `dateTimeInput.ts`
// dosyaları DOĞRUDAN kullanılır — kopya YAZILMAZ.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { priorityOptions } from '../../tasks/components/priorityMeta'
import { createStatusOptions } from '../../tasks/components/taskStatusMeta'
import { localInputToIso } from '../../tasks/components/dateTimeInput'
import { useCreateTask, useTaskUserOptions } from '../../tasks/api/tasksApi'
import type { Task } from '../../tasks/types'
import type { Ticket } from '../types'

export type TicketTaskFormModalProps = {
  open: boolean
  onClose: () => void
  ticket: Ticket
}

export function TicketTaskFormModal({ open, onClose, ticket }: TicketTaskFormModalProps) {
  const { t } = useTranslation(['tickets', 'enums'])
  const { data: userOptions, isForbidden: usersForbidden } = useTaskUserOptions()
  const createTask = useCreateTask()

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [dueAt, setDueAt] = useState('')
  const [reminderAt, setReminderAt] = useState('')
  const [priority, setPriority] = useState('normal')
  const [status, setStatus] = useState('pending')
  const [assignedTo, setAssignedTo] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [reminderClientError, setReminderClientError] = useState<string | undefined>(undefined)

  const openKey = open ? `create-${ticket.id}` : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setTitle('')
      setDescription('')
      setDueAt('')
      setReminderAt('')
      setPriority('normal')
      setStatus('pending')
      // Talebe atanan kişi varsa görev de doğal olarak ona önerilir — kullanıcı isterse değiştirir.
      setAssignedTo(ticket.assignee ? String(ticket.assignee.id) : '')
      setFieldErrors({})
      setReminderClientError(undefined)
    }
  }

  const isPending = createTask.isPending

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!title.trim()) errors.title = [t('tickets:tasks.form.validation.titleRequired')]
    const reminderAfterDue = !!dueAt && !!reminderAt && reminderAt > dueAt
    setReminderClientError(reminderAfterDue ? t('tickets:tasks.form.validation.reminderAfterDue') : undefined)
    if (reminderAfterDue) errors.reminder_at = [t('tickets:tasks.form.validation.reminderAfterDue')]
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    try {
      await createTask.mutateAsync({
        title,
        description: description || undefined,
        due_at: dueAt ? localInputToIso(dueAt) : null,
        reminder_at: reminderAt ? localInputToIso(reminderAt) : null,
        priority: priority as Task['priority'],
        status: status as Task['status'],
        assigned_to: assignedTo ? Number(assignedTo) : null,
        taskable_type: 'ticket',
        taskable_id: ticket.id,
      })
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  const assigneeOptions = [
    { value: '', label: t('tickets:tasks.form.unassignedOption') },
    ...(userOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('tickets:tasks.form.title')}
      description={`${ticket.ticket_number} — ${ticket.subject}`}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('tickets:tasks.form.cancel')}
          </Button>
          <Button type="submit" form="ticket-task-form" loading={isPending}>
            {t('tickets:tasks.form.submit')}
          </Button>
        </div>
      }
    >
      <form id="ticket-task-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Input
          label={t('tickets:tasks.form.titleFieldLabel')}
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          error={fieldError('title')}
          required
        />

        <Textarea
          label={t('tickets:tasks.form.descriptionLabel')}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          error={fieldError('description')}
        />

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('tickets:tasks.form.dueLabel')}
            type="datetime-local"
            value={dueAt}
            onChange={(e) => {
              setDueAt(e.target.value)
              if (reminderAt && e.target.value && reminderAt > e.target.value) setReminderAt('')
            }}
            error={fieldError('due_at')}
          />
          <Input
            label={t('tickets:tasks.form.reminderLabel')}
            type="datetime-local"
            value={reminderAt}
            onChange={(e) => {
              setReminderAt(e.target.value)
              setReminderClientError(undefined)
            }}
            max={dueAt || undefined}
            disabled={!dueAt}
            hint={!dueAt ? t('tickets:tasks.form.reminderHint') : undefined}
            error={reminderClientError ?? fieldError('reminder_at')}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Select
            label={t('tickets:tasks.form.priorityLabel')}
            value={priority}
            onChange={(e) => setPriority(e.target.value)}
            options={priorityOptions(t)}
            error={fieldError('priority')}
          />
          <Select
            label={t('tickets:tasks.form.statusLabel')}
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            options={createStatusOptions(t)}
            error={fieldError('status')}
          />
          {!usersForbidden && (
            <Select
              label={t('tickets:tasks.form.assigneeLabel')}
              value={assignedTo}
              onChange={(e) => setAssignedTo(e.target.value)}
              options={assigneeOptions}
              error={fieldError('assigned_to')}
            />
          )}
        </div>

        <p className="rounded-md bg-surface-2 px-3 py-2 text-xs text-fg-muted">
          {t('tickets:tasks.form.autoLinkedHint', { ticketNumber: ticket.ticket_number })}
        </p>
      </form>
    </Modal>
  )
}
