// Bildirim paneli içeriği — `NotificationBell`'in açılır panelinde kullanılır: son 10 bildirim,
// okunmamışlar vurgulu, tipe göre lucide ikonu, göreli zaman. Tıklayınca okundu işaretlenir +
// `link`'e gidilir. Boş durum `EmptyState`, yükleniyor `Skeleton` (desen:
// `features/presence/components/OnlineUsersPopover.tsx`).
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bell } from 'lucide-react'
import { EmptyState, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useMarkRead, useNotifications } from '../hooks/useNotifications'
import { formatRelativeTime, notificationTypeIcon } from './notificationMeta'
import type { Notification } from '../types'

const PREVIEW_LIMIT = 10

export type NotificationListProps = {
  /** Bir bildirime tıklanıp yönlendirme başlamadan hemen önce çağrılır — `NotificationBell`
   * bunu panelini kapatmak için kullanır. */
  onNavigate?: () => void
}

export function NotificationList({ onNavigate }: NotificationListProps) {
  const { t } = useTranslation('notifications')
  const navigate = useNavigate()
  const { data, isLoading } = useNotifications({})
  const markRead = useMarkRead()

  const notifications = (data?.data ?? []).slice(0, PREVIEW_LIMIT)

  function handleSelect(notification: Notification) {
    if (!notification.read_at) {
      markRead.mutate(notification.id)
    }
    onNavigate?.()
    navigate(notification.link)
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3 px-3 py-3" aria-busy="true">
        {Array.from({ length: 4 }).map((_, index) => (
          <div key={index} className="flex items-center gap-2.5">
            <Skeleton variant="circle" width={32} height={32} />
            <div className="min-w-0 flex-1">
              <Skeleton variant="text" lines={2} />
            </div>
          </div>
        ))}
      </div>
    )
  }

  if (notifications.length === 0) {
    return (
      <EmptyState
        icon={<Bell className="size-6" aria-hidden="true" />}
        title={t('notifications:list.emptyTitle')}
        description={t('notifications:list.emptyDescription')}
        className="px-4 py-6"
      />
    )
  }

  return (
    <ul className="flex flex-col">
      {notifications.map((notification) => {
        const Icon = notificationTypeIcon(notification.type)
        const isUnread = !notification.read_at

        return (
          <li key={notification.id}>
            <button
              type="button"
              onClick={() => handleSelect(notification)}
              className={cn(
                'flex w-full items-start gap-2.5 px-3 py-2.5 text-left hover:bg-surface-2',
                'transition-colors duration-150 motion-reduce:transition-none',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset',
                isUnread && 'bg-primary-tint'
              )}
            >
              <span
                className={cn(
                  'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full',
                  isUnread ? 'bg-surface-1 text-primary' : 'bg-surface-2 text-fg-muted'
                )}
              >
                <Icon className="size-4" aria-hidden="true" />
              </span>
              <div className="min-w-0 flex-1">
                <p className={cn('truncate text-sm', isUnread ? 'font-semibold text-fg' : 'font-medium text-fg')}>
                  {notification.title}
                </p>
                <p className="truncate text-xs text-fg-muted">{notification.body}</p>
                <p className="mt-0.5 text-xs text-fg-muted">{formatRelativeTime(notification.created_at)}</p>
              </div>
              {isUnread && (
                <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary" aria-hidden="true" />
              )}
            </button>
          </li>
        )
      })}
    </ul>
  )
}
