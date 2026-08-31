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
    <StrictMode>
      <PlatformProvider>
        <App />
      </PlatformProvider>
    </StrictMode>,
  )
})
