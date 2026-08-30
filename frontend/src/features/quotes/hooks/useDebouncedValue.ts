// Basit debounce hook'u — `deals/hooks/useDebouncedValue.ts` ve `tickets/hooks/useDebouncedValue.ts`
// ile AYNI desen; her modül kendi (bağımsız) küçük kopyasını taşıyor, bu üçüncüsü teklif modülüne ait.
import { useEffect, useState } from 'react'

export function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delayMs)
    return () => window.clearTimeout(timer)
  }, [value, delayMs])

  return debounced
}
