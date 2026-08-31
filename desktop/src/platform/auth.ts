// Desktop session lifecycle — `SYNCDESKTOP.md` §4.3 / §5.2, `docs/AUTH-FLOWS.md`.
//
// ## WHY THIS FILE REDIRECTS `authApi` INSTEAD OF ADDING A DESKTOP LOGIN SCREEN
//
// The brief offered two shapes: A27 shell chrome (a desktop-owned login surface layered over
// `App`, the way `DesktopPanel` layers the Conflict Inbox) or redirecting the shared
// `features/auth/api/authApi.ts` call target through the platform seam. This strand takes the
// SECOND, and the deciding argument is i18n, not taste:
//
//   * `SYNCDESKTOP.md` §0.6 forbids hard-coded UI text, and every dictionary lives under
//     `frontend/src/i18n/locales/**` — a directory this strand may not write.
//   * `desktop.json` has no login vocabulary at all: no e-mail/password labels, no submit
//     button, no "forgot password", no lockout sentence. `auth.json` has all of it, in four
//     languages, already wired to `LoginPage`.
//   * So a desktop login screen could only be built out of hard-coded strings (forbidden) or
//     out of `auth:*` keys — at which point it is a byte-for-byte reimplementation of
//     `LoginPage` with a different data source, i.e. exactly the "UI yeniden yazımı" K1 rules
//     out.
//
// Redirecting the transport keeps `LoginPage` — its focus handling, its lockout countdown, its
// forgot-password modal, its four translations — and swaps only WHERE the credentials go.
// Shell chrome is still used where there is no shared screen to reuse: the bootstrap progress
// surface and the logout confirmation (`ui/BootstrapScreen.tsx`, `ui/LogoutConfirm.tsx`).
//
// ## HOW THE REDIRECT WORKS
//
// `authApi` does not go through `Platform['http']` — it imports the shared axios instance
// directly (`frontend/src/lib/axios.ts`), and that file is also outside this strand. The seam
// that IS reachable is the instance itself: a request interceptor that swaps `config.adapter`
// for the four auth routes. Everything else on `api` keeps the real network adapter, so this
// is a redirect of four URLs and not a second HTTP client.
//
// Four routes, and why each one:
//
//   `POST /api/login`          -> `invoke('login')`   — device token, not a cookie session.
//   `POST /api/logout`         -> `invoke('logout')`  — §5.2 `LogoutOutcome`, may need `force`.
//   `GET  /api/me`             -> the engine's session — must answer OFFLINE (see below).
//   `GET  /sanctum/csrf-cookie`-> 204                 — bearer transport has no CSRF cookie;
//                                 `login()` fetches it unconditionally and the real request
//                                 would 404/blow up before the login call was ever made.
import i18n from '@/i18n'
import { api, registerUnauthorizedHandler } from '@/lib/axios'
import { queryClient } from '@/lib/queryClient'
import { router } from '@/router'
import { toast } from '@/components/ui'
import { useAuthStore } from '@/features/auth/store'
import type { User } from '@/features/auth/types'

import { invokeCommand } from '../bridge/invoke'
import { errorCodeOf, errorMessage, retryAfterOf } from '../ui/errors'
import { startBootstrapIfEmpty } from './bootstrap'
import { getDeviceToken, setDeviceToken } from './http'

// ------------------------------------------------------------------------------------------------
// Wire types — `syncra_sync::types`
// ------------------------------------------------------------------------------------------------

/** `syncra_sync::Session`. `user` is the `GET /api/me` document, verbatim. */
export interface DesktopSession {
  token_id: number
  user_id: number
  user: User
  must_change_password: boolean
  abilities: string[]
}

