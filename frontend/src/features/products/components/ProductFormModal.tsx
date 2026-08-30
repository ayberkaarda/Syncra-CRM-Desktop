// Ürün oluşturma/düzenleme modalı. `product` prop'u verilmezse (null/undefined) oluşturma
// modu.
//
// Kategori alanı: mevcut kategorilerden seçilebilen AMA serbest yazıma da açık bir alan —
// `datalist` ile `/api/products/categories` önerileri sunulur, kullanıcı listede olmayan yeni
// bir kategori de yazabilir (native `<input list>`, tasarım token'ı gerektirmez — tarayıcı
// tarafından render edilen bir açılır liste, stilize edilmez).
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Button, Checkbox, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { useCreateProduct, useUpdateProduct } from '../api/productsApi'
import { useProductCategories } from '../api/productsApi'
import { useProductCustomFields, useProductTags } from '../api/productsShared'
import { ProductCustomFieldsSection } from './ProductCustomFieldsSection'
import { ProductTagMultiSelect } from './ProductTagMultiSelect'
import type { Product, ProductTag } from '../types'

function currencyOptions(t: TFunction) {
  return [
    { value: 'TRY', label: t('form.currency.try') },
    { value: 'USD', label: t('form.currency.usd') },
    { value: 'EUR', label: t('form.currency.eur') },
    { value: 'GBP', label: t('form.currency.gbp') },
  ]
}

const CATEGORY_DATALIST_ID = 'product-category-suggestions'

export type ProductFormModalProps = {
  open: boolean
  onClose: () => void
  /** Verilirse düzenleme, yoksa oluşturma modu. */
  product?: Product | null
}

