// Structural error signals shared by both platform adapters — defter O91.
//
// `PlatformError.code` (`types.ts`) is already the contract every command/HTTP failure carries;
// this module is where a SHARED page (`frontend/src/features/*/pages`) reads one particular code
// off it without string-matching an error message.
//
// ## Why this file, not `desktop/src/ui/errors.ts`
//
// `desktop/src/ui/errors.ts` resolves a code to a `desktop.errors.<code>` sentence, but it lives
// under `desktop/src`, which `frontend/src` never imports (`platform/index.ts` — Tauri code must
// not leak into the web bundle). A page under `frontend/src/features/*/pages` needs the same
// distinction `desktop/src/ui/errors.ts` makes, so the check is duplicated here rather than
// imported — the same trade `OnlineOnlyError`'s `code: 'ONLINE_ONLY'` (`types.ts`) already makes.
//
// ## Why there is no platform branch in here (KARAR A19)
//
// `ROW_NOT_LOCAL` is raised only by `desktop/src/platform/data/engine.ts`'s `MissingRowError`,
// when a read addresses a row the local mirror does not currently hold (retention window, or not
// pulled yet — see that class's doc comment). `platform/web.ts` never produces this code: every
// web read either succeeds or rejects with the server's own HTTP failure. So `isRecordNotMirrored`
// is always `false` on the web build, and a page that branches on it falls through to its ordinary
// generic error state there, unchanged — no `isDesktop`, no `getPlatform()` check needed.
const ROW_NOT_LOCAL = 'ROW_NOT_LOCAL'

/**
 * True when `error` is a platform failure carrying the `ROW_NOT_LOCAL` code — the record a
 * detail page tried to read is not (yet, or any longer) in the local mirror, as opposed to a
 * real failure. Works on both `CommandError` (`bridge/invoke.ts`) and the plain `MissingRowError`
 * thrown directly by the data layer (`platform/data/engine.ts`, `writes.ts`) — this only checks
 * the shape, not the class.
 */
export function isRecordNotMirrored(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'code' in error &&
    (error as { code: unknown }).code === ROW_NOT_LOCAL
  )
}