/**
 * `syncra_sync::LogoutOutcome`, externally tagged (`rename_all = "snake_case"`).
 *
 * Three variants, and the middle one is the one that used to be missing:
 *
 *   `"wiped"`                          — token revoked on the server, mirror wiped.
 *   `{"wiped_local_only": "<reason>"}` — mirror wiped, but `DELETE /api/me/devices/{id}` did
 *                                        not go through, so the token may still be alive on
 *                                        the server. The normal outcome of an offline logout,
 *                                        and the user's recourse is the Devices page.
 *   `{"pending_mutations": 7}`         — refused; unpushed work exists and `force` was not set.
 */
export type LogoutOutcome =
  | 'wiped'
  | { wiped_local_only: string }
  | { pending_mutations: number }

/** `commands::auth::SessionSnapshot` — the engine's session plus the bearer behind it. */
export interface SessionSnapshot {
  session: DesktopSession | null
  token: string | null
}

function isPendingMutations(outcome: LogoutOutcome): outcome is { pending_mutations: number } {
  return typeof outcome === 'object' && outcome !== null && 'pending_mutations' in outcome
}

function isWipedLocalOnly(outcome: LogoutOutcome): outcome is { wiped_local_only: string } {
  return typeof outcome === 'object' && outcome !== null && 'wiped_local_only' in outcome
}

// ------------------------------------------------------------------------------------------------
// Session state
// ------------------------------------------------------------------------------------------------

/**
 * The query key `features/auth/hooks/useAuth.ts` uses for `GET /api/me`. Transcribed, not
 * imported: the constant is module-private there. Seeding it before the first render is what
 * keeps the app from flashing `/login` on the way to an already-restored session.
 */
const ME_QUERY_KEY = ['auth', 'me'] as const

/**
 * Where AUTH-1 used to cache the identity document so the app could open OFFLINE — **removed**.
 *
 * ## Why it existed, and why it is gone
 *
 * `auth::restore` -> `SyncEngine::restore_session()` calls `load_manifest(true)`, i.e. it
 * ALWAYS makes a network round trip. With the backend down it returned `OFFLINE`, so the one
 * command that could hand the webview an identity could not do it in exactly the situation the
 * offline-first contract is about (AUTH-1 U5). The engine had the answer in memory the whole
 * time (`SyncEngine::session()`, read back from the encrypted mirror in `open()`) — there was
 * simply no command that exposed it, so the webview kept its own copy in `localStorage`.
 *
 * `auth::session` is now that command, and it touches no network at all, so the copy has no
 * remaining purpose. It is not merely redundant: it was a **plaintext copy of name, e-mail,
 * role and permissions** sitting in the webview profile directory, i.e. PII on disk outside
 * SQLCipher. Deleting it is a security fix, not a cleanup.
 *
 * The constant survives for exactly one reason — {@link purgeLegacySessionCache} has to be able
 * to erase what earlier builds wrote. Nothing writes this key any more.
 */
const LEGACY_SESSION_KEY = 'syncra.desktop.session'

/**
 * Erase the identity copy earlier builds left in `localStorage`.
 *
 * Upgrading an existing install must not leave the plaintext document behind: no code reads it
 * any more, so nothing else would ever remove it. Called from {@link installDesktopAuth}, i.e.
 * before the first render and before any session work.
 */
function purgeLegacySessionCache(): void {
  try {
    window.localStorage.removeItem(LEGACY_SESSION_KEY)
  } catch {
    // A blocked or unavailable store means there is nothing there to erase either.
  }
}

/** The session in effect, or `null` when signed out. */
let currentSession: DesktopSession | null = null

/** Whether the identity in effect has not yet been proven against the server this run. */
let restoredOffline = false

/** The session in effect. `null` before `restoreDesktopSession()` has run. */
export function getDesktopSession(): DesktopSession | null {
  return currentSession
}

/**
 * The user document the shared app should see.
 *
 * `Session.must_change_password` and `user.must_change_password` come from the same server
 * response (`DeviceTokenController::store`) but are separate fields; if they ever disagree the
 * TOP-LEVEL flag wins, because that is the one `SYNCDESKTOP.md` §4.3 names as the contract and
 * the one `docs/AUTH-FLOWS.md` §4.1 hangs the `/change-password` gate on. `RequireAuth` reads
 * it off the user object, so it is normalised here rather than anywhere later.
 */
