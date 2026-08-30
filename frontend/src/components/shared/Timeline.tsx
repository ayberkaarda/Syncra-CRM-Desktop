// Ortak iletişim geçmişi zaman çizelgesi — Kişiler ve Firmalar detay sayfalarının ikisi de kullanır
// (bkz. Faz 6 / F görev tanımı). Backend `GET /api/{contacts|companies}/{id}/timeline` uçlarının
// döndürdüğü sayfalanmış `TimelineItem[]` listesini dikey bir zaman çizelgesi olarak çizer.
import {
  Activity,
  CalendarClock,
  CheckSquare,
  FileText,
  Handshake,
  History,
  LifeBuoy,
  Mail,
  MessageSquare,
  Paperclip,
  Phone,
  Plus,
  StickyNote,
  Users as UsersIcon,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, EmptyState, Skeleton } from '../ui'
import { cn } from '../../lib/cn'
import { getIntlLocale } from '../../i18n'

export type TimelineItemType = 'activity' | 'task' | 'deal' | 'ticket' | 'attachment'

export type TimelineItem = {
  type: TimelineItemType
  id: number
  title: string
  description: string | null
  icon_hint: string | null
  occurred_at: string
  user: { id: number; name: string } | null
  meta: Record<string, unknown>
}

export type TimelineProps = {
  items: TimelineItem[]
  isLoading: boolean
  isError: boolean
  onRetry: () => void
  hasMore: boolean
  onLoadMore: () => void
  /** "Daha fazla yükle" butonunun kendi yükleme durumu (sayfa 1 ilk yüklemesinden ayrı). */
  isLoadingMore?: boolean
  emptyDescription?: string
}

// `icon_hint` sözlüğü backend'de sabit bir enum olarak belgelenmedi; olası değerler için makul
// eşlemeler + tipe göre güvenli bir varsayılana düşme (fallback) uygulanır.
const HINT_ICON_MAP: Record<string, typeof Activity> = {
  call: Phone,
  phone: Phone,
  call_inbound: Phone,
  call_outbound: Phone,
  email: Mail,
  mail: Mail,
  meeting: UsersIcon,
  note: StickyNote,
  message: MessageSquare,
  status: History,
  status_change: History,
  created: Plus,
  updated: History,
  task: CheckSquare,
  task_completed: CheckSquare,
  deal: Handshake,
  deal_won: Handshake,
  deal_lost: Handshake,
  ticket: LifeBuoy,
  ticket_opened: LifeBuoy,
  ticket_closed: LifeBuoy,
  attachment: Paperclip,
  file: Paperclip,
  document: FileText,
  schedule: CalendarClock,
}

const TYPE_FALLBACK_ICON: Record<TimelineItemType, typeof Activity> = {
  activity: Activity,
  task: CheckSquare,
  deal: Handshake,
  ticket: LifeBuoy,
  attachment: Paperclip,
}

const TYPE_COLOR_CLASSES: Record<TimelineItemType, string> = {
  activity: 'bg-primary-tint text-primary',
  task: 'bg-warning-tint text-warning',
  deal: 'bg-success-tint text-success',
  ticket: 'bg-danger-tint text-danger',
  attachment: 'bg-surface-2 text-fg-muted',
}

function resolveIcon(item: TimelineItem) {
  if (item.icon_hint) {
    const hint = HINT_ICON_MAP[item.icon_hint.toLowerCase()]
    if (hint) return hint
  }
  return TYPE_FALLBACK_ICON[item.type] ?? Activity
}

