// Uygulama kabuğu — Sidebar + Topbar + sayfa içeriği (`<Outlet />`).
// Sidebar açık/kapalı (collapsed) durumu localStorage'a persist edilir.
// `lg` altında sidebar overlay (backdrop ile kapanan off-canvas panel), `lg` üstünde sabit
// genişlikte akışa dahil bir panel olarak davranır.
import { useCallback, useEffect, useState } from 'react'
import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'
import { usePageTracking } from '../../hooks/usePageTracking'
import { useNotificationSocket } from '../../features/notifications'
import { CommandPalette, useCommandPaletteShortcut } from '../../features/search'

const SIDEBAR_STORAGE_KEY = 'syncra-sidebar'
const DESKTOP_MEDIA_QUERY = '(min-width: 1024px)' // Tailwind `lg` kırılım noktası

function readStoredCollapsed(): boolean {
  if (typeof window === 'undefined') return false
  try {
    return window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1'
  } catch {
    // localStorage erişilemiyorsa (gizli sekme/izin kısıtı) varsayılan: açık.
    return false
  }
}

export function AppLayout() {
  // AppLayout yalnızca kimliği doğrulanmış rotaları sarmaladığı için sayfa
  // gezinme takibinin mount edildiği doğru yer burası (bkz. usePageTracking.ts).
  usePageTracking()
  useNotificationSocket()

  const [collapsed, setCollapsed] = useState<boolean>(readStoredCollapsed)
  const [mobileOpen, setMobileOpen] = useState(false)

  // Global komut paleti (Ctrl+K / Cmd+K) — Faz 14 / İz F / C1. Tek mount noktası burası:
  // `AppLayout` kimliği doğrulanmış TÜM rotaları sarmalar (bkz. router.tsx), bu yüzden
  // "her sayfada" gereksinimi buradan karşılanır. Açılış tetikleyicileri: (1) global kısayol
  // (bu hook), (2) Topbar'daki arama kutusu tıklaması (`onOpenSearch` prop'u).
  const [paletteOpen, setPaletteOpen] = useState(false)
  const openPalette = useCallback(() => setPaletteOpen(true), [])
  const closePalette = useCallback(() => setPaletteOpen(false), [])
  useCommandPaletteShortcut(openPalette)

  useEffect(() => {
    try {
      window.localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? '1' : '0')
    } catch {
      // Sessizce yok say — kalıcılık yalnızca bir kolaylık, kritik değil.
    }
  }, [collapsed])

  // Hamburger: masaüstünde daralt/genişlet, mobilde overlay'i aç/kapat.
  const toggleSidebar = useCallback(() => {
    const isDesktop = typeof window !== 'undefined' && window.matchMedia(DESKTOP_MEDIA_QUERY).matches
    if (isDesktop) {
      setCollapsed((prev) => !prev)
    } else {
      setMobileOpen((prev) => !prev)
    }
  }, [])

  const closeMobileSidebar = useCallback(() => setMobileOpen(false), [])

  return (
    <div className="flex h-screen overflow-hidden bg-surface-0">
      <Sidebar collapsed={collapsed} mobileOpen={mobileOpen} onCloseMobile={closeMobileSidebar} />

      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar onToggleSidebar={toggleSidebar} sidebarCollapsed={collapsed} onOpenSearch={openPalette} />

        {/* Sayfa gövdesi yatay kaymaz; geniş içerik (tablo vb.) kendi overflow-x-auto sarmalayıcısında kayar. */}
        <main className="flex-1 overflow-y-auto overflow-x-hidden p-5">
          <Outlet />
        </main>
      </div>

      <CommandPalette open={paletteOpen} onClose={closePalette} />
    </div>
  )
}
