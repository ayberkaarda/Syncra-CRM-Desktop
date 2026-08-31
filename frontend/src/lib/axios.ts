import axios, { AxiosError } from 'axios'
import type { InternalAxiosRequestConfig } from 'axios'
import { toast } from '../components/ui'
import i18n from '../i18n'

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

// Bearer transport (desktop, later): attaches `Authorization` per request. No-op under the
// default `'cookie'` transport — inert for web today.
api.interceptors.request.use((config) => {
  if (httpConfig.transport === 'bearer') {
    const token = httpConfig.getBearerToken?.()
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
  }
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
type RetriableConfig = InternalAxiosRequestConfig & { _csrfRetried?: boolean }

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
    if (status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true
      await getCsrfCookie()
      return api(config)
    }

    const onLoginPage = typeof window !== 'undefined' && window.location.pathname === '/login'

    // 401: session missing/expired — clear auth state and bounce to /login,
    // unless we're already there (avoid a redirect loop).
    if (status === 401) {
      if (!onLoginPage) {
        onUnauthorized?.()
      }
      return Promise.reject(error)
    }

    // 403 + USER_DEACTIVATED: account was deactivated mid-session — clear
    // state, notify the user, and bounce to /login.
    if (status === 403 && code === 'USER_DEACTIVATED') {
      if (!onLoginPage) {
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
