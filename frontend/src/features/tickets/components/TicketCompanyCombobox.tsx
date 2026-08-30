// Firma seçimi için aranabilir tekli combobox — `features/deals/components/DealCompanyCombobox.tsx`
// ile aynı desen, tickets modülünün kendi (geçici) `ticketsShared` tiplerine bağlı ayrı kopyası.
import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Building2, X } from 'lucide-react'
import { Input } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useTicketCompanyOptionsSearch } from './ticketsShared'
import type { CompanyOption } from '../types'
import { useDebouncedValue } from '../hooks/useDebouncedValue'

export type TicketCompanyComboboxProps = {
  value: CompanyOption | null
  onChange: (next: CompanyOption | null) => void
  label?: string
  error?: string
  placeholder?: string
}

export function TicketCompanyCombobox({
  value,
  onChange,
  label,
  error,
  placeholder,
}: TicketCompanyComboboxProps) {
  const { t } = useTranslation('tickets')
  const resolvedLabel = label ?? t('companyCombobox.label')
  const resolvedPlaceholder = placeholder ?? t('companyCombobox.placeholder')
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState('')
  const debouncedDraft = useDebouncedValue(draft, 300)
  const containerRef = useRef<HTMLDivElement | null>(null)

  const { data: options, isLoading } = useTicketCompanyOptionsSearch(debouncedDraft, { enabled: open })

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

  function handleSelect(option: CompanyOption | null) {
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
        leftIcon={<Building2 className="size-4" aria-hidden="true" />}
        rightIcon={
          value && !open ? (
            <button
              type="button"
              tabIndex={-1}
              onClick={(e) => {
                e.stopPropagation()
                handleSelect(null)
              }}
              aria-label={t('companyCombobox.clearAria')}
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
          <button
            type="button"
            onClick={() => handleSelect(null)}
            className={cn(
              'flex w-full items-center px-3 py-2 text-left text-sm text-fg-muted hover:bg-surface-3',
              !value && 'text-fg'
            )}
          >
            {t('companyCombobox.clearOption')}
          </button>
          {isLoading ? (
            <p className="px-3 py-2 text-sm text-fg-muted">{t('companyCombobox.loading')}</p>
          ) : (options ?? []).length === 0 ? (
            <p className="px-3 py-2 text-sm text-fg-muted">{t('companyCombobox.empty')}</p>
          ) : (
            (options ?? []).map((option) => (
              <button
                key={option.id}
                type="button"
                onClick={() => handleSelect(option)}
                className={cn(
                  'flex w-full items-center px-3 py-2 text-left text-sm text-fg hover:bg-surface-3',
                  value?.id === option.id && 'bg-primary-tint text-primary'
                )}
              >
                {option.name}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )
}
