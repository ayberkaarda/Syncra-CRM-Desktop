// The one place that turns a mirror row into a human-readable name.
//
// Two screens need it and they are not near each other: `panels/PendingRecords.tsx` labels the
// rows waiting in the outbox, and `recent-records.ts` labels the record that just went onto the
// Windows jump list (`SYNCDESKTOP.md` §6.4, defter O85). Both are answering the same question —
// "what is this row called?" — about the same eleven-plus mirror shapes, and a second copy of
// the column list is a second thing to keep in step with the sync scope.
//
// ## The column list is written by hand, and derivation is FORBIDDEN
//
// Same rule as `ENTITY_QUERY_KEYS` (KARAR D-5), `deeplink-routes.ts` and `record-context.ts`:
// there is no rule that produces `subject` for a ticket, `quote_number` for a quote and
// `first_name`/`last_name` for a contact. The list is transcribed from the mirror columns the
// sync scope actually carries, most specific first, and a row that matches none of them gets no
// name from this module — the caller decides what to do with that, because the honest fallback
// differs (the pending panel shows the `client_id`, which is copyable and stable; the jump list
// shows an entity label and an id, because a UUID on a taskbar menu is worse than useless).
import type { LocalRow } from '../platform/data/engine'

/**
 * Columns that carry a human-readable name, most specific first.
 *
 * Mirror column names, transcribed from the sync scope — not derived.
 */
export const LABEL_COLUMNS = [
  'title',
  'name',
  'subject',
  'quote_number',
  'ticket_number',
  'email',
  'body',
] as const

/**
 * The row's display name, or `''` when none of the known columns carries one.
 *
 * `''` rather than a placeholder: this module owns no UI text (§0.6), and every caller has a
 * better fallback than a constant this file could invent.
 */
export function recordLabel(row: LocalRow): string {
  for (const column of LABEL_COLUMNS) {
    const value = row[column]
    if (typeof value === 'string' && value.trim() !== '') return value.trim()
  }

  const first = typeof row.first_name === 'string' ? row.first_name : ''
  const last = typeof row.last_name === 'string' ? row.last_name : ''
  return `${first} ${last}`.trim()
}
