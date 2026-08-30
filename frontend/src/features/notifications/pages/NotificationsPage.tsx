// Bildirimler tam sayfa listesi — okunmamış/tümü/okunmuş sekmeleri, sayfalama, "tümünü okundu
// işaretle", tek tek silme. Sekme/sayfa durumu URL query string'inde tutulur (desen:
// `features/logs/pages/LogsPage.tsx`/`features/tickets/pages/TicketsListPage.tsx`).
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bell, CheckCheck, Trash2 } from 'lucide-react'
import {
  Badge,
  Button,
  Card,
  CardBody,
  CardHeader,
  EmptyState,
  Pagination,
  Skeleton,
  Tab,
  TabList,
  Tabs,
} from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useDeleteNotification, useMarkAllRead, useMarkRead, useNotifications } from '../hooks/useNotifications'
import { formatRelativeTime, notificationTypeIcon } from '../components/notificationMeta'
import type { Notification, NotificationReadFilter, NotificationsQuery } from '../types'

type TabValue = 'all' | NotificationReadFilter
const VALID_TABS: TabValue[] = ['all', 'unread', 'read']

export function NotificationsPage() {
  const { t } = useTranslation('notifications')
  const [searchParams, setSearchParams] = useSearchParams()
  const navigate = useNavigate()

  const rawTab = searchParams.get('tab')
  const tab: TabValue = (VALID_TABS as string[]).includes(rawTab ?? '') ? (rawTab as TabValue) : 'all'
  const page = Number(searchParams.get('page') ?? '1') || 1

  function updateParams(patch: Record<string, string | null>) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      return next
    })
  }

  function switchTab(nextTab: string) {
    if (nextTab === tab) return
    updateParams({ tab: nextTab === 'all' ? null : nextTab, page: '1' })
  }

  const query: NotificationsQuery = {
    read: tab === 'all' ? undefined : tab,
    page,
  }

  const { data, isLoading, isError } = useNotifications(query)
  const markRead = useMarkRead()
  const markAllRead = useMarkAllRead()
  const deleteNotification = useDeleteNotification()

  const notifications = data?.data ?? []
  const pagination = data?.meta.pagination
  const isEmpty = !isLoading && !isError && notifications.length === 0

  function handleSelect(notification: Notification) {
    if (!notification.read_at) {
      markRead.mutate(notification.id)
    }
    navigate(notification.link)
  }

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('notifications:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('notifications:breadcrumb.notifications')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('notifications:page.title')}
          subtitle={pagination ? t('notifications:page.subtitle', { count: pagination.total }) : undefined}
          action={
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<CheckCheck className="size-4" aria-hidden="true" />}
              onClick={() => markAllRead.mutate()}
              loading={markAllRead.isPending}
            >
              {t('notifications:page.markAllRead')}
            </Button>
          }
        />
        <CardBody noPadding>
          <Tabs value={tab} onValueChange={switchTab}>
            <TabList className="px-5 pt-3">
              <Tab value="all">{t('notifications:page.tabs.all')}</Tab>
              <Tab value="unread">{t('notifications:page.tabs.unread')}</Tab>
              <Tab value="read">{t('notifications:page.tabs.read')}</Tab>
            </TabList>
          </Tabs>

          {isLoading ? (
            <div className="flex flex-col gap-3 p-4" aria-busy="true">
              {Array.from({ length: 6 }).map((_, index) => (
                <div key={index} className="flex items-center gap-3">
                  <Skeleton variant="circle" width={36} height={36} />
                  <div className="min-w-0 flex-1">
                    <Skeleton variant="text" lines={2} />
                  </div>
                </div>
              ))}
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<Bell className="size-6" aria-hidden="true" />}
              title={t('notifications:page.emptyTitle')}
              description={tab === 'unread' ? t('notifications:page.emptyUnread') : t('notifications:page.emptyAll')}
              className="px-6 py-12"
            />
          ) : (
            <ul className="flex flex-col divide-y divide-border-subtle">
              {notifications.map((notification) => {
                const Icon = notificationTypeIcon(notification.type)
                const isUnread = !notification.read_at

                return (
                  <li
                    key={notification.id}
                    className={cn('flex items-start gap-3 px-4 py-3', isUnread && 'bg-primary-tint')}
                  >
                    <button
                      type="button"
                      onClick={() => handleSelect(notification)}
                      className="flex min-w-0 flex-1 items-start gap-3 text-left"
                    >
                      <span
                        className={cn(
                          'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full',
                          isUnread ? 'bg-surface-1 text-primary' : 'bg-surface-2 text-fg-muted'
                        )}
                      >
                        <Icon className="size-4" aria-hidden="true" />
                      </span>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <p
                            className={cn(
                              'truncate text-sm',
                              isUnread ? 'font-semibold text-fg' : 'font-medium text-fg'
                            )}
                          >
                            {notification.title}
                          </p>
                          {isUnread && (
                            <Badge variant="primary" size="sm" dot>
                              {t('notifications:page.newBadge')}
                            </Badge>
                          )}
                        </div>
                        <p className="mt-0.5 text-sm text-fg-muted">{notification.body}</p>
                        <p className="mt-1 text-xs text-fg-muted">{formatRelativeTime(notification.created_at)}</p>
                      </div>
                    </button>
                    <button
                      type="button"
                      onClick={() => deleteNotification.mutate(notification.id)}
                      disabled={deleteNotification.isPending}
                      aria-label={t('notifications:page.deleteAria')}
                      className={cn(
                        'mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md text-fg-muted',
                        'hover:bg-surface-2 hover:text-danger disabled:cursor-not-allowed disabled:opacity-50',
                        'transition-colors duration-150 motion-reduce:transition-none',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
                      )}
                    >
                      <Trash2 className="size-4" aria-hidden="true" />
                    </button>
                  </li>
                )
              })}
            </ul>
          )}

          {pagination && pagination.total > 0 && (
            <div className="border-t border-border-subtle px-4 py-3">
              <Pagination
                currentPage={pagination.current_page}
                totalItems={pagination.total}
                pageSize={pagination.per_page}
                onPageChange={(nextPage) => updateParams({ page: String(nextPage) })}
              />
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
