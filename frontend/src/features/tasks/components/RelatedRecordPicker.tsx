// İlgili kayıt (taskable/activityable) seçici — hem Görev hem Aktivite formunda AYNI bileşen
// kullanılır (bkz. görev tanımı §Formlar): tip `Select`'i + seçilen tipe göre aranabilir kayıt
// combobox'ı. Görevlere ÖZEL bir şey yok; `features/activities/components/ActivityFormModal.tsx`
// bu dosyadan DOĞRUDAN import eder (görev tanımının bıraktığı iki seçenekten biri: "tasks/
// components/ altına koy ve activities'ten import et" — burada karar verildi, activities
// tarafında bir kopya/eşdeğer YOK).
//
// `ticket` dalı D şeridin `features/tickets/api/ticketsApi.ts`'teki `useTickets()` hook'unu
// DOĞRUDAN kullanır (kendi kopyamı YAZMADIM) — diğer dört tip için burada tutulan ham axios
// çağrılarından FARKLI bir yol, çünkü o modülde zaten reusable bir hook var ve D şeridin
// dosyasına dokunmam yasak. İki sonuç:
//   1. `useTickets` bir `enabled` parametresi almıyor (D şeridin dosyası değiştirilemez) — bu
//      yüzden arama sorgusu, TÜM tipler için ortak `useQuery` yerine, yalnızca `value.type ===
//      'ticket'` iken mount edilen ayrı bir alt bileşende (`TicketSearchResults`) çağrılır.
//      Koşullu mount etmek (bir bileşeni hiç render etmemek) React hook kuralını ihlal etmez —
//      koşullu olan bir bileşenin GÖVDESİ içindeki hook çağrısı olurdu, bu farklı.
//   2. `tickets.view` izni olmayan bir kullanıcı için tip Select'inde "Talep" seçeneği hiç
//      GÖRÜNMEMELİ (kullanıcıya seçtirip sonra 403/boş sonuç göstermek kötü UX). Bu kontrol
//      seçim yapılmadan ÖNCE bilinmeli, bu yüzden `per_page:1`'lik ayrı, HER ZAMAN AÇIK bir
//      prob sorgusu (`useTickets({ per_page: 1 })`) bileşenin en üstünde tutulur — `useTickets`
//      `refetchInterval: 60_000` taşıyor, bu Modal açıkken kabul edilebilir bir maliyet
//      (bileşen Modal kapanınca unmount olur, arka planda sonsuza dek dönmez).
import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import axios from 'axios'
import { Search, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Select } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { api } from '../../../lib/axios'
import { useQuery } from '@tanstack/react-query'
import { useTickets } from '../../tickets/api/ticketsApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { RELATED_RECORD_SELECTABLE_TYPES, relatedRecordTypeLabel } from './relatedRecordMeta'
import type { TaskableType } from '../types'

export type RelatedRecordValue = { type: TaskableType; id: number; label: string } | null

export type RelatedRecordPickerProps = {
  value: RelatedRecordValue
  onChange: (next: RelatedRecordValue) => void
  typeError?: string
  idError?: string
}

type SearchOption = { id: number; label: string }

const SEARCH_DEBOUNCE_MS = 300

/** `ticket` HARİÇ dört tip için ortak arama — ticket ayrı bir dalda (`TicketSearchResults`), bkz. dosya başı notu. */
async function searchRelatedRecords(type: Exclude<TaskableType, 'ticket'>, q: string): Promise<SearchOption[]> {
  switch (type) {
    case 'deal': {
      const { data } = await api.get<{ data: { id: number; title: string }[] }>('/api/deals', {
        params: { q: q || undefined, per_page: 20, sort: 'title' },
      })
      return data.data.map((d) => ({ id: d.id, label: d.title }))
    }
    case 'lead': {
      const { data } = await api.get<{ data: { id: number; full_name: string }[] }>('/api/leads', {
        params: { q: q || undefined, per_page: 20 },
      })
      return data.data.map((l) => ({ id: l.id, label: l.full_name }))
    }
    case 'contact': {
      const { data } = await api.get<{ data: { id: number; full_name: string }[] }>('/api/contacts', {
        params: { q: q || undefined, per_page: 20, sort: 'last_name' },
      })
      return data.data.map((c) => ({ id: c.id, label: c.full_name }))
    }
    case 'company': {
      const { data } = await api.get<{ data: { id: number; name: string }[] }>('/api/companies', {
        params: { q: q || undefined, per_page: 20, sort: 'name' },
      })
      return data.data.map((c) => ({ id: c.id, label: c.name }))
    }
  }
}

/**
 * Talep (ticket) araması — D şeridin `useTickets()` hook'unu kullanır. Etiket biçimi
 * `{ticket_number} — {subject}`, backend `MorphTargets::label()`'ın ticket için ürettiği
 * biçimle BİREBİR aynı (tutarlılık: liste/detaydaki "İlgili Kayıt" bağlantısı ile aynı metni
 * görsün).
 */
function TicketSearchResults({
  q,
  selectedId,
  onSelect,
}: {
  q: string
  selectedId: number | undefined
  onSelect: (option: SearchOption) => void
}) {
  const { data, isLoading } = useTickets({ q: q || undefined, per_page: 20 })
  const options: SearchOption[] = (data?.data ?? []).map((ticket) => ({
    id: ticket.id,
    label: `${ticket.ticket_number} — ${ticket.subject}`,
  }))
  return <OptionsList isLoading={isLoading} options={options} selectedId={selectedId} onSelect={onSelect} />
}

