// Global komut paleti (Ctrl+K / Cmd+K) — Faz 14 / İz F / C1.
// Attio tarzı "her yerden kayda anında atla" — sözleşme: docs/PHASE-INTL.md §3,
// docs/PHASE-AUDIT.md §5.4 (C1 güvenlik kısıtı).
//
// GÜVENLİK KARARI (§5.4 C1) — İKİNCİ BİR FİLTRE YOK:
// Yetki filtresi SUNUCUDADIR (`GlobalSearchService::search()` — her modül `Gate::allows
// ('viewAny', ...)` ile elenir). Bu bileşen yanıtı OLDUĞU GİBİ render eder; istemci
// tarafında "bu modülü gösterme" türünden ikinci bir kural YAZILMADI (yanlış güven
// duygusu yaratırdı — sunucu zaten filtrelemiş olan bir kümeyi bir de burada süzmek,
// birinin unutulduğu durumda "iki kere kontrol edildi" yanılsaması verir).
// İzinsiz bir modülün anahtarı yanıtta (`SearchResponse`) HİÇ bulunmaz (ne `[]` ne `null`)
// — bu yüzden aşağıdaki render de yalnızca GERÇEKTEN mevcut ve boş olmayan grupları
// gösterir: boş bir grup BAŞLIĞI bile "bu modül var, sen göremiyorsun" bilgisini
// sızdırır (bkz. GlobalSearchService sınıf dokümanı, aynı gerekçe).
//
// KAYNAK ETİKETİ (`SYNCDESKTOP.md` §7.2) — PLATFORM DALLANMASI YOK:
// Masaüstünde arama İKİ dizinden gelir (yerel FTS + `GET /api/search`, `desktop/src/platform/
// data/comms.ts`), bu yüzden her sonuç hangisinden geldiğini `search_source` alanıyla taşır ve
// aşağıda küçük bir etiket olarak görünür. Web'de tek dizin vardır (sunucu): `web.ts`
// `fetchGlobalSearch` yanıtını olduğu gibi döner, alan HİÇ dolmaz, `searchResultSource()`
// `null` okur ve etiket hiç basmaz. Yani ayrımı yapan `isDesktop` değil, ALANIN VARLIĞIdir —
// `SyncStateBadge` / `recordSyncState` ile BİREBİR AYNI desen (KARAR A19). Tek kaynaklı bir
// listede her satıra "Sunucu" basmak zaten bilgi değil gürültü olurdu.
//
// ERİŞİLEBİLİRLİK: ARIA "listbox içinde combobox" deseni — odak her zaman input'ta kalır,
// sanal seçim `aria-activedescendant` ile duyurulur (Modal.tsx'teki gerçek odak tuzağının
// aksine, burada yalnızca input + kapat düğmesi arasında iki durak var — bkz. `handleKeyDown`
// Tab dalı, Modal.tsx'teki AYNI iki-uçlu tuzak deseni).
import { useEffect, useId, useMemo, useRef, useState } from 'react'
import type { ComponentType, KeyboardEvent as ReactKeyboardEvent } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Building2, FileText, Loader2, LifeBuoy, Search, Target, UserCog, UserPlus, Users, X } from 'lucide-react'
import { cn } from '../../../lib/cn'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { MIN_QUERY_LENGTH, useGlobalSearch } from '../api/searchApi'
import type { SearchGroupKey, SearchResultItem } from '../types'
import type { SearchResultSource } from '../../../platform/types'

export type CommandPaletteProps = {
  open: boolean
  onClose: () => void
}

const SEARCH_DEBOUNCE_MS = 200
const FOCUSABLE_SELECTOR = 'input:not([disabled]), button:not([disabled])'

type ModuleConfig = {
  key: SearchGroupKey
  icon: ComponentType<{ className?: string }>
  /** `common` namespace anahtarı — modül grup başlıkları için Sidebar ile AYNI etiketler. */
  navKey: string
}

/**
 * Backend `GlobalSearchService::RESPONSE_KEYS` sırasıyla BİREBİR aynı (deal, lead, contact,
 * company, quote, ticket, user) — hem bütçe paylaşım sırası hem yanıt anahtarı sırası orada
 * budur; komut paletinin grup sırası da aynı kalır (tutarlı tarama deneyimi).
 */
