// Destek Talebi detay sayfası — SLA göstergesi, durum akışı kontrolü, notlar/etkileşimler,
// bağlı görevler. Sayfa yapısı `deals/pages/DealDetailPage.tsx` deseniyle uyumludur.
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { ArrowLeft, Building2, Pencil, Trash2, User as UserIcon, Users } from 'lucide-react'
import { Badge, Button, Card, CardBody, CardHeader, Modal, Skeleton } from '../../../components/ui'
import { formatDateTime } from '../../../lib/datetime'
import { usePermission } from '../../auth/hooks/usePermission'
import { RecordChatPanel } from '../../chat/record'
import { TicketPriorityBadge } from '../components/TicketPriorityBadge'
import { TicketStatusBadge } from '../components/TicketStatusBadge'
import { TicketStatusControl } from '../components/TicketStatusControl'
import { SlaCountdownPanel } from '../components/SlaCountdown'
import { TicketFormModal } from '../components/TicketFormModal'
import { AssignTicketModal } from '../components/AssignTicketModal'
import { TicketActivityPanel } from '../components/TicketActivityPanel'
import { TicketTasksPanel } from '../components/TicketTasksPanel'
import { useDeleteTicket, useTicket } from '../api/ticketsApi'
import { useTicketRealtime } from '../hooks/useTicketRealtime'
import { ticketCategoryLabel } from '../components/ticketCategoryOptions'
import { companyGroupConfig, contactGroupConfig } from '../../related/adapters'
import { RelatedRecordsPanel } from '../../related/RelatedRecordsPanel'

