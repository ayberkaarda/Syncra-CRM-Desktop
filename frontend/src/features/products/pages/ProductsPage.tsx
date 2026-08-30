// Ürün kataloğu tablo görünümü — server-side sayfalama/sıralama/arama/filtreleme, tüm durum
// URL query string'inde (bkz. `deals/pages/DealsListPage.tsx` ile aynı desen).
//
// Pasif ürünler görsel olarak SOLUKLAŞTIRILIR (satır: `opacity-60`) — kullanıcı bu ürünü
// neden yeni tekliflerde göremediğini anlasın diye (bkz. görev tanımı iş kuralı: pasif
// ürünün fiyatı sorgulanabilir ama yeni tekliflere eklenmemeli).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { ListChecks, Package, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import {
  Badge,
  Button,
  Card,
  CardBody,
  CardHeader,
  Checkbox,
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
import { SavedViewsBar } from '../../saved-views/components/SavedViewsBar'
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import { useProductCategories, useProducts, useDeleteProduct } from '../api/productsApi'
import type { ProductsQuery } from '../api/productsApi'
import { useProductTags } from '../api/productsShared'
import { ProductFormModal } from '../components/ProductFormModal'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { formatCurrency } from '../utils/formatters'
import type { Product } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300

type FormModalState = { mode: 'create' } | { mode: 'edit'; product: Product } | null

