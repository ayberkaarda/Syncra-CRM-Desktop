// Atama modalı — `PATCH /api/tickets/{id}/assign` ({ assigned_to }). Liste ve detay
// sayfalarının ikisi de kullanır (bkz. `deals/components/AssignDealOwnerModal.tsx` deseni).
//
// Deals'tan FARKI: backend `AssignTicketRequest` `assigned_to`'nun NULL olmasına izin verir
// (bkz. o dosyanın gerekçesi: yanlış kişiye düşmüş bir talebi havuza geri bırakmak gerçek bir
// destek akışıdır) — bu yüzden burada "Atamayı kaldır" seçeneği de sunulur.
import { useState } from 'react'
import { Trans, useTranslation } from 'react-i18next'
import { Button, Modal, Select } from '../../../components/ui'
import { useAssignTicket } from '../api/ticketsApi'
import { useTicketUserOptions } from './ticketsShared'
import type { Ticket } from '../types'

export type AssignTicketModalProps = {
  open: boolean
  onClose: () => void
  ticket: Ticket | null
}

const UNASSIGNED_VALUE = '__unassigned__'

export function AssignTicketModal({ open, onClose, ticket }: AssignTicketModalProps) {
  const { t } = useTranslation('tickets')
  const { data: userOptions, isForbidden } = useTicketUserOptions()
  const assignTicket = useAssignTicket()
  const [assignedTo, setAssignedTo] = useState(UNASSIGNED_VALUE)

  const openKey = open ? `assign-${ticket?.id}` : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) setAssignedTo(ticket?.assignee ? String(ticket.assignee.id) : UNASSIGNED_VALUE)
  }

  if (!ticket) return null

  async function handleAssign() {
    if (!ticket) return
    await assignTicket.mutateAsync({ id: ticket.id, assignedTo: assignedTo === UNASSIGNED_VALUE ? null : Number(assignedTo) })
    onClose()
  }

  const options = [
    { value: UNASSIGNED_VALUE, label: t('assign.unassignedOption') },
    ...(userOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('assign.title')}
      description={
        <Trans
          i18nKey="tickets:assign.description"
          values={{ ticketNumber: ticket.ticket_number, subject: ticket.subject }}
          components={{ bold: <strong className="text-fg" /> }}
        />
      }
      size="sm"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('assign.cancel')}
          </Button>
          <Button type="button" loading={assignTicket.isPending} onClick={handleAssign}>
            {t('assign.submit')}
          </Button>
        </div>
      }
    >
      {isForbidden ? (
        <p className="text-sm text-fg-muted">{t('assign.forbidden')}</p>
      ) : (
        <Select label={t('assign.assigneeLabel')} value={assignedTo} onChange={(e) => setAssignedTo(e.target.value)} options={options} />
      )}
    </Modal>
  )
}
