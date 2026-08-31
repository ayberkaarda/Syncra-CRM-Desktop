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

  constructor(error: PlatformError) {
    super(error.message)
    this.name = 'CommandError'
    this.code = error.code
    this.fields = error.fields
  }
}

function hasStringProp(value: object, key: string): boolean {
  return key in value && typeof (value as Record<string, unknown>)[key] === 'string'
}

/**
 * Normalise whatever Tauri rejected with into a {@link CommandError}. A well-formed
 * `{code, message}` is preserved as-is; anything else (a panic string, a serde failure, a
 * plugin error) becomes `UNKNOWN` with the original stringified into `message` so it still
 * reaches the log.
 */
export function toCommandError(raw: unknown): CommandError {
  if (typeof raw === 'object' && raw !== null && hasStringProp(raw, 'code') && hasStringProp(raw, 'message')) {
    return new CommandError(raw as PlatformError)
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
