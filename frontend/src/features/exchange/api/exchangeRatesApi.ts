// `GET /api/exchange-rates/current` veri katmanı (Faz 14 / İz E, GÖREV 1/2). Yalnız
// `auth:sanctum` gerektirir — kimliği doğrulanmış HER kullanıcı çağırabilir (bkz. controller
// docblock'u), bu yüzden ek bir izin kontrolü burada YOK.
//
// NEDEN UZUN `staleTime`: TCMB kurları günde bir (16:00 zamanlanmış görevle) değişir — her
// sayfa/bileşenin kendi `useQuery`'si aynı `queryKey`'i paylaştığı için bu tek bir ağ isteğine
// karşılık gelir (React Query önbellek dedup'ı); `staleTime` boyunca sekme değiştirme/yeniden
// odaklanma yeniden çekmeyi TETİKLEMEZ — oturum boyunca pratikte tek sorgu.
import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/axios'
import type { ExchangeRatesCurrentResponse } from '../types'

export const exchangeRatesKeys = {
  current: ['exchange-rates', 'current'] as const,
}

async function fetchCurrentExchangeRates(): Promise<ExchangeRatesCurrentResponse> {
  const { data } = await api.get<ExchangeRatesCurrentResponse>('/api/exchange-rates/current')
  return data
}

const STALE_TIME_MS = 12 * 60 * 60 * 1000 // 12 saat — kurlar günde bir değişir.

export function useCurrentExchangeRates() {
  return useQuery({
    queryKey: exchangeRatesKeys.current,
    queryFn: fetchCurrentExchangeRates,
    staleTime: STALE_TIME_MS,
    gcTime: STALE_TIME_MS,
    refetchOnWindowFocus: false,
  })
}
