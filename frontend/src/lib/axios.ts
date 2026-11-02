import axios, { AxiosError } from 'axios'
import type { InternalAxiosRequestConfig } from 'axios'
import { toast } from '../components/ui'
import i18n from '../i18n'
import { currentSessionEpoch } from './sessionEpoch'

/** A request config carrying the sign-in it was sent under (see `SessionStamp`). */
type StampedConfig = InternalAxiosRequestConfig & { _session?: SessionStamp }

/**
 * How the `api` instance authenticates. `'cookie'`: Laravel Sanctum's SPA session + XSRF cookie
 * (web). `'bearer'`: a static `Authorization` header, e.g. a desktop device token
 * (`docs/DESKTOP-ARCHITECTURE.md` §3.5) — no consumer yet; this phase only adds the hook.
 */
export interface HttpAuthConfig {
  baseURL: string
  transport: 'cookie' | 'bearer'
  getBearerToken?: () => string | undefined
}

/**
 * Default matches today's (pre-platform-adapter) behavior exactly, so `api` works unchanged for
 * any caller that never imports `platform/`. `platform/web.ts` calls `configureHttp()` with the
 * same values, making the platform module — not this file — the declared single source
 * (`SYNCDESKTOP.md` §7.1: "baseURL ... platform'dan gelsin").
 */
let httpConfig: HttpAuthConfig = {
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  transport: 'cookie',
}

/**
 * Shared axios instance configured for Laravel Sanctum's SPA (cookie-based)
 * authentication flow.
 *
 * - `withCredentials`: sends/receives the Sanctum session + XSRF cookies.
 * - `withXSRFToken`: required on axios 1.x so the `XSRF-TOKEN` cookie value
 *   is actually attached as the `X-XSRF-TOKEN` header on requests.
 */
