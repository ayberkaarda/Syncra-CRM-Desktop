// In-app görev hatırlatıcısı — `private-user.{id}` kanalı, `.task.reminder` olayı.
//
// Abonelik/temizlik deseni `useRealtimeSession.ts` (`src/features/auth/hooks/`) ile AYNI kanala
// AYRI bir dinleyici kurar (bkz. görev tanımı §"Hatırlatıcı dinleyicisi": "useRealtimeSession.ts'i
// DEĞİŞTİRME — kendi hook'unu yaz, aynı kanala ayrı dinleyici kurulabilir"). İki hook da
// `App.tsx`'te `AuthBootstrap` içinde AYNI ömürle (aynı `userId` bağımlılığıyla) mount
// edilir — ikisi de kullanıcı değişince/logout'ta AYNI ANDA temizlenir, bu yüzden ikisinin de
// `echo.leave(channelName)` çağırması güvenlidir (bkz. `useRealtimeSession.ts`'teki analog yorum).
//
// `AuthBootstrap`, `RouterProvider`'ın KARDEŞİDİR (React ağacında İÇİNDE değil) — bu yüzden
// `useNavigate()` hook'u burada KULLANILAMAZ, `useRealtimeSession.ts` ile aynı gerekçeyle
// `router.navigate()` imperatif API'si kullanılır.
import { useEffect } from 'react'
import { getEcho } from '../../../lib/echo'
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

    const echo = getEcho()
    if (!echo) return

    const channelName = `user.${userId}`
    const channel = echo.private(channelName)

    channel.listen(EVENT_NAME, (payload: TaskReminderEvent) => {
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
    })

    return () => {
      echo.leave(channelName)
    }
  }, [userId])
}
