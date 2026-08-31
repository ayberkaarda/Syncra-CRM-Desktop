// Record badge — `SYNCDESKTOP.md` §7.2 ("kayıt rozetleri: `pending`, `conflict`").
//
// A row of the local mirror carries `sync_state` (`SYNCDESKTOP.md` §5.3). `synced` and
// `tombstone` render nothing at all: a badge on every row is a badge on no row, and the whole
// point of this indicator is that the handful of records which have NOT reached the server
// stand out.
import { Badge } from '@/components/ui'

import type { SyncState } from './commands'
import { useT } from './useT'

export interface SyncStateBadgeProps {
  state: SyncState | null
  /**
   * Dot only, with the label moved into `title`/`aria-label`. This is the form meant for a
   * table cell next to a record's name, where a full sentence would push the column open.
   */
  compact?: boolean
}

export function SyncStateBadge({ state, compact = false }: SyncStateBadgeProps) {
  const t = useT()

  if (state !== 'pending' && state !== 'conflict') return null

  const isConflict = state === 'conflict'
  const label = isConflict
    ? t('desktop:sync.status.conflict')
    : t('desktop:sync.pendingChanges', { count: 1 })

  return (
    <Badge
      dot
      size="sm"
      variant={isConflict ? 'danger' : 'warning'}
      title={label}
      aria-label={compact ? label : undefined}
    >
      {compact ? null : label}
    </Badge>
  )
}
