// Bildirimler modülü barrel export'u (desen: diğer feature'larda ayrı bir `index.ts` yok, ama
// bu modül teknik liderin `AppLayout`/`router.tsx`'e bağlaması için görev tanımınca istendi).
export { NotificationBell } from './components/NotificationBell'
export { NotificationsPage } from './pages/NotificationsPage'
export { useNotificationSocket } from './hooks/useNotificationSocket'
export { useNotificationStore } from './store'