function sessionUser(session: DesktopSession): User {
  if (session.user.must_change_password === session.must_change_password) return session.user
  return { ...session.user, must_change_password: session.must_change_password }
}

/**
 * Adopt a session: bearer token, shared auth store, query cache.
 *
 * The shared store is written imperatively (`useAuthStore.getState()`), the same way
 * `platform/session.ts` reads it — zustand stores are plain objects outside React.
 *
 * `token` is what closes AUTH-1 U2. The durable copy stays in the OS keychain (K9); this hands
 * the in-memory bearer to `platform/http.ts`, which is where `lib/axios.ts` reads it per
 * request. Until it flowed, every `Platform['http']` call — the `SYNCDESKTOP.md` §8 online-only
 * endpoints, `/api/broadcasting/auth`, `/api/password/change` — went out unauthenticated.
 */
export function adoptSession(
  session: DesktopSession,
  options: { offline?: boolean; token?: string | null } = {},
): void {
  currentSession = session
  restoredOffline = options.offline ?? false

  const user = sessionUser(session)

  setDeviceToken(options.token ?? undefined)

  useAuthStore.getState().setUser(user)
  useAuthStore.getState().setStatus('authenticated')
  queryClient.setQueryData(ME_QUERY_KEY, user)
}

/** Drop everything the webview holds about the session. Does NOT call the engine. */
export function forgetSession(): void {
  currentSession = null
  restoredOffline = false
  setDeviceToken(undefined)
  useAuthStore.getState().clear()
  queryClient.removeQueries({ queryKey: ME_QUERY_KEY })
}

/**
 * `EngineEvent::AuthLost` — the server rejected the token. The outbox survives on the Rust
 * side (§5.5); the webview only has to stop claiming a session.
 */
export function handleAuthLost(): void {
  forgetSession()
}

// ------------------------------------------------------------------------------------------------
// Commands
// ------------------------------------------------------------------------------------------------

/** `auth::restore` — `Ok(null)` means "no token in the keychain", i.e. genuinely signed out. */
function invokeRestore(): Promise<DesktopSession | null> {
  return invokeCommand<DesktopSession | null>('restore')
}

/**
 * `auth::session` — the engine's in-memory session and the bearer behind it, **without any
 * network access**.
 *
 * This is the command AUTH-1 did not have. It is answerable from `open()` onward (the engine
 * reads the session back out of `desktop_settings` and the token out of the keychain), which is
 * what lets the desktop restore a session with the backend down and what finally gives
 * `setDeviceToken` something to be called with.
 */
function invokeSession(): Promise<SessionSnapshot> {
  return invokeCommand<SessionSnapshot>('session')
}

/**
 * Read the engine's session and adopt it. Returns the session, or `null` when the engine is
 * signed out.
 */
async function adoptEngineSession(options: { offline?: boolean } = {}): Promise<DesktopSession | null> {
  const snapshot = await invokeSession()
  if (snapshot.session === null) return null
  adoptSession(snapshot.session, { ...options, token: snapshot.token })
  return snapshot.session
}

/** `auth::login` — exchanges credentials for a device token stored in the OS keychain. */
function invokeLogin(email: string, password: string): Promise<DesktopSession> {
  return invokeCommand<DesktopSession>('login', { email, password })
}

/** `auth::logout` — refuses with `{pending_mutations}` unless `force`. */
function invokeLogout(force: boolean): Promise<LogoutOutcome> {
  return invokeCommand<LogoutOutcome>('logout', { force })
}

