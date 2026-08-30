// Sol panel — arama + tip filtresi (Tümü/DM/Grup/Kayıt) + konuşma listesi. Seçili konuşma
// vurgulanır (`bg-primary-tint`). "Yeni sohbet" tetikleyicisi burada render edilir ama modal
// state'i `ChatPage`te tutulur (aynı modal, hem burada hem boş durumda tetiklenebilsin diye) —
// bkz. `onNewConversation` prop'u.
//
// `useConversations` standart bir `useQuery` sonucu döndürür (`data: Conversation[]`, sunucu +
// istemci sıralı — bkz. `hooks/useConversations.ts` → `select: sortConversations`).
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { BellOff, MessageSquarePlus, Search } from 'lucide-react'
import { Avatar, Badge, EmptyState, Input, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useConversations } from '../hooks/useConversations'
import { formatRelativeTime } from './chatShared'
import type { Conversation, ConversationType } from '../types'

export type ConversationListProps = {
  selectedId: number | null
  onSelect: (conversationId: number) => void
  onNewConversation: () => void
}

/** Etiketler ANAHTAR olarak durur — dil değişince donmasın diye (bkz. `Sidebar.tsx` NAV_SECTIONS gerekçesi). */
const FILTERS: Array<{ value: ConversationType | 'all'; labelKey: string }> = [
  { value: 'all', labelKey: 'chat:conversationList.filters.all' },
  { value: 'dm', labelKey: 'chat:conversationList.filters.dm' },
  { value: 'group', labelKey: 'chat:conversationList.filters.group' },
  { value: 'record', labelKey: 'chat:conversationList.filters.record' },
]

export function ConversationList({ selectedId, onSelect, onNewConversation }: ConversationListProps) {
  const { t } = useTranslation('chat')
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [filter, setFilter] = useState<ConversationType | 'all'>('all')

  // Basit yerel debounce — projede ayrı bir `useDebouncedValue` paylaşılan hook'u yok
  // (`tickets`/`tasks` kendi kopyalarını tutuyor), burada da aynı desen izlenir.
  useEffect(() => {
    const timeout = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => clearTimeout(timeout)
  }, [search])

  const { data, isLoading } = useConversations({
    type: filter === 'all' ? undefined : filter,
    q: debouncedSearch || undefined,
  })

  const conversations = data ?? []

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center justify-between gap-2 border-b border-border-subtle p-4">
        <h2 className="text-md font-medium text-fg">{t('conversationList.title')}</h2>
        <button
          type="button"
          onClick={onNewConversation}
          aria-label={t('conversationList.newConversationAria')}
          className={cn(
            'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
            'transition-colors duration-150 motion-reduce:transition-none',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
          )}
        >
          <MessageSquarePlus className="size-4" aria-hidden="true" />
        </button>
      </div>

      <div className="flex flex-col gap-3 border-b border-border-subtle p-3">
        <Input
          inputSize="sm"
          placeholder={t('conversationList.searchPlaceholder')}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          leftIcon={<Search className="size-4" aria-hidden="true" />}
          aria-label={t('conversationList.searchAria')}
        />
        <div
          className="flex items-center gap-1 rounded-md bg-surface-2 p-1"
          role="tablist"
          aria-label={t('conversationList.filterAria')}
        >
          {FILTERS.map((item) => (
            <button
              key={item.value}
              type="button"
              role="tab"
              aria-selected={filter === item.value}
              onClick={() => setFilter(item.value)}
              className={cn(
                'flex-1 rounded-md px-2 py-1.5 text-xs font-medium',
                'transition-colors duration-150 motion-reduce:transition-none',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface-1',
                filter === item.value ? 'bg-primary text-primary-fg' : 'text-fg-muted hover:text-fg'
              )}
            >
              {t(item.labelKey)}
            </button>
          ))}
        </div>
      </div>

      <div className="flex-1 overflow-y-auto">
        {isLoading ? (
          <div className="flex flex-col gap-4 p-3" aria-busy="true">
            {Array.from({ length: 6 }).map((_, index) => (
              <div key={index} className="flex items-center gap-3">
                <Skeleton variant="circle" width={40} height={40} />
                <div className="min-w-0 flex-1">
                  <Skeleton variant="text" lines={2} />
                </div>
              </div>
            ))}
          </div>
        ) : conversations.length === 0 ? (
          <EmptyState
            icon={<Search className="size-6" aria-hidden="true" />}
            title={t('conversationList.emptyTitle')}
            description={
              debouncedSearch
                ? t('conversationList.emptySearchDescription')
                : t('conversationList.emptyDescription')
            }
            className="px-4 py-10"
          />
        ) : (
          <ul>
            {conversations.map((conversation) => (
              <ConversationRow
                key={conversation.id}
                conversation={conversation}
                selected={conversation.id === selectedId}
                onSelect={() => onSelect(conversation.id)}
              />
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}

type ConversationRowProps = {
  conversation: Conversation
  selected: boolean
  onSelect: () => void
}

function ConversationRow({ conversation, selected, onSelect }: ConversationRowProps) {
  const { t } = useTranslation('chat')
  return (
    <li>
      <button
        type="button"
        onClick={onSelect}
        aria-current={selected ? 'true' : undefined}
        className={cn(
          'flex w-full items-center gap-3 border-b border-border-subtle px-4 py-3 text-left',
          'transition-colors duration-150 motion-reduce:transition-none hover:bg-surface-2',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset',
          selected && 'bg-primary-tint'
        )}
      >
        <Avatar name={conversation.display_name} size="md" />
        <div className="min-w-0 flex-1">
          <div className="flex items-center justify-between gap-2">
            <p
              className={cn(
                'truncate text-sm',
                conversation.unread_count > 0 ? 'font-semibold text-fg' : 'font-medium text-fg'
              )}
            >
              {conversation.display_name}
            </p>
            {conversation.last_message_at && (
              <span className="shrink-0 text-xs text-fg-muted">{formatRelativeTime(conversation.last_message_at)}</span>
            )}
          </div>
          <div className="flex items-center justify-between gap-2">
            <p className="min-w-0 flex-1 truncate text-xs text-fg-muted">
              {conversation.type === 'record' && conversation.conversable
                ? `${conversation.conversable.label} — ${conversation.last_message_preview || t('conversationList.noMessagesYet')}`
                : conversation.last_message_preview || t('conversationList.noMessagesYet')}
            </p>
            <div className="flex shrink-0 items-center gap-1.5">
              {conversation.is_muted && (
                <BellOff
                  className="size-3.5 text-fg-disabled"
                  aria-hidden="true"
                  aria-label={t('conversationList.mutedAria')}
                />
              )}
              {conversation.unread_count > 0 && (
                <Badge variant="primary" size="sm">
                  {conversation.unread_count > 99 ? '99+' : conversation.unread_count}
                </Badge>
              )}
            </div>
          </div>
        </div>
      </button>
    </li>
  )
}
