// Fiyat listesi detay/yönetim sayfası — bu sayfanın işi BİR listedeki ürün fiyatlarını
// yönetmek (üst bilgiler salt-okunur özet, asıl iş fiyat tablosunda).
//
// SATIR İÇİ DÜZENLEME KARARI (görev tanımı raporu): hücreye tıklayınca değil, her satırdaki
// bir DÜZENLE butonuyla tetiklenir — fiyat hücresine yanlışlıkla tıklayıp istemeden düzenleme
// moduna girmeyi önler (özellikle satır tıklamasıyla karışabilecek yoğun bir tabloda). Buton
// hücreyi bir `Input` + Kaydet/Vazgeç ikonlarına çevirir.
//
// UPSERT NOTU: hem "ürün ekle" hem "fiyatı düzenle" AYNI `useSetPrice` mutasyonunu kullanır —
// backend `PUT .../products/{productId}` zaten upsert (var olan kalemi günceller, yoksa
// oluşturur, her iki durumda da 200 döner). Zaten listede olan bir ürün combobox'tan tekrar
// seçilirse bu doğal olarak "mevcut fiyatı düzenle" anlamına gelir.
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { AlertTriangle, ArrowLeft, Check, Info, ListChecks, Pencil, Plus, Trash2, X } from 'lucide-react'
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
import { formatCurrency, formatDate } from '../../products/utils/formatters'
import type { Product } from '../../products/types'
import { usePriceList, usePriceListItems, useRemovePrice, useSetPrice } from '../api/priceListsApi'
import { PriceListFormModal } from '../components/PriceListFormModal'
import { ProductPickerCombobox } from '../components/ProductPickerCombobox'
import type { PriceListItem } from '../types'

function computeDiff(item: PriceListItem): { amount: number; percent: number | null } | null {
  if (item.catalog_price === null) return null
  const amount = item.unit_price - item.catalog_price
  const percent = item.catalog_price !== 0 ? (amount / item.catalog_price) * 100 : null
  return { amount, percent }
}

