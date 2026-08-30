import { useLayoutEffect, useState } from 'react'
import { useThemeStore } from '../stores/themeStore'

type ResolvedTheme = 'light' | 'dark'

function getSystemPrefersDark(): boolean {
  if (typeof window === 'undefined' || !window.matchMedia) return false
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

export function useTheme() {
  const theme = useThemeStore((state) => state.theme)
  const setTheme = useThemeStore((state) => state.setTheme)

  // Tracks live OS preference; kept in sync regardless of `theme` so that
  // switching back to 'system' never shows a stale value.
  const [systemPrefersDark, setSystemPrefersDark] = useState(getSystemPrefersDark)

  useLayoutEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    const handleChange = () => setSystemPrefersDark(mediaQuery.matches)
    mediaQuery.addEventListener('change', handleChange)
    return () => mediaQuery.removeEventListener('change', handleChange)
  }, [])

  const resolvedTheme: ResolvedTheme =
    theme === 'system' ? (systemPrefersDark ? 'dark' : 'light') : theme

  // Applied in a layout effect (not a plain effect) so the `data-theme`
  // attribute is written before paint — avoids a flash of the wrong theme.
  useLayoutEffect(() => {
    document.documentElement.setAttribute('data-theme', resolvedTheme)
  }, [resolvedTheme])

  return { theme, resolvedTheme, setTheme }
}
