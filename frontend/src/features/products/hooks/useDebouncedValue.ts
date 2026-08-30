// Genel amaçlı debounce hook'u — arama kutusu tetikleyicisi için. Aynı desen
// `deals/hooks/useDebouncedValue.ts` (ve `leads`/`contacts`/`companies`) içinde de var; her
// modül kendi kopyasını taşıyor (bkz. bu dosyaların üstündeki notlar) — burada da AYNI
// yaklaşım izlenir, `features/products/` ve `features/price-lists/` bu tek kopyayı paylaşır.
import { useEffect, useState } from 'react'

export function useDebouncedValue<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timeout = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timeout)
  }, [value, delay])
  return debounced
}
