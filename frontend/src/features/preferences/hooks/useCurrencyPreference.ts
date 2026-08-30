// Para birimi tercihi kancası — `CurrencySwitcher` bileşeninin durum/mutasyon mantığı (Faz 14 /
// İz E — docs/PHASE-INTL.md §2.3). `LanguageSwitcher`in yaptığı gibi doğrudan store'u okur
// (kimlik doğrulanmış oturum varsayımıyla — GÖREV 1: pre-auth ekranda para gösterimi YOK,
// bu yüzden `useAuthStore`un `user`ı `null` iken bileşen zaten render EDİLMEZ).
import { useTranslation } from 'react-i18next'
import { toast } from '../../../components/ui'
import { useAuthStore } from '../../auth/store'
import { useUpdatePreferredCurrency } from '../../auth/hooks/useAuth'
import { isSupportedCurrency, type SupportedCurrency } from '../constants'

export function useCurrencyPreference() {
  const { t } = useTranslation('common')
  const user = useAuthStore((state) => state.user)
  const setUser = useAuthStore((state) => state.setUser)
  const mutation = useUpdatePreferredCurrency()

  const active: SupportedCurrency = isSupportedCurrency(user?.preferred_currency)
    ? user.preferred_currency
    : 'TRY'

  function choose(currency: SupportedCurrency) {
    if (!user || currency === active) return

    const previous = user

    // İYİMSER GÜNCELLEME: rapor/dashboard ekranları `user.preferred_currency`yi anında okur
    // (bkz. `features/reports/**`, `features/dashboard/**`) — sunucu yazması bitene kadar
    // beklemek seçimi donuk hissettirir.
    setUser({ ...user, preferred_currency: currency })

    mutation.mutate(currency, {
      onError: () => {
        // Dil seçicisinden FARKLI olarak burada SESSİZ kalınmaz — gerekçe:
        // `useUpdatePreferredCurrency` docblock'u (features/auth/hooks/useAuth.ts).
        setUser(previous)
        toast.error(t('currency.saveFailed'))
      },
    })
  }

  return { active, choose, isAuthenticated: user !== null }
}
