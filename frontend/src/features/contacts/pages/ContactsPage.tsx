// Kişiler listesi — server-side sayfalama/sıralama/arama/filtreleme, tüm durum gösterge deseni
// (yükleme/boş/hata) ve izin kontrollü işlemler (bkz. `UsersPage.tsx` referans deseni).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { Eye, Pencil, Plus, Search, Trash2, Users as UsersIcon } from 'lucide-react'
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
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import { usePermission } from '../../auth/hooks/usePermission'
import { SavedViewsBar } from '../../saved-views/components/SavedViewsBar'
import { useAllCompanyOptions, useDeleteContact, useContacts, useTags, useUserOptions } from '../api/contactsApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { ContactFormModal } from '../components/ContactFormModal'
import type { Contact, ContactsQuery } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300

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

type FormModalState = { mode: 'create' } | { mode: 'edit'; contact: Contact } | null

export function ContactsPage() {
  const { t } = useTranslation('contacts')
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()
  const canViewUsers = can('users.view')

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [confirmDeleteContact, setConfirmDeleteContact] = useState<Contact | null>(null)

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

  const query: ContactsQuery = useMemo(
    () => ({
      page: Number(searchParams.get('page') ?? '1') || 1,
      per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
      sort: searchParams.get('sort') ?? undefined,
      q: searchParams.get('q') ?? undefined,
      company_id: searchParams.has('company_id') ? Number(searchParams.get('company_id')) : undefined,
      owner_id: searchParams.has('owner_id') ? Number(searchParams.get('owner_id')) : undefined,
      is_primary: searchParams.has('is_primary') ? searchParams.get('is_primary') === 'true' : undefined,
      city: searchParams.get('city') ?? undefined,
      tag_id: searchParams.has('tag_id') ? Number(searchParams.get('tag_id')) : undefined,
      from: searchParams.get('from') ?? undefined,
      to: searchParams.get('to') ?? undefined,
    }),
    [searchParams]
  )

  const { data, isLoading, isError, refetch } = useContacts(query)
  const { data: companyOptions } = useAllCompanyOptions()
  const { data: userOptions } = useUserOptions({ enabled: canViewUsers })
  const { data: tagOptions } = useTags()
  const deleteContact = useDeleteContact()

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

  const companyFilterOptions = [
    { value: '', label: t('filters.allCompanies') },
    ...(companyOptions ?? []).map((c) => ({ value: String(c.id), label: c.name })),
  ]

  const ownerFilterOptions = [
    { value: '', label: t('filters.allOwners') },
    ...(userOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]

  const tagFilterOptions = [
    { value: '', label: t('filters.allTags') },
    ...(tagOptions ?? []).map((tag) => ({ value: String(tag.id), label: tag.name })),
  ]

  const primaryFilterOptions = [
    { value: '', label: t('filters.all') },
    { value: 'true', label: t('filters.yes') },
    { value: 'false', label: t('filters.no') },
  ]

  const contacts = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && contacts.length === 0

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('breadcrumb.contacts')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('list.title')}
          subtitle={t('list.subtitle', { count: total })}
          action={
            <div className="flex items-center gap-2">
              <SavedViewsBar module="contacts" filterKeys={['company_id', 'owner_id', 'is_primary', 'city', 'tag_id', 'from', 'to']} />
              {can('contacts.create') && (
                <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
                  {t('list.createButton')}
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
                placeholder={t('filters.searchPlaceholder')}
                leftIcon={<Search className="size-4" aria-hidden="true" />}
                aria-label={t('filters.searchAria')}
              />
            </div>
            {/* w-52: FR "Toutes les entreprises"/"Tous les propriétaires"/"Toutes les étiquettes"
                gibi 4 dilin en uzun "Tümü" etiketleri (ölçüldü, bkz. BULGU 2 raporu) native
                <select> içinde kırpılmadan sığsın diye w-44/w-48'den büyütüldü. */}
            <div className="w-full lg:w-52">
              <Select
                value={query.company_id ? String(query.company_id) : ''}
                onChange={(e) => updateParams({ company_id: e.target.value || null, page: '1' })}
                options={companyFilterOptions}
                aria-label={t('filters.companyAria')}
              />
            </div>
            {canViewUsers && (
              <div className="w-full lg:w-52">
                <Select
                  value={query.owner_id ? String(query.owner_id) : ''}
                  onChange={(e) => updateParams({ owner_id: e.target.value || null, page: '1' })}
                  options={ownerFilterOptions}
                  aria-label={t('filters.ownerAria')}
                />
              </div>
            )}
            <div className="w-full lg:w-40">
              <Input
                value={query.city ?? ''}
                onChange={(e) => updateParams({ city: e.target.value || null, page: '1' })}
                placeholder={t('filters.cityPlaceholder')}
                aria-label={t('filters.cityAria')}
              />
            </div>
            <div className="w-full lg:w-52">
              <Select
                value={query.tag_id ? String(query.tag_id) : ''}
                onChange={(e) => updateParams({ tag_id: e.target.value || null, page: '1' })}
                options={tagFilterOptions}
                aria-label={t('filters.tagAria')}
              />
            </div>
            <div className="w-full lg:w-36">
              <Select
                value={query.is_primary === undefined ? '' : String(query.is_primary)}
                onChange={(e) => updateParams({ is_primary: e.target.value || null, page: '1' })}
                options={primaryFilterOptions}
                aria-label={t('filters.primaryAria')}
              />
            </div>
            <div className="flex w-full items-end gap-2 lg:w-auto">
              <div className="w-full lg:w-40">
                <Input
                  type="date"
                  value={query.from ?? ''}
                  onChange={(e) => updateParams({ from: e.target.value || null, page: '1' })}
                  aria-label={t('filters.fromDateAria')}
                  max={query.to || undefined}
                />
              </div>
              <span className="pb-2.5 text-xs text-fg-muted">—</span>
              <div className="w-full lg:w-40">
                <Input
                  type="date"
                  value={query.to ?? ''}
                  onChange={(e) => updateParams({ to: e.target.value || null, page: '1' })}
                  aria-label={t('filters.toDateAria')}
                  min={query.from || undefined}
                />
              </div>
            </div>
          </div>

          {isError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <p className="text-sm text-fg-muted">{t('list.error')}</p>
              <Button variant="secondary" onClick={() => refetch()}>
                {t('list.retry')}
              </Button>
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<UsersIcon className="size-6" aria-hidden="true" />}
              title={t('list.empty.title')}
              description={t('list.empty.description')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th sortable sortDirection={sortDirectionFor('last_name')} onSort={() => toggleSort('last_name')}>
                    {t('table.contact')}
                  </Th>
                  <Th>{t('table.company')}</Th>
                  <Th sortable sortDirection={sortDirectionFor('email')} onSort={() => toggleSort('email')}>
                    {t('table.email')}
                  </Th>
                  <Th>{t('table.phone')}</Th>
                  <Th sortable sortDirection={sortDirectionFor('city')} onSort={() => toggleSort('city')}>
                    {t('table.city')}
                  </Th>
                  <Th>{t('table.owner')}</Th>
                  <Th>{t('table.tags')}</Th>
                  <Th align="center">{t('table.primary')}</Th>
                  <Th align="right">{t('table.actions')}</Th>
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
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={140} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td align="center"><Skeleton variant="text" width={60} className="mx-auto" /></Td>
                        <Td align="right"><Skeleton variant="text" width={70} className="ml-auto" /></Td>
                      </Tr>
                    ))
                  : contacts.map((c) => (
                      <Tr key={c.id}>
                        <Td>
                          <div className="flex items-center gap-3">
                            <Avatar name={c.full_name} size="sm" />
                            <div className="min-w-0">
                              <div className="flex items-center gap-2">
                                <p className="truncate text-sm font-medium text-fg">{c.full_name}</p>
                                {/* Masaüstünde çevrimdışı yapılan bir düzenleme sunucuya ulaşana kadar
                                    burada işaretli kalır; web'de `sync_state` hiç dolmadığı için rozet
                                    `null` döner ve satır bugünküyle birebir aynıdır. */}
                                <SyncStateBadge state={recordSyncState(c)} compact />
                              </div>
                              {c.position && <p className="truncate text-xs text-fg-muted">{c.position}</p>}
                            </div>
                          </div>
                        </Td>
                        <Td>
                          {c.company ? (
                            <Link to={`/companies/${c.company.id}`} className="text-primary hover:underline">
                              {c.company.name}
                            </Link>
                          ) : (
                            <span className="text-fg-muted">—</span>
                          )}
                        </Td>
                        <Td>{c.email ?? <span className="text-fg-muted">—</span>}</Td>
                        <Td>{c.phone ?? c.mobile ?? <span className="text-fg-muted">—</span>}</Td>
                        <Td>{c.city ?? <span className="text-fg-muted">—</span>}</Td>
                        <Td>{c.owner?.name ?? <span className="text-fg-muted">—</span>}</Td>
                        <Td>
                          {c.tags.length > 0 ? (
                            <div className="flex flex-wrap gap-1">
                              {c.tags.map((tag) => (
                                <Badge key={tag.id} variant={tokenBadgeVariant(tag.color)} size="sm">
                                  {tag.name}
                                </Badge>
                              ))}
                            </div>
                          ) : (
                            <span className="text-fg-muted">—</span>
                          )}
                        </Td>
                        <Td align="center">
                          {c.is_primary && <Badge variant="primary">{t('table.primary')}</Badge>}
                        </Td>
                        <Td align="right">
                          <div className="flex items-center justify-end gap-1">
                            <Link
                              to={`/contacts/${c.id}`}
                              aria-label={t('table.detail')}
                              title={t('table.detail')}
                              className={cn(
                                'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
                                'transition-colors duration-150 motion-reduce:transition-none',
                                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
                              )}
                            >
                              <Eye className="size-4" aria-hidden="true" />
                            </Link>
                            {can('contacts.update') && (
                              <IconButton label={t('table.edit')} onClick={() => setFormModal({ mode: 'edit', contact: c })}>
                                <Pencil className="size-4" aria-hidden="true" />
                              </IconButton>
                            )}
                            {can('contacts.delete') && (
                              <IconButton label={t('table.delete')} danger onClick={() => setConfirmDeleteContact(c)}>
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

      <ContactFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        contact={formModal?.mode === 'edit' ? formModal.contact : null}
      />

      <Modal
        open={!!confirmDeleteContact}
        onClose={() => setConfirmDeleteContact(null)}
        title={t('deleteModal.title')}
        description={t('deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmDeleteContact(null)}>
              {t('form.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteContact.isPending}
              onClick={async () => {
                if (!confirmDeleteContact) return
                await deleteContact.mutateAsync(confirmDeleteContact.id)
                setConfirmDeleteContact(null)
              }}
            >
              {t('table.delete')}
            </Button>
          </div>
        }
      >
        {confirmDeleteContact && (
          <p className="text-sm text-fg-secondary">
            <Trans
              t={t}
              i18nKey="deleteModal.confirm"
              values={{ name: confirmDeleteContact.full_name }}
              components={{ strong: <strong className="text-fg" /> }}
            />
          </p>
        )}
      </Modal>
    </div>
  )
}
