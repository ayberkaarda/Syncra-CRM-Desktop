import { create } from 'zustand'

type NotificationState = {
  unreadCount: number
  setUnreadCount: (count: number) => void
  increment: () => void
}

/**
 * Okunmamış bildirim sayacı — zil rozetinin tek doğruluk kaynağıdır (bkz. `features/auth/store.ts`
 * ile aynı sade zustand deseni). İlk değer `useUnreadCount` sorgusuyla sunucudan alınır
 * (`NotificationBell` mount olduğunda), ardından `useNotificationSocket`'teki gerçek zamanlı
 * `.notification.created` olayının SUNUCU OTORİTELİ `unread_count` alanıyla senkron tutulur —
 * bkz. o dosyanın başındaki not. `increment`, olay yükü herhangi bir sebeple `unread_count`
 * taşımadığı bir kenar durumda iyimser (optimistic) artış için tutulur; normal akışta
 * `setUnreadCount` tercih edilir.
 */
export const useNotificationStore = create<NotificationState>()((set) => ({
  unreadCount: 0,
  setUnreadCount: (count) => set({ unreadCount: Math.max(0, count) }),
  increment: () => set((state) => ({ unreadCount: state.unreadCount + 1 })),
}))
