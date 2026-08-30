// Firma detay sayfası — özet kart, bağlı kişiler mini tablosu ve zaman çizelgesi.
import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import {
  Globe,
  Mail,
  MapPin,
  Pencil,
  Phone,
  Trash2,
  Users as UsersIcon,
} from 'lucide-react'
import {
  Badge,
  Button,
  Card,
  CardBody,
  CardHeader,
  EmptyState,
  Modal,
  Skeleton,
  Table,
  TBody,
  Td,
  THead,
  Th,
  Tr,
} from '../../../components/ui'
import { Timeline } from '../../../components/shared/Timeline'
import { formatMoney, formatNumber as formatNumberShared } from '../../../lib/money'
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import type { TimelineItem } from '../../../components/shared/Timeline'
import { usePermission } from '../../auth/hooks/usePermission'
import { useCompany, useCompanyContacts, useCompanyTimeline, useCustomFields, useDeleteCompany } from '../api/companiesApi'
import { CompanyFormModal } from '../components/CompanyFormModal'
import { dealsGroupConfig, quotesGroupConfig, ticketsGroupConfig } from '../../related/adapters'
import { RelatedRecordsPanel } from '../../related/RelatedRecordsPanel'

const formatCurrency = formatMoney
const formatNumber = formatNumberShared

