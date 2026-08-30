// Üst bar — hamburger (sidebar toggle), arama, tema değiştirici, bildirim zili (yer tutucu),
// kullanıcı menüsü. ~56px yükseklik (bkz. docs/DESIGN-SYSTEM.md §6/§7).
import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { LogOut, Menu, Monitor, Moon, Search, Sun, User as UserIcon, WifiOff } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Avatar } from '../ui'
import { cn } from '../../lib/cn'
import { useAuth } from '../../features/auth/hooks/useAuth'
import { useTheme } from '../../hooks/useTheme'
import type { Theme } from '../../stores/themeStore'
import { onConnectionStateChange } from '../../lib/echo'
import { OnlineUsersPopover } from '../../features/presence/components/OnlineUsersPopover'
import { NotificationBell } from '../../features/notifications'
import { LanguageSwitcher } from '../../i18n/LanguageSwitcher'
import { CurrencySwitcher } from '../../features/preferences'

const THEME_SEQUENCE: Theme[] = ['light', 'dark', 'system']

// Klavye kısayolu ipucu — bir dil metni DEĞİL, klavye gösterimi (bkz. features/search); bu
// yüzden çeviri anahtarı gerektirmez, yalnızca platforma göre değişir.
const IS_MAC = typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform)
const SEARCH_SHORTCUT_HINT = IS_MAC ? '⌘K' : 'Ctrl K'

// Etiketler ANAHTAR olarak durur, çevrilmiş metin olarak değil — modül seviyesindeki bir
// sabit `t()` çağırsaydı metin ilk dile donardı (aynı gerekçe: Sidebar NAV_SECTIONS).
const THEME_META: Record<Theme, { icon: typeof Sun; labelKey: string }> = {
  light: { icon: Sun, labelKey: 'layout.theme.light' },
  dark: { icon: Moon, labelKey: 'layout.theme.dark' },
  system: { icon: Monitor, labelKey: 'layout.theme.system' },
}

type TopbarProps = {
  onToggleSidebar: () => void
  sidebarCollapsed: boolean
  /** Global komut paletini açar (Faz 14 / İz F / C1) — bkz. `AppLayout.tsx` tek mount noktası. */
  onOpenSearch: () => void
}

