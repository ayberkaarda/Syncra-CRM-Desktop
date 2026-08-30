// Sahip atama modalı — `PATCH /api/leads/{id}/assign` ({ owner_id }).
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal, Select } from '../../../components/ui'
import { useAssignLead, useOwnerOptions } from '../api/leadsApi'
import type { Lead } from '../types'

export type AssignOwnerModalProps = {
  open: boolean
  onClose: () => void
  lead: Lead | null
}

export function AssignOwnerModal({ open, onClose, lead }: AssignOwnerModalProps) {
  const { t } = useTranslation('leads')
  const { data: ownerOptions, isForbidden } = useOwnerOptions()
  const assignLead = useAssignLead()
  const [ownerId, setOwnerId] = useState('')

  const openKey = open ? `assign-${lead?.id}` : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) setOwnerId(lead?.owner ? String(lead.owner.id) : '')
  }

  if (!lead) return null

  async function handleAssign() {
    if (!lead || !ownerId) return
    await assignLead.mutateAsync({ id: lead.id, ownerId: Number(ownerId) })
    onClose()
  }

  const options = [
    { value: '', label: t('leads:assignOwnerModal.selectOwnerPlaceholder'), disabled: true },
    ...(ownerOptions ?? []).map((owner) => ({ value: String(owner.id), label: owner.name })),
  ]

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('leads:assignOwnerModal.title')}
      description={t('leads:assignOwnerModal.description', { name: lead.full_name })}
      size="sm"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('leads:assignOwnerModal.cancel')}
          </Button>
          <Button type="button" loading={assignLead.isPending} disabled={!ownerId} onClick={handleAssign}>
            {t('leads:assignOwnerModal.submit')}
          </Button>
        </div>
      }
    >
      {isForbidden ? (
        <p className="text-sm text-fg-muted">{t('leads:assignOwnerModal.noPermission')}</p>
      ) : (
        <Select
          label={t('leads:assignOwnerModal.selectOwnerLabel')}
          value={ownerId}
          onChange={(e) => setOwnerId(e.target.value)}
          options={options}
        />
      )}
    </Modal>
  )
}