export function ProductFormModal({ open, onClose, product }: ProductFormModalProps) {
  const { t } = useTranslation('products')
  const isEdit = !!product

  const { data: categories } = useProductCategories()
  const { data: tagOptions, isLoading: tagsLoading } = useProductTags()
  const { data: customFieldDefs } = useProductCustomFields()
  const createProduct = useCreateProduct()
  const updateProduct = useUpdateProduct()

  const [name, setName] = useState('')
  const [sku, setSku] = useState('')
  const [description, setDescription] = useState('')
  const [category, setCategory] = useState('')
  const [unitPrice, setUnitPrice] = useState('')
  const [currency, setCurrency] = useState('TRY')
  const [taxRate, setTaxRate] = useState('20')
  const [unit, setUnit] = useState('adet')
  const [stockQuantity, setStockQuantity] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [tags, setTags] = useState<ProductTag[]>([])
  const [customFieldValues, setCustomFieldValues] = useState<Record<string, string>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const openKey = open ? (product ? `edit-${product.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setName(product?.name ?? '')
      setSku(product?.sku ?? '')
      setDescription(product?.description ?? '')
      setCategory(product?.category ?? '')
      setUnitPrice(product?.unit_price !== undefined && product?.unit_price !== null ? String(product.unit_price) : '')
      setCurrency(product?.currency ?? 'TRY')
      setTaxRate(product?.tax_rate !== undefined && product?.tax_rate !== null ? String(product.tax_rate) : '20')
      setUnit(product?.unit ?? 'adet')
      setStockQuantity(
        product?.stock_quantity !== undefined && product?.stock_quantity !== null ? String(product.stock_quantity) : ''
      )
      setIsActive(product?.is_active ?? true)
      setTags(product?.tags ?? [])
      setCustomFieldValues(product?.custom_fields ?? {})
      setFieldErrors({})
    }
  }

  const isPending = createProduct.isPending || updateProduct.isPending

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  const customFieldErrorMap: Record<string, string> = Object.fromEntries(
    Object.entries(fieldErrors)
      .filter(([key]) => key.startsWith('custom_fields'))
      .map(([key, messages]) => [key, messages[0]])
  )

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!name.trim()) errors.name = [t('form.validation.nameRequired')]
    if (unitPrice === '') errors.unit_price = [t('form.validation.unitPriceRequired')]
    else if (Number.isNaN(Number(unitPrice)) || Number(unitPrice) < 0) {
      errors.unit_price = [t('form.validation.unitPriceInvalid')]
    }
    if (taxRate !== '' && (Number.isNaN(Number(taxRate)) || Number(taxRate) < 0 || Number(taxRate) > 100)) {
      errors.tax_rate = [t('form.validation.taxRateRange')]
    }
    if (stockQuantity !== '' && (Number.isNaN(Number(stockQuantity)) || Number(stockQuantity) < 0)) {
      errors.stock_quantity = [t('form.validation.stockQuantityInvalid')]
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const payload = {
      name,
      sku: sku.trim() ? sku.trim() : null,
      description: description || undefined,
      category: category.trim() ? category.trim() : null,
      unit_price: Number(unitPrice),
      currency: currency || undefined,
      tax_rate: taxRate === '' ? undefined : Number(taxRate),
      unit: unit || undefined,
      stock_quantity: stockQuantity === '' ? null : Number(stockQuantity),
      is_active: isActive,
      tag_ids: tags.map((t) => t.id),
      custom_fields: customFieldValues,
    }

    try {
      if (isEdit && product) {
        await updateProduct.mutateAsync({ id: product.id, payload })
      } else {
        await createProduct.mutateAsync(payload)
      }
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? t('form.titleEdit') : t('form.titleCreate')}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('form.cancel')}
          </Button>
          <Button type="submit" form="product-form" loading={isPending}>
            {isEdit ? t('form.submitEdit') : t('form.submitCreate')}
          </Button>
        </div>
      }
    >
      <form id="product-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input label={t('form.nameLabel')} value={name} onChange={(e) => setName(e.target.value)} error={fieldError('name')} required />
          <Input
            label={t('form.skuLabel')}
            value={sku}
            onChange={(e) => setSku(e.target.value)}
            error={fieldError('sku')}
            hint={t('form.skuHint')}
          />
        </div>

        <Textarea
          label={t('form.descriptionLabel')}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          error={fieldError('description')}
        />

        <div>
          <Input
            label={t('form.categoryLabel')}
            list={CATEGORY_DATALIST_ID}
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            placeholder={t('form.categoryPlaceholder')}
            error={fieldError('category')}
            hint={!fieldError('category') ? t('form.categoryHint') : undefined}
          />
          <datalist id={CATEGORY_DATALIST_ID}>
            {(categories ?? []).map((c) => (
              <option key={c} value={c} />
            ))}
          </datalist>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Input
            label={t('form.unitPriceLabel')}
            type="number"
            min={0}
            step="0.01"
            value={unitPrice}
            onChange={(e) => setUnitPrice(e.target.value)}
            error={fieldError('unit_price')}
            required
          />
          <Select
            label={t('form.currencyLabel')}
            value={currency}
            onChange={(e) => setCurrency(e.target.value)}
            options={currencyOptions(t)}
            error={fieldError('currency')}
          />
          <Input
            label={t('form.taxRateLabel')}
            type="number"
            min={0}
            max={100}
            step="0.01"
            value={taxRate}
            onChange={(e) => setTaxRate(e.target.value)}
            error={fieldError('tax_rate')}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input label={t('form.unitLabel')} value={unit} onChange={(e) => setUnit(e.target.value)} error={fieldError('unit')} />
          <Input
            label={t('form.stockQuantityLabel')}
            type="number"
            min={0}
            step="1"
            value={stockQuantity}
            onChange={(e) => setStockQuantity(e.target.value)}
            error={fieldError('stock_quantity')}
            hint={t('form.stockQuantityHint')}
          />
        </div>

        <Checkbox label={t('form.activeLabel')} checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />

        <ProductTagMultiSelect value={tags} onChange={setTags} options={tagOptions ?? []} isLoading={tagsLoading} />

        <ProductCustomFieldsSection
          fields={customFieldDefs ?? []}
          values={customFieldValues}
          onChange={(key, value) => setCustomFieldValues((prev) => ({ ...prev, [key]: value }))}
          errors={customFieldErrorMap}
        />
      </form>
    </Modal>
  )
}