/**
 * Startup path: adopt the session the engine already holds, then prove it against the server
 * WITHOUT making the first render wait for the network.
 *
 * Runs BEFORE the first render (`main.desktop.tsx`) so the router never sees a half-known auth
 * state and bounces an authenticated user to `/login`.
 *
 * ## Why the network round trip moved off the opening path
 *
 * AUTH-1 had to `await invoke('restore')` here, because that was the only command that could
 * produce an identity — and it forces a manifest round trip, so an offline-first app blocked
 * its own cold start on the network and then fell back to a plaintext `localStorage` copy when
 * that failed. `auth::session` answers the same question from memory, so the sequence is now:
 *
 *   1. `session` — instant, offline-safe, and it carries the bearer token.
 *   2. `restore` — fired but NOT awaited: it refreshes the user document from the manifest and
 *      is the thing that can discover the token has been revoked. A failure that is merely
 *      "no network" leaves step 1's session standing (`restoredOffline` stays `true`).
 *
 * An engine with no session means genuinely signed out: `SyncEngine::restore_session` returns
 * `None` for a missing token as well as a missing session document, so there is nothing step 2
 * could have found that step 1 did not.
 */
export async function restoreDesktopSession(): Promise<void> {
  let session: DesktopSession | null = null
  try {
    // `offline: true` until `restore` confirms it — the identity is trusted, just unproven.
    session = await adoptEngineSession({ offline: true })
  } catch {
    // The engine could not answer at all (state not initialised, keychain unreadable). There
    // is nothing to open on.
    forgetSession()
    return
  }

  if (session === null) {
    forgetSession()
    return
  }

  leaveLoginRoute()
  void revalidateSession()
}

/**
 * Prove the adopted session against the server, in the background.
 *
 * `AUTH_REQUIRED` is the only outcome that signs the user out: the engine has already dropped
 * the token by then. Every other failure is a network condition, and an offline-first desktop
 * must not throw the user out of a mirror full of data because a manifest request timed out.
 */
async function revalidateSession(): Promise<void> {
  try {
    const proven = await invokeRestore()
    if (proven === null) {
      forgetSession()
      return
    }
    // Re-read rather than adopting `proven` directly: `restore` returns the refreshed `Session`
    // but no token, and the token is the half that matters to `platform/http.ts`.
    await adoptEngineSession({ offline: false })
    // The token still works, so a mirror that is somehow empty (a wiped profile, an interrupted
    // first run) can be filled now. A populated one is left alone.
    void startBootstrapIfEmpty()
  } catch (error) {
    if (errorCodeOf(error) === 'AUTH_REQUIRED') {
      forgetSession()
    }
    // OFFLINE / HTTP_ERROR / PROTOCOL_ERROR: the adopted session stands.
  }
}

/**
 * Send a restored session away from `/login`.
 *
 * `LoginPage` has no "already signed in" guard — on the web it never needs one, because a
 * cookie session only ever reaches `/login` by the user asking for it. On the desktop the app
 * reopens on whatever route it was closed on, and `tauri-plugin-window-state` plus the webview's
 * own history mean that route is frequently `/login`: the session restores, the store says
 * authenticated, and the user is left looking at a login form that will not go away. Observed
 * on the first end-to-end run.
 *
 * `history.replaceState` rather than `router.navigate`: this runs BEFORE `createRoot`, so there
 * is no router to navigate yet — the data router reads `window.location` when it initialises.
 */
function leaveLoginRoute(): void {
  if (window.location.pathname === '/login') {
    window.history.replaceState(null, '', '/')
  }
}

/** Whether the identity in effect was restored from the offline cache. */
export function isOfflineRestore(): boolean {
  return restoredOffline
}

// ------------------------------------------------------------------------------------------------
// Logout confirmation surface (`SYNCDESKTOP.md` §5.2)
// ------------------------------------------------------------------------------------------------

/** An outstanding "you have N unpushed changes, log out anyway?" question. */
export interface LogoutPrompt {
  /** Mutations that would be lost. */
  pending: number
  /** `true` logs out with `force`, `false` cancels. */
  answer: (force: boolean) => void
}

let logoutPrompt: LogoutPrompt | null = null
const logoutPromptListeners = new Set<() => void>()

/** The outstanding logout question, if any. */
export function getLogoutPrompt(): LogoutPrompt | null {
  return logoutPrompt
}

