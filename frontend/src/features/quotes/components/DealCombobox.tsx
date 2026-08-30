// Fırsat seçimi için aranabilir tekli combobox — `CompanyCombobox.tsx` ile aynı desen.
// Teklif formunun üst bölümündeki "Fırsat" alanı (opsiyonel).
import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Briefcase, X } from 'lucide-react'
import { Input } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useDealOptionsSearch } from '../api/catalogApi'
import type { DealOption } from '../api/catalogApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'

export type DealComboboxProps = {
  value: DealOption | null
  onChange: (next: DealOption | null) => void
  label?: string
  error?: string
}

export function DealCombobox({ value, onChange, label, error }: DealComboboxProps) {
  const { t } = useTranslation()
  const resolvedLabel = label ?? t('quotes:dealCombobox.label')
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState('')
  const debouncedDraft = useDebouncedValue(draft, 300)
  const containerRef = useRef<HTMLDivElement | null>(null)

  const { data: options, isLoading } = useDealOptionsSearch(debouncedDraft, { enabled: open })

  useEffect(() => {
    if (!open) return
    function handleClick(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [open])

  function handleSelect(option: DealOption | null) {
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

  const displayValue = open ? draft : (value?.title ?? '')

  return (
    <div ref={containerRef} className="relative">
      <Input
        label={resolvedLabel}
        value={displayValue}
        onChange={(e) => setDraft(e.target.value)}
        onFocus={() => {
          setDraft('')
          setOpen(true)
        }}
        onKeyDown={handleKeyDown}
        placeholder={t('quotes:dealCombobox.placeholder')}
        leftIcon={<Briefcase className="size-4" aria-hidden="true" />}
        rightIcon={
          value && !open ? (
            <button
              type="button"
              tabIndex={-1}
              onClick={(e) => {
                e.stopPropagation()
                handleSelect(null)
              }}
              aria-label={t('quotes:dealCombobox.clearAria')}
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
              !value && 'text-fg',
            )}
          >
            {t('quotes:dealCombobox.clearOption')}
          </button>
          {isLoading ? (
            <p className="px-3 py-2 text-sm text-fg-muted">{t('quotes:dealCombobox.loading')}</p>
          ) : (options ?? []).length === 0 ? (
            <p className="px-3 py-2 text-sm text-fg-muted">{t('quotes:dealCombobox.empty')}</p>
          ) : (
            (options ?? []).map((option) => (
              <button
                key={option.id}
                type="button"
                onClick={() => handleSelect(option)}
                className={cn(
                  'flex w-full items-center px-3 py-2 text-left text-sm text-fg hover:bg-surface-3',
                  value?.id === option.id && 'bg-primary-tint text-primary',
                )}
              >
                {option.title}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )
}
