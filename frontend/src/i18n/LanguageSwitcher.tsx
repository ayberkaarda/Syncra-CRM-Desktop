// Dil seçici — header (Topbar) ve giriş ekranında AYNI bileşen (§1.3).
//
// NEDEN `components/ui` ya da `features/auth` DEĞİL de `src/i18n/` ALTINDA: bu bileşenin tek
// işi i18n çekirdeğinin `setLocale()`ini sürmek ve oturum varsa sunucuya yazmaktır — ne genel
// bir tasarım-sistemi primitifi (ui) ne de auth'a ait bir ekran parçası. i18n sözleşmesiyle
// birlikte yaşaması, dil listesi/etiket şeması değiştiğinde tek klasörde kalmasını sağlar.
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, Languages } from 'lucide-react'
import { cn } from '../lib/cn'
import { SUPPORTED_LOCALES, getActiveLocale, setLocale, type Locale } from './index'
import { updatePreferences } from '../features/auth/api/authApi'
import { useAuthStore } from '../features/auth/store'

type LanguageSwitcherProps = {
  /** Menünün hangi kenara açılacağı — Topbar'da sağ uçta, login'de dar kolonda. */
  align?: 'left' | 'right'
  className?: string
}

export function LanguageSwitcher({ align = 'right', className }: LanguageSwitcherProps) {
  const { t } = useTranslation('common')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const triggerRef = useRef<HTMLButtonElement | null>(null)

  const user = useAuthStore((state) => state.user)
  const setUser = useAuthStore((state) => state.setUser)
  const active = getActiveLocale()

  useEffect(() => {
    if (!open) return

    function handleClickOutside(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
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

  async function choose(locale: Locale) {
    setOpen(false)
    if (locale === active) return

    // ÖNCE arayüz, SONRA sunucu: seçim anında görünür olmalı (pre-auth'ta sunucu ucu zaten
    // erişilemez). Sunucuya yazma başarısız olursa kullanıcı yine seçtiği dilde çalışmaya
    // devam eder — localStorage bu tarayıcıda kalıcılığı sağlar.
    await setLocale(locale)

    if (!user) return
    try {
      const updated = await updatePreferences({ locale })
      setUser(updated)
    } catch {
      // Sessiz: tercih kaydı bir kolaylıktır, oturumun çalışmasını engellememeli.
      // Kullanıcı dili yine görüyor; başka bir cihazda varsayılanı geçerli olur.
    }
  }

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`${t('language.aria')}: ${t(`language.names.${active}`)}`}
        className={cn(
          'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md px-2 text-sm text-fg-muted',
          'hover:bg-surface-2 hover:text-fg',
          'transition-colors duration-150 motion-reduce:transition-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
        )}
      >
        <Languages className="size-4" aria-hidden="true" />
        <span className="uppercase">{active}</span>
      </button>

      {open && (
        <div
          role="menu"
          aria-label={t('language.aria')}
          className={cn(
            'absolute top-full z-50 mt-2 w-40 rounded-lg border border-border bg-surface-3 py-1.5 shadow-popover',
            align === 'right' ? 'right-0' : 'left-0'
          )}
        >
          {SUPPORTED_LOCALES.map((locale) => (
            <button
              key={locale}
              type="button"
              role="menuitemradio"
              aria-checked={locale === active}
              onClick={() => void choose(locale)}
              className={cn(
                'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                'transition-colors duration-150 hover:bg-surface-2 motion-reduce:transition-none',
                locale === active ? 'font-medium text-fg' : 'text-fg-secondary'
              )}
            >
              <Check
                className={cn('size-4 shrink-0', locale === active ? 'opacity-100' : 'opacity-0')}
                aria-hidden="true"
              />
              {t(`language.names.${locale}`)}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
