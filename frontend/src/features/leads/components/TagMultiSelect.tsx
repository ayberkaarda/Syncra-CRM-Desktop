// Etiket çoklu seçim bileşeni — lead formunda kullanılır. `/api/tags`'ten
// mevcut etiketleri listeler, checkbox ile çoklu seçim yapılır; dipteki
// hızlı-ekle alanı `POST /api/tags` ile yeni etiket oluşturur (backend
// `firstOrCreate` kullandığından aynı isim tekrar gönderilirse hata vermez).
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, Plus, X } from 'lucide-react'
import { Badge, Checkbox, Input } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useCreateTag, useTags } from '../api/leadsApi'

export type TagMultiSelectProps = {
  selectedIds: number[]
  onChange: (ids: number[]) => void
}

export function TagMultiSelect({ selectedIds, onChange }: TagMultiSelectProps) {
  const { t } = useTranslation('leads')
  const { data: tags, isLoading } = useTags()
  const createTag = useCreateTag()
  const [open, setOpen] = useState(false)
  const [newTagName, setNewTagName] = useState('')
  const containerRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!open) return
    function handleClickOutside(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [open])

  const selectedTags = (tags ?? []).filter((tag) => selectedIds.includes(tag.id))

  function toggleTag(id: number) {
    if (selectedIds.includes(id)) onChange(selectedIds.filter((tagId) => tagId !== id))
    else onChange([...selectedIds, id])
  }

  async function handleCreateTag(event: FormEvent) {
    event.preventDefault()
    const name = newTagName.trim()
    if (!name) return
    const tag = await createTag.mutateAsync({ name })
    onChange([...selectedIds, tag.id])
    setNewTagName('')
  }

  return (
    <div className="flex flex-col gap-1.5">
      <label className="text-xs font-medium text-fg-muted">{t('leads:tagMultiSelect.label')}</label>
      <div ref={containerRef} className="relative">
        <button
          type="button"
          onClick={() => setOpen((prev) => !prev)}
          aria-haspopup="listbox"
          aria-expanded={open}
          className={cn(
            'flex min-h-10 w-full flex-wrap items-center gap-1.5 rounded-md border border-border-strong bg-surface-2 px-3 py-2 text-left text-sm text-fg',
            'transition-colors duration-150 motion-reduce:transition-none',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
          )}
        >
          {selectedTags.length === 0 ? (
            <span className="text-fg-muted">{t('leads:tagMultiSelect.placeholder')}</span>
          ) : (
            <span className="text-fg">{t('leads:tagMultiSelect.selectedCount', { count: selectedTags.length })}</span>
          )}
          <ChevronDown className="ml-auto size-4 shrink-0 text-fg-muted" aria-hidden="true" />
        </button>

        {open && (
          <div
            role="listbox"
            aria-label={t('leads:tagMultiSelect.listboxAria')}
            className="absolute left-0 top-full z-20 mt-2 w-full overflow-hidden rounded-lg border border-border bg-surface-3 shadow-popover"
          >
            <div className="max-h-48 overflow-y-auto p-1.5">
              {isLoading && <p className="px-2 py-2 text-xs text-fg-muted">{t('leads:tagMultiSelect.loading')}</p>}
              {!isLoading && (tags ?? []).length === 0 && (
                <p className="px-2 py-2 text-xs text-fg-muted">{t('leads:tagMultiSelect.empty')}</p>
              )}
              {(tags ?? []).map((tag) => (
                <label
                  key={tag.id}
                  className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-surface-2"
                >
                  <Checkbox checked={selectedIds.includes(tag.id)} onChange={() => toggleTag(tag.id)} />
                  <span className="text-sm text-fg">{tag.name}</span>
                </label>
              ))}
            </div>
            <form onSubmit={handleCreateTag} className="flex items-center gap-1.5 border-t border-border-subtle p-1.5">
              <Input
                value={newTagName}
                onChange={(e) => setNewTagName(e.target.value)}
                placeholder={t('leads:tagMultiSelect.newTagPlaceholder')}
                inputSize="sm"
                aria-label={t('leads:tagMultiSelect.newTagAria')}
              />
              <button
                type="submit"
                disabled={!newTagName.trim() || createTag.isPending}
                aria-label={t('leads:tagMultiSelect.addAria')}
                className={cn(
                  'inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-primary-fg',
                  'disabled:opacity-50 disabled:cursor-not-allowed'
                )}
              >
                <Plus className="size-4" aria-hidden="true" />
              </button>
            </form>
          </div>
        )}
      </div>
      {selectedTags.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {selectedTags.map((tag) => (
            <Badge key={tag.id} variant="neutral" size="sm">
              {tag.name}
              <button
                type="button"
                onClick={() => toggleTag(tag.id)}
                aria-label={t('leads:tagMultiSelect.removeAria', { name: tag.name })}
                className="ml-0.5 hover:text-danger"
              >
                <X className="size-3" aria-hidden="true" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  )
}
