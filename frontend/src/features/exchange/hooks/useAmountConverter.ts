// Kaydın kendi para birimindeki bir tutarı kullanıcının `preferred_currency`'sine çevirmek
// için tek giriş noktası (Faz 14 / İz E, GÖREV 2). `useCurrentExchangeRates` React Query
// önbelleğini okur (bkz. `exchangeRatesApi.ts` — oturum boyunca pratikte tek ağ isteği) ve
// saf `computeConversion`'ı kullanıcının tercih ettiği para birimiyle çağırır.
import { useAuthStore } from '../../auth/store'
import { isSupportedCurrency } from '../../preferences/constants'
import { useCurrentExchangeRates } from '../api/exchangeRatesApi'
import { computeConversion } from '../convert'
import type { AmountConversion } from '../convert'

export type UseAmountConverterResult = {
  /** `sourceCurrency` (kaydın kendi para birimi) → kullanıcı tercihi dönüşüm bilgisi. */
  convert: (sourceCurrency: string) => AmountConversion
  /** Kur verisi henüz gelmediyse `true` — bu sürede "çevrilemiyor" iddiası ERKEN davranış
   *  olur (§2.6 disiplini: bilinmeyen ile "kur yok" ayrı şeylerdir), çağıran taraf bu süre
   *  boyunca yalnızca kaydın kendi para biriminde göstermeli, uyarı BASMAMALI. */
  isLoading: boolean
}

export function useAmountConverter(): UseAmountConverterResult {
  const { data, isLoading } = useCurrentExchangeRates()
  const user = useAuthStore((state) => state.user)
  const preferredCurrency = isSupportedCurrency(user?.preferred_currency) ? user.preferred_currency : 'TRY'

  return {
    convert: (sourceCurrency: string) => computeConversion(data, preferredCurrency, sourceCurrency),
    isLoading,
  }
}
