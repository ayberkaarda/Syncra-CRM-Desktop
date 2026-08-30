// Etiket çoklu seçimi — açılır panelde onay kutusu listesi. Seçili etiketler input üstünde
// Badge olarak gösterilir (tag.color backend alanı token sözleşmesi gereği KULLANILMAZ).
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, X } from 'lucide-react'
import { Badge } from '../../../components/ui'
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import { cn } from '../../../lib/cn'
import type { Tag } from '../types'

export type TagMultiSelectProps = {
  value: Tag[]
  onChange: (next: Tag[]) => void
  options: Tag[]
  label?: string
  isLoading?: boolean
}

export function TagMultiSelect({ value, onChange, options, label, isLoading }: TagMultiSelectProps) {
  const { t } = useTranslation('contacts')
  const resolvedLabel = label ?? t('tags.label')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)

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

  function toggleTag(tag: Tag) {
    const exists = value.some((t) => t.id === tag.id)
    if (exists) onChange(value.filter((t) => t.id !== tag.id))
    else onChange([...value, tag])
  }

  function removeTag(tagId: number) {
    onChange(value.filter((t) => t.id !== tagId))
  }

  return (
    <div ref={containerRef} className="relative flex flex-col gap-1.5">
      {resolvedLabel && <label className="text-xs font-medium text-fg-muted">{resolvedLabel}</label>}

      {value.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {value.map((tag) => (
            <Badge key={tag.id} variant={tokenBadgeVariant(tag.color)}>
              {tag.name}
              <button
                type="button"
                onClick={() => removeTag(tag.id)}
                aria-label={t('tags.removeAria', { name: tag.name })}
                className="text-fg-muted hover:text-fg"
              >
                <X className="size-3" aria-hidden="true" />
              </button>
            </Badge>
          ))}
        </div>
      )}

      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-expanded={open}
        className={cn(
          'flex h-10 w-full items-center justify-between rounded-md border border-border-strong bg-surface-2 px-3 text-sm text-fg-muted',
          'transition-colors duration-150 motion-reduce:transition-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
        )}
      >
        <span>{t('tags.select')}</span>
        <ChevronDown className="size-4" aria-hidden="true" />
      </button>

      {open && (
        <div className="absolute z-10 top-full mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border-strong bg-surface-2 shadow-popover">
          {isLoading ? (
            <p className="px-3 py-2 text-sm text-fg-muted">{t('tags.loading')}</p>
          ) : options.length === 0 ? (
            <p className="px-3 py-2 text-sm text-fg-muted">{t('tags.notFound')}</p>
          ) : (
            options.map((tag) => {
              const checked = value.some((t) => t.id === tag.id)
              return (
                <label
                  key={tag.id}
                  className="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-fg hover:bg-surface-3"
                >
                  <input
                    type="checkbox"
                    checked={checked}
                    onChange={() => toggleTag(tag)}
                    className={cn(
                      'size-4 appearance-none rounded-sm border border-border-strong bg-surface-2',
                      'checked:border-primary checked:bg-primary'
                    )}
                  />
                  {tag.name}
                </label>
              )
            })
          )}
        </div>
      )}
    </div>
  )
}