export function Topbar({ onToggleSidebar, sidebarCollapsed, onOpenSearch }: TopbarProps) {
  const { user, logout } = useAuth()
  const { t } = useTranslation('common')
  const { theme, setTheme } = useTheme()
  const [menuOpen, setMenuOpen] = useState(false)
  const [realtimeConnected, setRealtimeConnected] = useState(false)
  const menuRef = useRef<HTMLDivElement | null>(null)
  const menuButtonRef = useRef<HTMLButtonElement | null>(null)
  const logoutButtonRef = useRef<HTMLButtonElement | null>(null)

  const ThemeIcon = THEME_META[theme].icon

  // Bağlantı durumu sessiz kalır (yeşil nokta spam'i yok) — yalnızca Echo
  // bağlı DEĞİLKEN küçük bir uyarı gösterir.
  useEffect(() => onConnectionStateChange((state) => setRealtimeConnected(state === 'connected')), [])

  function cycleTheme() {
    const currentIndex = THEME_SEQUENCE.indexOf(theme)
    setTheme(THEME_SEQUENCE[(currentIndex + 1) % THEME_SEQUENCE.length])
  }

  // Dışarı tıklayınca / Esc ile kapanma + açılışta odağı ilk aktif öğeye taşıma.
  useEffect(() => {
    if (!menuOpen) return
    logoutButtonRef.current?.focus()

    function handleClickOutside(event: MouseEvent) {
      if (!menuRef.current?.contains(event.target as Node)) {
        setMenuOpen(false)
      }
    }
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setMenuOpen(false)
        menuButtonRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [menuOpen])

  // Menü içinde ok tuşlarıyla gezinme (yalnızca etkin — disabled olmayan — öğeler arasında).
  function handleMenuKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return
    const items = Array.from(menuRef.current?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]:not(:disabled)') ?? [])
    if (items.length === 0) return
    event.preventDefault()
    const currentIndex = items.indexOf(document.activeElement as HTMLButtonElement)
    const delta = event.key === 'ArrowDown' ? 1 : -1
    const nextIndex = (currentIndex + delta + items.length) % items.length
    items[nextIndex]?.focus()
  }

  async function handleLogout() {
    setMenuOpen(false)
    await logout()
  }

  const iconButtonClass = cn(
    'inline-flex size-9 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
    'transition-colors duration-150 motion-reduce:transition-none',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
  )

  return (
    <header className="flex h-14 shrink-0 items-center gap-3 border-b border-border-subtle bg-surface-1 px-4">
      <button
        type="button"
        onClick={onToggleSidebar}
        aria-label={t('layout.toggleSidebar')}
        aria-expanded={!sidebarCollapsed}
        className={iconButtonClass}
      >
        <Menu className="size-5" aria-hidden="true" />
      </button>

      {/* Gerçek bir metin girişi DEĞİL — tıklanınca global komut paletini açan bir tetikleyici
          (Faz 14 / İz F / C1, bkz. `AppLayout.tsx`). Yazma burada değil, palet içinde olur;
          bu yüzden `<Input>` yerine anlamsal olarak doğru olan `<button>` kullanılıyor. */}
      <button
        type="button"
        onClick={onOpenSearch}
        aria-label={t('layout.search')}
        className={cn(
          'flex h-8 w-full max-w-xs items-center gap-2 rounded-md border border-border-strong bg-surface-2 px-3 text-xs text-fg-muted',
          'transition-colors duration-150 hover:bg-surface-3 motion-reduce:transition-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
        )}
      >
        <Search className="size-4 shrink-0" aria-hidden="true" />
        <span className="truncate">{t('layout.searchPlaceholder')}</span>
        <span className="ml-auto hidden shrink-0 rounded border border-border-subtle px-1.5 py-0.5 font-mono text-[10px] sm:inline-block">
          {SEARCH_SHORTCUT_HINT}
        </span>
      </button>

      <div className="ml-auto flex items-center gap-1.5">
        <LanguageSwitcher />
        <CurrencySwitcher />

        <button
          type="button"
          onClick={cycleTheme}
          aria-label={t('layout.themeAria', { theme: t(THEME_META[theme].labelKey) })}
          className={iconButtonClass}
        >
          <ThemeIcon className="size-4" aria-hidden="true" />
        </button>

        {!realtimeConnected && (
          <span
            title={t('layout.realtimeDisconnected')}
            aria-label={t('layout.realtimeDisconnected')}
            role="status"
            className="inline-flex size-9 shrink-0 items-center justify-center text-warning"
          >
            <WifiOff className="size-4" aria-hidden="true" />
          </span>
        )}

        <OnlineUsersPopover />

        <NotificationBell />

        <div ref={menuRef} className="relative">
          <button
            ref={menuButtonRef}
            type="button"
            onClick={() => setMenuOpen((prev) => !prev)}
            aria-haspopup="menu"
            aria-expanded={menuOpen}
            className={cn(
              'flex items-center gap-2 rounded-md py-1 pl-1 pr-2 hover:bg-surface-2',
              'transition-colors duration-150 motion-reduce:transition-none',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
            )}
          >
            <Avatar name={user?.name ?? t('layout.userFallback')} size="sm" />
            <span className="hidden flex-col items-start sm:flex">
              <span className="max-w-[10rem] truncate text-sm font-medium text-fg">{user?.name ?? t('layout.userFallback')}</span>
              <span className="max-w-[10rem] truncate text-xs text-fg-muted">{user?.email ?? ''}</span>
            </span>
          </button>

          {menuOpen && (
            <div
              role="menu"
              aria-label={t('layout.userMenu')}
              onKeyDown={handleMenuKeyDown}
              className="absolute right-0 top-full z-50 mt-2 w-56 rounded-lg border border-border bg-surface-3 py-1.5 shadow-popover"
            >
              <div className="border-b border-border-subtle px-3 pb-2 pt-1 sm:hidden">
                <p className="truncate text-sm font-medium text-fg">{user?.name ?? t('layout.userFallback')}</p>
                <p className="truncate text-xs text-fg-muted">{user?.email ?? ''}</p>
              </div>
              {/* Profil — yer tutucu, sonraki bir fazda bağlanacak. */}
              <button
                type="button"
                role="menuitem"
                disabled
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-fg-disabled disabled:cursor-not-allowed"
              >
                <UserIcon className="size-4" aria-hidden="true" />
                {t('layout.profile')}
              </button>
              <button
                ref={logoutButtonRef}
                type="button"
                role="menuitem"
                onClick={handleLogout}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-danger transition-colors duration-150 hover:bg-surface-2 motion-reduce:transition-none"
              >
                <LogOut className="size-4" aria-hidden="true" />
                {t('layout.logout')}
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  )
}
