// Durum akışı kontrolü — `docs/SLA-DESIGN.md` §4. Yalnızca `STATUS_TRANSITIONS`'ta o anki
// durum için listelenen HEDEFLER buton olarak sunulur; backend'in 422
// `INVALID_STATUS_TRANSITION`'ı KULLANICIYA HİÇ GÖSTERİLMEZ (görev tanımı: "UI yalnızca geçerli
// geçişleri sunmalı"). `closed` terminal olduğu için o durumda hiç buton kalmaz.
//
// ÇÖZÜM NOTU — backend'de `resolution_note` diye bir ALAN YOKTUR (`StatusTicketRequest` yalnızca
// `status`'ü doğrular). Bu yüzden not istemcide UYDURULMAZ: kullanıcı "Çözüldü" durumuna
// geçerken opsiyonel bir not girerse, bu `POST /api/activities` ile `type: 'note'` olarak
// talebe bağlı NORMAL bir iç nota dönüşür (aynı mekanizma `TicketActivityFormModal`'ın
// kullandığı — bkz. `TicketResource` dokümanındaki "İç notlar için ayrı tablo yok" gerekçesi).
// Durum geçişi ile not kaydı iki AYRI istektir; not boşsa hiç gönderilmez.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal, Textarea } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import { usePermission } from '../../auth/hooks/usePermission'
import { useCreateActivity } from '../../activities/api/activitiesApi'
import { useChangeTicketStatus } from '../api/ticketsApi'
import { allowedTransitions, statusLabel } from './ticketStatusMeta'
import type { Ticket, TicketStatus } from '../types'

export function TicketStatusControl({ ticket }: { ticket: Ticket }) {
  const { t } = useTranslation(['tickets', 'enums'])
  // Faz 13: bu bileşende ÖNCEDEN hiçbir izin denetimi YOKTU — salt okunur bir rolün bile durum
  // butonlarını görüp tıklayabilmesi (ve 403 yemesi) bir boşluktu. `tickets.update` ucu
  // `/status` isteğini de yetkilendirdiği için (bkz. `TicketPolicy::update` dokümanı) modül
  // kontrolü de o izne bakar; `ticket.can.status` aynı yeteneğin BU talep için sahiplik dahil
  // gerçek sonucudur (bkz. `TicketResource`).
  const { can } = usePermission()
  const changeStatus = useChangeTicketStatus()
  const createNote = useCreateActivity()

  const [resolveOpen, setResolveOpen] = useState(false)
  const [resolutionNote, setResolutionNote] = useState('')
  const [pendingTarget, setPendingTarget] = useState<TicketStatus | null>(null)

  const targets = allowedTransitions(ticket.status)

  async function runTransition(target: TicketStatus) {
    setPendingTarget(target)
    try {
      await changeStatus.mutateAsync({ id: ticket.id, status: target })
    } finally {
      setPendingTarget(null)
    }
  }

  function handleClick(target: TicketStatus) {
    if (target === 'resolved') {
      setResolutionNote('')
      setResolveOpen(true)
      return
    }
    void runTransition(target)
  }

  async function handleResolveSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setPendingTarget('resolved')
    try {
      await changeStatus.mutateAsync({ id: ticket.id, status: 'resolved' })
      if (resolutionNote.trim()) {
        try {
          await createNote.mutateAsync({
            type: 'note',
            subject: t('tickets:status.resolveModal.defaultNoteSubject'),
            body: resolutionNote.trim(),
            occurred_at: new Date().toISOString(),
            activityable_type: 'ticket',
            activityable_id: ticket.id,
          })
        } catch (error) {
          // Durum geçişi zaten BAŞARILI oldu (üstteki await patlamadı); not eklemesi ayrı bir
          // istektir ve başarısız olsa dahi durum geçişini GERİ ALMAYIZ — kullanıcıyı yalnızca
          // notun kaydedilmediği konusunda uyarırız.
          toast.error(t('tickets:status.toastNoteFailed', { error: getErrorMessage(error) }))
        }
      }
      setResolveOpen(false)
    } finally {
      setPendingTarget(null)
    }
  }

  // Modül izni hiç yoksa (salt okunur rol) kontrol tamamen GİZLENİR — statü zaten üstteki
  // `TicketStatusBadge`de görünür, buraya tıklanamaz butonlar koymanın anlamı yok.
  if (!can('tickets.update')) {
    return null
  }

  if (targets.length === 0) {
    return (
      <p className="text-sm text-fg-muted">
        {t('tickets:status.terminalHint', { status: statusLabel('closed', t) })}
      </p>
    )
  }

  // İzin var ama BU talepte `can.status` false — kalan tek engel sahipliktir (atanan kişi,
  // atanmamış talep ya da `tickets.assign` değilse). Butonlar GİZLENMEZ, hepsi birlikte devre
  // dışı bırakılıp nedeni açıklayan bir tooltip/metin eklenir (tek tek buton bazında ayrı bir
  // sahiplik anlamı yok — geçiş kümesi zaten talebin durumuna göre aynı).
  const lockedByOwnership = !ticket.can.status

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-medium text-fg-muted">{t('tickets:status.changeLabel')}</span>
        {targets.map((target) => (
          <Button
            key={target}
            type="button"
            variant="secondary"
            size="sm"
            loading={changeStatus.isPending && pendingTarget === target}
            disabled={lockedByOwnership || (changeStatus.isPending && pendingTarget !== target)}
            title={lockedByOwnership ? t('tickets:status.ownershipLockedHint') : undefined}
            onClick={() => handleClick(target)}
          >
            {statusLabel(target, t)}
          </Button>
        ))}
      </div>
      {lockedByOwnership && (
        <p className="text-xs text-fg-muted">{t('tickets:status.ownershipLockedHint')}</p>
      )}

      <Modal
        open={resolveOpen}
        onClose={() => setResolveOpen(false)}
        title={t('tickets:status.resolveModal.title')}
        description={t('tickets:status.resolveModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setResolveOpen(false)}>
              {t('tickets:status.resolveModal.cancel')}
            </Button>
            <Button type="submit" form="ticket-resolve-form" loading={changeStatus.isPending && pendingTarget === 'resolved'}>
              {t('tickets:status.resolveModal.confirm')}
            </Button>
          </div>
        }
      >
        <form id="ticket-resolve-form" onSubmit={handleResolveSubmit}>
          <Textarea
            label={t('tickets:status.resolveModal.noteLabel')}
            value={resolutionNote}
            onChange={(e) => setResolutionNote(e.target.value)}
            placeholder={t('tickets:status.resolveModal.notePlaceholder')}
          />
        </form>
      </Modal>
    </>
  )
}
