// Kalem editörü — teklif formunun en zor parçası. Satır ekleme (ürün seçerek ya da serbest
// kalem), silme, sıralama (yukarı/aşağı — dnd-kit YASAK, görev tanımı), her satırda alanlar
// (ad, açıklama, miktar, birim fiyat, indirim %, KDV %) ve İSTEMCİ tarafında hesaplanan
// `line_total` gösterimi (görev tanımı: "satır toplamını istemcide gösterebilirsin, risk düşük" —
// `subtotal`/`tax_amount`/`total` bu bileşenin DIŞINDA, `useQuoteCalculate` ile sunucudan gelir).
//
// `sent` SONRASI: bu bileşen tamamen `readOnly` render edilir (üstte açıklayıcı şerit
// `QuoteFormPage`'de) — ürün ekleme, serbest kalem ekleme, silme, sıralama, alan düzenleme
// hepsi kapanır.
import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowDown, ArrowUp, Package, Plus, Trash2 } from 'lucide-react'
import { Button, Input, Table, TBody, Td, THead, Th, Tr } from '../../../components/ui'
import { formatMoney } from '../../../lib/money'
import { resolveProductPrice } from '../api/catalogApi'
// D şeridin (`features/price-lists/`) hazır ürün combobox'ı YENİDEN KULLANILIYOR (görev tanımı:
// "aranabilir Select, D şeridinin features/products/ api'sini yeniden kullan") — kendi kopyamızı
// yazmak yerine. `ProductPickerCombobox` bir "seçili değeri tutan" combobox; burada satır EKLEME
// aracı olarak kullanıldığından `value` her zaman `null` verilip her seçimde satır eklenip
// sıfırlanıyor (bkz. aşağıdaki `<ProductPickerCombobox value={null} onChange={...}>`).
import { ProductPickerCombobox } from '../../price-lists/components/ProductPickerCombobox'
import type { Product } from '../../products/types'
import { clientLineTotal, createFreeItem, createProductItem } from '../utils/quoteItems'
import type { EditableQuoteItem } from '../utils/quoteItems'

export type QuoteItemsEditorProps = {
  items: EditableQuoteItem[]
  onChange: (items: EditableQuoteItem[]) => void
  /** Yeni eklenen ürün bazlı satırların fiyatını çözerken kullanılan liste (formun üstündeki seçici). */
  priceListId: number | null
  /**
   * Teklifin KENDİ para birimi (`quote.currency`) — ZORUNLU, varsayılan verilmez. Görev
   * sözleşmesi (docs/PHASE-INTL.md §2, Karar B): "kayıtlar kendi para biriminde saklanır" ve
   * gösterilir; çağıran bunu unutursa derleme hatası alsın, sessizce yanlış sembole (`TRY`)
   * düşülmesin. Bu bir DÖNÜŞÜM değildir — yalnızca doğru sembolün basılmasıdır.
   */
  currency: string
  fieldErrors?: Record<string, string[]>
  readOnly?: boolean
}

function itemError(fieldErrors: Record<string, string[]> | undefined, index: number, field: string): string | undefined {
  return fieldErrors?.[`items.${index}.${field}`]?.[0]
}

