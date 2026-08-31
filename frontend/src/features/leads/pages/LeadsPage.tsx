// Müşteri Adayları (Leads) listesi — server-side sayfalama/sıralama/arama/filtreleme,
// tüm durum URL query string'inde (bkz. `UsersPage`/`LogsPage` deseni).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { Pencil, Plus, Repeat, Search, Trash2, Upload, UserCog, Users } from 'lucide-react'
import {
  Avatar,
  Badge,
  Button,
  Card,
  CardBody,
  CardHeader,
  EmptyState,
  Input,
  Modal,
  Pagination,
  Select,
  Skeleton,
  Table,
  TBody,
  Td,
  THead,
  Th,
  Tr,
} from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { recordSyncState } from '../../../components/shared/recordSyncState'
import { SyncStateBadge } from '../../../components/shared/SyncStateBadge'
import { usePermission } from '../../auth/hooks/usePermission'
import { SavedViewsBar } from '../../saved-views/components/SavedViewsBar'
import { useDeleteLead, useLeads, useOwnerOptions, useTags } from '../api/leadsApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { LeadFormModal } from '../components/LeadFormModal'
import { ConvertLeadModal } from '../components/ConvertLeadModal'
import { AssignOwnerModal } from '../components/AssignOwnerModal'
import { ImportLeadsModal } from '../components/ImportLeadsModal'
import { ScoreIndicator } from '../components/ScoreIndicator'
import { SOURCE_LABEL_KEY, STATUS_BADGE_VARIANT, STATUS_LABEL_KEY, leadSourceOptions } from '../utils'
import type { Lead, LeadsQuery } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300

type FormModalState = { mode: 'create' } | { mode: 'edit'; lead: Lead } | null

