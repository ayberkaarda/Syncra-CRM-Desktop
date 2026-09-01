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
  // FIX-RUST made the server's own refusal codes structural (`SyncError::Server{code, ...}`),
  // so these two finally arrive as themselves instead of being buried in a message string.
  // Both keys already existed in all four dictionaries and nothing consumed them (O20).
  'INVALID_CREDENTIALS',
  'PENDING_MUTATIONS',
  // O25: a `5xx` is `SyncError::Server` now, not `SyncError::Offline` — a real server error
  // no longer reads as "no internet connection". `commands/mod.rs` falls back to this code
  // whenever the server's own body carried none of its own (the common case for a `500`).
  'SERVER_ERROR',
  // O48 / finding B7: the four dictionaries got `errors.INVALID_MUTATION` but this set did not,
  // so `errorMessage()` fell through to `errors.unknown` and the Conflict Inbox showed every
  // server refusal as "An unknown error occurred." — the second F4 scenario run caught it with a
  // deliberate reject probe. A key can exist in all four locales and still be dead if its code is
  // not listed here; that asymmetry is exactly what open item O55 proposes to check statically.
  'INVALID_MUTATION',
  // F5-2/7: an OS-side failure the user did not cause — autostart refused by the system, the
  // toast could not be handed to the notification centre, the main window is gone.
  // `VALIDATION_ERROR` would be the wrong shape: nothing the user typed is at fault.
  'OS_ERROR',
  // F5-5 (§6.4 drag-drop): per-file refusals from `commands::files`. They are separate codes
  // because a bulk drop reports one verdict per file, and "invalid" would not tell the user
  // which file, why, or whether it is their file or their device that is the problem.
  'FILE_TYPE_REJECTED',
  'FILE_TOO_LARGE',
  'QUEUE_FULL',
  // O91: `MissingRowError` (`platform/data/engine.ts`) — a read addressed a row the local
  // mirror does not currently hold (outside the retention window, or not pulled yet). Structural
  // signal, not a generic failure: a page that sees this code can say so instead of showing the
  // same sentence a real error would.
  'ROW_NOT_LOCAL',
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

/**
 * `retry_after` off a rejected command, in seconds.
 *
 * `bridge/invoke.ts` copies `CommandError.retry_after` onto `CommandError.retryAfter`; this is
 * the read side, kept next to `errorCodeOf` because the two are always used together — a
 * `LOCKED_OUT` code without its countdown is only half the refusal.
 */
export function retryAfterOf(error: unknown): number | undefined {
  if (typeof error === 'object' && error !== null && 'retryAfter' in error) {
    const value = (error as { retryAfter: unknown }).retryAfter
    if (typeof value === 'number' && Number.isFinite(value) && value > 0) return value
  }
  return undefined
}
