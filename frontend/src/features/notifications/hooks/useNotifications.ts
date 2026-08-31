// Bildirimler TanStack Query katmanı — liste (okunmamış/tümü filtresi), okunmamış sayısı,
// okundu işaretleme, tümünü okundu işaretleme, silme. Mutation'lar sonrası ilgili sorgular
// invalidate edilir (desen: `features/tickets/api/ticketsApi.ts`).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import i18n from '../../../i18n'
import type { NotificationsQuery } from '../types'
import { getPlatform } from '../../../platform'

export const notificationsKeys = {
  all: ['notifications'] as const,
  lists: ['notifications', 'list'] as const,
  list: (query: NotificationsQuery) => ['notifications', 'list', query] as const,
  unreadCount: ['notifications', 'unread-count'] as const,
}

export function useNotifications(query: NotificationsQuery) {
  return useQuery({
    queryKey: notificationsKeys.list(query),
    queryFn: () => getPlatform().data.notifications.list(query),
    placeholderData: keepPreviousData,
  })
}

/**
 * Zil rozetinin İLK değeri — `NotificationBell` mount olduğunda bunu okuyup store'a yazar
 * (bkz. `store.ts` başındaki not). Sayfa yenilemede sıfır görünmemesi için socket'in yanında
 * bu sorgu ŞARTTIR, socket'e tek başına güvenilmez.
 */
export function useUnreadCount() {
  return useQuery({
    queryKey: notificationsKeys.unreadCount,
    queryFn: () => getPlatform().data.notifications.unreadCount(),
  })
}

function invalidateNotificationCaches(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: notificationsKeys.lists })
  void queryClient.invalidateQueries({ queryKey: notificationsKeys.unreadCount })
}

/** Tek bildirimi okundu işaretler. Sessizdir (toast göstermez) — sık tıklanan, düşük riskli bir
 * aksiyon için başarı bildirimi gürültü olurdu; hata olursa yine de bildirilir. */
export function useMarkRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => getPlatform().data.notifications.markRead(id),
    onSuccess: () => invalidateNotificationCaches(queryClient),
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useMarkAllRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => getPlatform().data.notifications.markAllRead(),
    onSuccess: () => {
      invalidateNotificationCaches(queryClient)
      toast.success(i18n.t('notifications:toast.markAllReadSuccess'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useDeleteNotification() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => getPlatform().data.notifications.delete(id),
    onSuccess: () => {
      invalidateNotificationCaches(queryClient)
      toast.success(i18n.t('notifications:toast.deleteSuccess'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