/** Observe {@link getLogoutPrompt} — `useSyncExternalStore` subscriber. */
export function subscribeToLogoutPrompt(listener: () => void): () => void {
  logoutPromptListeners.add(listener)
  return () => {
    logoutPromptListeners.delete(listener)
  }
}

function setLogoutPrompt(next: LogoutPrompt | null): void {
  logoutPrompt = next
  for (const listener of logoutPromptListeners) listener()
}

/**
 * Put the session back after the user cancelled a logout that would have discarded unpushed
 * work.
 *
 * `useAuth`'s `logoutMutation` clears the auth store from `onSettled`, which runs on success
 * AND on failure — there is no request outcome that leaves the store alone, and that hook is
 * outside this strand. So the cancel path lets the mutation "succeed" (no rejection to leak
 * out of `Topbar.handleLogout`, which does not catch) and re-adopts the session the moment the
 * store is actually cleared. Subscribing rather than scheduling a timer makes it deterministic:
 * it fires when the clear happens, not a guessed number of milliseconds later.
 */
function keepSessionAfterCancelledLogout(returnTo: string): void {
  const session = currentSession
  if (session === null) return
  const offline = restoredOffline
  // The engine refused the logout, so the keychain token is untouched and still valid — the
  // in-memory bearer has to come back with the session or every subsequent §8 call 401s.
  const token = getDeviceToken()

  const handle: { unsubscribe: (() => void) | null } = { unsubscribe: null }
  const stop = (): void => {
    handle.unsubscribe?.()
    handle.unsubscribe = null
  }

  handle.unsubscribe = useAuthStore.subscribe((state) => {
    if (state.status !== 'unauthenticated') return
    stop()
    adoptSession(session, { offline, token })
    // `RequireAuth` has already bounced to `/login` by now; put the user back where they were.
    // The router object is the same module singleton `App` hands to `RouterProvider`, reached
    // imperatively — the pattern `router.tsx:317-322` already uses from outside the tree.
    void router.navigate(returnTo, { replace: true })
  })

  // If nothing ever clears the store, stop listening rather than holding the subscription (and
  // the session object) for the life of the process.
  window.setTimeout(stop, 10_000)
}

function askToDiscard(pending: number): Promise<boolean> {
  return new Promise((resolve) => {
    setLogoutPrompt({
      pending,
      answer: (force) => {
        setLogoutPrompt(null)
        resolve(force)
      },
    })
  })
}

// ------------------------------------------------------------------------------------------------
// 401 handling
// ------------------------------------------------------------------------------------------------

/**
 * Narrow the shared 401 handler so a request that carried NO credential cannot sign the user
 * out — and nothing else.
 *
 * ## What this used to be, and why it could not stay
 *
 * `router.tsx`'s `registerAuthRedirect()` maps every 401 on the shared axios instance to "clear
 * the store and go to /login". On the web that is exactly right: one credential (the Sanctum
 * cookie), one client, so a 401 means the session is gone.
 *
 * On the desktop there are two clients — the engine's reqwest client and this webview's axios
 * instance — and until `auth::session` existed the second one had no credential at all
 * (AUTH-1 U2). Every §8 online-only call, `/api/broadcasting/auth` and `/api/password/change`
 * came back 401, so opening the dashboard a second after login logged the user straight back
 * out. AUTH-1's answer was to swallow **every** 401 while a session was held and mark it "a
 * patch, not a solution" — correctly, because an unconditional swallow means a genuinely
 * revoked token leaves the user in a session that no longer works, silently, forever.
 *
 * ## The decision
 *
 * The guard stays, but inverted: it now keys off **whether the request had a bearer to
 * present**, not off "is a session held".
 *
 *   * `getDeviceToken() === undefined` while a session is held — the request provably went out
 *     with no `Authorization` header, so its 401 says nothing about the token's validity. That
 *     window is real and not hypothetical: `Platform['http']` calls can be issued between the
 *     first render and `adoptEngineSession()` landing, and `keepSessionAfterCancelledLogout`
 *     re-adopts asynchronously. The response is to recover the token from the engine, not to
 *     sign anyone out.
 *   * Token attached and still 401 — both clients share ONE credential now, so the server has
 *     rejected the same token the engine syncs with. That is the web's situation exactly, and
 *     it gets the web's behaviour: clear and redirect. This is the half AUTH-1 was swallowing.
 *
 * `EngineEvent::AuthLost` still calls `handleAuthLost()` independently; the two paths agree
 * rather than one covering for the other.
 *
 * Registered from `DesktopShell` rather than at module scope because `App`'s own
 * `registerAuthRedirect()` runs in an effect and would otherwise overwrite it — the handler is
 * a single slot, and child effects run before the parent's.
 */