export function QuoteItemsEditor({ items, onChange, priceListId, currency, fieldErrors, readOnly = false }: QuoteItemsEditorProps) {
  const { t } = useTranslation()
  // Controlled bileşen — kendi state'i yok, `items` daima prop'tan gelir. Async fiyat çözümleme
  // (`handleAddProduct`) beklerken kullanıcı başka bir satırı değiştirmiş olabileceğinden, o an
  // kapanışta yakalanmış (stale) `items` yerine EN GÜNCEL değere bu ref üzerinden erişilir.
  const itemsRef = useRef(items)
  useEffect(() => {
    itemsRef.current = items
  }, [items])

  function updateItem(index: number, patch: Partial<EditableQuoteItem>) {
    const next = items.slice()
    next[index] = { ...next[index], ...patch }
    onChange(next)
  }

  function removeItem(index: number) {
    onChange(items.filter((_, i) => i !== index))
  }

  function moveItem(index: number, direction: -1 | 1) {
    const target = index + direction
    if (target < 0 || target >= items.length) return
    const next = items.slice()
    ;[next[index], next[target]] = [next[target], next[index]]
    onChange(next)
  }

  // Ürün seçilince: KATALOG fiyatıyla satır HEMEN eklenir (gecikme hissettirmemek için), ardından
  // seçili fiyat listesine göre çözümlenmiş fiyat (`GET /api/products/{id}/price?price_list_id=`)
  // geldiğinde AYNI satır (istemci-yerel `key`'iyle bulunur) güncellenir. Bu bileşen `items`'ı
  // prop olarak alan CONTROLLED bir bileşen (kendi state'i yok); `await` sırasında kapanışta
  // yakalanmış (stale) `items` yerine `itemsRef.current` kullanılır — arada kullanıcı başka bir
  // satırı değiştirmiş/silmiş olabilir.
  async function handleAddProduct(product: Product) {
    const newItem = createProductItem(product)
    onChange([...items, newItem])

    try {
      const resolved = await resolveProductPrice(product.id, priceListId)
      const current = itemsRef.current
      const idx = current.findIndex((i) => i.key === newItem.key)
      if (idx === -1) return // kullanıcı bu satırı beklerken sildi
      const next = current.slice()
      next[idx] = { ...next[idx], unit_price: String(resolved.unit_price), tax_rate: String(resolved.tax_rate) }
      onChange(next)
    } catch {
      // Fiyat çözümleme başarısız olursa ürünün katalog fiyatı (zaten eklendi) geçerli kalır.
    }
  }

  function handleAddFreeItem() {
    onChange([...items, createFreeItem()])
  }

  return (
    <div className="flex flex-col gap-3">
      {items.length === 0 ? (
        <p className="rounded-md border border-dashed border-border-strong px-4 py-6 text-center text-sm text-fg-muted">
          {t('quotes:itemsEditor.emptyMessage')} {!readOnly && t('quotes:itemsEditor.emptyHint')}
        </p>
      ) : (
        <Table>
          <THead>
            <Tr>
              <Th align="center">{t('quotes:itemsEditor.columns.order')}</Th>
              <Th>{t('quotes:itemsEditor.columns.item')}</Th>
              <Th align="right">{t('quotes:itemsEditor.columns.quantity')}</Th>
              <Th align="right">{t('quotes:itemsEditor.columns.unitPrice')}</Th>
              <Th align="right">{t('quotes:itemsEditor.columns.discountPercent')}</Th>
              <Th align="right">{t('quotes:itemsEditor.columns.taxRate')}</Th>
              <Th align="right">{t('quotes:itemsEditor.columns.lineTotal')}</Th>
              {!readOnly && <Th align="right">{t('quotes:itemsEditor.columns.actions')}</Th>}
            </Tr>
          </THead>
          <TBody>
            {items.map((item, index) => (
              <Tr key={item.key}>
                <Td align="center">
                  {readOnly ? (
                    <span className="text-fg-muted">{index + 1}</span>
                  ) : (
                    <div className="flex flex-col items-center gap-0.5">
                      <button
                        type="button"
                        onClick={() => moveItem(index, -1)}
                        disabled={index === 0}
                        aria-label={t('quotes:itemsEditor.moveUp')}
                        className="rounded text-fg-muted hover:text-fg disabled:opacity-30"
                      >
                        <ArrowUp className="size-3.5" aria-hidden="true" />
                      </button>
                      <span className="text-xs text-fg-muted">{index + 1}</span>
                      <button
                        type="button"
                        onClick={() => moveItem(index, 1)}
                        disabled={index === items.length - 1}
                        aria-label={t('quotes:itemsEditor.moveDown')}
                        className="rounded text-fg-muted hover:text-fg disabled:opacity-30"
                      >
                        <ArrowDown className="size-3.5" aria-hidden="true" />
                      </button>
                    </div>
                  )}
                </Td>
                <Td className="min-w-56">
                  {readOnly ? (
                    <div className="flex flex-col gap-0.5">
                      <span className="flex items-center gap-1.5 text-fg">
                        {item.product_id !== null && <Package className="size-3.5 shrink-0 text-fg-muted" aria-hidden="true" />}
                        {item.name}
                      </span>
                      {item.description && <span className="text-xs text-fg-muted">{item.description}</span>}
                    </div>
                  ) : (
                    <div className="flex flex-col gap-1.5">
                      <Input
                        value={item.name}
                        onChange={(e) => updateItem(index, { name: e.target.value })}
                        placeholder={t('quotes:itemsEditor.namePlaceholder')}
                        error={itemError(fieldErrors, index, 'name')}
                        aria-label={t('quotes:itemsEditor.nameLabel')}
                      />
                      <Input
                        value={item.description}
                        onChange={(e) => updateItem(index, { description: e.target.value })}
                        placeholder={t('quotes:itemsEditor.descriptionPlaceholder')}
                        inputSize="sm"
                        aria-label={t('quotes:itemsEditor.descriptionLabel')}
                      />
                    </div>
                  )}
                </Td>
                <Td align="right" className="min-w-24">
                  {readOnly ? (
                    item.quantity
                  ) : (
                    <Input
                      type="number"
                      min={0}
                      step="0.01"
                      value={item.quantity}
                      onChange={(e) => updateItem(index, { quantity: e.target.value })}
                      error={itemError(fieldErrors, index, 'quantity')}
                      aria-label={t('quotes:itemsEditor.quantityLabel')}
                    />
                  )}
                </Td>
                <Td align="right" className="min-w-32">
                  {readOnly ? (
                    formatMoney(Number(item.unit_price) || 0, currency)
                  ) : (
                    <Input
                      type="number"
                      min={0}
                      step="0.01"
                      value={item.unit_price}
                      onChange={(e) => updateItem(index, { unit_price: e.target.value })}
                      error={itemError(fieldErrors, index, 'unit_price')}
                      aria-label={t('quotes:itemsEditor.unitPriceLabel')}
                    />
                  )}
                </Td>
                <Td align="right" className="min-w-20">
                  {readOnly ? (
                    `%${item.discount_percent}`
                  ) : (
                    <Input
                      type="number"
                      min={0}
                      max={100}
                      step="0.01"
                      value={item.discount_percent}
                      onChange={(e) => updateItem(index, { discount_percent: e.target.value })}
                      error={itemError(fieldErrors, index, 'discount_percent')}
                      aria-label={t('quotes:itemsEditor.discountPercentLabel')}
                    />
                  )}
                </Td>
                <Td align="right" className="min-w-20">
                  {readOnly ? (
                    `%${item.tax_rate}`
                  ) : (
                    <Input
                      type="number"
                      min={0}
                      max={100}
                      step="0.01"
                      value={item.tax_rate}
                      onChange={(e) => updateItem(index, { tax_rate: e.target.value })}
                      error={itemError(fieldErrors, index, 'tax_rate')}
                      aria-label={t('quotes:itemsEditor.taxRateLabel')}
                    />
                  )}
                </Td>
                <Td align="right" className="whitespace-nowrap font-medium text-fg">
                  {formatMoney(clientLineTotal(item), currency)}
                </Td>
                {!readOnly && (
                  <Td align="right">
                    <button
                      type="button"
                      onClick={() => removeItem(index)}
                      aria-label={t('quotes:itemsEditor.removeItem')}
                      title={t('quotes:itemsEditor.removeItem')}
                      className="inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-danger"
                    >
                      <Trash2 className="size-4" aria-hidden="true" />
                    </button>
                  </Td>
                )}
              </Tr>
            ))}
          </TBody>
        </Table>
      )}

      {fieldErrors?.items?.[0] && <p className="text-xs text-danger">{fieldErrors.items[0]}</p>}

      {!readOnly && (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <div className="w-full sm:max-w-xs">
            <ProductPickerCombobox
              value={null}
              onChange={(product) => {
                if (product) void handleAddProduct(product)
              }}
              label={t('quotes:itemsEditor.addProductLabel')}
              placeholder={t('quotes:itemsEditor.addProductPlaceholder')}
            />
          </div>
          <Button type="button" variant="secondary" leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={handleAddFreeItem}>
            {t('quotes:itemsEditor.addFreeItem')}
          </Button>
        </div>
      )}
    </div>
  )
}
