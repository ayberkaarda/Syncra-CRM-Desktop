// Record sync-state badge — `SYNCDESKTOP.md` §7.2 ("kayıt rozetleri: `pending`, `conflict`").
//
// ## Why this lives in `frontend/src/components/shared`, not in `desktop/src/ui`
//
// It started in `desktop/src/ui/SyncStateBadge.tsx`, where only the desktop shell could reach
// it: `frontend` and `desktop` are two builds, and a page under `frontend/src/features/*/pages`
// cannot import out of the desktop tree. But the badge's whole purpose is to appear IN those
// pages — a user who edits a company offline and returns to the list must see that the change
// has not left the machine. So the component moved to the side both builds already share (the
// desktop app renders the frontend's own pages, and its shell reaches back through the `@`
// alias for `@/components/ui`), next to `Timeline` — the other cross-feature composite built on
// the `ui/` primitives.
//
// ## Why there is no platform branch in here
//
// KARAR A19: the platform layer differs, component code does not. This file contains no
// `isDesktop`, no `getPlatform()`, no conditional import — and it does not need one, because
// the state it renders can only exist where copies of records exist:
//
//   * the web adapter (`platform/web.ts`) talks to the API and never writes `sync_state`, so
//     `recordSyncState()` (the sibling module a list page reads the field with) finds
//     `undefined` there and this component returns `null` — the web UI is what it always was;
//   * the desktop adapter maps mirror rows and carries the column through
//     (`desktop/src/platform/data/mappers.ts`), so exactly the rows that are waiting in the
//     outbox, or that the server rejected, light up.
//
// `synced` renders nothing either: a badge on every row is a badge on no row, and the point of
// this indicator is that the handful of records which have NOT reached the server stand out.
import { useTranslation } from 'react-i18next'

import { Badge } from '../ui'
import type { SyncState } from '../../platform/types'

export type SyncStateBadgeProps = {
  state: SyncState | null | undefined
  /**
   * Dot only, with the label moved into `title`/`aria-label`. This is the form meant for a
   * table cell next to a record's name, where a full sentence would push the column open.
   */
  compact?: boolean
}

export function SyncStateBadge({ state, compact = false }: SyncStateBadgeProps) {
  const { t } = useTranslation()

  // `synced`, `tombstone`, `null` and `undefined` all render nothing — the last two being the
  // only values the web ever produces, which is why this component is inert there.
  if (state !== 'pending' && state !== 'conflict') return null

  const isConflict = state === 'conflict'
  const label = isConflict ? t('desktop:recordBadge.conflict') : t('desktop:recordBadge.pending')

  // `shrink-0`: the record's own name is what should be truncated when a column gets tight; an
  // indicator squeezed to half a dot would be worse than no indicator at all.
  return (
    <Badge
      dot
      size="sm"
      variant={isConflict ? 'danger' : 'warning'}
      title={label}
      aria-label={compact ? label : undefined}
      className="shrink-0"
    >
      {compact ? null : label}
    </Badge>
  )
}
