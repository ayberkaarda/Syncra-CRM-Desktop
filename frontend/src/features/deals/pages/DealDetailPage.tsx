// Fırsat detay sayfası — özet kart, kapanış şeridi, ilişkili kayıtlar, özel alanlar.
//
// İletişim geçmişi NOTU (görev tanımı): Faz 6'da `components/shared/Timeline.tsx` yazıldı ve
// `GET /api/contacts/{id}/timeline` ile `GET /api/companies/{id}/timeline` uçları var, ama
// fırsat (deal) için ayrı bir timeline ucu YOK. Bu yüzden burada timeline gösterilmiyor —
// bunun yerine deal'ın bağlı olduğu kişinin (yoksa firmanın) kendi detay sayfasına bağlantı
// veriliyor; kullanıcı oradaki zaman çizelgesini görüntüleyebilir.
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  AlertTriangle,
  ArrowLeft,
  Building2,
  CheckCircle2,
  History,
  LayoutGrid,
  Pencil,
  Trash2,
  User as UserIcon,
  Users,
  XCircle,
} from 'lucide-react'
import { Badge, Button, Card, CardBody, CardHeader, Modal, Skeleton } from '../../../components/ui'
import { formatDate, formatDateTime } from '../../../lib/datetime'
import { usePermission } from '../../auth/hooks/usePermission'
import { RecordChatPanel } from '../../chat/record'
import { DealStageBadge } from '../components/DealStageBadge'
import { DealStatusBadge } from '../components/DealStatusBadge'
import { DealFormModal } from '../components/DealFormModal'
import { AssignDealOwnerModal } from '../components/AssignDealOwnerModal'
import { useDeal, useDeleteDeal } from '../api/dealsApi'
import { quotesGroupConfig } from '../../related/adapters'
import { RelatedRecordsPanel } from '../../related/RelatedRecordsPanel'
import { ConvertedAmount } from '../../exchange/components/ConvertedAmount'
import { isRecordNotMirrored } from '../../../platform/errors'

