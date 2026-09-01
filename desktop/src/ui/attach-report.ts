// One `AttachOutcome` -> the sentence the user gets.
//
// Separate from the components that raise it because this is the part that can be wrong
// without anyone noticing: a `queued` file reported as `uploaded` is a document the user
// believes is on the record and is not. `files::attach_from_paths` returns one verdict per
// file precisely so each can be reported on its own, and this table is where that promise is
// either kept or quietly broken.
//
// `queued.reason` and `rejected.code` both come from the `desktop.errors.*` vocabulary, so the
// caller appends `errorMessage(t, code)` — the WHY is never re-worded here, which is what
// keeps `FILE_TOO_LARGE` reading the same on this path as it does in the Conflict Inbox.
import type { AttachOutcome } from './files'

/** The `toast` method to raise the report with. */
export type AttachReportLevel = 'success' | 'warning' | 'error'

export interface AttachReport {
  level: AttachReportLevel
  /** i18n key, interpolated with `{ name }`. */
  key: string
  /** The file name the user recognises — the source file's own, never the server's. */
  name: string
  /** A `desktop.errors.*` code to append, or `null` when the outcome needs no explanation. */
  code: string | null
}

/**
 * How one file's verdict is announced.
 *
 * `queued` is a WARNING, not a success: the file is on disk and nothing was sent, and the one
 * thing the user must not conclude is that the record now carries it. It is not an error
 * either — queuing offline is the designed behaviour (§8), and the file is not lost.
 */
export function reportForOutcome(outcome: AttachOutcome): AttachReport {
  switch (outcome.status) {
    case 'uploaded':
      return {
        level: 'success',
        key: 'desktop:files.attach.uploaded',
        name: outcome.original_name,
        code: null,
      }
    case 'queued':
      return {
        level: 'warning',
        key: 'desktop:files.attach.queued',
        name: outcome.original_name,
        code: outcome.reason,
      }
    case 'rejected':
      return {
        level: 'error',
        key: 'desktop:files.attach.rejected',
        name: outcome.original_name,
        code: outcome.code,
      }
  }
}