export function CompanyDetailPage() {
  const { t } = useTranslation(['companies', 'common'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const companyId = id ? Number(id) : undefined
  const { can } = usePermission()

  const { data: company, isLoading, isError, refetch } = useCompany(companyId)
  const { data: customFields } = useCustomFields()
  const { data: linkedContacts, isLoading: isContactsLoading, isError: isContactsError, refetch: refetchContacts } =
    useCompanyContacts(companyId)

  const {
    data: timelineData,
    isLoading: isTimelineLoading,
    isError: isTimelineError,
    refetch: refetchTimeline,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
  } = useCompanyTimeline(companyId)

  const timelineItems: TimelineItem[] = useMemo(
    () => timelineData?.pages.flatMap((page) => page.data) ?? [],
    [timelineData]
  )

  const [editOpen, setEditOpen] = useState(false)
  const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false)
  const deleteCompany = useDeleteCompany()

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton variant="text" width={200} />
        <Card>
          <CardBody className="flex flex-col gap-4">
            <Skeleton variant="text" width={240} height={24} />
            <Skeleton variant="text" lines={3} />
          </CardBody>
        </Card>
      </div>
    )
  }

  if (isError || !company) {
    return (
      <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
        <p className="text-sm text-fg-muted">{t('companies:detail.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('companies:detail.retry')}
        </Button>
      </div>
    )
  }

  const customFieldEntries = (customFields ?? []).filter((field) => company.custom_fields[field.key])

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('companies:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <Link to="/companies" className="hover:text-fg hover:underline">
          {t('companies:breadcrumb.companies')}
        </Link>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{company.name}</span>
      </nav>

      <Card>
        <CardHeader
          title={company.name}
          subtitle={company.industry ?? undefined}
          action={
            <div className="flex items-center gap-2">
              {can('companies.update') && (
                <Button variant="secondary" leftIcon={<Pencil className="size-4" aria-hidden="true" />} onClick={() => setEditOpen(true)}>
                  {t('companies:actions.edit')}
                </Button>
              )}
              {can('companies.delete') && (
                <Button variant="danger" leftIcon={<Trash2 className="size-4" aria-hidden="true" />} onClick={() => setConfirmDeleteOpen(true)}>
                  {t('companies:actions.delete')}
                </Button>
              )}
            </div>
          }
        >
          <div className="flex flex-wrap gap-1.5 pt-1">
            <Badge variant="neutral">{t('companies:detail.contactsBadge', { count: company.contacts_count })}</Badge>
            <Badge variant="neutral">{t('companies:detail.dealsBadge', { count: company.deals_count })}</Badge>
          </div>
        </CardHeader>
        <CardBody className="flex flex-col gap-6">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div className="flex items-center gap-2 text-sm">
              <Mail className="size-4 text-fg-muted" aria-hidden="true" />
              <span className="text-fg">{company.email ?? '—'}</span>
            </div>
            <div className="flex items-center gap-2 text-sm">
              <Phone className="size-4 text-fg-muted" aria-hidden="true" />
              <span className="text-fg">{company.phone ?? '—'}</span>
            </div>
            <div className="flex items-center gap-2 text-sm">
              <Globe className="size-4 text-fg-muted" aria-hidden="true" />
              {company.website ? (
                <a
                  href={company.website}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="truncate text-primary hover:underline"
                >
                  {company.website}
                </a>
              ) : (
                <span className="text-fg">—</span>
              )}
            </div>
            <div className="flex items-center gap-2 text-sm sm:col-span-2">
              <MapPin className="size-4 shrink-0 text-fg-muted" aria-hidden="true" />
              <span className="text-fg">
                {[company.address, company.city, company.country].filter(Boolean).join(', ') || '—'}
              </span>
            </div>
            <div className="flex items-center gap-2 text-sm">
              <UsersIcon className="size-4 text-fg-muted" aria-hidden="true" />
              <span className="text-fg">
                {company.employee_count != null
                  ? t('companies:detail.employeeCount', {
                      count: company.employee_count,
                      value: formatNumber(company.employee_count),
                    })
                  : formatNumber(company.employee_count)}
              </span>
            </div>
            <div className="flex flex-col gap-1 text-sm">
              <span className="text-xs font-medium text-fg-muted">{t('companies:detail.annualRevenue')}</span>
              <span className="text-fg">{formatCurrency(company.annual_revenue)}</span>
            </div>
            <div className="flex flex-col gap-1 text-sm">
              <span className="text-xs font-medium text-fg-muted">{t('companies:detail.owner')}</span>
              <span className="text-fg">{company.owner?.name ?? '—'}</span>
            </div>
          </div>

          {company.tags.length > 0 && (
            <div className="flex flex-col gap-1.5">
              <span className="text-xs font-medium text-fg-muted">{t('companies:detail.tags')}</span>
              <div className="flex flex-wrap gap-1.5">
                {company.tags.map((tag) => (
                  <Badge key={tag.id} variant={tokenBadgeVariant(tag.color)}>
                    {tag.name}
                  </Badge>
                ))}
              </div>
            </div>
          )}

          {customFieldEntries.length > 0 && (
            <div className="flex flex-col gap-2">
              <span className="text-xs font-medium text-fg-muted">{t('companies:detail.customFields')}</span>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {customFieldEntries.map((field) => (
                  <div key={field.key} className="flex flex-col gap-0.5">
                    <span className="text-xs text-fg-muted">{field.label}</span>
                    <span className="text-sm text-fg">{company.custom_fields[field.key]}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {company.notes && (
            <div className="flex flex-col gap-1.5">
              <span className="text-xs font-medium text-fg-muted">{t('companies:detail.notes')}</span>
              <p className="whitespace-pre-wrap text-sm text-fg-secondary">{company.notes}</p>
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title={t('companies:detail.linkedContactsTitle')}
          subtitle={t('companies:detail.linkedContactsSubtitle', { count: linkedContacts?.length ?? 0 })}
        />
        <CardBody noPadding>
          {isContactsError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
              <p className="text-sm text-fg-muted">{t('companies:detail.linkedContactsError')}</p>
              <Button variant="secondary" onClick={() => refetchContacts()}>
                {t('companies:detail.retry')}
              </Button>
            </div>
          ) : !isContactsLoading && (linkedContacts ?? []).length === 0 ? (
            <EmptyState
              icon={<UsersIcon className="size-6" aria-hidden="true" />}
              title={t('companies:detail.linkedContactsEmptyTitle')}
              description={t('companies:detail.linkedContactsEmptyDescription')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th>{t('companies:detail.columns.fullName')}</Th>
                  <Th>{t('companies:detail.columns.position')}</Th>
                  <Th>{t('companies:detail.columns.email')}</Th>
                  <Th>{t('companies:detail.columns.phone')}</Th>
                </Tr>
              </THead>
              <TBody aria-busy={isContactsLoading}>
                {isContactsLoading
                  ? Array.from({ length: 3 }).map((_, i) => (
                      <Tr key={i}>
                        <Td><Skeleton variant="text" width={140} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={140} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                      </Tr>
                    ))
                  : (linkedContacts ?? []).map((contact) => (
                      <Tr key={contact.id}>
                        <Td>
                          <div className="flex items-center gap-2">
                            <Link to={`/contacts/${contact.id}`} className="text-primary hover:underline">
                              {contact.full_name}
                            </Link>
                            {contact.is_primary && <Badge variant="primary">{t('companies:detail.primaryBadge')}</Badge>}
                          </div>
                        </Td>
                        <Td>{contact.position ?? '—'}</Td>
                        <Td>{contact.email ?? '—'}</Td>
                        <Td>{contact.phone ?? '—'}</Td>
                      </Tr>
                    ))}
              </TBody>
            </Table>
          )}
        </CardBody>
      </Card>

      <RelatedRecordsPanel
        groups={[
          dealsGroupConfig(t, company.related?.deals),
          quotesGroupConfig(t, company.related?.quotes),
          ticketsGroupConfig(t, company.related?.tickets),
        ]}
      />

      <Card>
        <CardHeader title={t('companies:detail.timelineTitle')} />
        <CardBody className="flex flex-col gap-3">
          <p className="text-xs text-fg-muted">
            {t('companies:detail.timelineDescription')}
          </p>
          <Timeline
            items={timelineItems}
            isLoading={isTimelineLoading}
            isError={isTimelineError}
            onRetry={() => refetchTimeline()}
            hasMore={!!hasNextPage}
            onLoadMore={() => fetchNextPage()}
            isLoadingMore={isFetchingNextPage}
            emptyDescription={t('companies:detail.timelineEmptyDescription')}
          />
        </CardBody>
      </Card>

      <CompanyFormModal open={editOpen} onClose={() => setEditOpen(false)} company={company} />

      <Modal
        open={confirmDeleteOpen}
        onClose={() => setConfirmDeleteOpen(false)}
        title={t('companies:deleteModal.title')}
        description={t('companies:deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmDeleteOpen(false)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteCompany.isPending}
              onClick={async () => {
                // 422 (açık fırsatı olan firma silinemez) durumunda hata yukarı fırlatılır,
                // böylece modal kapanmaz ve gerçek backend mesajı toast'a düşer.
                await deleteCompany.mutateAsync(company.id)
                setConfirmDeleteOpen(false)
                // Kayıt artık yok — sayfada kalırsa detay sorgusu 404 döner ve hata durumu
                // gösterilir. Silme başarılı olduğunda listeye geri dön.
                navigate('/companies')
              }}
            >
              {t('companies:actions.delete')}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-fg-secondary">
          <Trans
            i18nKey="companies:deleteModal.confirmText"
            values={{ name: company.name }}
            components={{ bold: <strong className="text-fg" /> }}
          />
        </p>
      </Modal>
    </div>
  )
}
