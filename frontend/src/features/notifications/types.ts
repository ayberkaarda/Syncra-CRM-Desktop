// Bildirimler modülü tipleri — Faz 10 görev tanımındaki "BACKEND SÖZLEŞMESİ" bölümüyle birebir
// eşleşir. Alan adları BAĞLAYICIDIR, dokümandaki isimlerin dışına çıkılmaz.

export const NOTIFICATION_TYPES = [
  'deal.assigned',
  'deal.stage_changed',
  'deal.won',
  'deal.lost',
  'task.assigned',
  'task.reminder',
  'ticket.assigned',
  'ticket.sla_warning',
  'ticket.sla_breached',
  'lead.assigned',
  'quote.status_changed',
] as const
export type NotificationType = (typeof NOTIFICATION_TYPES)[number]

/**
 * Bildirim nesnesi (`GET /api/notifications`, `GET /api/notifications/{id}` yerine tek uçlu
 * REST kaynağı). `link` frontend rotasıdır (ör. `/deals/123`) — bileşenler doğrudan
 * `navigate(notification.link)` ile kullanır.
 */
export type Notification = {
  id: string
  type: NotificationType
  title: string
  body: string
  link: string
  meta: Record<string, unknown>
  read_at: string | null
  created_at: string
}

/** `features/tickets/types.ts`'teki `Pagination` ile aynı zarf şekli. */
export type Pagination = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type NotificationsListResponse = {
  data: Notification[]
  meta: { pagination: Pagination }
}

export type NotificationReadFilter = 'unread' | 'read'

/** `GET /api/notifications?filter[read]=unread|read&page=1` sorgu parametreleri. */
export type NotificationsQuery = {
  read?: NotificationReadFilter
  page?: number
}

export type UnreadCountResponse = {
  unread_count: number
}

/**
 * `private-user.{currentUserId}` kanalındaki `.notification.created` yükü (baştaki nokta
 * ZORUNLU — sunucu `broadcastAs` kullanıyor). Bildirim alanları + güncel okunmamış sayısı;
 * `unread_count` SUNUCU OTORİTELİDİR, istemci kendi sayaç aritmetiği yapmaz — bkz.
 * `hooks/useNotificationSocket.ts`.
 */
export type NotificationCreatedEvent = Notification & {
  unread_count: number
}
