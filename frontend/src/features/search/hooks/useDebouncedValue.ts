// Genel amaçlı debounce hook'u — diğer feature'lardaki aynı desenin bağımsız kopyası
// (bkz. `features/companies/hooks/useDebouncedValue.ts` — merkezi bir `lib/` hook'u YOK,
// her feature kendi küçük kopyasını taşıyor; bu dosya o kararı DEĞİŞTİRMEZ).
import { useEffect, useState } from 'react'

export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timeout = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timeout)
  }, [value, delay])
  return debounced
}