function formatFullDate(iso: string, intlLocale: string): string {
  try {
    return new Intl.DateTimeFormat(intlLocale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return iso
  }
}

const RELATIVE_UNITS: Array<{ unit: Intl.RelativeTimeFormatUnit; ms: number }> = [
  { unit: 'year', ms: 365 * 24 * 60 * 60 * 1000 },
  { unit: 'month', ms: 30 * 24 * 60 * 60 * 1000 },
  { unit: 'week', ms: 7 * 24 * 60 * 60 * 1000 },
  { unit: 'day', ms: 24 * 60 * 60 * 1000 },
  { unit: 'hour', ms: 60 * 60 * 1000 },
  { unit: 'minute', ms: 60 * 1000 },
]

function formatRelativeTime(iso: string, intlLocale: string, justNow: string): string {
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return iso
  const diffMs = then - Date.now()
  const absMs = Math.abs(diffMs)

  if (absMs < 60_000) return justNow

  const rtf = new Intl.RelativeTimeFormat(intlLocale, { numeric: 'auto' })
  for (const { unit, ms } of RELATIVE_UNITS) {
    if (absMs >= ms || unit === 'minute') {
      const value = Math.round(diffMs / ms)
      return rtf.format(value, unit)
    }
  }
  return rtf.format(Math.round(diffMs / 60_000), 'minute')
}

function TimelineSkeletonItem() {
  return (
    <li className="flex gap-3">
      <Skeleton variant="circle" width={32} height={32} />
      <div className="flex flex-1 flex-col gap-2 pb-6">
        <Skeleton variant="text" width={180} />
        <Skeleton variant="text" width={280} />
        <Skeleton variant="text" width={100} />
      </div>
    </li>
  )
}

export function Timeline({
  items,
  isLoading,
  isError,
  onRetry,
  hasMore,
  onLoadMore,
  isLoadingMore = false,
  emptyDescription,
}: TimelineProps) {
  const { t } = useTranslation('common')
  const intlLocale = getIntlLocale()
  const resolvedEmptyDescription = emptyDescription ?? t('timeline.emptyDescription')
  const isInitialLoading = isLoading && items.length === 0
  const isEmpty = !isLoading && !isError && items.length === 0

  if (isError && items.length === 0) {
    return (
      <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
        <p className="text-sm text-fg-muted">{t('timeline.loadError')}</p>
        <Button variant="secondary" onClick={onRetry}>
          {t('actions.retry')}
        </Button>
      </div>
    )
  }

  if (isInitialLoading) {
    return (
      <ol className="flex flex-col gap-0" aria-busy="true">
        {Array.from({ length: 4 }).map((_, i) => (
          <TimelineSkeletonItem key={i} />
        ))}
      </ol>
    )
  }

  if (isEmpty) {
    return (
      <EmptyState
        icon={<History className="size-6" aria-hidden="true" />}
        title={t('timeline.emptyTitle')}
        description={resolvedEmptyDescription}
      />
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <ol className="flex flex-col gap-0">
        {items.map((item, index) => {
          const Icon = resolveIcon(item)
          const isLast = index === items.length - 1
          return (
            <li key={`${item.type}-${item.id}`} className="relative flex gap-3">
              {!isLast && (
                <span
                  aria-hidden="true"
                  className="absolute left-4 top-9 bottom-0 w-px -translate-x-1/2 bg-border-subtle"
                />
              )}
              <span
                className={cn(
                  'relative z-[1] flex size-8 shrink-0 items-center justify-center rounded-full',
                  TYPE_COLOR_CLASSES[item.type]
                )}
                aria-hidden="true"
              >
                <Icon className="size-4" aria-hidden="true" />
              </span>
              <div className="flex flex-1 flex-col gap-0.5 pb-6">
                <p className="text-sm font-medium text-fg">{item.title}</p>
                {item.description && <p className="text-sm text-fg-secondary">{item.description}</p>}
                <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-fg-muted">
                  {item.user && <span>{item.user.name}</span>}
                  {item.user && <span aria-hidden="true">·</span>}
                  <time dateTime={item.occurred_at} title={formatFullDate(item.occurred_at, intlLocale)}>
                    {formatRelativeTime(item.occurred_at, intlLocale, t('timeline.justNow'))}
                  </time>
                </div>
              </div>
            </li>
          )
        })}
      </ol>

      {isError && items.length > 0 && (
        <div className="flex flex-col items-center gap-2 py-2 text-center">
          <p className="text-xs text-fg-muted">{t('timeline.loadMoreError')}</p>
          <Button variant="secondary" size="sm" onClick={onRetry}>
            {t('actions.retry')}
          </Button>
        </div>
      )}

      {hasMore && !isError && (
        <div className="flex justify-center pt-1">
          <Button variant="secondary" size="sm" loading={isLoadingMore} onClick={onLoadMore}>
            {t('timeline.loadMore')}
          </Button>
        </div>
      )}
    </div>
  )
}
