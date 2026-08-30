// Para birimi seçici — `i18n/LanguageSwitcher.tsx`in KARDEŞİ, birebir aynı yerleşim/etkileşim
// disiplininde (GÖREV 1). Header (`Topbar`) kullanıcı menüsü civarında yaşar.
//
// NEDEN LOGIN EKRANINDA YOK (dil seçicinin AKSİNE): dil seçici pre-auth'ta gerekli çünkü giriş
// ekranının KENDİ metni (form etiketleri, hata mesajları) bir dilde gösterilir — kullanıcı daha
// oturum açmadan hangi dilde okuyacağını seçer. Para birimi ise yalnızca rapor/dashboard/kayıt
// TUTARLARININ hangi para biriminde GÖRÜNTÜLENECEĞİNİ belirler (§1.8 — VERİ ekseni, dil gibi bir
// ARAYÜZ ekseni değil); pre-auth ekranda hiçbir tutar/para gösterimi yoktur (yalnızca e-posta/
// şifre formu), dolayısıyla seçilecek bir şey de yoktur. Bu yüzden bileşen `user` yokken (oturum
// açılmadan) hiç render EDİLMEZ — `Topbar` zaten yalnızca kimliği doğrulanmış düzende mount edilir,
// ama `isAuthenticated` koruması burada da tekrarlanır ki bileşen başka bir yerde yanlışlıkla
// pre-auth bağlamda kullanılırsa sessizce hiçbir şey render etmesin (boş bir çarpı yerine).
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, Coins } from 'lucide-react'
import { cn } from '../../../lib/cn'
import { SUPPORTED_CURRENCIES } from '../constants'
import { useCurrencyPreference } from '../hooks/useCurrencyPreference'

export type CurrencySwitcherProps = {
  /** Menünün hangi kenara açılacağı — `LanguageSwitcher` ile aynı sözleşim. */
  align?: 'left' | 'right'
  className?: string
}

export function CurrencySwitcher({ align = 'right', className }: CurrencySwitcherProps) {
  const { t } = useTranslation('common')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const triggerRef = useRef<HTMLButtonElement | null>(null)

  const { active, choose, isAuthenticated } = useCurrencyPreference()

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

  // Pre-auth'ta hiç render edilmez — bkz. dosya başı yorumu.
  if (!isAuthenticated) return null

  function select(currency: (typeof SUPPORTED_CURRENCIES)[number]) {
    setOpen(false)
    choose(currency)
  }

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`${t('currency.aria')}: ${t(`currency.names.${active}`)}`}
        className={cn(
          'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md px-2 text-sm text-fg-muted',
          'hover:bg-surface-2 hover:text-fg',
          'transition-colors duration-150 motion-reduce:transition-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
        )}
      >
        <Coins className="size-4" aria-hidden="true" />
        <span className="uppercase">{active}</span>
      </button>

      {open && (
        <div
          role="menu"
          aria-label={t('currency.aria')}
          className={cn(
            'absolute top-full z-50 mt-2 w-48 rounded-lg border border-border bg-surface-3 py-1.5 shadow-popover',
            align === 'right' ? 'right-0' : 'left-0'
          )}
        >
          {SUPPORTED_CURRENCIES.map((currency) => (
            <button
              key={currency}
              type="button"
              role="menuitemradio"
              aria-checked={currency === active}
              onClick={() => select(currency)}
              className={cn(
                'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                'transition-colors duration-150 hover:bg-surface-2 motion-reduce:transition-none',
                currency === active ? 'font-medium text-fg' : 'text-fg-secondary'
              )}
            >
              <Check
                className={cn('size-4 shrink-0', currency === active ? 'opacity-100' : 'opacity-0')}
                aria-hidden="true"
              />
              <span className="flex min-w-0 flex-1 items-center justify-between gap-2">
                <span className="truncate">{t(`currency.names.${currency}`)}</span>
                <span className="shrink-0 font-mono text-xs uppercase text-fg-muted">{currency}</span>
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