export const api = axios.create({
  baseURL: httpConfig.baseURL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

/**
 * Lets a platform adapter supply the API base URL and auth transport instead of this module
 * resolving them itself. Updates the live `api` instance's defaults in place; in-flight requests
 * are unaffected. Calling this with `transport: 'cookie'` and the same `baseURL` (what
 * `platform/web.ts` does) is behavior-neutral — it only matters once a `'bearer'` transport
 * (desktop) is configured.
 */
export function configureHttp(config: HttpAuthConfig) {
  httpConfig = config
  api.defaults.baseURL = config.baseURL
  api.defaults.withCredentials = config.transport === 'cookie'
  api.defaults.withXSRFToken = config.transport === 'cookie'
}

/**
 * The sign-in a request was sent under: the session epoch (`lib/sessionEpoch.ts`) and, under
 * the bearer transport, the token that went out with it (`undefined` when none did).
 */
type SessionStamp = { epoch: number; bearer: string | undefined }

function currentStamp(): SessionStamp {
  return {
    epoch: currentSessionEpoch(),
    bearer: httpConfig.transport === 'bearer' ? httpConfig.getBearerToken?.() : undefined,
  }
}

/**
 * Whether a failed request still belongs to the sign-in in effect. A request with no stamp
 * (it never passed the request interceptor) is treated as current, which is the old
 * behaviour.
 */
function belongsToCurrentSession(config: StampedConfig | undefined): boolean {
  const sent = config?._session
  if (!sent) return true
  const now = currentStamp()
  return sent.epoch === now.epoch && sent.bearer === now.bearer
}

const SESSION_ENDED = Symbol('sessionEnded')

function markSessionEnded<E extends object>(error: E): E {
  ;(error as E & { [SESSION_ENDED]?: true })[SESSION_ENDED] = true
  return error
}

/**
 * Whether a rejection came from a request whose sign-in had already ended when it answered.
 * Such an error says nothing about the session in effect; a caller that would otherwise act
 * on it (e.g. treat a failed `/api/me` as "signed out") should ask again instead.
 */
export function isSessionEndedError(error: unknown): boolean {
  return typeof error === 'object' && error !== null && SESSION_ENDED in error
}

// Bearer transport (desktop): attaches `Authorization` per request. Under the default
// `'cookie'` transport only the session stamp is recorded. A 419 retry passes through here
// again and is stamped anew, because it goes out with whatever credentials are current then.
api.interceptors.request.use((config) => {
  const stamp = currentStamp()
  if (stamp.bearer) {
    config.headers.Authorization = `Bearer ${stamp.bearer}`
  }
  ;(config as StampedConfig)._session = stamp
  return config
})

/**
 * Fetches the Sanctum CSRF cookie. Must be called once before the first
 * "unsafe" request (POST/PUT/PATCH/DELETE) in an unauthenticated session,
 * e.g. right before submitting the login form.
 */
export function getCsrfCookie() {
  return api.get('/sanctum/csrf-cookie')
}

/**
 * Marks a request config so a 419 (CSRF mismatch) retry only ever happens
 * once per original request — prevents an infinite retry loop if the
 * backend keeps rejecting the refreshed token.
 */
type RetriableConfig = StampedConfig & { _csrfRetried?: boolean }

/**
 * Registered by the router bootstrap (see `src/router.tsx`) so the axios
 * layer can react to a 401 without importing the router or reaching for
 * `window.location` directly. Kept as a plain module-level callback instead
 * of importing the auth store here, so this file has no feature-layer
 * dependency.
 */
let onUnauthorized: (() => void) | null = null

export function registerUnauthorizedHandler(handler: () => void) {
  onUnauthorized = handler
}

/**
 * Registered alongside `onUnauthorized` (see `src/router.tsx` →
 * `registerAuthRedirect()`), but kept as a separate callback: a
 * `PASSWORD_CHANGE_REQUIRED` response means the session is still valid — it
 * must NOT clear the auth store, only redirect to `/change-password`.
 */
let onPasswordChangeRequired: (() => void) | null = null

export function registerPasswordChangeHandler(handler: () => void) {
  onPasswordChangeRequired = handler
}

/** Shape of the error body every backend endpoint returns on failure. */
type ApiErrorBody = {
  errors?: {
    message?: string
    code?: string
    fields?: Record<string, string[]>
  }
}

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiErrorBody>) => {
    const status = error.response?.status
    const config = error.config as RetriableConfig | undefined
    const code = error.response?.data?.errors?.code

    // 419: CSRF token mismatch/expired — refetch the cookie and retry the
    // original request exactly once.
    //
    // Only for a request of the sign-in still in effect, checked before the refetch and
    // again after it. A replay goes out with the CURRENT cookie: replaying user A's late
    // `POST /api/logout` after user B signed in would sign B out on the server.
    if (status === 419 && config && !config._csrfRetried) {
      if (!belongsToCurrentSession(config)) return Promise.reject(markSessionEnded(error))
      config._csrfRetried = true
      await getCsrfCookie()
      if (!belongsToCurrentSession(config)) return Promise.reject(markSessionEnded(error))
      return api(config)
    }

    const onLoginPage = typeof window !== 'undefined' && window.location.pathname === '/login'

    // A request sent under a sign-in that has since ended (logout, another login, a token
    // swap) says nothing about the current one: its 401 or USER_DEACTIVATED must not sign the
    // new session out. The error still reaches the caller, marked (`isSessionEndedError`) so
    // callers that act on auth errors themselves can tell it apart too. Decided BEFORE the
    // handler runs, because the handler's own `clear()` moves the epoch.
    const current = belongsToCurrentSession(config)
    if (!current) markSessionEnded(error)

    // 401: session missing/expired — clear auth state and bounce to /login,
    // unless we're already there (avoid a redirect loop).
    if (status === 401) {
      if (!onLoginPage && current) {
        onUnauthorized?.()
      }
      return Promise.reject(error)
    }

    // 403 + USER_DEACTIVATED: account was deactivated mid-session — clear
    // state, notify the user, and bounce to /login.
    if (status === 403 && code === 'USER_DEACTIVATED') {
      if (!onLoginPage && current) {
        toast.error(i18n.t('errors:accountDeactivated'))
        onUnauthorized?.()
      }
      return Promise.reject(error)
    }

    // 403 + PASSWORD_CHANGE_REQUIRED: session is still valid, the user just
    // hasn't cleared the forced password-change gate yet (stale client state
    // reaching a non-whitelisted endpoint). Redirect only — do NOT clear the
    // store, unlike USER_DEACTIVATED/401 above.
    if (status === 403 && code === 'PASSWORD_CHANGE_REQUIRED') {
      onPasswordChangeRequired?.()
      return Promise.reject(error)
    }

    return Promise.reject(error)
  },
)

/** Extracts the human-readable error message from an API error response. */
export function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError<ApiErrorBody>(error)) {
    const message = error.response?.data?.errors?.message
    if (message) return message
  }
  return i18n.t('errors:generic')
}

/** Extracts per-field validation errors (422) from an API error response, if any. */
export function getFieldErrors(error: unknown): Record<string, string[]> | undefined {
  if (axios.isAxiosError<ApiErrorBody>(error)) {
    return error.response?.data?.errors?.fields
  }
  return undefined
}

export default api