export function DealDetailPage() {
  const { t } = useTranslation('deals')
  const params = useParams<{ id: string }>()
  const dealId = Number(params.id)
  const navigate = useNavigate()
  const { can } = usePermission()

  const { data: deal, isLoading, isError, error, refetch } = useDeal(Number.isFinite(dealId) ? dealId : undefined)
  const deleteDeal = useDeleteDeal()

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

  if (isError || !deal) {
    // A valid link to a real record can still fail to load offline: the local mirror only
    // keeps a retention window, and a record outside it is not a broken link (O91). The desktop
    // data layer signals that case structurally (`ROW_NOT_LOCAL`, `platform/data/engine.ts`
    // `MissingRowError`) rather than through the message text, so this branches on the code —
    // never on `error.message` — and is a no-op on the web build, which never produces that code.
    const notMirrored = isRecordNotMirrored(error)
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-center">
        <p className="text-sm text-fg-muted">{notMirrored ? t('desktop:errors.ROW_NOT_LOCAL') : t('detail.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('detail.retry')}
        </Button>
      </div>
    )
  }

  const isWon = deal.status === 'won'
  const isLost = deal.status === 'lost'

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="flex items-center gap-1.5 text-xs text-fg-muted">
        <Link to="/deals/list" className="inline-flex items-center gap-1 hover:text-fg">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          {t('detail.backToList')}
        </Link>
        <span className="mx-1">/</span>
        <span className="text-primary">{deal.title}</span>
      </nav>

      {isWon && (
        <div className="flex flex-col gap-2 rounded-lg bg-success-tint p-4 text-success sm:flex-row sm:items-start sm:justify-between">
          <div className="flex items-start gap-2">
            <CheckCircle2 className="size-5 shrink-0" aria-hidden="true" />
            <div className="flex flex-col gap-0.5">
              <p className="text-sm font-medium">{t('detail.wonBanner', { date: formatDateTime(deal.closed_at) })}</p>
              <p className="text-sm">{deal.won_reason || t('detail.noReason')}</p>
            </div>
          </div>
        </div>
      )}
      {isLost && (
        <div className="flex flex-col gap-2 rounded-lg bg-danger-tint p-4 text-danger sm:flex-row sm:items-start sm:justify-between">
          <div className="flex items-start gap-2">
            <XCircle className="size-5 shrink-0" aria-hidden="true" />
            <div className="flex flex-col gap-0.5">
              <p className="text-sm font-medium">{t('detail.lostBanner', { date: formatDateTime(deal.closed_at) })}</p>
              <p className="text-sm">{deal.lost_reason || t('detail.noReason')}</p>
            </div>
          </div>
        </div>
      )}

      <Card>
        <CardHeader
          title={deal.title}
          subtitle={<ConvertedAmount amount={deal.amount} currency={deal.currency} />}
          action={
            <div className="flex items-center gap-2">
              <Button variant="secondary" leftIcon={<LayoutGrid className="size-4" aria-hidden="true" />} onClick={() => navigate('/deals')}>
                {t('detail.showOnBoard')}
              </Button>
              {/* `deals.assign` saf izin kontrolüdür (sahiplik boyutu yok), bkz. `DealPolicy::assign` —
                  `deal.can.assign` her zaman modül izniyle aynıdır, gizlemek yeterli. */}
              {can('deals.assign') && deal.can.assign && (
                <Button variant="secondary" leftIcon={<Users className="size-4" aria-hidden="true" />} onClick={() => setAssignOpen(true)}>
                  {t('detail.assignOwner')}
                </Button>
              )}
              {/* Faz 13: izin var ama `can.update` false ise (sahip/sahipsiz/atama yetkisi yok)
                  buton GİZLENMEZ — devre dışı + tooltip ile neden anlaşılır kılınır. */}
              {can('deals.update') && (
                <Button
                  variant="secondary"
                  leftIcon={<Pencil className="size-4" aria-hidden="true" />}
                  onClick={() => setEditOpen(true)}
                  disabled={!deal.can.update}
                  title={deal.can.update ? undefined : t('detail.editLockedTooltip')}
                >
                  {t('detail.edit')}
                </Button>
              )}
              {/* Kapanmış (won/lost) fırsat silinemez — sahiplikten bağımsız, herkes için geçerli bir
                  durum kuralı (bkz. `DealPolicy::delete`); bu yüzden disabled değil GİZLEME. İstemci
                  artık kendi `isClosed` kopyasını tutmaz, backend'in `can.delete`'ine güvenir. */}
              {can('deals.delete') && deal.can.delete && (
                <Button variant="danger" leftIcon={<Trash2 className="size-4" aria-hidden="true" />} onClick={() => setDeleteOpen(true)}>
                  {t('detail.delete')}
                </Button>
              )}
            </div>
          }
        >
          <div className="flex flex-wrap items-center gap-1.5 pt-1">
            <DealStageBadge stage={deal.pipeline_stage} />
            <DealStatusBadge status={deal.status} />
            {deal.probability !== null && (
              <Badge variant="neutral">{t('detail.probabilityBadge', { value: deal.probability })}</Badge>
            )}
          </div>
        </CardHeader>
        <CardBody className="flex flex-col gap-6">
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <DetailField label={t('detail.fields.company')}>
              {deal.company ? (
                <Link to={`/companies/${deal.company.id}`} className="flex items-center gap-1.5 text-sm text-primary hover:underline">
                  <Building2 className="size-3.5" aria-hidden="true" />
                  {deal.company.name}
                </Link>
              ) : (
                <span className="text-sm text-fg-muted">—</span>
              )}
            </DetailField>
            <DetailField label={t('detail.fields.contact')}>
              {deal.contact ? (
                <Link to={`/contacts/${deal.contact.id}`} className="flex items-center gap-1.5 text-sm text-primary hover:underline">
                  <UserIcon className="size-3.5" aria-hidden="true" />
                  {deal.contact.full_name}
                </Link>
              ) : (
                <span className="text-sm text-fg-muted">—</span>
              )}
            </DetailField>
            <DetailField label={t('detail.fields.owner')}>
              <span className="text-sm text-fg">{deal.owner?.name ?? t('detail.unassignedOwner')}</span>
            </DetailField>
            <DetailField label={t('detail.fields.expectedClose')}>
              <span className={deal.is_overdue ? 'flex items-center gap-1.5 text-sm font-medium text-danger' : 'text-sm text-fg'}>
                {deal.is_overdue && <AlertTriangle className="size-3.5" aria-hidden="true" />}
                {formatDate(deal.expected_close_date)}
                {deal.is_overdue && t('detail.overdueSuffix')}
              </span>
            </DetailField>
          </div>

          {deal.description && (
            <div className="flex flex-col gap-1.5">
              <span className="text-xs font-medium text-fg-muted">{t('detail.descriptionLabel')}</span>
              <p className="whitespace-pre-wrap text-sm text-fg-secondary">{deal.description}</p>
            </div>
          )}

          <div className="flex flex-col gap-2">
            <p className="text-xs font-medium uppercase tracking-wide text-fg-muted">{t('detail.tagsLabel')}</p>
            <div className="flex flex-wrap gap-1.5">
              {deal.tags.length === 0 && <span className="text-sm text-fg-muted">{t('detail.noTags')}</span>}
              {deal.tags.map((tag) => (
                <Badge key={tag.id} variant="neutral">
                  {tag.name}
                </Badge>
              ))}
            </div>
          </div>
        </CardBody>
      </Card>

      {Object.keys(deal.custom_fields).length > 0 && (
        <Card>
          <CardHeader title={t('detail.customFieldsTitle')} />
          <CardBody>
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {Object.entries(deal.custom_fields).map(([key, value]) => (
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
        <CardHeader title={t('detail.timeline.title')} />
        <CardBody className="flex flex-col gap-3">
          <div className="flex items-start gap-3 rounded-lg bg-surface-2 p-4">
            <History className="mt-0.5 size-5 shrink-0 text-fg-muted" aria-hidden="true" />
            <div className="flex flex-col gap-1">
              <p className="text-sm text-fg-secondary">{t('detail.timeline.notice')}</p>
              {deal.contact ? (
                <Link to={`/contacts/${deal.contact.id}`} className="text-sm font-medium text-primary hover:underline">
                  {t('detail.timeline.viewContact')}
                </Link>
              ) : deal.company ? (
                <Link to={`/companies/${deal.company.id}`} className="text-sm font-medium text-primary hover:underline">
                  {t('detail.timeline.viewCompany')}
                </Link>
              ) : (
                <span className="text-sm text-fg-muted">{t('detail.timeline.none')}</span>
              )}
            </div>
          </div>
        </CardBody>
      </Card>

      <RelatedRecordsPanel groups={[quotesGroupConfig(t, deal.related?.quotes)]} />

      <RecordChatPanel recordType="deal" recordId={deal.id} />

      <DealFormModal open={editOpen} onClose={() => setEditOpen(false)} deal={deal} />
      <AssignDealOwnerModal open={assignOpen} onClose={() => setAssignOpen(false)} deal={deal} />

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
              loading={deleteDeal.isPending}
              onClick={async () => {
                await deleteDeal.mutateAsync(deal.id)
                setDeleteOpen(false)
                navigate('/deals/list')
              }}
            >
              {t('detail.deleteModal.confirm')}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-fg-secondary">{t('detail.deleteModal.confirmText', { title: deal.title })}</p>
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