export function LeadsPage() {
  const { t } = useTranslation(['leads', 'common', 'enums'])
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()

  const STATUS_FILTER_OPTIONS = [
    { value: '', label: t('leads:list.allStatuses') },
    ...(Object.keys(STATUS_LABEL_KEY) as Array<keyof typeof STATUS_LABEL_KEY>).map((value) => ({
      value,
      label: t(STATUS_LABEL_KEY[value], { ns: 'enums' }),
    })),
  ]

  const SOURCE_FILTER_OPTIONS = [{ value: '', label: t('leads:list.allSources') }, ...leadSourceOptions(t)]

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [convertLead, setConvertLead] = useState<Lead | null>(null)
  const [assignLead, setAssignLead] = useState<Lead | null>(null)
  const [deleteLead, setDeleteLeadState] = useState<Lead | null>(null)
  const [importOpen, setImportOpen] = useState(false)

  function updateParams(patch: Record<string, string | null>) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      return next
    })
  }

  useEffect(() => {
    const currentQ = searchParams.get('q') ?? ''
    if (debouncedSearch === currentQ) return
    updateParams({ q: debouncedSearch || null, page: '1' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const query: LeadsQuery = useMemo(() => {
    const ownerId = searchParams.get('owner_id')
    const tagId = searchParams.get('tag_id')
    const scoreMin = searchParams.get('score_min')
    const scoreMax = searchParams.get('score_max')
    return {
      page: Number(searchParams.get('page') ?? '1') || 1,
      per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
      sort: searchParams.get('sort') ?? undefined,
      q: searchParams.get('q') ?? undefined,
      status: searchParams.get('status') ?? undefined,
      source: searchParams.get('source') ?? undefined,
      owner_id: ownerId ? Number(ownerId) : undefined,
      tag_id: tagId ? Number(tagId) : undefined,
      score_min: scoreMin ? Number(scoreMin) : undefined,
      score_max: scoreMax ? Number(scoreMax) : undefined,
      from: searchParams.get('from') ?? undefined,
      to: searchParams.get('to') ?? undefined,
    }
  }, [searchParams])

  const { data, isLoading, isError, refetch } = useLeads(query)
  const { data: ownerOptions, isForbidden: ownersForbidden } = useOwnerOptions()
  const { data: tags } = useTags()
  const deleteLeadMutation = useDeleteLead()

  function sortDirectionFor(field: string): 'asc' | 'desc' | null {
    if (query.sort === field) return 'asc'
    if (query.sort === `-${field}`) return 'desc'
    return null
  }

  function toggleSort(field: string) {
    const current = query.sort
    let nextSort: string | null
    if (current === field) nextSort = `-${field}`
    else if (current === `-${field}`) nextSort = null
    else nextSort = field
    updateParams({ sort: nextSort, page: '1' })
  }

  const ownerFilterOptions = [
    { value: '', label: t('leads:list.allOwners') },
    ...(ownerOptions ?? []).map((owner) => ({ value: String(owner.id), label: owner.name })),
  ]
  const tagFilterOptions = [
    { value: '', label: t('leads:list.allTags') },
    ...(tags ?? []).map((tag) => ({ value: String(tag.id), label: tag.name })),
  ]

  const leads = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && leads.length === 0

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('leads:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('leads:breadcrumb.leads')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('leads:list.title')}
          subtitle={t('leads:list.subtitle', { count: total })}
          action={
            <div className="flex items-center gap-2">
              <SavedViewsBar
                module="leads"
                filterKeys={['status', 'source', 'owner_id', 'tag_id', 'score_min', 'score_max', 'from', 'to']}
              />
              {can('leads.import') && (
                <Button variant="secondary" leftIcon={<Upload className="size-4" aria-hidden="true" />} onClick={() => setImportOpen(true)}>
                  {t('leads:list.importButton')}
                </Button>
              )}
              {can('leads.create') && (
                <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
                  {t('leads:list.createButton')}
                </Button>
              )}
            </div>
          }
        />
        <CardBody noPadding>
          <div className="flex flex-col gap-3 border-b border-border-subtle p-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
              <div className="w-full lg:max-w-xs">
                <Input
                  value={searchDraft}
                  onChange={(e) => setSearchDraft(e.target.value)}
                  placeholder={t('leads:list.searchPlaceholder')}
                  leftIcon={<Search className="size-4" aria-hidden="true" />}
                  aria-label={t('leads:list.searchAria')}
                />
              </div>
              <div className="w-full lg:w-44">
                <Select
                  value={query.status ?? ''}
                  onChange={(e) => updateParams({ status: e.target.value || null, page: '1' })}
                  options={STATUS_FILTER_OPTIONS}
                  aria-label={t('leads:list.statusFilterAria')}
                />
              </div>
              {/* w-52: FR "Toutes les sources"/"Tous les propriétaires"/"Toutes les étiquettes" gibi
                  4 dilin en uzun "Tümü" etiketleri (ölçüldü, bkz. BULGU 2 raporu) native <select>
                  içinde kırpılmadan sığsın diye w-44/w-48'den büyütüldü. */}
              <div className="w-full lg:w-52">
                <Select
                  value={query.source ?? ''}
                  onChange={(e) => updateParams({ source: e.target.value || null, page: '1' })}
                  options={SOURCE_FILTER_OPTIONS}
                  aria-label={t('leads:list.sourceFilterAria')}
                />
              </div>
              {!ownersForbidden && (
                <div className="w-full lg:w-52">
                  <Select
                    value={query.owner_id ? String(query.owner_id) : ''}
                    onChange={(e) => updateParams({ owner_id: e.target.value || null, page: '1' })}
                    options={ownerFilterOptions}
                    aria-label={t('leads:list.ownerFilterAria')}
                  />
                </div>
              )}
              <div className="w-full lg:w-52">
                <Select
                  value={query.tag_id ? String(query.tag_id) : ''}
                  onChange={(e) => updateParams({ tag_id: e.target.value || null, page: '1' })}
                  options={tagFilterOptions}
                  aria-label={t('leads:list.tagFilterAria')}
                />
              </div>
            </div>
            <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
              <div className="flex w-full items-end gap-2 lg:w-auto">
                <div className="w-full lg:w-28">
                  <Input
                    type="number"
                    min={0}
                    max={100}
                    value={query.score_min ?? ''}
                    onChange={(e) => updateParams({ score_min: e.target.value || null, page: '1' })}
                    placeholder={t('leads:list.scoreMinPlaceholder')}
                    aria-label={t('leads:list.scoreMinAria')}
                  />
                </div>
                <span className="pb-2.5 text-xs text-fg-muted">—</span>
                <div className="w-full lg:w-28">
                  <Input
                    type="number"
                    min={0}
                    max={100}
                    value={query.score_max ?? ''}
                    onChange={(e) => updateParams({ score_max: e.target.value || null, page: '1' })}
                    placeholder={t('leads:list.scoreMaxPlaceholder')}
                    aria-label={t('leads:list.scoreMaxAria')}
                  />
                </div>
              </div>
              <div className="flex w-full items-end gap-2 lg:w-auto">
                <div className="w-full lg:w-40">
                  <Input
                    type="date"
                    value={query.from ?? ''}
                    onChange={(e) => updateParams({ from: e.target.value || null, page: '1' })}
                    aria-label={t('leads:list.fromDateAria')}
                    max={query.to || undefined}
                  />
                </div>
                <span className="pb-2.5 text-xs text-fg-muted">—</span>
                <div className="w-full lg:w-40">
                  <Input
                    type="date"
                    value={query.to ?? ''}
                    onChange={(e) => updateParams({ to: e.target.value || null, page: '1' })}
                    aria-label={t('leads:list.toDateAria')}
                    min={query.from || undefined}
                  />
                </div>
              </div>
            </div>
          </div>

          {isError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <p className="text-sm text-fg-muted">{t('leads:list.loadError')}</p>
              <Button variant="secondary" onClick={() => refetch()}>
                {t('leads:list.retry')}
              </Button>
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<Users className="size-6" aria-hidden="true" />}
              title={t('leads:list.emptyTitle')}
              description={t('leads:list.emptyDescription')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th sortable sortDirection={sortDirectionFor('first_name')} onSort={() => toggleSort('first_name')}>
                    {t('leads:list.columns.name')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('company_name')} onSort={() => toggleSort('company_name')}>
                    {t('leads:list.columns.company')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('email')} onSort={() => toggleSort('email')}>
                    {t('leads:list.columns.email')}
                  </Th>
                  <Th>{t('leads:list.columns.phone')}</Th>
                  <Th sortable sortDirection={sortDirectionFor('source')} onSort={() => toggleSort('source')}>
                    {t('leads:list.columns.source')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('status')} onSort={() => toggleSort('status')}>
                    {t('leads:list.columns.status')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('score')} onSort={() => toggleSort('score')}>
                    {t('leads:list.columns.score')}
                  </Th>
                  <Th>{t('leads:list.columns.owner')}</Th>
                  <Th>{t('leads:list.columns.tags')}</Th>
                  <Th align="right">{t('leads:list.columns.actions')}</Th>
                </Tr>
              </THead>
              <TBody aria-busy={isLoading}>
                {isLoading
                  ? Array.from({ length: query.per_page ?? DEFAULT_PER_PAGE }).map((_, i) => (
                      <Tr key={i}>
                        <Td><Skeleton variant="text" width={140} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={140} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td><Skeleton variant="text" width={70} /></Td>
                        <Td><Skeleton variant="text" width={90} /></Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td align="right"><Skeleton variant="text" width={100} className="ml-auto" /></Td>
                      </Tr>
                    ))
                  : leads.map((lead) => {
                      const isConverted = lead.status === 'converted'
                      return (
                        <Tr key={lead.id}>
                          <Td>
                            <span className="inline-flex items-center gap-2">
                              <Link to={`/leads/${lead.id}`} className="font-medium text-fg hover:text-primary hover:underline">
                                {lead.full_name}
                              </Link>
                              <SyncStateBadge state={recordSyncState(lead)} compact />
                            </span>
                          </Td>
                          <Td>{lead.company_name ?? '—'}</Td>
                          <Td className="text-fg-secondary">{lead.email ?? '—'}</Td>
                          <Td className="text-fg-secondary">{lead.phone ?? '—'}</Td>
                          <Td>
                            <Badge variant="neutral">{t(SOURCE_LABEL_KEY[lead.source], { ns: 'enums' })}</Badge>
                          </Td>
                          <Td>
                            <Badge variant={STATUS_BADGE_VARIANT[lead.status]}>
                              {t(STATUS_LABEL_KEY[lead.status], { ns: 'enums' })}
                            </Badge>
                          </Td>
                          <Td>
                            <ScoreIndicator score={lead.score} />
                          </Td>
                          <Td>
                            {lead.owner ? (
                              <div className="flex items-center gap-2">
                                <Avatar name={lead.owner.name} size="xs" />
                                <span className="truncate text-sm text-fg">{lead.owner.name}</span>
                              </div>
                            ) : (
                              <span className="text-fg-muted">—</span>
                            )}
                          </Td>
                          <Td>
                            <div className="flex flex-wrap gap-1">
                              {lead.tags.length === 0 && <span className="text-fg-muted">—</span>}
                              {lead.tags.map((tag) => (
                                <Badge key={tag.id} variant="neutral" size="sm">
                                  {tag.name}
                                </Badge>
                              ))}
                            </div>
                          </Td>
                          <Td align="right">
                            <div className="flex items-center justify-end gap-1">
                              <IconLinkButton label={t('leads:actions.detail')} to={`/leads/${lead.id}`}>
                                <UserCog className="size-4" aria-hidden="true" />
                              </IconLinkButton>
                              {/* Faz 13: `!isConverted` durum kuralını korur (dönüşmüş lead zaten policy'de
                                  de reddedilir, ama iş kuralı olarak burada da açık kalsın), `can.update`
                                  false ise (yalnızca sahiplik kalır — durum burada zaten elendi) buton
                                  GİZLENMEZ, devre dışı + tooltip gösterilir. */}
                              {!isConverted && can('leads.update') && (
                                <IconButton
                                  label={t('leads:actions.edit')}
                                  disabled={!lead.can.update}
                                  title={lead.can.update ? t('leads:actions.edit') : t('leads:actions.editDisabledTitle')}
                                  onClick={() => setFormModal({ mode: 'edit', lead })}
                                >
                                  <Pencil className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {!isConverted && can('leads.convert') && (
                                <IconButton
                                  label={t('leads:actions.convert')}
                                  disabled={!lead.can.convert}
                                  title={lead.can.convert ? t('leads:actions.convert') : t('leads:actions.convertDisabledTitle')}
                                  onClick={() => setConvertLead(lead)}
                                >
                                  <Repeat className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {/* `leads.assign` saf izin kontrolüdür (sahiplik boyutu yok) — `can.assign`
                                  her zaman modül izniyle aynıdır, gizlemek yeterli. */}
                              {can('leads.assign') && lead.can.assign && (
                                <IconButton label={t('leads:actions.assign')} onClick={() => setAssignLead(lead)}>
                                  <Users className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {/* Dönüştürülmüş lead silinemez — sahiplikten bağımsız, herkes için geçerli
                                  bir durum kuralı (bkz. `LeadPolicy::delete`); GİZLEME ile ele alınır,
                                  istemci kendi `isConverted` kopyasını bu koşulda tutmaz. */}
                              {can('leads.delete') && lead.can.delete && (
                                <IconButton label={t('leads:actions.delete')} danger onClick={() => setDeleteLeadState(lead)}>
                                  <Trash2 className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                            </div>
                          </Td>
                        </Tr>
                      )
                    })}
              </TBody>
            </Table>
          )}

          {!isError && !isEmpty && (
            <div className="border-t border-border-subtle p-4">
              <Pagination
                currentPage={query.page ?? 1}
                totalItems={total}
                pageSize={query.per_page ?? DEFAULT_PER_PAGE}
                onPageChange={(page) => updateParams({ page: String(page) })}
              />
            </div>
          )}
        </CardBody>
      </Card>

      <LeadFormModal open={!!formModal} onClose={() => setFormModal(null)} lead={formModal?.mode === 'edit' ? formModal.lead : null} />
      <ConvertLeadModal open={!!convertLead} onClose={() => setConvertLead(null)} lead={convertLead} />
      <AssignOwnerModal open={!!assignLead} onClose={() => setAssignLead(null)} lead={assignLead} />
      <ImportLeadsModal open={importOpen} onClose={() => setImportOpen(false)} />

      <Modal
        open={!!deleteLead}
        onClose={() => setDeleteLeadState(null)}
        title={t('leads:deleteModal.title')}
        description={t('leads:deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteLeadState(null)}>
              {t('leads:deleteModal.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteLeadMutation.isPending}
              onClick={async () => {
                if (!deleteLead) return
                await deleteLeadMutation.mutateAsync(deleteLead.id)
                setDeleteLeadState(null)
              }}
            >
              {t('leads:deleteModal.confirm')}
            </Button>
          </div>
        }
      >
        {deleteLead && (
          <p className="text-sm text-fg-secondary">
            <Trans
              i18nKey="leads:deleteModal.confirmText"
              values={{ name: deleteLead.full_name }}
              components={{ bold: <strong className="text-fg" /> }}
            />
          </p>
        )}
      </Modal>
    </div>
  )
}

function IconButton({
  label,
  onClick,
  children,
  danger,
  disabled,
  title,
}: {
  label: string
  onClick: () => void
  children: ReactNode
  danger?: boolean
  /** Faz 13: izin var ama bu kayıtta `can.*` false — buton görünür kalır, tıklanamaz olur. */
  disabled?: boolean
  /** Varsayılan tooltip `label`'dır; devre dışı durumda nedeni açıklayan bir metinle geçilebilir. */
  title?: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      title={title ?? label}
      className={cn(
        'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
        'transition-colors duration-150 motion-reduce:transition-none',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
        'disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-fg-muted',
        danger && 'hover:text-danger'
      )}
    >
      {children}
    </button>
  )
}

function IconLinkButton({ label, to, children }: { label: string; to: string; children: ReactNode }) {
  return (
    <Link
      to={to}
      aria-label={label}
      title={label}
      className={cn(
        'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
        'transition-colors duration-150 motion-reduce:transition-none',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
      )}
    >
      {children}
    </Link>
  )
}
