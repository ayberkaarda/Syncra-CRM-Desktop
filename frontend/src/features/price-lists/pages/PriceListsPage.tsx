// Fiyat listeleri tablo görünümü — server-side sayfalama/sıralama/arama/filtreleme, tüm durum
// URL query string'inde (bkz. `products/pages/ProductsPage.tsx` ile aynı desen).
//
// SIDEBAR NOTU (görev tanımı): `Sidebar.tsx` bu D şeridin dosya sahipliği DIŞINDA — "Fiyat
// Listeleri" için sidebar'a öğe EKLENMEDİ. Bunun yerine `/products` sayfasının başlığındaki
// "Fiyat Listeleri" bağlantısı (bkz. `ProductsPage.tsx`) ve buradaki geri bağlantı tek giriş
// noktasıdır.
//
// SİLME NOTU: varsayılan liste sunucuda 422 ile SİLİNEMEZ (bkz. `PriceListService::delete()`).
// Bu yüzden varsayılan bir liste için Sil butonu DEVRE DIŞI bırakılır + açıklama gösterilir,
// silme isteği hiç gönderilmez (kullanıcı 422'yi görmeden önce engellenir).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { AlertTriangle, ArrowLeft, ListChecks, Pencil, Plus, Search, Settings2, Trash2 } from 'lucide-react'
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
import { usePermission } from '../../auth/hooks/usePermission'
import { formatDate } from '../../products/utils/formatters'
import { useDebouncedValue } from '../../products/hooks/useDebouncedValue'
import { useDeletePriceList, usePriceLists } from '../api/priceListsApi'
import type { PriceListsQuery } from '../api/priceListsApi'
import { PriceListFormModal } from '../components/PriceListFormModal'
import type { PriceList } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300

type FormModalState = { mode: 'create' } | { mode: 'edit'; priceList: PriceList } | null

function isOutsideValidity(priceList: PriceList): boolean {
  const today = new Date().toISOString().slice(0, 10)
  if (priceList.valid_from && today < priceList.valid_from) return true
  if (priceList.valid_until && today > priceList.valid_until) return true
  return false
}