export function installUnauthorizedGuard(): void {
  registerUnauthorizedHandler(() => {
    if (currentSession !== null && getDeviceToken() === undefined) {
      console.warn(
        '[desktop] 401 on a webview request that carried no bearer token; recovering it from ' +
          'the engine rather than dropping the session.',
      )
      void recoverDeviceToken()
      return
    }
    // Either no session is held, or the device token WAS presented and the server refused it.
    // Both are the shared behaviour, verbatim (`router.tsx:317-320`).
    forgetSession()
    void router.navigate('/login')
  })
}

/**
 * Re-read the bearer from the engine after a 401 on an unauthenticated webview request.
 *
 * If the engine has since signed out, this is a real logout that the webview simply had not
 * heard about yet, and the redirect happens here instead.
 */
async function recoverDeviceToken(): Promise<void> {
  try {
    if ((await adoptEngineSession({ offline: restoredOffline })) !== null) return
  } catch {
    // The engine could not answer; leave the session alone rather than guessing.
    return
  }
  forgetSession()
  void router.navigate('/login')
}

// ------------------------------------------------------------------------------------------------
// The axios redirect
// ------------------------------------------------------------------------------------------------

/** `InternalAxiosRequestConfig`, without naming `axios` (unresolvable from `desktop/src`). */
type RequestConfig = Parameters<NonNullable<Parameters<typeof api.interceptors.request.use>[0]>>[0]

/**
 * `AxiosResponse`, obtained the same way.
 *
 * Read off the RESPONSE interceptor rather than `ReturnType<typeof api.get>`: `get` is generic
 * in its response type with `R = AxiosResponse<T>` as a default, and `ReturnType` on it
 * collapses to `unknown` instead of instantiating the default.
 */
type ResponseShape = Parameters<NonNullable<Parameters<typeof api.interceptors.response.use>[0]>>[0]

/** One redirected route. */
type RouteHandler = (config: RequestConfig) => Promise<ResponseShape>

function respond(
  config: RequestConfig,
  status: number,
  data: unknown,
  headers: Record<string, string> = {},
): ResponseShape {
  return {
    data,
    status,
    statusText: '',
    headers,
    config,
  } as unknown as ResponseShape
}

/**
 * A rejection the shared error helpers understand.
 *
 * `getErrorMessage`/`getFieldErrors` (`lib/axios.ts`) and `LoginPage`'s retry-after reader all
 * go through `axios.isAxiosError`, which is `isObject(payload) && payload.isAxiosError === true`
 * — a plain `Error` carrying that flag and a `response` satisfies every one of them. Importing
 * `AxiosError` itself is not possible here (KARAR A1/A2: bare `axios` does not resolve from
 * `desktop/src`).
 */
function reject(
  config: RequestConfig,
  status: number,
  code: string,
  message: string,
  retryAfter?: number,
): Promise<never> {
  const error = new Error(message) as Error & Record<string, unknown>
  error.isAxiosError = true
  error.code = code
  error.config = config
  // `retry-after`, lowercase, because that is the key `LoginPage`/`ChangePasswordPage` read off
  // `error.response.headers` — axios normalises real response headers to lowercase and their
  // `getRetryAfterSeconds()` indexes the raw object, so a `Retry-After` here would be invisible.
  error.response = respond(
    config,
    status,
    { errors: { code, message, ...(retryAfter === undefined ? {} : { retry_after: retryAfter }) } },
    retryAfter === undefined ? {} : { 'retry-after': String(retryAfter) },
  )
  return Promise.reject(error)
}

