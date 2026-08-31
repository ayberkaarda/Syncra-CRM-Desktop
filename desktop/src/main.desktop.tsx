import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

// Desktop entry — `docs/DESKTOP-ARCHITECTURE.md` KARAR A3.
//
// The platform is chosen HERE, in the entry, and nowhere else. `frontend/src/platform/index.ts`
// must never import `desktop.ts` (KARAR A2/A3): that single import would drag `@tauri-apps/api`
// into the web bundle. `frontend/src/main.tsx` therefore stays byte-for-byte unchanged and keeps
// the default `webPlatform`; this file — and only this file — swaps it.
//
// IMPORT ORDER IS LOAD-BEARING. ES modules evaluate depth-first in declaration order, so
// `@/platform` (which pulls in `web.ts`, whose module body calls `configureHttp`/
// `configureRealtimeAuth` with the WEB cookie config) must be listed BEFORE
// `./platform/desktop` (whose module body calls the same two functions with the desktop bearer
// config). Reversing these two lines silently gives the desktop shell cookie auth against
// `/broadcasting/auth`, which fails as a 419 three layers away from the cause (§6.4 TUZAK 2).
import '@/index.css'
import { i18nReady } from '@/i18n'
import { PlatformProvider, setPlatform } from '@/platform'
import { queryClient } from '@/lib/queryClient'
import { applyEngineStatus, desktopPlatform, primeDesktopPlatform } from './platform/desktop'
import { subscribeToEngineEvents } from './bridge/events'
import { startRealtimeBridge } from './bridge/realtime'
import { DesktopShell } from './ui/DesktopShell'
import App from '@/App'

// Before the first render, and before anything can call `getPlatform()` from a React tree.
setPlatform(desktopPlatform)
primeDesktopPlatform()

// The engine's event stream drives the query cache: `tables_changed` -> `invalidateQueries`
// through the hand-written entity -> key table in `bridge/events.ts` (KARAR A5 / D-5). It is
// started BEFORE the first render because an event emitted while nothing is listening is
// simply lost — the broadcast channel only replays to live subscribers. `queryClient` is the
// same module singleton `App` hands to `QueryClientProvider`, so invalidations land on the
// cache the tree is actually reading.
void subscribeToEngineEvents(queryClient, {
  onStatusChanged: applyEngineStatus,
})

// The other half of the same rule, for the OTHER event source (KARAR A11). A Reverb frame is
// translated into a `RealtimeEvent` and handed to the engine, which mini-pulls and emits the
// `tables_changed` the subscription above is waiting for — the socket never touches the query
// cache on the desktop. Armed here, before the first render, for exactly the reason above: a
// frame that arrives while nothing is bound is gone, pusher-js does not replay it. Echo itself
// only exists after login (`connectEcho()` in `features/auth/hooks/useAuth.ts`), so this call
// arms the listeners that subscribe the moment it does.
startRealtimeBridge()

// OPENING GATE — mirrors `frontend/src/main.tsx:19-25` deliberately and must keep mirroring it
// (KARAR A7). `tr` is eager, `en/de/fr` are lazy chunks; rendering before the selected locale's
// chunk has landed makes i18next fall back to `tr`, so a user who picked English would get a
// silently Turkish desktop UI. Nothing in the regression gate catches that here —
// `frontend/scripts/check-i18n-bootstrap.mjs` asserts against `src/main.tsx` only, and widening
// it to this file is KARAR D-6, owned by the `frontend/**` strand.
//
// With `tr` selected this is an already-resolved promise: one microtask, no network wait.
void i18nReady.then(() => {
  createRoot(document.getElementById('root')!).render(
    // `DesktopShell` is the F4 chrome (`SYNCDESKTOP.md` §7.2): the connectivity bar and the
    // panel that carries the Conflict Inbox, storage settings and the device list. It wraps
    // `App` instead of living inside it because `frontend/src/router.tsx` and `AppLayout` stay
    // byte-for-byte the web's (K1) — the reasoning is recorded in `ui/DesktopPanel.tsx`.
    <StrictMode>
      <PlatformProvider>
        <DesktopShell>
          <App />
        </DesktopShell>
      </PlatformProvider>
    </StrictMode>,
  )
})
