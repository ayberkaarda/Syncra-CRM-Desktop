// Müşteri Adayı detay sayfası — özet kart, dönüşüm şeridi, özel alanlar, notlar.
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { ArrowLeft, Building2, CheckCircle2, Mail, Pencil, Phone, Repeat, Trash2, Users } from 'lucide-react'
import { Avatar, Badge, Button, Card, CardBody, CardHeader, Modal, Skeleton } from '../../../components/ui'
import { formatDateTime } from '../../../lib/datetime'
import { usePermission } from '../../auth/hooks/usePermission'
import { useDeleteLead, useLead } from '../api/leadsApi'
import { LeadFormModal } from '../components/LeadFormModal'
import { ConvertLeadModal } from '../components/ConvertLeadModal'
import { AssignOwnerModal } from '../components/AssignOwnerModal'
import { ScoreIndicator } from '../components/ScoreIndicator'
import { SOURCE_LABEL_KEY, STATUS_BADGE_VARIANT, STATUS_LABEL_KEY } from '../utils'
import { companyGroupConfig, contactGroupConfig, dealGroupConfig } from '../../related/adapters'
import { RelatedRecordsPanel } from '../../related/RelatedRecordsPanel'

export function LeadDetailPage() {
  const { t } = useTranslation(['leads', 'common', 'enums'])
  const params = useParams<{ id: string }>()
  const leadId = Number(params.id)
  const navigate = useNavigate()
  const { can } = usePermission()

  const { data: lead, isLoading, isError, refetch } = useLead(Number.isFinite(leadId) ? leadId : undefined)
  const deleteLead = useDeleteLead()

  const [editOpen, setEditOpen] = useState(false)
  const [convertOpen, setConvertOpen] = useState(false)
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

  if (isError || !lead) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-center">
        <p className="text-sm text-fg-muted">{t('leads:detail.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('leads:detail.retry')}
        </Button>
      </div>
    )
  }

  const isConverted = lead.status === 'converted'

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="flex items-center gap-1.5 text-xs text-fg-muted">
        <Link to="/leads" className="inline-flex items-center gap-1 hover:text-fg">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          {t('leads:breadcrumb.leads')}
        </Link>
        <span className="mx-1">/</span>
        <span className="text-primary">{lead.full_name}</span>
      </nav>

      {isConverted && (
        <div className="flex flex-col gap-2 rounded-lg bg-success-tint p-4 text-success sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-2">
            <CheckCircle2 className="size-5 shrink-0" aria-hidden="true" />
            <p className="text-sm font-medium">
              {t('leads:detail.convertedMessage', { date: formatDateTime(lead.converted_at) })}
            </p>
          </div>
          <div className="flex flex-wrap gap-3 text-xs font-medium">
            {lead.converted_contact_id && (
              <Link to={`/contacts/${lead.converted_contact_id}`} className="underline hover:no-underline">
                {t('leads:detail.goToContact')}
              </Link>
            )}
            {lead.converted_company_id && (
              <Link to={`/companies/${lead.converted_company_id}`} className="underline hover:no-underline">
                {t('leads:detail.goToCompany')}
              </Link>
            )}
            {lead.converted_deal_id && (
              // Fırsat (Deal) route'u henüz yok — Faz 7'de eklenecek. Bağlantı bilerek
              // burada duruyor; tıklanırsa NotFoundPage'e düşer, bu normal/beklenen.
              <Link to={`/deals/${lead.converted_deal_id}`} className="underline hover:no-underline">
                {t('leads:detail.goToDeal')}
              </Link>
            )}
          </div>
        </div>
      )}

      <Card>
        <CardHeader
          title={lead.full_name}
          subtitle={lead.position ? `${lead.position}${lead.company_name ? ' — ' + lead.company_name : ''}` : lead.company_name ?? undefined}
          action={
            <div className="flex items-center gap-2">
              {/* `leads.assign` saf izin kontrolüdür — `can.assign` her zaman modül izniyle aynıdır. */}
              {can('leads.assign') && lead.can.assign && (
                <Button variant="secondary" leftIcon={<Users className="size-4" aria-hidden="true" />} onClick={() => setAssignOpen(true)}>
                  {t('leads:detail.assignOwner')}
                </Button>
              )}
              {/* Faz 13: `!isConverted` durum kuralı korunur; kalan tek engel sahiplikse (can.convert
                  false) buton GİZLENMEZ, devre dışı + tooltip gösterilir. */}
              {!isConverted && can('leads.convert') && (
                <Button
                  variant="secondary"
                  leftIcon={<Repeat className="size-4" aria-hidden="true" />}
                  onClick={() => setConvertOpen(true)}
                  disabled={!lead.can.convert}
                  title={lead.can.convert ? undefined : t('leads:actions.convertDisabledTitle')}
                >
                  {t('leads:detail.convert')}
                </Button>
              )}
              {!isConverted && can('leads.update') && (
                <Button
                  variant="secondary"
                  leftIcon={<Pencil className="size-4" aria-hidden="true" />}
                  onClick={() => setEditOpen(true)}
                  disabled={!lead.can.update}
                  title={lead.can.update ? undefined : t('leads:actions.editDisabledTitle')}
                >
                  {t('leads:detail.edit')}
                </Button>
              )}
              {/* Dönüştürülmüş lead silinemez — sahiplikten bağımsız durum kuralı, GİZLEME ile ele
                  alınır (bkz. `LeadPolicy::delete`). */}
              {can('leads.delete') && lead.can.delete && (
                <Button variant="danger" leftIcon={<Trash2 className="size-4" aria-hidden="true" />} onClick={() => setDeleteOpen(true)}>
                  {t('leads:detail.delete')}
                </Button>
              )}
            </div>
          }
        />
        <CardBody>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <DetailField label={t('leads:detail.fields.status')}>
              <Badge variant={STATUS_BADGE_VARIANT[lead.status]}>{t(STATUS_LABEL_KEY[lead.status], { ns: 'enums' })}</Badge>
            </DetailField>
            <DetailField label={t('leads:detail.fields.source')}>
              <Badge variant="neutral">{t(SOURCE_LABEL_KEY[lead.source], { ns: 'enums' })}</Badge>
            </DetailField>
            <DetailField label={t('leads:detail.fields.score')}>
              <ScoreIndicator score={lead.score} />
            </DetailField>
            <DetailField label={t('leads:detail.fields.owner')}>
              {lead.owner ? (
                <div className="flex items-center gap-2">
                  <Avatar name={lead.owner.name} size="xs" />
                  <span className="text-sm text-fg">{lead.owner.name}</span>
                </div>
              ) : (
                <span className="text-sm text-fg-muted">{t('leads:detail.fields.unassigned')}</span>
              )}
            </DetailField>
            <DetailField label={t('leads:detail.fields.email')}>
              <span className="flex items-center gap-1.5 text-sm text-fg">
                <Mail className="size-3.5 text-fg-muted" aria-hidden="true" />
                {lead.email ?? '—'}
              </span>
            </DetailField>
            <DetailField label={t('leads:detail.fields.phone')}>
              <span className="flex items-center gap-1.5 text-sm text-fg">
                <Phone className="size-3.5 text-fg-muted" aria-hidden="true" />
                {lead.phone ?? '—'}
              </span>
            </DetailField>
            <DetailField label={t('leads:detail.fields.company')}>
              <span className="flex items-center gap-1.5 text-sm text-fg">
                <Building2 className="size-3.5 text-fg-muted" aria-hidden="true" />
                {lead.company_name ?? '—'}
              </span>
            </DetailField>
            <DetailField label={t('leads:detail.fields.createdAt')}>
              <span className="text-sm text-fg">{formatDateTime(lead.created_at)}</span>
            </DetailField>
          </div>

          <div className="mt-6 flex flex-col gap-2">
            <p className="text-xs font-medium uppercase tracking-wide text-fg-muted">{t('leads:detail.tagsTitle')}</p>
            <div className="flex flex-wrap gap-1.5">
              {lead.tags.length === 0 && <span className="text-sm text-fg-muted">{t('leads:detail.noTags')}</span>}
              {lead.tags.map((tag) => (
                <Badge key={tag.id} variant="neutral">
                  {tag.name}
                </Badge>
              ))}
            </div>
          </div>
        </CardBody>
      </Card>

      {Object.keys(lead.custom_fields).length > 0 && (
        <Card>
          <CardHeader title={t('leads:detail.customFieldsTitle')} />
          <CardBody>
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {Object.entries(lead.custom_fields).map(([key, value]) => (
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
        <CardHeader title={t('leads:detail.notesTitle')} />
        <CardBody>
          <p className="whitespace-pre-wrap text-sm text-fg-secondary">{lead.notes || t('leads:detail.notesEmpty')}</p>
        </CardBody>
      </Card>

      {/* Faz 14 / İz F — C3: ters yön (bir kişi/firma/fırsatın hangi lead'den geldiği)
          şemada yok (bkz. LeadController::loadRelatedRecords() dokümanı) — yalnızca
          dönüşümün GERÇEK, şemadaki tek yönü: Lead → dönüştürüldüğü kayıtlar. */}
      <RelatedRecordsPanel
        groups={[
          contactGroupConfig(
            'converted_contact',
            t('related:groups.convertedContact'),
            t('related:empty.convertedContact'),
            lead.related?.converted_contact
          ),
          companyGroupConfig(
            'converted_company',
            t('related:groups.convertedCompany'),
            t('related:empty.convertedCompany'),
            lead.related?.converted_company
          ),
          dealGroupConfig(
            'converted_deal',
            t('related:groups.convertedDeal'),
            t('related:empty.convertedDeal'),
            lead.related?.converted_deal
          ),
        ]}
      />

      <LeadFormModal open={editOpen} onClose={() => setEditOpen(false)} lead={lead} />
      <ConvertLeadModal open={convertOpen} onClose={() => setConvertOpen(false)} lead={lead} />
      <AssignOwnerModal open={assignOpen} onClose={() => setAssignOpen(false)} lead={lead} />

      <Modal
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        title={t('leads:deleteModal.title')}
        description={t('leads:deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteOpen(false)}>
              {t('leads:deleteModal.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteLead.isPending}
              onClick={async () => {
                await deleteLead.mutateAsync(lead.id)
                setDeleteOpen(false)
                navigate('/leads')
              }}
            >
              {t('leads:deleteModal.confirm')}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-fg-secondary">
          <Trans
            i18nKey="leads:deleteModal.confirmText"
            values={{ name: lead.full_name }}
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