export function PriceListDetailPage() {
  const { t } = useTranslation()
  const params = useParams<{ id: string }>()
  const priceListId = Number(params.id)
  const { can } = usePermission()

  const [itemsPage, setItemsPage] = useState(1)
  const [editOpen, setEditOpen] = useState(false)
  const [editingProductId, setEditingProductId] = useState<number | null>(null)
  const [editValue, setEditValue] = useState('')
  const [removeState, setRemoveState] = useState<PriceListItem | null>(null)
  const [addProduct, setAddProduct] = useState<Product | null>(null)
  const [addPrice, setAddPrice] = useState('')
  const [addError, setAddError] = useState<string | undefined>(undefined)

  const { data: priceList, isLoading, isError, refetch } = usePriceList(Number.isFinite(priceListId) ? priceListId : undefined)
  const { data: itemsData, isLoading: itemsLoading } = usePriceListItems(
    Number.isFinite(priceListId) ? priceListId : undefined,
    itemsPage,
    { enabled: !!priceList }
  )
  const setPrice = useSetPrice(priceListId)
  const removePrice = useRemovePrice(priceListId)

  const canManage = can('products.update')

  function startEdit(item: PriceListItem) {
    setEditingProductId(item.product_id)
    setEditValue(String(item.unit_price))
  }

  function cancelEdit() {
    setEditingProductId(null)
    setEditValue('')
  }

  async function saveEdit(productId: number) {
    const value = Number(editValue)
    if (editValue === '' || Number.isNaN(value) || value < 0) return
    await setPrice.mutateAsync({ productId, unitPrice: value })
    setEditingProductId(null)
    setEditValue('')
  }

  async function handleAddProduct() {
    setAddError(undefined)
    if (!addProduct) {
      setAddError(t('priceLists:detail.addProductRequired'))
      return
    }
    const value = Number(addPrice)
    if (addPrice === '' || Number.isNaN(value) || value < 0) {
      setAddError(t('priceLists:detail.addPriceInvalid'))
      return
    }
    await setPrice.mutateAsync({ productId: addProduct.id, unitPrice: value })
    setAddProduct(null)
    setAddPrice('')
  }

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

  if (isError || !priceList) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-center">
        <p className="text-sm text-fg-muted">{t('priceLists:detail.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('priceLists:retry')}
        </Button>
      </div>
    )
  }

  const items = itemsData?.data ?? []
  const totalItems = itemsData?.meta.pagination.total ?? priceList.items_count
  const isEmpty = !itemsLoading && items.length === 0

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="flex items-center gap-1.5 text-xs text-fg-muted">
        <Link to="/price-lists" className="inline-flex items-center gap-1 hover:text-fg">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          {t('priceLists:breadcrumb.priceLists')}
        </Link>
        <span className="mx-1">/</span>
        <span className="text-primary">{priceList.name}</span>
      </nav>

      <Card>
        <CardHeader
          title={priceList.name}
          subtitle={
            <span className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-fg-muted">{priceList.code}</span>
              <span>
                · {t('priceLists:detail.validityLabel')}:{' '}
                {priceList.valid_from || priceList.valid_until
                  ? `${formatDate(priceList.valid_from)} – ${formatDate(priceList.valid_until)}`
                  : t('priceLists:unlimited')}
              </span>
              <span>· {priceList.currency}</span>
            </span>
          }
          action={
            <div className="flex items-center gap-2">
              {priceList.is_default && <Badge variant="primary">{t('priceLists:defaultBadge')}</Badge>}
              <Badge variant={priceList.is_active ? 'success' : 'neutral'}>
                {priceList.is_active ? t('priceLists:status.active') : t('priceLists:status.inactive')}
              </Badge>
              {canManage && (
                <Button variant="secondary" leftIcon={<Pencil className="size-4" aria-hidden="true" />} onClick={() => setEditOpen(true)}>
                  {t('priceLists:actions.edit')}
                </Button>
              )}
            </div>
          }
        />

        {canManage && (
          <CardBody className="border-b border-border-subtle">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <div className="w-full sm:max-w-sm">
                <ProductPickerCombobox value={addProduct} onChange={setAddProduct} />
              </div>
              <div className="w-full sm:w-40">
                <Input
                  label={t('priceLists:detail.addProductPriceLabel')}
                  type="number"
                  min={0}
                  step="0.01"
                  value={addPrice}
                  onChange={(e) => setAddPrice(e.target.value)}
                  placeholder={addProduct ? String(addProduct.unit_price) : undefined}
                />
              </div>
              <Button
                leftIcon={<Plus className="size-4" aria-hidden="true" />}
                loading={setPrice.isPending}
                onClick={handleAddProduct}
              >
                {t('priceLists:detail.addOrUpdateAction')}
              </Button>
            </div>
            {addError && <p className="mt-1.5 text-xs text-danger">{addError}</p>}
            <p className="mt-1.5 text-xs text-fg-muted">{t('priceLists:detail.addHint')}</p>
          </CardBody>
        )}

        <CardBody noPadding>
          {isEmpty ? (
            <EmptyState
              icon={<ListChecks className="size-6" aria-hidden="true" />}
              title={t('priceLists:detail.emptyTitle')}
              description={t('priceLists:detail.emptyDescription')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th>{t('priceLists:detail.columns.product')}</Th>
                  <Th align="right">{t('priceLists:detail.columns.catalogPrice')}</Th>
                  <Th align="right">{t('priceLists:detail.columns.listPrice')}</Th>
                  <Th align="right">{t('priceLists:detail.columns.diff')}</Th>
                  {canManage && <Th align="right">{t('priceLists:detail.columns.actions')}</Th>}
                </Tr>
              </THead>
              <TBody aria-busy={itemsLoading}>
                {itemsLoading
                  ? Array.from({ length: 5 }).map((_, i) => (
                      <Tr key={i}>
                        <Td><Skeleton variant="text" width={160} /></Td>
                        <Td align="right"><Skeleton variant="text" width={80} className="ml-auto" /></Td>
                        <Td align="right"><Skeleton variant="text" width={80} className="ml-auto" /></Td>
                        <Td align="right"><Skeleton variant="text" width={70} className="ml-auto" /></Td>
                        {canManage && <Td align="right"><Skeleton variant="text" width={70} className="ml-auto" /></Td>}
                      </Tr>
                    ))
                  : items.map((item) => {
                      const diff = computeDiff(item)
                      const isEditing = editingProductId === item.product_id
                      return (
                        <Tr key={item.product_id}>
                          <Td>
                            <div className="flex flex-col">
                              <span className="font-medium text-fg">{item.product_name ?? `#${item.product_id}`}</span>
                              {item.product_sku && <span className="font-mono text-xs text-fg-muted">{item.product_sku}</span>}
                            </div>
                          </Td>
                          <Td align="right" className="whitespace-nowrap text-fg-muted">
                            {item.catalog_price === null ? '—' : formatCurrency(item.catalog_price, priceList.currency)}
                          </Td>
                          <Td align="right" className="whitespace-nowrap font-medium">
                            {isEditing ? (
                              <div className="flex items-center justify-end gap-1">
                                <Input
                                  autoFocus
                                  type="number"
                                  min={0}
                                  step="0.01"
                                  value={editValue}
                                  onChange={(e) => setEditValue(e.target.value)}
                                  inputSize="sm"
                                  className="w-28 text-right"
                                  aria-label={t('priceLists:detail.priceInputAria', {
                                    name: item.product_name ?? t('priceLists:detail.productFallback'),
                                  })}
                                />
                              </div>
                            ) : (
                              formatCurrency(item.unit_price, priceList.currency)
                            )}
                          </Td>
                          <Td align="right" className="whitespace-nowrap">
                            {diff === null ? (
                              <span className="text-fg-muted">—</span>
                            ) : diff.amount === 0 ? (
                              <span className="text-fg-muted">{t('priceLists:detail.noDiff')}</span>
                            ) : (
                              <span className={diff.amount < 0 ? 'text-success' : 'text-warning'}>
                                {diff.amount < 0 ? '−' : '+'}
                                {formatCurrency(Math.abs(diff.amount), priceList.currency)}
                                {diff.percent !== null && ` (%${Math.abs(diff.percent).toFixed(1)})`}
                              </span>
                            )}
                          </Td>
                          {canManage && (
                            <Td align="right">
                              <div className="flex items-center justify-end gap-1">
                                {isEditing ? (
                                  <>
                                    <IconButton label={t('common:actions.save')} onClick={() => saveEdit(item.product_id)}>
                                      <Check className="size-4" aria-hidden="true" />
                                    </IconButton>
                                    <IconButton label={t('common:actions.cancel')} onClick={cancelEdit}>
                                      <X className="size-4" aria-hidden="true" />
                                    </IconButton>
                                  </>
                                ) : (
                                  <>
                                    <IconButton label={t('priceLists:detail.editPrice')} onClick={() => startEdit(item)}>
                                      <Pencil className="size-4" aria-hidden="true" />
                                    </IconButton>
                                    <IconButton label={t('priceLists:detail.removeAction')} danger onClick={() => setRemoveState(item)}>
                                      <Trash2 className="size-4" aria-hidden="true" />
                                    </IconButton>
                                  </>
                                )}
                              </div>
                            </Td>
                          )}
                        </Tr>
                      )
                    })}
              </TBody>
            </Table>
          )}

          {!isEmpty && (
            <div className="border-t border-border-subtle p-4">
              <Pagination currentPage={itemsPage} totalItems={totalItems} pageSize={25} onPageChange={setItemsPage} />
            </div>
          )}
        </CardBody>
      </Card>

      <PriceListFormModal open={editOpen} onClose={() => setEditOpen(false)} priceList={priceList} />

      <Modal
        open={!!removeState}
        onClose={() => setRemoveState(null)}
        title={t('priceLists:detail.removeModal.title')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRemoveState(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={removePrice.isPending}
              onClick={async () => {
                if (!removeState) return
                await removePrice.mutateAsync(removeState.product_id)
                setRemoveState(null)
              }}
            >
              {t('priceLists:detail.removeAction')}
            </Button>
          </div>
        }
      >
        {removeState && (
          <div className="flex flex-col gap-3">
            <p className="text-sm text-fg-secondary">
              <Trans
                i18nKey="priceLists:detail.removeModal.confirmText"
                values={{ name: removeState.product_name ?? `#${removeState.product_id}` }}
                components={{ bold: <strong className="text-fg" /> }}
              />
            </p>
            <div className="flex items-start gap-2 rounded-md bg-warning-tint px-3 py-2 text-xs text-warning">
              <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
              <span>{t('priceLists:detail.removeModal.warning')}</span>
            </div>
          </div>
        )}
      </Modal>

      {!canManage && (
        <div className="flex items-center gap-2 text-xs text-fg-muted">
          <Info className="size-3.5" aria-hidden="true" />
          {t('priceLists:detail.readOnlyHint')}
        </div>
      )}
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