export function TicketDetailPage() {
  const { t } = useTranslation(['tickets', 'enums'])
  const params = useParams<{ id: string }>()
  const ticketId = Number(params.id)
  const navigate = useNavigate()
  const { can } = usePermission()

  // Yalnızca bu ticket'ın SLA olaylarıyla ilgilenmiyoruz — kanal modül-geneli (tek `private-
  // tickets`), bu yüzden hook parametresizdir ve cache'te bu ticket'ı bulursa yamar (bkz. hook
  // başındaki gerekçe).
  useTicketRealtime()

  const { data: ticket, isLoading, isError, refetch } = useTicket(Number.isFinite(ticketId) ? ticketId : undefined)
  const deleteTicket = useDeleteTicket()

  const [editOpen, setEditOpen] = useState(false)
  const [assignOpen, setAssignOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton variant="text" width={200} />
        <Card>
          <CardBody>
            <div className="flex flex-col gap-3">
              <Skeleton variant="text" width={220} height={24} />
              <Skeleton variant="text" width={320} />
              <Skeleton variant="text" width={280} />
            </div>
          </CardBody>
        </Card>
      </div>
    )
  }

  if (isError || !ticket) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-center">
        <p className="text-sm text-fg-muted">{t('detail.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('detail.retry')}
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="flex items-center gap-1.5 text-xs text-fg-muted">
        <Link to="/tickets" className="inline-flex items-center gap-1 hover:text-fg">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          {t('detail.backToList')}
        </Link>
        <span className="mx-1">/</span>
        <span className="text-primary">{ticket.ticket_number}</span>
      </nav>

      <Card>
        <CardHeader
          title={`${ticket.ticket_number} — ${ticket.subject}`}
          action={
            <div className="flex items-center gap-2">
              {/* `tickets.assign` saf izin kontrolüdür (bkz. `TicketPolicy::assign`) — sahiplik
                  boyutu yok, `can.assign` her zaman modül izniyle aynıdır. */}
              {can('tickets.assign') && ticket.can.assign && (
                <Button variant="secondary" leftIcon={<Users className="size-4" aria-hidden="true" />} onClick={() => setAssignOpen(true)}>
                  {t('detail.assign')}
                </Button>
              )}
              {/* Faz 13: `tickets.update` izni yeterli değil — atanan kişi, atanmamış talep ya da
                  `tickets.assign` taşıyan biri düzenleyebilir (bkz. `TicketPolicy::update`). İzin
                  varken sadece sahiplik yüzünden engellendiğinde buton GİZLENMEZ, devre dışı +
                  tooltip gösterilir. */}
              {can('tickets.update') && (
                <Button
                  variant="secondary"
                  leftIcon={<Pencil className="size-4" aria-hidden="true" />}
                  onClick={() => setEditOpen(true)}
                  disabled={!ticket.can.update}
                  title={ticket.can.update ? undefined : t('detail.editLockedTooltip')}
                >
                  {t('detail.edit')}
                </Button>
              )}
              {/* Çözülmüş/kapanmış talep silinemez — sahiplikten bağımsız, herkes için geçerli bir
                  durum kuralı (bkz. `TicketPolicy::delete`); GİZLEME ile ele alınır, istemci kendi
                  durum kopyasını tutmaz. */}
              {can('tickets.delete') && ticket.can.delete && (
                <Button variant="danger" leftIcon={<Trash2 className="size-4" aria-hidden="true" />} onClick={() => setDeleteOpen(true)}>
                  {t('detail.delete')}
                </Button>
              )}
            </div>
          }
        >
          <div className="flex flex-wrap items-center gap-1.5 pt-1">
            <TicketPriorityBadge priority={ticket.priority} />
            <TicketStatusBadge status={ticket.status} />
            {ticket.category && <Badge variant="neutral">{ticketCategoryLabel(ticket.category, t)}</Badge>}
          </div>
        </CardHeader>
        <CardBody className="flex flex-col gap-6">
          <SlaCountdownPanel ticket={ticket} />

          <TicketStatusControl ticket={ticket} />

          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <DetailField label={t('detail.fields.company')}>
              {ticket.company ? (
                <Link to={`/companies/${ticket.company.id}`} className="flex items-center gap-1.5 text-sm text-primary hover:underline">
                  <Building2 className="size-3.5" aria-hidden="true" />
                  {ticket.company.name}
                </Link>
              ) : (
                <span className="text-sm text-fg-muted">—</span>
              )}
            </DetailField>
            <DetailField label={t('detail.fields.contact')}>
              {ticket.contact ? (
                <Link to={`/contacts/${ticket.contact.id}`} className="flex items-center gap-1.5 text-sm text-primary hover:underline">
                  <UserIcon className="size-3.5" aria-hidden="true" />
                  {ticket.contact.full_name}
                </Link>
              ) : (
                <span className="text-sm text-fg-muted">—</span>
              )}
            </DetailField>
            <DetailField label={t('detail.fields.assignee')}>
              <span className="text-sm text-fg">{ticket.assignee?.name ?? t('detail.unassignedAssignee')}</span>
            </DetailField>
            <DetailField label={t('detail.fields.creator')}>
              <span className="text-sm text-fg">{ticket.creator?.name ?? '—'}</span>
            </DetailField>
          </div>

          <div className="flex flex-col gap-1.5">
            <span className="text-xs font-medium text-fg-muted">{t('detail.descriptionLabel')}</span>
            <p className="whitespace-pre-wrap text-sm text-fg-secondary">{ticket.description}</p>
          </div>

          <div className="flex flex-col gap-2">
            <p className="text-xs font-medium uppercase tracking-wide text-fg-muted">{t('detail.tagsLabel')}</p>
            <div className="flex flex-wrap gap-1.5">
              {ticket.tags.length === 0 && <span className="text-sm text-fg-muted">{t('detail.noTags')}</span>}
              {ticket.tags.map((tag) => (
                <Badge key={tag.id} variant="neutral">
                  {tag.name}
                </Badge>
              ))}
            </div>
          </div>
        </CardBody>
      </Card>

      {Object.keys(ticket.custom_fields).length > 0 && (
        <Card>
          <CardHeader title={t('detail.customFieldsTitle')} />
          <CardBody>
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {Object.entries(ticket.custom_fields).map(([key, value]) => (
                <div key={key} className="flex flex-col gap-1">
                  <dt className="text-xs font-medium text-fg-muted">{key}</dt>
                  <dd className="text-sm text-fg">{value || '—'}</dd>
                </div>
              ))}
            </dl>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader title={t('detail.timestampsTitle')} />
        <CardBody>
          <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <TimestampField label={t('detail.timestamps.created')} value={formatDateTime(ticket.created_at)} />
            <TimestampField
              label={t('detail.timestamps.firstResponse')}
              value={ticket.first_response_at ? formatDateTime(ticket.first_response_at) : t('detail.noResponseYet')}
              muted={!ticket.first_response_at}
            />
            <TimestampField
              label={t('detail.timestamps.resolved')}
              value={ticket.resolved_at ? formatDateTime(ticket.resolved_at) : '—'}
              muted={!ticket.resolved_at}
            />
            <TimestampField
              label={t('detail.timestamps.closed')}
              value={ticket.closed_at ? formatDateTime(ticket.closed_at) : '—'}
              muted={!ticket.closed_at}
            />
            <TimestampField label={t('detail.timestamps.slaDue')} value={ticket.sla_due_at ? formatDateTime(ticket.sla_due_at) : '—'} />
            <TimestampField
              label={t('detail.timestamps.slaTargetHours')}
              value={t('detail.slaTargetHoursValue', { hours: ticket.sla_target_hours })}
            />
          </dl>
        </CardBody>
      </Card>

      <TicketActivityPanel ticket={ticket} />

      <TicketTasksPanel ticket={ticket} />

      {/* Faz 14 / İz F — C3: `destek talebi ↔ firma/kişi` ters yönü (firma/kişi → destek
          talepleri) Company/ContactController::loadRelatedRecords()'ta eklendi; bu yön
          (talep → firma/kişi) zaten `ticket.company`/`ticket.contact` alanlarında vardı
          (yukarıdaki DetailField'lar) — burada YENİ bir BE ucu açmadan, aynı veriyi TEK
          ortak panel bileşeniyle tutarlı biçimde tekrar sunuyoruz. */}
      <RelatedRecordsPanel
        groups={[
          companyGroupConfig(
            'company',
            t('related:groups.company'),
            t('related:empty.company'),
            can('companies.view')
              ? { total: ticket.company ? 1 : 0, items: ticket.company ? [ticket.company] : [] }
              : undefined
          ),
          contactGroupConfig(
            'contact',
            t('related:groups.contact'),
            t('related:empty.contact'),
            can('contacts.view')
              ? { total: ticket.contact ? 1 : 0, items: ticket.contact ? [ticket.contact] : [] }
              : undefined
          ),
        ]}
      />

      <RecordChatPanel recordType="ticket" recordId={ticket.id} />

      <TicketFormModal open={editOpen} onClose={() => setEditOpen(false)} ticket={ticket} />
      <AssignTicketModal open={assignOpen} onClose={() => setAssignOpen(false)} ticket={ticket} />

      <Modal
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        title={t('detail.deleteModal.title')}
        description={t('detail.deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteOpen(false)}>
              {t('detail.deleteModal.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteTicket.isPending}
              onClick={async () => {
                await deleteTicket.mutateAsync(ticket.id)
                setDeleteOpen(false)
                navigate('/tickets')
              }}
            >
              {t('detail.deleteModal.confirm')}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-fg-secondary">
          <Trans
            i18nKey="tickets:detail.deleteModal.confirmText"
            values={{ ticketNumber: ticket.ticket_number, subject: ticket.subject }}
            components={{ bold: <strong className="text-fg" /> }}
          />
        </p>
      </Modal>
    </div>
  )
}

function DetailField({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <p className="text-xs font-medium uppercase tracking-wide text-fg-muted">{label}</p>
      {children}
    </div>
  )
}

function TimestampField({ label, value, muted }: { label: string; value: string; muted?: boolean }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs font-medium text-fg-muted">{label}</dt>
      <dd className={muted ? 'text-sm text-fg-muted' : 'text-sm text-fg'}>{value}</dd>
    </div>
  )
}
