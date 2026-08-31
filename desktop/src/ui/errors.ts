// Command failure -> `desktop.errors.*` key resolution (KARAR A10).
//
// Every Rust command rejects with `{code, message}` (`bridge/invoke.ts` normalises it into a
// `CommandError`), and every push result the engine could not apply silently carries the same
// `code` on a `Conflict` row. Both feeds are rendered through this module, so a code the
// dictionary does not know degrades to `desktop.errors.unknown` instead of throwing: i18next's
// `missingKeyHandler` is configured to THROW in dev when a key is absent from the fallback
// language too (`frontend/src/i18n/index.ts`), which would turn an unrecognised server code
// into a white screen. Resolving the key here, against a transcribed list, is what keeps an
// unknown code a message rather than a crash.
import type { Translate } from './useT'

/**
 * Codes the four `frontend/src/i18n/locales/<lang>/desktop.json` files define under `errors`.
 * Transcribed, not derived — the dictionary lives in `frontend/**` and this strand cannot
 * write it, so drift has to fail loudly here rather than silently there.
 */
const KNOWN_ERROR_CODES = new Set<string>([
  'AUTH_REQUIRED',
  'OFFLINE',
  'PROTOCOL_ERROR',
  'DB_ERROR',
  'HTTP_ERROR',
  'WRITE_BLOCKED',
  'VALIDATION_ERROR',
  'ONLINE_ONLY',
  'UNRESOLVED_REFERENCE',
  'FIELD_CONFLICT',
  'RECORD_DELETED',
  'PROTOCOL_VERSION_MISMATCH',
  'ABILITY_REQUIRED',
  'LOCKED_OUT',
  'USER_INACTIVE',
])

/** `HTTP_403` and friends — `commands/auth.rs` builds this shape from any non-2xx response. */
const HTTP_STATUS_CODE = /^HTTP_(\d{3})$/

/**
 * The user-facing sentence for one engine/command error code.
 *
 * `SYNCDESKTOP.md` §8's rejection codes (`ONLINE_ONLY`, `UNRESOLVED_REFERENCE`) and the
 * permission refusals A22 pushed into this phase (`ABILITY_REQUIRED`, `HTTP_403`) all arrive
 * here, which is why the Conflict Inbox can answer "why did this not happen" without any
 * hard-coded prose.
 */
export function errorMessage(t: Translate, code: string): string {
  const httpStatus = HTTP_STATUS_CODE.exec(code)
  if (httpStatus) {
    return t('desktop:errors.httpStatus', { status: Number(httpStatus[1]) })
  }
  if (KNOWN_ERROR_CODES.has(code)) {
    return t(`desktop:errors.${code}`)
  }
  return t('desktop:errors.unknown')
}

/** `code` off a rejected promise, whatever it was rejected with. */
export function errorCodeOf(error: unknown): string {
  if (typeof error === 'object' && error !== null && 'code' in error) {
    const code = (error as { code: unknown }).code
    if (typeof code === 'string') return code
  }
  return 'unknown'
}
