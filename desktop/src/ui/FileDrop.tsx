// Drag-and-drop attachments — `SYNCDESKTOP.md` §6.4 item 5.
//
// The window is created with `dragDropEnabled: true` (`tauri.conf.json`), so the webview's own
// HTML drag events are suppressed and the RUNTIME emits `tauri://drag-drop` instead. That is
// the only event that carries file SYSTEM PATHS: an HTML `DataTransfer` hands out `File`
// handles with no path at all, which is exactly why `comms.uploadAttachment` could never feed
// the queue (`platform/data/comms.ts` says so on the method). So this listener, and not a React
// `onDrop`, is where §6.4's drag-drop lives.
//
// ## A drop outside a deal or a ticket is refused, not "uploaded anyway"
//
// `AttachTarget::Unattached` exists and would accept any drop, but it creates an attachment row
// with `attachable_id = NULL` that only a chat message can ever link — and this shell has no
// way to reach the composer's state to hand it the id (KARAR A27: the chrome is mounted outside
// the router, and `MessageComposer` is `frontend/**`). Uploading anyway would leave the user
// with a file that is on the server, counts against nothing, and appears on no record. Saying
// where drops work is the honest answer.
import { useEffect, useRef } from 'react'

import { toast } from '@/components/ui'
import { listen, type UnlistenFn } from '@tauri-apps/api/event'

import { reportForOutcome } from './attach-report'
import { errorCodeOf, errorMessage } from './errors'
import { attachFromPaths } from './files'
import { recordContextOf } from './record-context'
import { useT, type Translate } from './useT'

/** The runtime event a completed drop arrives on (Tauri 2 `DragDropEvent::Drop`). */
export const DRAG_DROP_EVENT = 'tauri://drag-drop'

/** Payload of {@link DRAG_DROP_EVENT}. `position` is unused: the route decides the target. */
interface DragDropPayload {
  paths: string[]
}

/**
 * Mounted once, by `DesktopShell`. Renders nothing — it is a listener with a lifetime.
 *
 * Toasts are raised per FILE, because `attach_from_paths` answers per file: five uploads and
 * one rejection is five successes and one explanation, not a single ambiguous message.
 */
export function FileDrop() {
  const t = useT()

  // The listener is bound ONCE and reads `t` through a ref. Putting `t` in the dependency list
  // instead would tear the subscription down and rebuild it on every language change, and a
  // drop that landed in that window would be dropped in the other sense.
  const translate = useRef<Translate>(t)
  translate.current = t

  useEffect(() => {
    let unlisten: UnlistenFn | null = null
    let disposed = false

    void listen<DragDropPayload>(DRAG_DROP_EVENT, (event) => {
      void handleDrop(event.payload.paths, translate.current)
    })
      .then((fn) => {
        // `listen` resolves asynchronously; if the component unmounted while it was in flight
        // the handle would otherwise leak and keep answering drops for a dead tree.
        if (disposed) fn()
        else unlisten = fn
      })
      .catch(() => undefined)

    return () => {
      disposed = true
      unlisten?.()
    }
  }, [])

  return null
}

async function handleDrop(paths: readonly string[], t: Translate): Promise<void> {
  if (paths.length === 0) return

  const target = recordContextOf(window.location.pathname)
  if (target === null) {
    toast.info(t('desktop:files.drop.noTarget'))
    return
  }

  // The upload is a network round trip per file. Saying the batch was accepted before it
  // finishes is the difference between "nothing happened" and "something is happening"; the
  // per-file verdicts follow.
  toast.info(t('desktop:files.drop.started', { count: paths.length }))

  try {
    const outcomes = await attachFromPaths(paths, {
      kind: 'record',
      record: target.kind,
      id: target.id,
    })
    for (const outcome of outcomes) {
      const report = reportForOutcome(outcome)
      const sentence = t(report.key, { name: report.name })
      toast[report.level](
        report.code === null ? sentence : `${sentence} ${errorMessage(t, report.code)}`,
      )
    }
  } catch (error) {
    // The BATCH was refused (an unusable target, a cache directory that cannot be written) —
    // as opposed to a file being refused, which arrives as a `rejected` outcome above.
    toast.error(`${t('desktop:files.drop.failed')} ${errorMessage(t, errorCodeOf(error))}`)
  }
}
