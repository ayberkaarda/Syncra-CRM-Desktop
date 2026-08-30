// Görev oluşturma/düzenleme modalı. `task` verilmezse (null/undefined) oluşturma modu.
//
// Durum Select'i HER İKİ modda da `completed` SUNMAZ (yalnızca create'te değil): backend
// `PATCH /api/tasks/{id}` durumu 'completed' kabul etse de `completed_at`'i bu uçtan KESİNLİKLE
// yazmaz (`UpdateTaskRequest`'te `completed_at => ['missing']` + `TaskService::update()` veriyi
// olduğu gibi geçirir) — status'u buradan 'completed' yapmak `completed_at=null` kalan tutarsız
// bir kayıt üretirdi. Tamamlama TEK yol: `/complete` ucu (bkz. satır içi checkbox / `useCompleteTask`).
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { priorityOptions } from './priorityMeta'
import { createStatusOptions } from './taskStatusMeta'
import { RelatedRecordPicker } from './RelatedRecordPicker'
import type { RelatedRecordValue } from './RelatedRecordPicker'
import { isoToLocalInput, localInputToIso } from './dateTimeInput'
import { useCreateTask, useTaskUserOptions, useUpdateTask } from '../api/tasksApi'
import type { Task } from '../types'

export type TaskFormModalProps = {
  open: boolean
  onClose: () => void
  /** Verilirse düzenleme, yoksa oluşturma modu. */
  task?: Task | null
  /** Takvimde boş bir güne tıklanınca o tarihle önceden doldurulmuş form açılır (09:00 yerel). */
  defaultDueDate?: string | null
}

export function TaskFormModal({ open, onClose, task, defaultDueDate }: TaskFormModalProps) {
  const { t } = useTranslation(['tasks', 'common'])
  const isEdit = !!task

  const { data: userOptions, isForbidden: usersForbidden } = useTaskUserOptions()
  const createTask = useCreateTask()
  const updateTask = useUpdateTask()

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [dueAt, setDueAt] = useState('')
  const [reminderAt, setReminderAt] = useState('')
  const [priority, setPriority] = useState('normal')
  const [status, setStatus] = useState('pending')
  const [assignedTo, setAssignedTo] = useState('')
  const [related, setRelated] = useState<RelatedRecordValue>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [reminderClientError, setReminderClientError] = useState<string | undefined>(undefined)

  const openKey = open ? (task ? `edit-${task.id}` : `create-${defaultDueDate ?? ''}`) : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setTitle(task?.title ?? '')
      setDescription(task?.description ?? '')
      setDueAt(task ? isoToLocalInput(task.due_at) : defaultDueDate ? `${defaultDueDate}T09:00` : '')
      setReminderAt(task ? isoToLocalInput(task.reminder_at) : '')
      setPriority(task?.priority ?? 'normal')
      setStatus(task && task.status !== 'completed' ? task.status : 'pending')
      setAssignedTo(task?.assignee ? String(task.assignee.id) : '')
      setRelated(task?.taskable ? { type: task.taskable.type, id: task.taskable.id, label: task.taskable.label ?? '' } : null)
      setFieldErrors({})
      setReminderClientError(undefined)
    }
  }

  const isPending = createTask.isPending || updateTask.isPending

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!title.trim()) errors.title = [t('tasks:form.titleRequired')]
    const reminderAfterDue = !!dueAt && !!reminderAt && reminderAt > dueAt
    setReminderClientError(reminderAfterDue ? t('tasks:form.reminderAfterDue') : undefined)
    if (reminderAfterDue) errors.reminder_at = [t('tasks:form.reminderAfterDue')]
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const basePayload = {
      title,
      description: description || undefined,
      due_at: dueAt ? localInputToIso(dueAt) : null,
      reminder_at: reminderAt ? localInputToIso(reminderAt) : null,
      priority: priority as Task['priority'],
      assigned_to: assignedTo ? Number(assignedTo) : null,
      taskable_type: related && related.id > 0 ? related.type : null,
      taskable_id: related && related.id > 0 ? related.id : null,
    }

    try {
      if (isEdit && task) {
        await updateTask.mutateAsync({ id: task.id, payload: { ...basePayload, status: status as Task['status'] } })
      } else {
        await createTask.mutateAsync({ ...basePayload, status: status as Task['status'] })
      }
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  const assigneeOptions = [
    { value: '', label: t('tasks:common.unassigned') },
    ...(userOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? t('tasks:form.editTitle') : t('tasks:form.createTitle')}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" form="task-form" loading={isPending}>
            {isEdit ? t('common:actions.save') : t('tasks:form.create')}
          </Button>
        </div>
      }
    >
      <form id="task-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Input
          label={t('tasks:form.titleLabel')}
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          error={fieldError('title')}
          required
        />

        <Textarea
          label={t('tasks:form.descriptionLabel')}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          error={fieldError('description')}
        />

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('tasks:form.dueDateLabel')}
            type="datetime-local"
            value={dueAt}
            onChange={(e) => {
              setDueAt(e.target.value)
              if (reminderAt && e.target.value && reminderAt > e.target.value) setReminderAt('')
            }}
            error={fieldError('due_at')}
          />
          <Input
            label={t('tasks:form.reminderLabel')}
            type="datetime-local"
            value={reminderAt}
            onChange={(e) => {
              setReminderAt(e.target.value)
              setReminderClientError(undefined)
            }}
            max={dueAt || undefined}
            disabled={!dueAt}
            hint={!dueAt ? t('tasks:form.reminderHintNoDueDate') : undefined}
            error={reminderClientError ?? fieldError('reminder_at')}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Select
            label={t('tasks:form.priorityLabel')}
            value={priority}
            onChange={(e) => setPriority(e.target.value)}
            options={priorityOptions(t)}
            error={fieldError('priority')}
          />
          <Select
            label={t('tasks:form.statusLabel')}
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            options={createStatusOptions(t)}
            hint={t('tasks:form.statusHint')}
            error={fieldError('status')}
          />
          {!usersForbidden && (
            <Select
              label={t('tasks:form.assigneeLabel')}
              value={assignedTo}
              onChange={(e) => setAssignedTo(e.target.value)}
              options={assigneeOptions}
              error={fieldError('assigned_to')}
            />
          )}
        </div>

        <RelatedRecordPicker
          value={related}
          onChange={setRelated}
          typeError={fieldError('taskable_type')}
          idError={fieldError('taskable_id')}
        />
      </form>
    </Modal>
  )
}