function OptionsList({
  isLoading,
  options,
  selectedId,
  onSelect,
}: {
  isLoading: boolean
  options: SearchOption[]
  selectedId: number | undefined
  onSelect: (option: SearchOption) => void
}) {
  const { t } = useTranslation('tasks')
  if (isLoading) return <p className="px-3 py-2 text-sm text-fg-muted">{t('relatedPicker.loading')}</p>
  if (options.length === 0) return <p className="px-3 py-2 text-sm text-fg-muted">{t('relatedPicker.noResults')}</p>
  return (
    <>
      {options.map((option) => (
        <button
          key={option.id}
          type="button"
          onClick={() => onSelect(option)}
          className={cn(
            'flex w-full items-center px-3 py-2 text-left text-sm text-fg hover:bg-surface-3',
            selectedId === option.id && 'bg-primary-tint text-primary'
          )}
        >
          {option.label}
        </button>
      ))}
    </>
  )
}

export function RelatedRecordPicker({ value, onChange, typeError, idError }: RelatedRecordPickerProps) {
  const { t } = useTranslation('tasks')
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState('')
  const debouncedDraft = useDebouncedValue(draft, SEARCH_DEBOUNCE_MS)
  const containerRef = useRef<HTMLDivElement | null>(null)

  const isTicketType = value?.type === 'ticket'
  const selectedType = value?.type ?? ''

  // `tickets.view` izin probu — bkz. dosya başı notu §2. Yalnızca `error` okunur, `data`
  // bilerek kullanılmaz (bu sorgunun tek amacı 403 tespiti).
  const ticketAccess = useTickets({ per_page: 1 })
  const ticketsForbidden = axios.isAxiosError(ticketAccess.error) && ticketAccess.error.response?.status === 403

  const typeOptions = [
    { value: '', label: t('relatedPicker.noneOption') },
    ...RELATED_RECORD_SELECTABLE_TYPES.filter((type) => type !== 'ticket' || !ticketsForbidden).map((type) => ({
      value: type,
      label: relatedRecordTypeLabel(type, t),
    })),
  ]

  const { data: genericOptions, isLoading: genericLoading } = useQuery({
    queryKey: ['related-record-search', value?.type, debouncedDraft],
    queryFn: () => searchRelatedRecords(value?.type as Exclude<TaskableType, 'ticket'>, debouncedDraft),
    enabled: open && !!value?.type && !isTicketType,
  })

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

  function handleTypeChange(nextType: string) {
    if (!nextType) {
      onChange(null)
      return
    }
    // Tip değişince seçili kayıt sıfırlanır — eski id yeni tipte anlamsız.
    onChange({ type: nextType as TaskableType, id: 0, label: '' })
    setDraft('')
  }

  function handleFocus() {
    setDraft('')
    setOpen(true)
  }

  function handleSelect(option: SearchOption | null) {
    if (!value?.type) return
    onChange(option ? { type: value.type, id: option.id, label: option.label } : { type: value.type, id: 0, label: '' })
    setDraft('')
    setOpen(false)
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Escape') {
      setOpen(false)
      event.currentTarget.blur()
    }
  }

  const hasSelection = !!value && value.id > 0
  const displayValue = open ? draft : hasSelection ? value.label : ''

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <Select
        label={t('relatedPicker.typeLabel')}
        value={selectedType}
        onChange={(e) => handleTypeChange(e.target.value)}
        options={typeOptions}
        error={typeError}
      />

      <div ref={containerRef} className="relative">
        <label className="mb-1.5 block text-xs font-medium text-fg-muted">{t('relatedPicker.searchLabel')}</label>
        <div className="relative">
          <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-fg-muted">
            <Search className="size-4" aria-hidden="true" />
          </span>
          <input
            value={displayValue}
            onChange={(e) => setDraft(e.target.value)}
            onFocus={handleFocus}
            onKeyDown={handleKeyDown}
            disabled={!value?.type}
            placeholder={value?.type ? t('relatedPicker.searchPlaceholder') : t('relatedPicker.selectTypeFirst')}
            role="combobox"
            aria-expanded={open}
            aria-autocomplete="list"
            className={cn(
              'h-10 w-full rounded-md border border-border-strong bg-surface-2 pl-9 pr-9 text-sm text-fg',
              'placeholder:text-fg-muted',
              'transition-colors duration-150 motion-reduce:transition-none',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
              'disabled:opacity-50 disabled:cursor-not-allowed',
              idError && 'border-danger'
            )}
          />
          {hasSelection && !open && (
            <button
              type="button"
              tabIndex={-1}
              onClick={() => handleSelect(null)}
              aria-label={t('relatedPicker.clearSelection')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-fg-muted hover:text-fg"
            >
              <X className="size-4" aria-hidden="true" />
            </button>
          )}
        </div>
        {idError && <p className="mt-1.5 text-xs text-danger">{idError}</p>}
        {open && value?.type && (
          <div className="absolute z-10 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border-strong bg-surface-2 shadow-popover">
            {isTicketType ? (
              <TicketSearchResults q={debouncedDraft} selectedId={value?.id} onSelect={handleSelect} />
            ) : (
              <OptionsList isLoading={genericLoading} options={genericOptions ?? []} selectedId={value?.id} onSelect={handleSelect} />
            )}
          </div>
        )}
      </div>
    </div>
  )
}
