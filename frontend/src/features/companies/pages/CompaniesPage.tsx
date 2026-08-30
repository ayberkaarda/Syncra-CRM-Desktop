// Firmalar listesi — server-side sayfalama/sıralama/arama/filtreleme, tüm durum gösterge
// deseni (yükleme/boş/hata) ve izin kontrollü işlemler. `UsersPage.tsx` deseninin bire bir
// aynısı, Firmalar alan modeline uyarlanmış hali.
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { Building2, Eye, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import {
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
import { formatMoney } from '../../../lib/money'
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import { usePermission } from '../../auth/hooks/usePermission'
import { SavedViewsBar } from '../../saved-views/components/SavedViewsBar'
import { useCompanies, useDeleteCompany, useTags, useUserOptions } from '../api/companiesApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { CompanyFormModal } from '../components/CompanyFormModal'
import type { Company, CompaniesQuery } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300

const formatCurrency = formatMoney

function IconButton({
  label,
  onClick,
  children,
  danger,
}: {
  label: string
  onClick: () => void
  children: ReactNode
  danger?: boolean
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      title={label}
      className={cn(
        'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
        'transition-colors duration-150 motion-reduce:transition-none',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
        danger && 'hover:text-danger'
      )}
    >
      {children}
    </button>
  )
}

function IconLink({ to, label, children }: { to: string; label: string; children: ReactNode }) {
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

type FormModalState = { mode: 'create' } | { mode: 'edit'; company: Company } | null

export function CompaniesPage() {
  const { t } = useTranslation(['companies', 'common'])
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()
  const canViewUsers = can('users.view')

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [confirmDeleteCompany, setConfirmDeleteCompany] = useState<Company | null>(null)

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

  // Arama kutusu debounce edilir; sonuç URL'e yazılır ki sayfa yenilenince kaybolmasın.
  useEffect(() => {
    const currentQ = searchParams.get('q') ?? ''
    if (debouncedSearch === currentQ) return
    updateParams({ q: debouncedSearch || null, page: '1' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const query: CompaniesQuery = useMemo(
    () => ({
      page: Number(searchParams.get('page') ?? '1') || 1,
      per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
      sort: searchParams.get('sort') ?? undefined,
      q: searchParams.get('q') ?? undefined,
      industry: searchParams.get('industry') ?? undefined,
      owner_id: searchParams.has('owner_id') ? Number(searchParams.get('owner_id')) : undefined,
      city: searchParams.get('city') ?? undefined,
      country: searchParams.get('country') ?? undefined,
      tag_id: searchParams.has('tag_id') ? Number(searchParams.get('tag_id')) : undefined,
      from: searchParams.get('from') ?? undefined,
      to: searchParams.get('to') ?? undefined,
    }),
    [searchParams]
  )

  const { data, isLoading, isError, refetch } = useCompanies(query)
  const { data: tags } = useTags()
  const { data: userOptions } = useUserOptions({ enabled: canViewUsers })
  const deleteCompany = useDeleteCompany()

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

  const tagFilterOptions = [
    { value: '', label: t('companies:list.allTags') },
    ...(tags ?? []).map((tag) => ({ value: String(tag.id), label: tag.name })),
  ]

  const ownerFilterOptions = [
    { value: '', label: t('companies:list.allOwners') },
    ...(userOptions ?? []).map((user) => ({ value: String(user.id), label: user.name })),
  ]

  const companies = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && companies.length === 0

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('companies:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('companies:breadcrumb.companies')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('companies:list.title')}
          subtitle={t('companies:list.subtitle', { count: total })}
          action={
            <div className="flex items-center gap-2">
              <SavedViewsBar
                module="companies"
                filterKeys={['industry', 'owner_id', 'city', 'country', 'tag_id', 'from', 'to']}
              />
              {can('companies.create') && (
                <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
                  {t('companies:list.createButton')}
                </Button>
              )}
            </div>
          }
        />
        <CardBody noPadding>
          <div className="flex flex-col gap-3 border-b border-border-subtle p-4 lg:flex-row lg:flex-wrap lg:items-end">
            <div className="w-full lg:max-w-xs">
              <Input
                value={searchDraft}
                onChange={(e) => setSearchDraft(e.target.value)}
                placeholder={t('companies:list.searchPlaceholder')}
                leftIcon={<Search className="size-4" aria-hidden="true" />}
                aria-label={t('companies:list.searchAria')}
              />
            </div>
            <div className="w-full lg:w-44">
              <Input
                value={query.industry ?? ''}
                onChange={(e) => updateParams({ industry: e.target.value || null, page: '1' })}
                placeholder={t('companies:list.industryPlaceholder')}
                aria-label={t('companies:list.industryAria')}
              />
            </div>
            {/* w-52: FR "Tous les propriétaires"/"Toutes les étiquettes" gibi 4 dilin en uzun
                "Tümü" etiketleri (ölçüldü, bkz. BULGU 2 raporu) native <select> içinde
                kırpılmadan sığsın diye w-44/w-48'den büyütüldü. */}
            {canViewUsers && (
              <div className="w-full lg:w-52">
                <Select
                  value={query.owner_id ? String(query.owner_id) : ''}
                  onChange={(e) => updateParams({ owner_id: e.target.value || null, page: '1' })}
                  options={ownerFilterOptions}
                  aria-label={t('companies:list.ownerAria')}
                />
              </div>
            )}
            <div className="w-full lg:w-36">
              <Input
                value={query.city ?? ''}
                onChange={(e) => updateParams({ city: e.target.value || null, page: '1' })}
                placeholder={t('companies:list.cityPlaceholder')}
                aria-label={t('companies:list.cityAria')}
              />
            </div>
            <div className="w-full lg:w-36">
              <Input
                value={query.country ?? ''}
                onChange={(e) => updateParams({ country: e.target.value || null, page: '1' })}
                placeholder={t('companies:list.countryPlaceholder')}
                aria-label={t('companies:list.countryAria')}
              />
            </div>
            <div className="w-full lg:w-52">
              <Select
                value={query.tag_id ? String(query.tag_id) : ''}
                onChange={(e) => updateParams({ tag_id: e.target.value || null, page: '1' })}
                options={tagFilterOptions}
                aria-label={t('companies:list.tagAria')}
              />
            </div>
            <div className="flex w-full items-end gap-2 lg:w-auto">
              <div className="w-full lg:w-40">
                <Input
                  type="date"
                  value={query.from ?? ''}
                  onChange={(e) => updateParams({ from: e.target.value || null, page: '1' })}
                  aria-label={t('companies:list.fromDateAria')}
                  max={query.to || undefined}
                />
              </div>
              <span className="pb-2.5 text-xs text-fg-muted">—</span>
              <div className="w-full lg:w-40">
                <Input
                  type="date"
                  value={query.to ?? ''}
                  onChange={(e) => updateParams({ to: e.target.value || null, page: '1' })}
                  aria-label={t('companies:list.toDateAria')}
                  min={query.from || undefined}
                />
              </div>
            </div>
          </div>

          {isError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <p className="text-sm text-fg-muted">{t('companies:list.loadError')}</p>
              <Button variant="secondary" onClick={() => refetch()}>
                {t('companies:list.retry')}
              </Button>
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<Building2 className="size-6" aria-hidden="true" />}
              title={t('companies:list.emptyTitle')}
              description={t('companies:list.emptyDescription')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th sortable sortDirection={sortDirectionFor('name')} onSort={() => toggleSort('name')}>
                    {t('companies:list.columns.name')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('industry')} onSort={() => toggleSort('industry')}>
                    {t('companies:list.columns.industry')}
                  </Th>
                  <Th>{t('companies:list.columns.primaryContact')}</Th>
                  <Th>{t('companies:list.columns.email')}</Th>
                  <Th>{t('companies:list.columns.phone')}</Th>
                  <Th
                    sortable
                    sortDirection={sortDirectionFor('city')}
                    onSort={() => toggleSort('city')}
                    title={t('companies:list.columns.cityCountrySortHint')}
                  >
                    {t('companies:list.columns.cityCountry')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('employee_count')} onSort={() => toggleSort('employee_count')} align="right">
                    {t('companies:list.columns.employeeCount')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('annual_revenue')} onSort={() => toggleSort('annual_revenue')} align="right">
                    {t('companies:list.columns.annualRevenue')}
                  </Th>
                  <Th align="right">{t('companies:list.columns.contactsCount')}</Th>
                  <Th align="right">{t('companies:list.columns.dealsCount')}</Th>
                  <Th>{t('companies:list.columns.owner')}</Th>
                  <Th>{t('companies:list.columns.tags')}</Th>
                  <Th align="right">{t('companies:list.columns.actions')}</Th>
                </Tr>
              </THead>
              <TBody aria-busy={isLoading}>
                {isLoading
                  ? Array.from({ length: query.per_page ?? DEFAULT_PER_PAGE }).map((_, i) => (
                      <Tr key={i}>
                        <Td>
                          <div className="flex items-center gap-3">
                            <Skeleton variant="circle" width={32} height={32} />
                            <Skeleton variant="text" width={140} />
                          </div>
                        </Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={120} /></Td>
                        <Td><Skeleton variant="text" width={90} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td align="right"><Skeleton variant="text" width={50} className="ml-auto" /></Td>
                        <Td align="right"><Skeleton variant="text" width={70} className="ml-auto" /></Td>
                        <Td align="right"><Skeleton variant="text" width={40} className="ml-auto" /></Td>
                        <Td align="right"><Skeleton variant="text" width={40} className="ml-auto" /></Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td align="right"><Skeleton variant="text" width={90} className="ml-auto" /></Td>
                      </Tr>
                    ))
                  : companies.map((c) => (
                      <Tr key={c.id}>
                        <Td>
                          <div className="flex items-center gap-3">
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-tint text-primary">
                              <Building2 className="size-4" aria-hidden="true" />
                            </span>
                            <p className="truncate text-sm font-medium text-fg">{c.name}</p>
                          </div>
                        </Td>
                        <Td>{c.industry ?? <span className="text-fg-muted">—</span>}</Td>
                        <Td>
                          {c.primary_contact ? (
                            <Link to={`/contacts/${c.primary_contact.id}`} className="text-primary hover:underline">
                              {c.primary_contact.full_name}
                            </Link>
                          ) : (
                            <span className="text-fg-muted">—</span>
                          )}
                        </Td>
                        <Td>{c.email ?? '—'}</Td>
                        <Td>{c.phone ?? '—'}</Td>
                        <Td>{[c.city, c.country].filter(Boolean).join(', ') || '—'}</Td>
                        <Td align="right">{c.employee_count ?? '—'}</Td>
                        <Td align="right">{formatCurrency(c.annual_revenue)}</Td>
                        <Td align="right">{c.contacts_count}</Td>
                        <Td align="right">{c.deals_count}</Td>
                        <Td>{c.owner?.name ?? '—'}</Td>
                        <Td>
                          {c.tags.length > 0 ? (
                            <div className="flex flex-wrap gap-1">
                              {c.tags.map((tag) => (
                                <Badge key={tag.id} variant={tokenBadgeVariant(tag.color)}>
                                  {tag.name}
                                </Badge>
                              ))}
                            </div>
                          ) : (
                            <span className="text-fg-muted">—</span>
                          )}
                        </Td>
                        <Td align="right">
                          <div className="flex items-center justify-end gap-1">
                            <IconLink to={`/companies/${c.id}`} label={t('companies:actions.detail')}>
                              <Eye className="size-4" aria-hidden="true" />
                            </IconLink>
                            {can('companies.update') && (
                              <IconButton label={t('companies:actions.edit')} onClick={() => setFormModal({ mode: 'edit', company: c })}>
                                <Pencil className="size-4" aria-hidden="true" />
                              </IconButton>
                            )}
                            {can('companies.delete') && (
                              <IconButton label={t('companies:actions.delete')} danger onClick={() => setConfirmDeleteCompany(c)}>
                                <Trash2 className="size-4" aria-hidden="true" />
                              </IconButton>
                            )}
                          </div>
                        </Td>
                      </Tr>
                    ))}
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

      <CompanyFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        company={formModal?.mode === 'edit' ? formModal.company : null}
      />

      <Modal
        open={!!confirmDeleteCompany}
        onClose={() => setConfirmDeleteCompany(null)}
        title={t('companies:deleteModal.title')}
        description={t('companies:deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmDeleteCompany(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteCompany.isPending}
              onClick={async () => {
                if (!confirmDeleteCompany) return
                // 422 (açık fırsatı olan firma silinemez) durumunda hata yukarı fırlatılır,
                // böylece modal kapanmaz ve `useDeleteCompany`'nin onError'ı gerçek mesajı toast'a düşürür.
                await deleteCompany.mutateAsync(confirmDeleteCompany.id)
                setConfirmDeleteCompany(null)
              }}
            >
              {t('companies:actions.delete')}
            </Button>
          </div>
        }
      >
        {confirmDeleteCompany && (
          <p className="text-sm text-fg-secondary">
            <Trans
              i18nKey="companies:deleteModal.confirmText"
              values={{ name: confirmDeleteCompany.name }}
              components={{ bold: <strong className="text-fg" /> }}
            />
          </p>
        )}
      </Modal>
    </div>
  )
}
