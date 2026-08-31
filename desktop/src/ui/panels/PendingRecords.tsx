// Pending records — the reachable half of `SYNCDESKTOP.md` §7.2's "kayıt rozetleri".
//
// ## Why the badges are also collected here
//
// The badge itself is `ui/SyncStateBadge.tsx` and it is ready to drop into a record row. Putting
// it INTO those rows means editing `frontend/src/features/*/pages/*` — a tree this strand
// cannot write, and one three other lanes are working in this turn. So this screen renders the
// same information from the other end: every row the local mirror still holds in `pending` or
// `conflict`, per entity, in the order the outbox will push them (`SYNCDESKTOP.md` §5.4).
//
// That is not a substitute for the per-row badge and is not claimed to be one — it is the part
// that is deliverable without a `frontend/**` edit, and it answers the same question ("what of
// mine has not reached the server?") in one place instead of eleven.
import { useCallback, useEffect, useState } from 'react'

import { EmptyState } from '@/components/ui'

import type { EntityName, LocalRow } from '../../platform/data/engine'
import { listPendingRows, syncStateOf, WRITABLE_ENTITIES } from '../commands'
import { errorCodeOf, errorMessage } from '../errors'
import { PendingIcon } from '../icons'
import { SyncStateBadge } from '../SyncStateBadge'
import { useEngineStatus } from '../useEngineStatus'
import { useT } from '../useT'

/**
 * Rows read per entity. This is a status screen, not a work queue: eleven entities at 25 rows
 * is already more than anyone reads, and the count in the connectivity bar (`status.pending`)
 * is the authoritative total.
 */
const ROWS_PER_ENTITY = 25

/**
 * Columns that carry a human-readable name, most specific first.
 *
 * These are mirror column names, transcribed from the sync scope — not derived. A row that
 * matches none of them falls back to its `client_id`, which is at least stable and copyable.
 */
const LABEL_COLUMNS = [
  'title',
  'name',
  'subject',
  'quote_number',
  'ticket_number',
  'email',
  'body',
] as const

interface PendingEntry {
  entity: EntityName
  clientId: string
  label: string
  row: LocalRow
}

function labelOf(row: LocalRow): string {
  for (const column of LABEL_COLUMNS) {
    const value = row[column]
    if (typeof value === 'string' && value.trim() !== '') return value
  }
  const first = typeof row.first_name === 'string' ? row.first_name : ''
  const last = typeof row.last_name === 'string' ? row.last_name : ''
  const full = `${first} ${last}`.trim()
  if (full !== '') return full
  return typeof row.client_id === 'string' ? row.client_id : ''
}

export function PendingRecords() {
  const t = useT()
  const status = useEngineStatus()

  const [entries, setEntries] = useState<PendingEntry[] | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      const perEntity = await Promise.all(
        WRITABLE_ENTITIES.map(async (entity) => {
          const rows = await listPendingRows(entity, { limit: ROWS_PER_ENTITY })
          return rows.map<PendingEntry>((row) => ({
            entity,
            clientId: typeof row.client_id === 'string' ? row.client_id : '',
            label: labelOf(row),
            row,
          }))
        })
      )
      setEntries(perEntity.flat())
      setLoadError(null)
    } catch (error) {
      setEntries([])
      setLoadError(errorMessage(t, errorCodeOf(error)))
    }
  }, [t])

  // Re-read whenever the engine's own counters move: a push that lands clears rows here, and a
  // local write adds one. `status` is the single feed both of those already flow through.
  useEffect(() => {
    void load()
  }, [load, status.pending, status.conflicts])

  if (entries === null) {
    return <p className="text-sm text-fg-muted">{t('common:states.loading')}</p>
  }

  return (
    <div className="flex flex-col gap-4">
      {loadError && (
        <p className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">{loadError}</p>
      )}

      {entries.length === 0 ? (
        <EmptyState
          icon={<PendingIcon className="size-6" />}
          title={t('desktop:sync.pendingChanges', { count: 0 })}
          description={t('common:states.empty')}
        />
      ) : (
        <ul className="flex flex-col gap-1">
          {entries.map((entry) => (
            <li
              key={`${entry.entity}:${entry.clientId}`}
              className="flex items-center gap-3 rounded-md border border-border-subtle bg-surface-1 px-3 py-2"
            >
              {/* The entity name is the engine's own table identifier, rendered as data — the
                  same way the conflict rows and the device `platform` column render theirs. */}
              <span className="w-28 shrink-0 truncate font-mono text-xs text-fg-muted">
                {entry.entity}
              </span>
              <span className="min-w-0 flex-1 truncate text-sm text-fg">{entry.label}</span>
              <SyncStateBadge state={syncStateOf(entry.row)} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
