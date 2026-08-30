// Chat modülü barrel export'u — teknik lider `router.tsx`/`AppLayout` gibi yerlere bağlamak için
// kullanır (desen: `features/notifications/index.ts`). `hooks/` altında bir barrel (`index.ts`)
// YOK — her kanca kendi dosyasından tek tek re-export edilir.
export { ChatPage } from './pages/ChatPage'
export { useChatUnread } from './hooks/useChatUnread'
export { useChatSocket } from './hooks/useChatSocket'

// Kayda bağlı sohbet paneli — `record/` klasörünü BAŞKA bir şerit yazdı (kendi `record/index.ts`
// barrel'ı var). Bu satır YALNIZCA barrel'da yer açar.
export { RecordChatPanel } from './record'
