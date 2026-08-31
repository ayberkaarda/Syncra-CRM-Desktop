// The desktop HTTP client — bearer, never cookies.
//
// Split out of `desktop.ts` so the `data/` modules can reach it without importing the module
// that assembles the platform (which imports them back). It is the same axios instance the
// web uses, reconfigured; `SYNCDESKTOP.md` §7.1 keeps one client per platform, not two.
import { api, configureHttp } from '@/lib/axios'
import type { Platform } from '@/platform/types'

/**
 * The device token, held in memory only. The durable copy lives in the OS keychain on the
 * Rust side (K9); the webview never persists it.
 */
let deviceToken: string | undefined

/** Called by the auth bridge once a session exists. Pass `undefined` on logout. */
export function setDeviceToken(token: string | undefined): void {
  deviceToken = token
}

/** The device token currently in effect, if any. */
export function getDeviceToken(): string | undefined {
  return deviceToken
}

// TUZAK 2 / KARAR A12 (`docs/DESKTOP-ARCHITECTURE.md` §6.4): the webview's origin on Windows
// is `http://tauri.localhost`. Were that origin ever treated as stateful by Sanctum, every
// bearer POST would come back 419 and `lib/axios.ts`'s single CSRF retry would make it look
// like a random one-off failure. Second line of defence, here: `transport: 'bearer'` turns
// `withCredentials`/`withXSRFToken` OFF, so no cookie is ever sent and that path cannot be
// entered at all.
configureHttp({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  transport: 'bearer',
  getBearerToken: () => deviceToken,
})

// `Parameters<typeof api.get>[1]` rather than `AxiosRequestConfig`: `axios` is a
// `frontend/package.json` dependency and is not resolvable from `desktop/`, and the platform
// contract deliberately types `config` as `unknown` so axios never enters it (§3.3).
type HttpConfig = Parameters<typeof api.get>[1]

/** `Platform['http']` for the desktop: the shared axios instance, unwrapped to the body. */
export const http: Platform['http'] = {
  get: async (url, config) => (await api.get(url, config as HttpConfig)).data,
  post: async (url, body, config) => (await api.post(url, body, config as HttpConfig)).data,
  put: async (url, body, config) => (await api.put(url, body, config as HttpConfig)).data,
  patch: async (url, body, config) => (await api.patch(url, body, config as HttpConfig)).data,
  delete: async (url, config) => (await api.delete(url, config as HttpConfig)).data,
}