/** `i18n.t` in the shape `ui/errors.ts` expects, for use outside a React tree. */
const translate = (key: string, options?: Record<string, unknown>): string => String(i18n.t(key, options))

/**
 * Command error code -> the HTTP status the shared layer would have seen.
 *
 * ## Why there is no longer a regex here
 *
 * AUTH-1 had to recover the server's code with a **substring search over the error message**:
 * `transport.rs` flattened every `POST /api/auth/device` refusal into
 * `SyncError::Validation(err.code)`, i.e. the code survived only as the tail of the string
 * `"validation error: LOCKED_OUT"`. That was fragile by construction and it also lost
 * `retry_after` entirely, so `LoginPage`'s lockout countdown could not run.
 *
 * `SyncError::Server{status, code, message, retry_after}` replaced that: `CommandError.code` now
 * carries `INVALID_CREDENTIALS` / `USER_INACTIVE` / `LOCKED_OUT` verbatim and `retry_after`
 * arrives as a number. Nothing parses a message any more; this table only translates a code
 * into the status the shared axios layer expects to see, because `CommandError` carries no
 * status of its own.
 *
 * The statuses are the server's own (`DeviceTokenService`: 401 / 403 / 423, `Retry-After` on
 * the lockout). A code that is not listed is a transport or engine failure rather than a
 * refusal, and falls back below.
 */
const AUTH_ERROR_STATUS: Record<string, number> = {
  INVALID_CREDENTIALS: 401,
  USER_INACTIVE: 403,
  LOCKED_OUT: 423,
  VALIDATION_ERROR: 422,
  AUTH_REQUIRED: 401,
  OFFLINE: 503,
}

function loginFailure(config: RequestConfig, error: unknown): Promise<never> {
  const code = errorCodeOf(error)
  return reject(
    config,
    AUTH_ERROR_STATUS[code] ?? 400,
    code,
    errorMessage(translate, code),
    retryAfterOf(error),
  )
}

/**
 * Tell the user the server-side token could not be revoked, after a logout that wiped the
 * device anyway (`LogoutOutcome::WipedLocalOnly`).
 *
 * The recourse is the Devices page, so the sentence is built from the two `desktop:devices.*`
 * keys that say exactly that — "an error occurred while signing out the device" plus "manage
 * the devices linked to this account". `desktop.logout` has no key of its own for this variant;
 * inventing one would mean writing `frontend/src/i18n/locales/**`, which this strand may not do,
 * and hard-coding the sentence is forbidden outright (§0.6). Reported as missing vocabulary.
 */
function warnLocalOnlyLogout(reason: string): void {
  console.warn('[desktop] logout could not revoke the device token on the server:', reason)
  toast.warning(`${translate('desktop:devices.revokeError')} ${translate('desktop:devices.subtitle')}`)
}

/** The request body, after axios' `transformRequest` has already turned it into JSON. */
function requestBody(config: RequestConfig): Record<string, unknown> {
  const raw = config.data
  if (typeof raw === 'string') {
    try {
      return JSON.parse(raw) as Record<string, unknown>
    } catch {
      return {}
    }
  }
  if (typeof raw === 'object' && raw !== null) return raw as Record<string, unknown>
  return {}
}

