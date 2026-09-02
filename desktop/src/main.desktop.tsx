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
// Two import statements from the SAME module, on purpose. `frontend/scripts/check-i18n-bootstrap.mjs`
// (KARAR D-6) asserts the opening gate with a regex that requires a NAMED import list to open the
// statement — `import\s*\{[^}]*i18nReady` — so folding the default in as
// `import i18n, { i18nReady } from '@/i18n'` makes that check fail with a message about the symbol
// not being imported at all. Merging these two lines is the trap; do not.
import i18n from '@/i18n'
import { PlatformProvider, setPlatform } from '@/platform'
import { queryClient } from '@/lib/queryClient'
import { applyEngineStatus, desktopPlatform, primeDesktopPlatform } from './platform/desktop'
import { handleAuthLost, installDesktopAuth, restoreDesktopSession } from './platform/auth'
import { subscribeToEngineEvents } from './bridge/events'
import { notificationWatcher } from './bridge/notifications'
import { startDeepLinkBridge } from './bridge/deeplink'
import { startRealtimeBridge } from './bridge/realtime'
import { DesktopShell } from './ui/DesktopShell'
import { setTrayLanguage } from './ui/commands'
import { restoreHotkey } from './ui/hotkey'
import App from '@/App'

// Before the first render, and before anything can call `getPlatform()` from a React tree.
setPlatform(desktopPlatform)

// The shared auth endpoints are pointed at the engine BEFORE anything can call them
// (`platform/auth.ts` explains why the transport is redirected instead of a desktop login
// screen being written). This must run before `restoreDesktopSession()` below and before the
// tree mounts, because `useAuth` fires `GET /api/me` on its first render and that request must
// never reach the network: it has to be answerable offline.
installDesktopAuth()
primeDesktopPlatform()

// The engine's event stream drives the query cache: `tables_changed` -> `invalidateQueries`
// through the hand-written entity -> key table in `bridge/events.ts` (KARAR A5 / D-5). It is
// started BEFORE the first render because an event emitted while nothing is listening is
// simply lost — the broadcast channel only replays to live subscribers. `queryClient` is the
// same module singleton `App` hands to `QueryClientProvider`, so invalidations land on the
// cache the tree is actually reading.
// §6.4 items 2 and 7 ride the SAME subscription: a `tables_changed` batch carrying
// `notification` is what turns a pulled or broadcast row into a native toast and moves the
// taskbar badge. Created here (rather than lazily inside the handler) so `startedAt` is the
// process's start and not the moment the first notification happened to arrive — the backlog
// rule depends on it. See `bridge/notifications.ts` for why nothing is toasted on the first read.
const notifications = notificationWatcher()

void subscribeToEngineEvents(queryClient, {
  onStatusChanged: applyEngineStatus,
  onTablesChanged: notifications.onTablesChanged,
  // The server rejected the device token (§5.5). The engine has already dropped it and kept
  // the outbox; the webview only has to stop claiming a session, which sends `RequireAuth` to
  // `/login` on the next render. `USER_DEACTIVATED` (403) does NOT arrive here — `transport.rs`
  // folds every 403 into `PROTOCOL_ERROR` — and wiring that is KARAR A25 / O1, crate work this
  // strand does not own. Today's behaviour is preserved deliberately.
  onAuthLost: handleAuthLost,
})

// The other half of the same rule, for the OTHER event source (KARAR A11). A Reverb frame is
// translated into a `RealtimeEvent` and handed to the engine, which mini-pulls and emits the
// `tables_changed` the subscription above is waiting for — the socket never touches the query
// cache on the desktop. Armed here, before the first render, for exactly the reason above: a
// frame that arrives while nothing is bound is gone, pusher-js does not replay it. Echo itself
// only exists after login (`connectEcho()` in `features/auth/hooks/useAuth.ts`), so this call
// arms the listeners that subscribe the moment it does.
startRealtimeBridge()

// §6.4 deep links. The Rust side consumes the url this process was LAUNCHED with
// (`deep_link::install` reads `get_current()` in `.setup()`), which on a cold start happens
// long before this tree exists — and a Tauri event with no listener is simply dropped. That is
// exactly how cold-start links used to be lost (defter O86): the app opened on `/`.
//
// So Rust no longer emits a launch target on sight. It HOLDS it, and `startDeepLinkBridge()`
// announces itself only once its `listen()` has actually resolved; whichever side arrives
// second performs the delivery, and the target is delivered exactly once. Arming early still
// matters — it shortens the wait — but correctness no longer depends on winning the race.
// Every url has already been validated in Rust; this side only maps an entity to a route.
void startDeepLinkBridge()

// The tray speaks whatever the account's `users.locale` says, because that is all Rust can
// read: the per-install override i18next keeps in `localStorage` lives inside the WebView2 /
// WebKitGTK profile and is invisible to the shell (defter C1). So the webview pushes it down —
// once for the language that is active right now, and again whenever it changes.
//
// Failures are swallowed: a tray label in the previous language is a cosmetic defect, and this
// is not a path any screen is waiting on. The command rejects only for a language the tray has
// no dictionary for, which `i18n.language` can never be.
function pushTrayLanguage(language: string): void {
  void setTrayLanguage(language).catch(() => undefined)
}
i18n.on('languageChanged', pushTrayLanguage)

// §6.4's "configurable" half of the global hotkey. `.setup()` has already claimed the default
// (`quick_capture::install_default`), so this only does work when the user picked something
// else; see `ui/hotkey.ts` for why the choice lives in `localStorage`.
restoreHotkey()

// OPENING GATE — mirrors `frontend/src/main.tsx:19-25` deliberately and must keep mirroring it
// (KARAR A7). `tr` is eager, `en/de/fr` are lazy chunks; rendering before the selected locale's
// chunk has landed makes i18next fall back to `tr`, so a user who picked English would get a
// silently Turkish desktop UI. Nothing in the regression gate catches that here —
// `frontend/scripts/check-i18n-bootstrap.mjs` asserts against `src/main.tsx` only, and widening
// it to this file is KARAR D-6, owned by the `frontend/**` strand.
//
// With `tr` selected this is an already-resolved promise: one microtask, no network wait.
//
// `restoreDesktopSession()` is chained onto the SAME gate rather than run in parallel: the
// router decides on its very first render whether the user is signed in, and a session that
// lands one tick later would show `/login` and then yank it away.
void i18nReady.then(restoreDesktopSession).then(() => {
  // After the gate, so the tray is told the locale that actually resolved rather than the one
  // i18next was still bootstrapping. `languageChanged` above covers every later change.
  pushTrayLanguage(i18n.resolvedLanguage ?? i18n.language)

  // The priming read (§6.4 item 7): it puts the unread count on the taskbar badge at boot and
  // raises no toast. It runs AFTER `restoreDesktopSession()` because the local mirror is only
  // readable once the session is restored — before that the query would reject with
  // `AUTH_REQUIRED` and the badge would keep whatever the previous run left on it. Failures are
  // swallowed inside the watcher.
  void notifications.refresh()

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