export function ProductsPage() {
  const { t } = useTranslation('products')
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [deleteProductState, setDeleteProductState] = useState<Product | null>(null)

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

  const query: ProductsQuery = useMemo(() => {
    const tagId = searchParams.get('tag_id')
    const priceMin = searchParams.get('price_min')
    const priceMax = searchParams.get('price_max')
    const isActive = searchParams.get('is_active')
    return {
      page: Number(searchParams.get('page') ?? '1') || 1,
      per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
      sort: searchParams.get('sort') ?? undefined,
      q: searchParams.get('q') ?? undefined,
      category: searchParams.get('category') ?? undefined,
      is_active: isActive === '' || isActive === null ? undefined : isActive === '1',
      tag_id: tagId ? Number(tagId) : undefined,
      price_min: priceMin ? Number(priceMin) : undefined,
      price_max: priceMax ? Number(priceMax) : undefined,
      in_stock: searchParams.get('in_stock') === '1' ? true : undefined,
    }
  }, [searchParams])

  const { data, isLoading, isError, refetch } = useProducts(query)
  const { data: categories } = useProductCategories()
  const { data: tags } = useProductTags()
  const deleteProductMutation = useDeleteProduct()

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

  const categoryFilterOptions = [
    { value: '', label: t('list.allCategories') },
    ...(categories ?? []).map((c) => ({ value: c, label: c })),
  ]
  const statusFilterOptions = [
    { value: '', label: t('list.allStatuses') },
    { value: '1', label: t('status.active') },
    { value: '0', label: t('status.inactive') },
  ]
  const tagFilterOptions = [
    { value: '', label: t('list.allTags') },
    ...(tags ?? []).map((tag) => ({ value: String(tag.id), label: tag.name })),
  ]

  const products = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && products.length === 0
  const inStockOnly = searchParams.get('in_stock') === '1'

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('list.breadcrumbHome')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('list.breadcrumbCurrent')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('list.title')}
          subtitle={t('list.subtitle', { count: total })}
          action={
            <div className="flex items-center gap-2">
              <Link
                to="/price-lists"
                className="inline-flex h-10 items-center gap-1.5 rounded-md border border-border bg-surface-2 px-4 text-sm font-medium text-fg hover:bg-surface-3"
              >
                <ListChecks className="size-4" aria-hidden="true" />
                {t('list.priceListsLink')}
              </Link>
              <SavedViewsBar
                module="products"
                filterKeys={['category', 'is_active', 'tag_id', 'price_min', 'price_max', 'in_stock']}
              />
              {can('products.create') && (
                <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
                  {t('list.newProduct')}
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
                  placeholder={t('list.searchPlaceholder')}
                  leftIcon={<Search className="size-4" aria-hidden="true" />}
                  aria-label={t('list.searchAria')}
                />
              </div>
              {/* w-52: FR "Toutes les catégories"/"Toutes les étiquettes" gibi 4 dilin en uzun
                  "Tümü" etiketleri (ölçüldü, bkz. BULGU 2 raporu) native <select> içinde
                  kırpılmadan sığsın diye w-44/w-48'den büyütüldü. */}
              <div className="w-full lg:w-52">
                <Select
                  value={query.category ?? ''}
                  onChange={(e) => updateParams({ category: e.target.value || null, page: '1' })}
                  options={categoryFilterOptions}
                  aria-label={t('list.categoryFilterAria')}
                />
              </div>
              <div className="w-full lg:w-40">
                <Select
                  value={searchParams.get('is_active') ?? ''}
                  onChange={(e) => updateParams({ is_active: e.target.value || null, page: '1' })}
                  options={statusFilterOptions}
                  aria-label={t('list.statusFilterAria')}
                />
              </div>
              <div className="w-full lg:w-52">
                <Select
                  value={query.tag_id ? String(query.tag_id) : ''}
                  onChange={(e) => updateParams({ tag_id: e.target.value || null, page: '1' })}
                  options={tagFilterOptions}
                  aria-label={t('list.tagFilterAria')}
                />
              </div>
            </div>
            <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
              <div className="flex w-full items-end gap-2 lg:w-auto">
                <div className="w-full lg:w-32">
                  <Input
                    type="number"
                    min={0}
                    value={query.price_min ?? ''}
                    onChange={(e) => updateParams({ price_min: e.target.value || null, page: '1' })}
                    placeholder={t('list.minPricePlaceholder')}
                    aria-label={t('list.minPriceAria')}
                  />
                </div>
                <span className="pb-2.5 text-xs text-fg-muted">—</span>
                <div className="w-full lg:w-32">
                  <Input
                    type="number"
                    min={0}
                    value={query.price_max ?? ''}
                    onChange={(e) => updateParams({ price_max: e.target.value || null, page: '1' })}
                    placeholder={t('list.maxPricePlaceholder')}
                    aria-label={t('list.maxPriceAria')}
                  />
                </div>
              </div>
              <Checkbox
                label={t('list.inStockOnly')}
                checked={inStockOnly}
                onChange={(e) => updateParams({ in_stock: e.target.checked ? '1' : null, page: '1' })}
              />
            </div>
          </div>

          {isError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <p className="text-sm text-fg-muted">{t('list.loadError')}</p>
              <Button variant="secondary" onClick={() => refetch()}>
                {t('list.retry')}
              </Button>
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<Package className="size-6" aria-hidden="true" />}
              title={t('list.empty.title')}
              description={t('list.empty.description')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th sortable sortDirection={sortDirectionFor('name')} onSort={() => toggleSort('name')}>
                    {t('list.columns.product')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('category')} onSort={() => toggleSort('category')}>
                    {t('list.columns.category')}
                  </Th>
                  <Th align="right" sortable sortDirection={sortDirectionFor('unit_price')} onSort={() => toggleSort('unit_price')}>
                    {t('list.columns.unitPrice')}
                  </Th>
                  <Th align="right">{t('list.columns.taxRate')}</Th>
                  <Th>{t('list.columns.unit')}</Th>
                  <Th align="right" sortable sortDirection={sortDirectionFor('stock_quantity')} onSort={() => toggleSort('stock_quantity')}>
                    {t('list.columns.stock')}
                  </Th>
                  <Th>{t('list.columns.status')}</Th>
                  <Th>{t('list.columns.tags')}</Th>
                  <Th align="right">{t('list.columns.actions')}</Th>
                </Tr>
              </THead>
              <TBody aria-busy={isLoading}>
                {isLoading
                  ? Array.from({ length: query.per_page ?? DEFAULT_PER_PAGE }).map((_, i) => (
                      <Tr key={i}>
                        <Td><Skeleton variant="text" width={180} /></Td>
                        <Td><Skeleton variant="text" width={90} /></Td>
                        <Td align="right"><Skeleton variant="text" width={80} className="ml-auto" /></Td>
                        <Td align="right"><Skeleton variant="text" width={40} className="ml-auto" /></Td>
                        <Td><Skeleton variant="text" width={50} /></Td>
                        <Td align="right"><Skeleton variant="text" width={40} className="ml-auto" /></Td>
                        <Td><Skeleton variant="text" width={60} /></Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td align="right"><Skeleton variant="text" width={70} className="ml-auto" /></Td>
                      </Tr>
                    ))
                  : products.map((product) => {
                      const isOutOfStock = product.stock_quantity === 0
                      return (
                        <Tr key={product.id} className={cn(!product.is_active && 'opacity-60')}>
                          <Td>
                            <div className="flex flex-col">
                              <span className={cn('font-medium', product.is_active ? 'text-fg' : 'text-fg-muted')}>
                                {product.name}
                              </span>
                              {product.sku && <span className="font-mono text-xs text-fg-muted">{product.sku}</span>}
                            </div>
                          </Td>
                          <Td>{product.category ? <Badge variant="neutral">{product.category}</Badge> : <span className="text-fg-muted">—</span>}</Td>
                          <Td align="right" className="whitespace-nowrap font-medium">
                            {formatCurrency(product.unit_price, product.currency)}
                          </Td>
                          <Td align="right">%{product.tax_rate}</Td>
                          <Td>{product.unit}</Td>
                          <Td align="right" className={cn(isOutOfStock && 'font-medium text-warning')}>
                            {product.stock_quantity === null ? '—' : product.stock_quantity}
                          </Td>
                          <Td>
                            <Badge variant={product.is_active ? 'success' : 'neutral'}>
                              {product.is_active ? t('status.active') : t('status.inactive')}
                            </Badge>
                          </Td>
                          <Td>
                            <div className="flex flex-wrap gap-1">
                              {product.tags.length === 0 && <span className="text-fg-muted">—</span>}
                              {product.tags.map((tag) => (
                                <Badge key={tag.id} variant={tokenBadgeVariant(tag.color)} size="sm">
                                  {tag.name}
                                </Badge>
                              ))}
                            </div>
                          </Td>
                          <Td align="right">
                            <div className="flex items-center justify-end gap-1">
                              {can('products.update') && (
                                <IconButton label={t('list.actions.edit')} onClick={() => setFormModal({ mode: 'edit', product })}>
                                  <Pencil className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {can('products.delete') && (
                                <IconButton label={t('list.actions.delete')} danger onClick={() => setDeleteProductState(product)}>
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

      <ProductFormModal open={!!formModal} onClose={() => setFormModal(null)} product={formModal?.mode === 'edit' ? formModal.product : null} />

      <Modal
        open={!!deleteProductState}
        onClose={() => setDeleteProductState(null)}
        title={t('list.deleteModal.title')}
        description={t('list.deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteProductState(null)}>
              {t('list.deleteModal.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteProductMutation.isPending}
              onClick={async () => {
                if (!deleteProductState) return
                await deleteProductMutation.mutateAsync(deleteProductState.id)
                setDeleteProductState(null)
              }}
            >
              {t('list.deleteModal.confirm')}
            </Button>
          </div>
        }
      >
        {deleteProductState && (
          <p className="text-sm text-fg-secondary">
            <Trans
              i18nKey="products:list.deleteModal.confirmText"
              values={{ name: deleteProductState.name }}
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