const routes: Record<string, RouteHandler> = {
  // Sanctum's CSRF cookie is meaningless under the bearer transport (KARAR A12): the desktop
  // instance runs with `withCredentials: false`, so no cookie is stored or sent. `authApi.login`
  // fetches it unconditionally before every login, and letting that reach the network would
  // make an unrelated 404/CORS failure look like a failed login.
  'get /sanctum/csrf-cookie': (config) => Promise.resolve(respond(config, 204, null)),

  'post /api/login': async (config) => {
    const body = requestBody(config)
    const email = typeof body.email === 'string' ? body.email : ''
    const password = typeof body.password === 'string' ? body.password : ''
    // `remember` is dropped on purpose: a device token has no "remember me" axis — it lives in
    // the OS keychain until logout or revocation (`SYNCDESKTOP.md` §4.3).
    try {
      const session = await invokeLogin(email, password)
      // `login` returns the `Session` but not the token it just put in the keychain, so the
      // bearer is read back through `auth::session` — otherwise the very first §8 call after
      // login would go out unauthenticated, which is the whole of U2.
      const adopted = (await adoptEngineSession()) ?? session
      // The first download starts here, not on the next render: `LoginPage` navigates to `/`
      // as soon as this resolves, and the list screens behind it read the local mirror.
      void startBootstrapIfEmpty()
      return respond(config, 200, { data: sessionUser(adopted) })
    } catch (error) {
      return loginFailure(config, error)
    }
  },

  'post /api/logout': async (config) => {
    const returnTo = `${window.location.pathname}${window.location.search}`
    try {
      let outcome = await invokeLogout(false)

      if (isPendingMutations(outcome)) {
        const force = await askToDiscard(outcome.pending_mutations)
        if (!force) {
          keepSessionAfterCancelledLogout(returnTo)
          return respond(config, 204, null)
        }
        outcome = await invokeLogout(true)
        if (isPendingMutations(outcome)) {
          // A forced logout that still refuses is outside the engine's contract, but the
          // webview must not claim a logout that did not happen.
          return reject(config, 409, 'PENDING_MUTATIONS', errorMessage(translate, 'PENDING_MUTATIONS'))
        }
      }

      forgetSession()
      // `wiped_local_only`: the mirror is gone and the user IS signed out here, but the
      // personal access token may still be alive on the server (the normal offline logout, and
      // AUTH-1 U6 when it is not). Silence would leave a usable credential behind with no trace.
      if (isWipedLocalOnly(outcome)) warnLocalOnlyLogout(outcome.wiped_local_only)
      return respond(config, 204, null)
    } catch (error) {
      const code = errorCodeOf(error)
      return reject(config, 500, code, errorMessage(translate, code))
    }
  },

  // The engine is the authority on "who is signed in" here, not the network: answering this
  // from the session keeps the app usable offline, which a real `GET /api/me` cannot do. The
  // document is refreshed from the server on every successful `restore` (the manifest carries
  // `user`) and by `useChangePassword`, which writes the fresh user straight into this cache.
  'get /api/me': (config) => {
    if (currentSession === null) {
      return reject(config, 401, 'AUTH_REQUIRED', errorMessage(translate, 'AUTH_REQUIRED'))
    }
    return Promise.resolve(respond(config, 200, { data: sessionUser(currentSession) }))
  },
}

function routeKey(config: RequestConfig): string {
  const method = (config.method ?? 'get').toLowerCase()
  const url = (config.url ?? '').split('?')[0]
  return `${method} ${url}`
}

let installed = false

/**
 * Point the shared auth endpoints at the engine. Idempotent; call once, before the first
 * render, so nothing can reach the network on a route this owns.
 */
export function installDesktopAuth(): void {
  if (installed) return
  installed = true

  // Earlier builds cached the identity document in `localStorage` (U5). `auth::session` made
  // that unnecessary; erasing what they wrote is what makes an UPGRADE stop carrying plaintext
  // PII on disk, not just a fresh install.
  purgeLegacySessionCache()

  api.interceptors.request.use((config) => {
    const handler = routes[routeKey(config)]
    if (handler) {
      // Per-request adapter rather than `api.defaults.adapter`: every other URL keeps the
      // real one, and nothing here has to know how to delegate to it.
      config.adapter = (forwarded) => handler(forwarded as RequestConfig)
    }
    return config
  })
}
