// Ürün ekleme için aranabilir tekli combobox — `deals/components/DealCompanyCombobox.tsx` ile
// aynı desen. Katalogdaki ürünleri ada/SKU'ya göre arar; zaten listede olan bir ürün seçilirse
// çağıran taraf (bkz. `PriceListDetailPage`) bunu "mevcut fiyatı düzenle" akışına yönlendirir
// (sunucu tarafı zaten upsert — `PUT` 200 döner, bkz. `priceListsApi.ts` notu).
import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Package, X } from 'lucide-react'
import { Input } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useProducts } from '../../products/api/productsApi'
import { useDebouncedValue } from '../../products/hooks/useDebouncedValue'
import type { Product } from '../../products/types'

export type ProductPickerComboboxProps = {
  value: Product | null
  onChange: (next: Product | null) => void
  label?: string
  error?: string
  placeholder?: string
}

export function ProductPickerCombobox({
  value,
  onChange,
  label,
  error,
  placeholder,
}: ProductPickerComboboxProps) {
  const { t } = useTranslation()
  const resolvedLabel = label ?? t('priceLists:productCombobox.label')
  const resolvedPlaceholder = placeholder ?? t('priceLists:productCombobox.placeholder')
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState('')
  const debouncedDraft = useDebouncedValue(draft, 300)
  const containerRef = useRef<HTMLDivElement | null>(null)

  const { data, isLoading } = useProducts({ q: debouncedDraft || undefined, per_page: 20, sort: 'name' })
  const options = data?.data ?? []

  useEffect(() => {
    if (!open) return
    function handleClick(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [open])

  function handleFocus() {
    setDraft('')
    setOpen(true)
  }

  function handleSelect(option: Product | null) {
    onChange(option)
    setDraft('')
    setOpen(false)
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Escape') {
      setOpen(false)
      event.currentTarget.blur()
    }
  }

  const displayValue = open ? draft : (value?.name ?? '')

  return (
    <div ref={containerRef} className="relative">
      <Input
        label={resolvedLabel}
        value={displayValue}
        onChange={(e) => setDraft(e.target.value)}
        onFocus={handleFocus}
        onKeyDown={handleKeyDown}
        placeholder={resolvedPlaceholder}
        leftIcon={<Package className="size-4" aria-hidden="true" />}
        rightIcon={
          value && !open ? (
            <button
              type="button"
              tabIndex={-1}
              onClick={(e) => {
                e.stopPropagation()
                handleSelect(null)
              }}
              aria-label={t('priceLists:productCombobox.clearAria')}
              className="pointer-events-auto text-fg-muted hover:text-fg"
            >
              <X className="size-4" aria-hidden="true" />
            </button>
          ) : undefined
        }
        error={error}
        aria-expanded={open}
        role="combobox"
        aria-autocomplete="list"
      />
      {open && (
        <div className="absolute z-10 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border-strong bg-surface-2 shadow-popover">
          {isLoading ? (
            <p className="px-3 py-2 text-sm text-fg-muted">{t('priceLists:productCombobox.loading')}</p>
          ) : options.length === 0 ? (
            <p className="px-3 py-2 text-sm text-fg-muted">{t('priceLists:productCombobox.empty')}</p>
          ) : (
            options.map((option) => (
              <button
                key={option.id}
                type="button"
                onClick={() => handleSelect(option)}
                className={cn(
                  'flex w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-surface-3',
                  value?.id === option.id ? 'bg-primary-tint text-primary' : 'text-fg',
                  !option.is_active && 'opacity-60'
                )}
              >
                <span className="flex w-full items-center justify-between gap-2">
                  <span>{option.name}</span>
                  {!option.is_active && <span className="text-xs text-fg-muted">{t('priceLists:status.inactive')}</span>}
                </span>
                {option.sku && <span className="font-mono text-xs text-fg-muted">{option.sku}</span>}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )
}
