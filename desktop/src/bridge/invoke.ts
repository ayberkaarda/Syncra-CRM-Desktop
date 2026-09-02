// Typed `invoke` wrapper — `docs/DESKTOP-ARCHITECTURE.md` §2.2 (`desktop/src/bridge/invoke.ts`).
//
// Every Rust command returns `{code, message}` on failure (`SYNCDESKTOP.md` §6.2, KARAR A10).
// Tauri rejects the promise with that object verbatim, i.e. with a plain object that is NOT an
// `Error` — it has no stack, `instanceof Error` is false, and anything that logs it or feeds it
// to a generic error path degrades badly. This module is the single place that normalises it.
import { invoke as tauriInvoke } from '@tauri-apps/api/core'

import type { PlatformError } from '@/platform/types'

/**
 * A command failure as a real `Error`, carrying the contract's `{code, message}`.
 *
 * `code` is what the UI maps through `desktop.errors.<code>`; an unrecognised code renders
 * `desktop.errors.unknown` (KARAR A10) — that lookup belongs to the UI layer, not here.
 */
export class CommandError extends Error implements PlatformError {
  readonly code: string
  readonly fields?: Record<string, string[]>
  /**
   * Seconds until a lockout expires, off `CommandError.retry_after`
   * (`src-tauri/src/commands/mod.rs`).
   *
   * Additive on the Rust side and optional here: only `423 LOCKED_OUT` carries it. Dropping it
   * — which this class used to do — is what left `LoginPage`'s lockout countdown dead on the
   * desktop, because the countdown reads a `retry-after` response header that nothing could
   * populate (`platform/auth.ts`, `loginFailure`).
   */
  readonly retryAfter?: number

  constructor(error: PlatformError & { retryAfter?: number }) {
    super(error.message)
    this.name = 'CommandError'
    this.code = error.code
    this.fields = error.fields
    this.retryAfter = error.retryAfter
  }
}

function hasStringProp(value: object, key: string): boolean {
  return key in value && typeof (value as Record<string, unknown>)[key] === 'string'
}

/** `retry_after` off the raw rejection, when the command sent one. */
function retryAfterOf(value: object): number | undefined {
  const raw = (value as Record<string, unknown>).retry_after
  return typeof raw === 'number' && Number.isFinite(raw) && raw > 0 ? raw : undefined
}

/**
 * Normalise whatever Tauri rejected with into a {@link CommandError}. A well-formed
 * `{code, message}` is preserved as-is; anything else (a panic string, a serde failure, a
 * plugin error) becomes `UNKNOWN` with the original stringified into `message` so it still
 * reaches the log.
 */
export function toCommandError(raw: unknown): CommandError {
  if (typeof raw === 'object' && raw !== null && hasStringProp(raw, 'code') && hasStringProp(raw, 'message')) {
    return new CommandError({ ...(raw as PlatformError), retryAfter: retryAfterOf(raw) })
  }
  return new CommandError({
    code: 'UNKNOWN',
    message: raw instanceof Error ? raw.message : String(raw),
  })
}

/** `invoke()` with the error contract applied. Every command call in `desktop/src` goes here. */
export async function invokeCommand<T>(command: string, args?: Record<string, unknown>): Promise<T> {
  try {
    return await tauriInvoke<T>(command, args)
  } catch (raw) {
    throw toCommandError(raw)
  }
}
