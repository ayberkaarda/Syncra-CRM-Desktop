// Mesaj arama — hem konuşma içi (`conversationId` verilirse) hem genel (verilmezse) arama.
// Kendi kendine yeten bir tetikleyici + açılır panel (desen:
// `features/notifications/components/NotificationBell.tsx`). Sonuca tıklayınca ilgili konuşma
// açılır (`onSelectResult`).
//
// Geciktirme (debounce) `useSearchMessages`'ın İÇİNDEDİR (bkz. o dosyanın gerekçesi) — burada
// AYRICA bir debounce KURULMAZ, ham `query` doğrudan kancaya verilir. `MIN_SEARCH_LENGTH`in
// altındaki sorgular kancada zaten `enabled: false` olduğundan burada "yazmaya başlayın" durumu
// ayrıca kontrol edilir.
//
// Mesaj listesinde belirli bir mesaja kaydırma desteği (`MessageList`/`useMessages`
// sözleşmesinde böyle bir parametre yok) bu sürümde YOK — yalnızca ilgili konuşma açılır.
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Search } from 'lucide-react'
import { EmptyState, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { MIN_SEARCH_LENGTH, useSearchMessages } from '../hooks/useSearchMessages'
import { formatRelativeTime, useDismiss } from './chatShared'
import type { Message } from '../types'

export type MessageSearchProps = {
  conversationId?: number
  onSelectResult: (conversationId: number, messageId: number) => void
}

export function MessageSearch({ conversationId, onSelectResult }: MessageSearchProps) {
  const { t } = useTranslation('chat')
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')

  const containerRef = useDismiss<HTMLDivElement>(open, () => setOpen(false))

  const { data, isLoading } = useSearchMessages(query, conversationId)
  const hasEnoughQuery = query.trim().length >= MIN_SEARCH_LENGTH
  const results: Message[] = hasEnoughQuery ? data ?? [] : []

  function handleSelect(message: Message) {
    onSelectResult(message.conversation_id, message.id)
    setOpen(false)
    setQuery('')
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={conversationId ? t('search.conversationAria') : t('search.globalAria')}
        className={cn(
          'inline-flex size-9 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
          'transition-colors duration-150 motion-reduce:transition-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
        )}
      >
        <Search className="size-4" aria-hidden="true" />
      </button>

      {open && (
        <div
          role="dialog"
          aria-label={conversationId ? t('search.conversationAria') : t('search.globalAria')}
          className="absolute right-0 top-full z-30 mt-2 w-80 rounded-lg border border-border bg-surface-3 py-2 shadow-popover"
        >
          <div className="px-3 pb-2">
            <input
              autoFocus
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={conversationId ? t('search.conversationPlaceholder') : t('search.globalPlaceholder')}
              aria-label={t('search.inputAria')}
              className={cn(
                'h-9 w-full rounded-md border border-border-strong bg-surface-2 px-3 text-sm text-fg placeholder:text-fg-muted',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary'
              )}
            />
          </div>

          <div className="max-h-80 overflow-y-auto border-t border-border-subtle">
            {!hasEnoughQuery ? (
              <p className="px-3 py-6 text-center text-xs text-fg-muted">
                {t('search.minLength', { count: MIN_SEARCH_LENGTH })}
              </p>
            ) : isLoading ? (
              <div className="flex flex-col gap-3 px-3 py-3">
                {Array.from({ length: 3 }).map((_, index) => (
                  <Skeleton key={index} variant="text" lines={2} />
                ))}
              </div>
            ) : results.length === 0 ? (
              <EmptyState
                icon={<Search className="size-5" aria-hidden="true" />}
                title={t('search.noResultsTitle')}
                description={t('search.noResultsDescription')}
                className="px-4 py-6"
              />
            ) : (
              <ul>
                {results.map((message) => (
                  <li key={message.id}>
                    <button
                      type="button"
                      onClick={() => handleSelect(message)}
                      className="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left hover:bg-surface-2"
                    >
                      <span className="text-xs font-medium text-fg">{message.user?.name ?? t('message.unknownUser')}</span>
                      <span className="line-clamp-2 text-xs text-fg-muted">{message.body}</span>
                      <span className="text-[11px] text-fg-disabled">{formatRelativeTime(message.created_at)}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
