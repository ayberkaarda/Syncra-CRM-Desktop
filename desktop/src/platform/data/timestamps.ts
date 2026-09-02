// The mirror timestamp parser's former home — now a re-export, deliberately kept.
//
// The implementation moved to `frontend/src/lib/mirrorTime.ts` (its docblock explains why: the
// notification list and `lib/datetime.ts` live in the SHARED app and cannot import from
// `desktop/src`, because the `@` alias only points the other way). This module was NOT deleted
// with it, for two reasons:
//
//   * `bridge/notifications.ts`, `platform/data/mappers.ts` and `ui/format.ts` already import
//     `parseMirrorTimestamp` from here, and `platform/data/` is the right layer for a mirror
//     data-shape fact to enter the desktop shell from — rewriting three call sites to reach
//     across the alias into `@/lib/mirrorTime` would make the shell's data layer thinner in
//     name only.
//   * `timestamps.test.ts` is the suite that pins the parser's behaviour against the desktop's
//     own callers, and it addresses the function through this path.
//
// So this file stays as the desktop-side name for the shared parser; the behaviour has exactly
// one definition, over in `@/lib/mirrorTime`.
export { parseMirrorTimestamp } from '@/lib/mirrorTime'
