// Görev atama modalı — `deals/components/AssignDealOwnerModal.tsx` ile aynı desen.
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal, Select } from '../../../components/ui'
import { useAssignTask, useTaskUserOptions } from '../api/tasksApi'
import type { Task } from '../types'

export type AssignTaskModalProps = {
  open: boolean
  onClose: () => void
  task: Task | null
}

export function AssignTaskModal({ open, onClose, task }: AssignTaskModalProps) {
  const { t } = useTranslation(['tasks', 'common'])
  const { data: userOptions } = useTaskUserOptions()
  const assignTask = useAssignTask()
  const [assignedTo, setAssignedTo] = useState('')

  const openKey = open && task ? task.id : null
  const [lastOpenKey, setLastOpenKey] = useState<number | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) setAssignedTo(task?.assignee ? String(task.assignee.id) : '')
  }

  const options = [
    { value: '', label: t('tasks:common.unassigned') },
    ...(userOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]

  async function handleSubmit() {
    if (!task) return
    await assignTask.mutateAsync({ id: task.id, assignedTo: assignedTo ? Number(assignedTo) : null })
    onClose()
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('tasks:assignModal.title')}
      description={task?.title}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button loading={assignTask.isPending} onClick={handleSubmit}>
            {t('tasks:assignModal.submit')}
          </Button>
        </div>
      }
    >
      <Select
        label={t('tasks:form.assigneeLabel')}
        value={assignedTo}
        onChange={(e) => setAssignedTo(e.target.value)}
        options={options}
      />
    </Modal>
  )
}