export function PriceListsPage() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [deleteState, setDeleteState] = useState<PriceList | null>(null)

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

  const query: PriceListsQuery = useMemo(() => {
    const isActive = searchParams.get('is_active')
    const isDefault = searchParams.get('is_default')
    return {
      page: Number(searchParams.get('page') ?? '1') || 1,
      per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
      sort: searchParams.get('sort') ?? '-created_at',
      q: searchParams.get('q') ?? undefined,
      is_active: isActive === '' || isActive === null ? undefined : isActive === '1',
      is_default: isDefault === '' || isDefault === null ? undefined : isDefault === '1',
    }
  }, [searchParams])

  const { data, isLoading, isError, refetch } = usePriceLists(query)
  const deletePriceListMutation = useDeletePriceList()

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

  const statusFilterOptions = [
    { value: '', label: t('priceLists:list.allStatuses') },
    { value: '1', label: t('priceLists:status.active') },
    { value: '0', label: t('priceLists:status.inactive') },
  ]
  const defaultFilterOptions = [
    { value: '', label: t('priceLists:list.allDefault') },
    { value: '1', label: t('priceLists:list.onlyDefault') },
    { value: '0', label: t('priceLists:list.notDefault') },
  ]

  const priceLists = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && priceLists.length === 0

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="flex items-center gap-1.5 text-xs text-fg-muted">
        <Link to="/products" className="inline-flex items-center gap-1 hover:text-fg">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          {t('priceLists:breadcrumb.products')}
        </Link>
        <span className="mx-1">/</span>
        <span className="text-primary">{t('priceLists:breadcrumb.priceLists')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('priceLists:list.title')}
          subtitle={t('priceLists:list.subtitle', { count: total })}
          action={
            can('products.create') && (
              <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
                {t('priceLists:list.createButton')}
              </Button>
            )
          }
        />
        <CardBody noPadding>
          <div className="flex flex-col gap-3 border-b border-border-subtle p-4 lg:flex-row lg:flex-wrap lg:items-end">
            <div className="w-full lg:max-w-xs">
              <Input
                value={searchDraft}
                onChange={(e) => setSearchDraft(e.target.value)}
                placeholder={t('priceLists:list.searchPlaceholder')}
                leftIcon={<Search className="size-4" aria-hidden="true" />}
                aria-label={t('priceLists:list.searchAria')}
              />
            </div>
            <div className="w-full lg:w-40">
              <Select
                value={searchParams.get('is_active') ?? ''}
                onChange={(e) => updateParams({ is_active: e.target.value || null, page: '1' })}
                options={statusFilterOptions}
                aria-label={t('priceLists:list.statusAria')}
              />
            </div>
            <div className="w-full lg:w-52">
              <Select
                value={searchParams.get('is_default') ?? ''}
                onChange={(e) => updateParams({ is_default: e.target.value || null, page: '1' })}
                options={defaultFilterOptions}
                aria-label={t('priceLists:list.defaultAria')}
              />
            </div>
          </div>

          {isError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <p className="text-sm text-fg-muted">{t('priceLists:list.loadError')}</p>
              <Button variant="secondary" onClick={() => refetch()}>
                {t('priceLists:retry')}
              </Button>
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<ListChecks className="size-6" aria-hidden="true" />}
              title={t('priceLists:list.emptyTitle')}
              description={t('priceLists:list.emptyDescription')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th sortable sortDirection={sortDirectionFor('name')} onSort={() => toggleSort('name')}>
                    {t('priceLists:list.columns.list')}
                  </Th>
                  <Th align="right">{t('priceLists:list.columns.itemCount')}</Th>
                  <Th>{t('priceLists:list.columns.validity')}</Th>
                  <Th>{t('priceLists:list.columns.default')}</Th>
                  <Th>{t('priceLists:list.columns.status')}</Th>
                  <Th align="right">{t('priceLists:list.columns.actions')}</Th>
                </Tr>
              </THead>
              <TBody aria-busy={isLoading}>
                {isLoading
                  ? Array.from({ length: query.per_page ?? DEFAULT_PER_PAGE }).map((_, i) => (
                      <Tr key={i}>
                        <Td><Skeleton variant="text" width={180} /></Td>
                        <Td align="right"><Skeleton variant="text" width={40} className="ml-auto" /></Td>
                        <Td><Skeleton variant="text" width={140} /></Td>
                        <Td><Skeleton variant="text" width={70} /></Td>
                        <Td><Skeleton variant="text" width={60} /></Td>
                        <Td align="right"><Skeleton variant="text" width={100} className="ml-auto" /></Td>
                      </Tr>
                    ))
                  : priceLists.map((priceList) => {
                      const outsideValidity = isOutsideValidity(priceList)
                      return (
                        <Tr key={priceList.id}>
                          <Td>
                            <div className="flex flex-col">
                              <Link
                                to={`/price-lists/${priceList.id}`}
                                className="font-medium text-fg hover:text-primary hover:underline"
                              >
                                {priceList.name}
                              </Link>
                              <span className="font-mono text-xs text-fg-muted">{priceList.code}</span>
                            </div>
                          </Td>
                          <Td align="right">{priceList.items_count}</Td>
                          <Td className={cn(outsideValidity && 'text-warning')}>
                            <div className="flex items-center gap-1.5">
                              {outsideValidity && <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" />}
                              <span>
                                {priceList.valid_from || priceList.valid_until
                                  ? `${formatDate(priceList.valid_from)} – ${formatDate(priceList.valid_until)}`
                                  : t('priceLists:unlimited')}
                              </span>
                            </div>
                          </Td>
                          <Td>
                            {priceList.is_default ? (
                              <Badge variant="primary">{t('priceLists:defaultBadge')}</Badge>
                            ) : (
                              <span className="text-fg-muted">—</span>
                            )}
                          </Td>
                          <Td>
                            <Badge variant={priceList.is_active ? 'success' : 'neutral'}>
                              {priceList.is_active ? t('priceLists:status.active') : t('priceLists:status.inactive')}
                            </Badge>
                          </Td>
                          <Td align="right">
                            <div className="flex items-center justify-end gap-1">
                              <IconLinkButton label={t('priceLists:list.manageAction')} to={`/price-lists/${priceList.id}`}>
                                <Settings2 className="size-4" aria-hidden="true" />
                              </IconLinkButton>
                              {can('products.update') && (
                                <IconButton label={t('priceLists:actions.edit')} onClick={() => setFormModal({ mode: 'edit', priceList })}>
                                  <Pencil className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {can('products.delete') && (
                                <IconButton
                                  label={priceList.is_default ? t('priceLists:list.deleteDisabledAction') : t('priceLists:actions.delete')}
                                  onClick={() => setDeleteState(priceList)}
                                  danger
                                  disabled={priceList.is_default}
                                >
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

      <PriceListFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        priceList={formModal?.mode === 'edit' ? formModal.priceList : null}
      />

      <Modal
        open={!!deleteState}
        onClose={() => setDeleteState(null)}
        title={t('priceLists:deleteModal.title')}
        description={
          deleteState?.is_default
            ? t('priceLists:deleteModal.descriptionDefault')
            : t('priceLists:deleteModal.descriptionNormal')
        }
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteState(null)}>
              {deleteState?.is_default ? t('common:actions.close') : t('common:actions.cancel')}
            </Button>
            {!deleteState?.is_default && (
              <Button
                variant="danger"
                loading={deletePriceListMutation.isPending}
                onClick={async () => {
                  if (!deleteState) return
                  await deletePriceListMutation.mutateAsync(deleteState.id)
                  setDeleteState(null)
                }}
              >
                {t('priceLists:actions.delete')}
              </Button>
            )}
          </div>
        }
      >
        {deleteState && !deleteState.is_default && (
          <p className="text-sm text-fg-secondary">
            <Trans
              i18nKey="priceLists:deleteModal.confirmText"
              values={{ name: deleteState.name }}
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
}: {
  label: string
  onClick: () => void
  children: ReactNode
  danger?: boolean
  disabled?: boolean
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      title={label}
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
