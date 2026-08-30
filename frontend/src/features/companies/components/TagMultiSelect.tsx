// Çoklu etiket seçici — açılır panel içinde onay kutusu listesi. Native <select multiple>
// yerine tercih edildi çünkü seçili etiketleri Badge olarak ayrıca göstermek istiyoruz ve
// tasarım jetonu sözleşmesine uymayan native çoklu seçim görünümünden kaçınıyoruz.
import { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, X } from 'lucide-react'
import { Badge, Checkbox } from '../../../components/ui'
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import { cn } from '../../../lib/cn'
import type { Tag } from '../types'

export type TagMultiSelectProps = {
  label?: string
  tags: Tag[]
  selectedIds: number[]
  onChange: (ids: number[]) => void
  error?: string
  placeholder?: string
}

export function TagMultiSelect({
  label,
  tags,
  selectedIds,
  onChange,
  error,
  placeholder,
}: TagMultiSelectProps) {
  const { t } = useTranslation('companies')
  const resolvedPlaceholder = placeholder ?? t('companies:tagSelect.placeholder')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const autoId = useId()

  useEffect(() => {
    if (!open) return
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open])

  const selectedTags = tags.filter((tag) => selectedIds.includes(tag.id))

  function toggleTag(id: number) {
    if (selectedIds.includes(id)) onChange(selectedIds.filter((tagId) => tagId !== id))
    else onChange([...selectedIds, id])
  }

  function removeTag(id: number) {
    onChange(selectedIds.filter((tagId) => tagId !== id))
  }

  return (
    <div className="flex flex-col gap-1.5" ref={containerRef}>
      {label && (
        <label id={`${autoId}-label`} className="text-xs font-medium text-fg-muted">
          {label}
        </label>
      )}
      <div className="relative">
        <button
          type="button"
          onClick={() => setOpen((prev) => !prev)}
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-labelledby={label ? `${autoId}-label` : undefined}
          className={cn(
            'flex h-10 w-full items-center justify-between rounded-md border border-border-strong bg-surface-2 px-3 text-sm text-fg',
            'transition-colors duration-150 motion-reduce:transition-none',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
            error && 'border-danger'
          )}
        >
          <span className={cn(selectedTags.length === 0 && 'text-fg-muted')}>
            {selectedTags.length === 0
              ? resolvedPlaceholder
              : t('companies:tagSelect.selectedCount', { count: selectedTags.length })}
          </span>
          <ChevronDown className="size-4 text-fg-muted" aria-hidden="true" />
        </button>

        {open && (
          <div
            role="listbox"
            aria-multiselectable="true"
            className="absolute z-10 mt-1.5 max-h-56 w-full overflow-y-auto rounded-md border border-border-subtle bg-surface-1 p-2 shadow-popover"
          >
            {tags.length === 0 ? (
              <p className="px-2 py-1.5 text-xs text-fg-muted">{t('companies:tagSelect.noTags')}</p>
            ) : (
              <div className="flex flex-col gap-1">
                {tags.map((tag) => (
                  <div key={tag.id} className="rounded-sm px-1 py-0.5 hover:bg-surface-2">
                    <Checkbox label={tag.name} checked={selectedIds.includes(tag.id)} onChange={() => toggleTag(tag.id)} />
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {selectedTags.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {selectedTags.map((tag) => (
            <Badge key={tag.id} variant={tokenBadgeVariant(tag.color)}>
              {tag.name}
              <button
                type="button"
                onClick={() => removeTag(tag.id)}
                aria-label={t('companies:tagSelect.removeAria', { name: tag.name })}
                className="ml-0.5 rounded-sm hover:text-fg"
              >
                <X className="size-3" aria-hidden="true" />
              </button>
            </Badge>
          ))}
        </div>
      )}

      {error && <p className="text-xs text-danger">{error}</p>}
    </div>
  )
}
