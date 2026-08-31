// Desktop chrome — everything `SYNCDESKTOP.md` §7.2 adds that the web app does not have.
//
// Wraps `App` rather than reaching into it (see the KARAR block in `DesktopPanel.tsx` for why
// there are no router routes). `App` renders its own `QueryClientProvider` and `Toaster`; this
// layer sits OUTSIDE both, which is why nothing under `ui/` uses TanStack Query — those screens
// read Tauri commands directly, and `@tanstack/react-query` does not resolve from `desktop/src`
// anyway (`bridge/events.ts` records the same constraint for the same reason).
//
// The `toast` calls in these screens DO reach the app's `Toaster`: `components/ui/Toast`'s
// store is a module singleton, so a toast raised from outside the tree still renders inside it.
import { useCallback, useEffect, useState, type ReactNode } from 'react'

import { installUnauthorizedGuard } from '../platform/auth'

import { BootstrapScreen } from './BootstrapScreen'
import { ConnectivityBar } from './ConnectivityBar'
import { DesktopPanel, type DesktopPanelTab } from './DesktopPanel'
import { LogoutConfirm } from './LogoutConfirm'
import { useEngineStatus } from './useEngineStatus'

/**
 * Which tab the connectivity bar opens onto.
 *
 * The bar is the only entry point, so the click has to land on the thing the bar is currently
 * complaining about: an unresolved conflict, then a storage ceiling that is refusing writes,
 * and otherwise the pending queue.
 */
function tabForStatus(conflicts: number, writeBlocked: string | null): DesktopPanelTab {
  if (conflicts > 0) return 'conflicts'
  if (writeBlocked !== null) return 'storage'
  return 'pending'
}

export function DesktopShell({ children }: { children: ReactNode }) {
  const status = useEngineStatus()
  const [openTab, setOpenTab] = useState<DesktopPanelTab | null>(null)

  // AFTER `App`'s own `registerAuthRedirect()` — `App` is this component's child and child
  // effects run first, so this overwrite lands last and stays. See `installUnauthorizedGuard`
  // for why the desktop needs a different rule than the web.
  useEffect(() => {
    installUnauthorizedGuard()
  }, [])

  const open = useCallback(() => {
    setOpenTab(tabForStatus(status.conflicts, status.write_blocked))
  }, [status.conflicts, status.write_blocked])

  return (
    <>
      {children}
      {/* Both of these are answers to a question raised OUTSIDE the React tree — the first
          download that starts right after `invoke('login')` returns, and the logout the shared
          `Topbar` fired without knowing the outbox is not empty. They live here, in the chrome,
          for the same reason the panel does (KARAR A27): there is no route to put them on and
          no shared screen to reuse. */}
      <BootstrapScreen />
      <LogoutConfirm />
      <ConnectivityBar onOpen={open} />
      {/* Keyed by the tab it opened on, so re-opening the panel after the state changed lands
          on the new tab instead of the one `useState` initialised with the first time. */}
      {openTab !== null && (
        <DesktopPanel
          key={openTab}
          open
          initialTab={openTab}
          onClose={() => setOpenTab(null)}
        />
      )}
    </>
  )
}
