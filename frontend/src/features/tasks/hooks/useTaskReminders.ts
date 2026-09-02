// In-app görev hatırlatıcısı — `private-user.{id}` kanalı, `.task.reminder` olayı.
//
// AYNI `user.{id}` kanalını `useRealtimeSession.ts`, `useNotificationSocket.ts` ve
// `useChatUnread.ts` da dinliyor (bkz. görev tanımı §"Hatırlatıcı dinleyicisi":
// "useRealtimeSession.ts'i DEĞİŞTİRME — kendi hook'unu yaz, aynı kanala ayrı dinleyici
// kurulabilir"). `Echo.leave()` referans SAYMADIĞI için doğrudan çağrılsaydı bu diğer
// abonelerin dinleyicilerini de düşürürdü — bu yüzden kanal PAYLAŞILAN, referans sayan
// `src/lib/channelRegistry.ts` üzerinden alınır/bırakılır; unmount'ta yalnızca KENDİ
// dinleyicimiz `channel.stopListening()` ile bırakılır, kanalın kendisi sayaç sıfıra inince
// gerçekten bırakılır (`releaseChannel`).
//
// `AuthBootstrap`, `RouterProvider`'ın KARDEŞİDİR (React ağacında İÇİNDE değil) — bu yüzden
// `useNavigate()` hook'u burada KULLANILAMAZ, `useRealtimeSession.ts` ile aynı gerekçeyle
// `router.navigate()` imperatif API'si kullanılır.
import { useEffect } from 'react'
import { acquireChannel, releaseChannel } from '../../../lib/channelRegistry'
import { toast } from '../../../components/ui'
import { useAuthStore } from '../../auth/store'
import { router } from '../../../router'
import i18n, { getIntlLocale } from '../../../i18n'
import type { TaskReminderEvent } from '../types'

const EVENT_NAME = '.task.reminder'

function formatDueAt(iso: string): string {
  try {
    return new Intl.DateTimeFormat(getIntlLocale(), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return iso
  }
}

export function useTaskReminders() {
  const userId = useAuthStore((state) => state.user?.id)

  useEffect(() => {
    if (!userId) return

    const channelName = `user.${userId}`
    const channel = acquireChannel(channelName)
    if (!channel) return

    const handleReminder = (payload: TaskReminderEvent) => {
      const descriptionParts = [i18n.t('tasks:reminders.dueLabel', { date: formatDueAt(payload.due_at) })]
      if (payload.taskable_label) descriptionParts.push(payload.taskable_label)

      toast.info(payload.title, {
        description: descriptionParts.join(' · '),
        action: {
          label: i18n.t('tasks:reminders.goToTask'),
          // `?highlight=<task_id>` — `TasksPage`'in liste görünümü bu parametreyi tüketip
          // ilgili satırı kısa süreliğine vurgular (bkz. o dosyadaki efekt). Başlıkla arama
          // (`?q=`) YERİNE id kullanılır: aynı başlıklı iki görev varsa arama ikisini de
          // getirir ve kullanıcı hangisinin hatırlatıldığını ayırt edemez, id ise tekildir.
          //
          // Kullanıcı ZATEN `/tasks` üzerindeyse mevcut arama/filtre durumu KORUNUR (yalnızca
          // `view`/`highlight` eklenir/üzerine yazılır) — başka bir sayfadaysa filtre
          // "koruyacak" bir durum yoktur, temiz bir `?view=list&highlight=` ile açılır.
          onClick: () => {
            const currentLocation = router.state.location
            const onTasksPage = currentLocation.pathname === '/tasks'
            const params = new URLSearchParams(onTasksPage ? currentLocation.search : '')
            params.set('view', 'list')
            params.set('highlight', String(payload.task_id))
            void router.navigate(`/tasks?${params.toString()}`)
          },
        },
      })
    }

    channel.listen(EVENT_NAME, handleReminder)

    return () => {
      channel.stopListening(EVENT_NAME, handleReminder)
      releaseChannel(channelName)
    }
  }, [userId])
}
