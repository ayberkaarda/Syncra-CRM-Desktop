// Bildirim zili — okunmamış rozetiyle (99+ taşması) zil ikonu, tıklayınca son bildirimleri
// gösteren açılır panel. Klavye erişilebilir (Esc kapatır, dışarı tıklayınca kapanır) — açılır
// panel deseni `components/layout/Topbar.tsx`'teki kullanıcı menüsü /
// `features/presence/components/OnlineUsersPopover.tsx` ile AYNIDIR.
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bell, CheckCheck } from 'lucide-react'
import { cn } from '../../../lib/cn'
import { useMarkAllRead, useUnreadCount } from '../hooks/useNotifications'
import { useNotificationStore } from '../store'
import { NotificationList } from './NotificationList'

const OVERFLOW_THRESHOLD = 99

export function NotificationBell() {
  const { t } = useTranslation('notifications')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const triggerRef = useRef<HTMLButtonElement | null>(null)

  const unreadCount = useNotificationStore((state) => state.unreadCount)
  const setUnreadCount = useNotificationStore((state) => state.setUnreadCount)
  const { data: serverUnreadCount } = useUnreadCount()
  const markAllRead = useMarkAllRead()

  // Zil rozetinin İLK değeri sunucudan gelir (bkz. `store.ts` başındaki not) — gerçek zamanlı
  // soket bundan SONRA devralır; yalnızca soket'e güvenilirse sayfa yenilemede rozet sıfır
  // görünür, bu yüzden bu sorgu ŞARTTIR.
  useEffect(() => {
    if (serverUnreadCount !== undefined) {
      setUnreadCount(serverUnreadCount)
    }
  }, [serverUnreadCount, setUnreadCount])

  useEffect(() => {
    if (!open) return

    function handleClickOutside(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open])

  const badgeLabel = unreadCount > OVERFLOW_THRESHOLD ? '99+' : String(unreadCount)

  return (
    <div ref={containerRef} className="relative">
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={unreadCount > 0 ? t('notifications:bell.ariaLabelUnread', { count: unreadCount }) : t('notifications:bell.ariaLabel')}
        className={cn(
          'relative inline-flex size-9 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
          'transition-colors duration-150 motion-reduce:transition-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
        )}
      >
        <Bell className="size-4" aria-hidden="true" />
        {unreadCount > 0 && (
          <span
            aria-hidden="true"
            className="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-none text-white"
          >
            {badgeLabel}
          </span>
        )}
      </button>

      {open && (
        <div
          role="dialog"
          aria-label={t('notifications:bell.dialogAria')}
          className="absolute right-0 top-full z-50 mt-2 w-80 rounded-lg border border-border bg-surface-3 py-2 shadow-popover"
        >
          <div className="flex items-center justify-between px-3 pb-2">
            <p className="text-sm font-medium text-fg">{t('notifications:bell.title')}</p>
            <button
              type="button"
              onClick={() => markAllRead.mutate()}
              disabled={markAllRead.isPending || unreadCount === 0}
              className={cn(
                'inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline',
                'disabled:cursor-not-allowed disabled:text-fg-disabled disabled:no-underline'
              )}
            >
              <CheckCheck className="size-3.5" aria-hidden="true" />
              {t('notifications:bell.markAllRead')}
            </button>
          </div>

          <div className="max-h-96 overflow-y-auto border-y border-border-subtle">
            <NotificationList onNavigate={() => setOpen(false)} />
          </div>

          <div className="px-3 pt-2">
            <Link
              to="/notifications"
              onClick={() => setOpen(false)}
              className="block text-center text-xs font-medium text-primary hover:underline"
            >
              {t('notifications:bell.viewAll')}
            </Link>
          </div>
        </div>
      )}
    </div>
  )
}
