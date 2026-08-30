// Genel amaçlı debounce hook'u — arama kutusu ve duplicate kontrolü tetikleyicileri
// için kullanılır (bkz. `UsersPage`'deki eşdeğer desen).
import { useEffect, useState } from 'react'

export function useDebouncedValue<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timeout = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timeout)
  }, [value, delay])
  return debounced
}