const MODULE_ORDER: ModuleConfig[] = [
  { key: 'deals', icon: Target, navKey: 'nav.deals' },
  { key: 'leads', icon: UserPlus, navKey: 'nav.leads' },
  { key: 'contacts', icon: Users, navKey: 'nav.contacts' },
  { key: 'companies', icon: Building2, navKey: 'nav.companies' },
  { key: 'quotes', icon: FileText, navKey: 'nav.quotes' },
  { key: 'tickets', icon: LifeBuoy, navKey: 'nav.tickets' },
  { key: 'users', icon: UserCog, navKey: 'nav.users' },
]

/**
 * Bir sonucun hangi dizinden geldiği — `recordSyncState()` ile AYNI disiplin: değer
 * DOĞRULANIR, cast EDİLMEZ.
 *
 * Argüman `unknown`, çünkü `search_source` bir alan DEĞİLDİR — `SearchResultItem` onu bilmez;
 * `WithSearchSource<T>` (`platform/types.ts`) ile yalnızca çift dizinli bir platformun
 * doldurduğu isteğe bağlı bir ektir. Tanınmayan her değer (web'in ürettiği `undefined`
 * dahil) `null` döner ve etiket basmaz. Dışa AKTARILMAZ: bir `.tsx` dosyasının hem bileşen
 * hem düz fonksiyon ihraç etmesi o dosyanın Fast Refresh'ini bozar (bkz.
 * `components/shared/recordSyncState.ts` başlığı).
 */
function searchResultSource(item: unknown): SearchResultSource | null {
  if (item === null || typeof item !== 'object') return null
  const source = (item as { search_source?: unknown }).search_source
  return source === 'local' || source === 'server' ? source : null
}

type FlatItem = SearchResultItem & { groupKey: SearchGroupKey; domId: string; index: number }

type FlatGroup = {
  key: SearchGroupKey
  icon: ComponentType<{ className?: string }>
  navKey: string
  items: FlatItem[]
}

