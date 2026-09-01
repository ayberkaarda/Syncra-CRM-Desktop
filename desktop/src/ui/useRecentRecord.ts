// Report the record the window is showing to the Windows JumpList (§6.4, defter O85).
//
// Mounted once, from `DesktopShell`. It watches the pathname the same way `RecordActions` does
// (`useRoutePath`), and on every *new* record detail route it resolves a title and calls
// `record_opened`. Rust does the rest.
import { useEffect, useRef } from 'react'

import { rowById } from '../platform/data/engine'

import { recordOpened } from './commands'
import { entityTableOf, recentTargetOf, recentTitleOf } from './recent-records'
import { useRoutePath } from './useRoutePath'
import { useT } from './useT'

/**
 * Send `record_opened` for each record detail route the user lands on.
 *
 * ## Why the last target is remembered
 *
 * `useRoutePath` re-reads `window.location` every 400 ms, so the effect's dependencies settle
 * on the same value over and over while the user reads a record; without the ref, every tick
 * would be a mirror query, a file write and a COM round trip for a list that did not change.
 * The ref is also what makes React 18's StrictMode double-mount harmless.
 *
 * It is deliberately NOT reset when the language changes: `t` is a dependency because the
 * fallback title is translated, but re-sending the same record under a new language would
 * reorder the list (the newest entry goes to the front) for a change the user made in Settings,
 * not in the record. The stored title updates on the next real visit.
 *
 * ## Every failure is swallowed, and that is the whole contract
 *
 * A jump list is an accelerator on a menu the user may never open. It must not raise a toast, it
 * must not block a navigation, and it must not turn a mirror miss into an error: a record that
 * is not in the local mirror still gets an entry, under the entity-and-id fallback title. The
 * command's own rejections (`VALIDATION_ERROR`, `OS_ERROR`) reach `.catch` here for the same
 * reason — nothing the user can do about a shell refusal, and nothing they asked for.
 */
export function useRecentRecord(): void {
  const path = useRoutePath()
  const t = useT()
  const lastReported = useRef<string | null>(null)

  useEffect(() => {
    const target = recentTargetOf(path)
    if (target === null) return

    const key = `${target.entity}/${target.id}`
    if (lastReported.current === key) return
    lastReported.current = key

    let cancelled = false

    void (async () => {
      // A mirror miss is not a failure — see the doc comment. `rowById` takes the numeric id a
      // DTO carries; `recentTargetOf` has already proved the segment is one to twelve digits.
      const row = await rowById(entityTableOf(target.entity), Number(target.id)).catch(
        () => null,
      )
      if (cancelled) return

      await recordOpened(
        target.entity,
        target.id,
        recentTitleOf(t, target, row),
        t('desktop:jumpList.category'),
      ).catch(() => undefined)
    })()

    return () => {
      cancelled = true
    }
  }, [path, t])
}
