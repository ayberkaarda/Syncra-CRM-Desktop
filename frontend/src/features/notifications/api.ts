// Bildirimler veri katmanı — yalnızca uç fonksiyonları (bkz. görev tanımı "BACKEND SÖZLEŞMESİ").
// React Query kancaları `hooks/useNotifications.ts`'te; bu dosya saf axios çağrılarını taşır
// (desen: `features/tickets/api/ticketsApi.ts`). Hata gövdesi tüm uçlarda
// `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { api } from '../../lib/axios'
import type { Notification, NotificationsListResponse, NotificationsQuery, UnreadCountResponse } from './types'

export async function fetchNotifications(query: NotificationsQuery): Promise<NotificationsListResponse> {
  const { data } = await api.get<NotificationsListResponse>('/api/notifications', {
    params: {
      'filter[read]': query.read || undefined,
      page: query.page,
    },
  })
  return data
}

export async function fetchUnreadCount(): Promise<number> {
  const { data } = await api.get<{ data: UnreadCountResponse }>('/api/notifications/unread-count')
  return data.data.unread_count
}

export async function markNotificationReadRequest(id: string): Promise<Notification> {
  const { data } = await api.patch<{ data: Notification }>(`/api/notifications/${id}/read`)
  return data.data
}

export async function markAllNotificationsReadRequest(): Promise<void> {
  await api.post('/api/notifications/read-all')
}

export async function deleteNotificationRequest(id: string): Promise<void> {
  await api.delete(`/api/notifications/${id}`)
}
