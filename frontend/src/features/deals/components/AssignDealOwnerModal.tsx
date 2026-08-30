// Sahip atama modalı — `PATCH /api/deals/{id}/assign` ({ owner_id }). Liste ve detay
// sayfalarının ikisi de kullanır (bkz. `leads/components/AssignOwnerModal.tsx` deseni).
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal, Select } from '../../../components/ui'
import { useAssignDeal } from '../api/dealsApi'
import { useDealOwnerOptions } from '../api/boardApi'
import type { Deal } from '../types'

export type AssignDealOwnerModalProps = {
  open: boolean
  onClose: () => void
  deal: Deal | null
}

export function AssignDealOwnerModal({ open, onClose, deal }: AssignDealOwnerModalProps) {
  const { t } = useTranslation('deals')
  const { data: ownerOptions, isForbidden } = useDealOwnerOptions()
  const assignDeal = useAssignDeal()
  const [ownerId, setOwnerId] = useState('')

  const openKey = open ? `assign-${deal?.id}` : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) setOwnerId(deal?.owner ? String(deal.owner.id) : '')
  }

  if (!deal) return null

  async function handleAssign() {
    if (!deal || !ownerId) return
    await assignDeal.mutateAsync({ id: deal.id, ownerId: Number(ownerId) })
    onClose()
  }

  const options = [
    { value: '', label: t('assignOwner.ownerPlaceholder'), disabled: true },
    ...(ownerOptions ?? []).map((owner) => ({ value: String(owner.id), label: owner.name })),
  ]

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('assignOwner.title')}
      description={t('assignOwner.description', { title: deal.title })}
      size="sm"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('assignOwner.cancel')}
          </Button>
          <Button type="button" loading={assignDeal.isPending} disabled={!ownerId} onClick={handleAssign}>
            {t('assignOwner.submit')}
          </Button>
        </div>
      }
    >
      {isForbidden ? (
        <p className="text-sm text-fg-muted">{t('assignOwner.forbidden')}</p>
      ) : (
        <Select label={t('assignOwner.ownerLabel')} value={ownerId} onChange={(e) => setOwnerId(e.target.value)} options={options} />
      )}
    </Modal>
  )
}