export function CommandPalette({ open, onClose }: CommandPaletteProps) {
  const { t } = useTranslation(['search', 'common'])
  const navigate = useNavigate()
  const autoId = useId()
  const listboxId = `${autoId}-listbox`

  const inputRef = useRef<HTMLInputElement | null>(null)
  const panelRef = useRef<HTMLDivElement | null>(null)
  const listRef = useRef<HTMLDivElement | null>(null)
  const previouslyFocused = useRef<HTMLElement | null>(null)

  const [draft, setDraft] = useState('')
  const debouncedDraft = useDebouncedValue(draft, SEARCH_DEBOUNCE_MS)
  const [activeIndex, setActiveIndex] = useState(0)

  const trimmed = debouncedDraft.trim()
  const { data, isLoading, isFetching, isError, refetch } = useGlobalSearch(open ? trimmed : '')

  const flatItems = useMemo<FlatItem[]>(() => {
    if (!data) return []
    const items: FlatItem[] = []
    let index = 0
    for (const module of MODULE_ORDER) {
      const group = data[module.key]
      if (!group || group.length === 0) continue
      for (const item of group) {
        items.push({ ...item, groupKey: module.key, domId: `${listboxId}-${module.key}-${item.id}`, index })
        index += 1
      }
    }
    return items
  }, [data, listboxId])

  // Sıralı `flatItems` zaten modül bazında ard arda gruplanmış (bkz. yukarıdaki üretim
  // döngüsü) — ikinci bir gruplama/eşleştirme araması gerekmez, tek geçişte bölünür.
  const groupedFlat = useMemo<FlatGroup[]>(() => {
    const groups: FlatGroup[] = []
    for (const item of flatItems) {
      const last = groups[groups.length - 1]
      if (last && last.key === item.groupKey) {
        last.items.push(item)
        continue
      }
      const config = MODULE_ORDER.find((module) => module.key === item.groupKey)
      if (!config) continue
      groups.push({ key: config.key, icon: config.icon, navKey: config.navKey, items: [item] })
    }
    return groups
  }, [flatItems])

  // Yeni yanıt geldiğinde etkin seçimi sıfırla — önceki index yeni kümede anlamsız olabilir.
  // BİLİNÇLİ OLARAK effect DEĞİL: "bir bağımlılık değiştiğinde state'i ayarla" burada React'in
  // önerdiği render-anı deseniyle yapılır (react-hooks/set-state-in-effect kuralı, bkz.
  // https://react.dev/learn/you-might-not-need-an-effect) — bir efekt içinde koşulsuz `setState`
  // basamaklı render'a yol açardı.
  const [lastData, setLastData] = useState(data)
  if (data !== lastData) {
    setLastData(data)
    setActiveIndex(0)
  }

  // `open` `false -> true` geçişinde aramayı/seçimi sıfırla — AYNI render-anı deseni (yukarıdaki
  // gerekçe). Odak/DOM yönetimi (aşağıdaki effect) bundan AYRI tutulur çünkü o kısım gerçek bir
  // dış-sistem senkronizasyonudur (imperatif focus), setState İÇERMEZ.
  const [wasOpen, setWasOpen] = useState(open)
  if (open !== wasOpen) {
    setWasOpen(open)
    if (open) {
      setDraft('')
      setActiveIndex(0)
    }
  }

  // Açılışta: odağı input'a taşı, açan öğeyi hatırla; kapanışta o öğeye odağı geri ver
  // (Modal.tsx'teki AYNI `document.activeElement` deseni). Burada setState YOK — yalnızca
  // imperatif DOM odak yönetimi, bu yüzden `react-hooks/set-state-in-effect` kuralını tetiklemez.
  useEffect(() => {
    if (!open) return
    previouslyFocused.current = document.activeElement as HTMLElement | null
    const raf = requestAnimationFrame(() => inputRef.current?.focus())
    return () => {
      cancelAnimationFrame(raf)
      previouslyFocused.current?.focus?.()
    }
  }, [open])

  // Açıkken body scroll'unu kilitle (Modal.tsx ile aynı desen).
  useEffect(() => {
    if (!open) return
    const original = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = original
    }
  }, [open])

  // Etkin öğeyi görünür tut (ok tuşlarıyla gezinirken liste otomatik kayar).
  useEffect(() => {
    if (!open) return
    listRef.current?.querySelector<HTMLElement>('[data-active="true"]')?.scrollIntoView({ block: 'nearest' })
  }, [activeIndex, open])

  function handleSelect(item: FlatItem) {
    onClose()
    navigate(item.link)
  }

  function handleKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape') {
      event.stopPropagation()
      onClose()
      return
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      if (flatItems.length > 0) setActiveIndex((prev) => (prev + 1) % flatItems.length)
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      if (flatItems.length > 0) setActiveIndex((prev) => (prev - 1 + flatItems.length) % flatItems.length)
      return
    }
    if (event.key === 'Enter') {
      event.preventDefault()
      const active = flatItems[activeIndex]
      if (active) handleSelect(active)
      return
    }
    if (event.key !== 'Tab') return
    // İki-uçlu odak tuzağı (input <-> kapat düğmesi) — Modal.tsx'teki tuzak mantığının
    // yalnızca iki odaklanabilir öğeye indirgenmiş hâli.
    const panel = panelRef.current
    if (!panel) return
    const focusables = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
    if (focusables.length === 0) return
    const first = focusables[0]
    const last = focusables[focusables.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  if (!open) return null

  const showPrompt = trimmed.length < MIN_QUERY_LENGTH
  const showLoading = !showPrompt && isLoading
  const showError = !showPrompt && isError
  const showEmpty = !showPrompt && !isLoading && !isError && flatItems.length === 0
  const showResults = !showPrompt && !isLoading && !isError && flatItems.length > 0
  const activeItem = flatItems[activeIndex]

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[12vh]">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} aria-hidden="true" />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={t('search:palette.title')}
        onKeyDown={handleKeyDown}
        className="relative flex max-h-[70vh] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-border-subtle bg-surface-1 shadow-popover"
      >
        <div className="flex items-center gap-2 border-b border-border-subtle px-4 py-3">
          {isFetching ? (
            <Loader2 className="size-4 shrink-0 animate-spin text-fg-muted" aria-hidden="true" />
          ) : (
            <Search className="size-4 shrink-0 text-fg-muted" aria-hidden="true" />
          )}
          <input
            ref={inputRef}
            type="text"
            role="combobox"
            aria-expanded="true"
            aria-controls={listboxId}
            aria-autocomplete="list"
            aria-activedescendant={activeItem?.domId}
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder={t('search:palette.inputPlaceholder')}
            aria-label={t('search:palette.inputAriaLabel')}
            className="w-full border-0 bg-transparent text-sm text-fg placeholder:text-fg-muted focus:outline-none"
            autoComplete="off"
            spellCheck={false}
          />
          <button
            type="button"
            onClick={onClose}
            aria-label={t('common:actions.close')}
            className={cn(
              'shrink-0 rounded-md p-1 text-fg-muted hover:bg-surface-2 hover:text-fg',
              'transition-colors duration-150 motion-reduce:transition-none',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary'
            )}
          >
            <X className="size-4" aria-hidden="true" />
          </button>
        </div>

        <div
          ref={listRef}
          id={listboxId}
          role="listbox"
          aria-label={t('search:palette.resultsAria')}
          className="flex-1 overflow-y-auto p-2"
        >
          {showPrompt && <p className="px-3 py-6 text-center text-sm text-fg-muted">{t('search:palette.prompt')}</p>}

          {showLoading && <p className="px-3 py-6 text-center text-sm text-fg-muted">{t('search:palette.loading')}</p>}

          {showError && (
            <div className="flex flex-col items-center gap-2 px-3 py-6 text-center text-sm">
              <p className="text-danger">{t('search:palette.error')}</p>
              <button type="button" onClick={() => refetch()} className="font-medium text-primary hover:underline">
                {t('common:actions.retry')}
              </button>
            </div>
          )}

          {showEmpty && <p className="px-3 py-6 text-center text-sm text-fg-muted">{t('search:palette.empty')}</p>}

          {showResults && (
            <div className="flex flex-col gap-3">
              {groupedFlat.map((group) => {
                const Icon = group.icon
                return (
                  <div key={group.key}>
                    <p className="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-fg-muted">
                      {t(`common:${group.navKey}`)}
                    </p>
                    <ul className="flex flex-col">
                      {group.items.map((item) => {
                        const active = item.index === activeIndex
                        const source = searchResultSource(item)
                        return (
                          <li key={item.domId} role="presentation">
                            <button
                              type="button"
                              id={item.domId}
                              role="option"
                              aria-selected={active}
                              data-active={active}
                              onMouseEnter={() => setActiveIndex(item.index)}
                              onClick={() => handleSelect(item)}
                              className={cn(
                                'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm',
                                'transition-colors duration-150 motion-reduce:transition-none',
                                active ? 'bg-primary-tint text-primary' : 'text-fg hover:bg-surface-2'
                              )}
                            >
                              <Icon className="size-4 shrink-0 text-fg-muted" aria-hidden="true" />
                              <span className="flex min-w-0 flex-col">
                                <span className="truncate font-medium">{item.title}</span>
                                {item.subtitle && (
                                  <span className="truncate text-xs text-fg-muted">{item.subtitle}</span>
                                )}
                              </span>
                              {source && (
                                <span
                                  className={cn(
                                    'ml-auto shrink-0 rounded border border-border-subtle px-1.5 py-0.5',
                                    'text-[10px] font-medium uppercase tracking-wide text-fg-muted'
                                  )}
                                >
                                  {source === 'local'
                                    ? t('desktop:search.sourceLocal')
                                    : t('desktop:search.sourceServer')}
                                </span>
                              )}
                            </button>
                          </li>
                        )
                      })}
                    </ul>
                  </div>
                )
              })}
            </div>
          )}
        </div>

        <div className="flex items-center justify-end gap-3 border-t border-border-subtle px-4 py-2 text-xs text-fg-muted">
          <span>{t('search:palette.hintNavigate')}</span>
          <span>{t('search:palette.hintSelect')}</span>
          <span>{t('search:palette.hintClose')}</span>
        </div>
      </div>
    </div>,
    document.body
  )
}
