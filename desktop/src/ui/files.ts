// The `files::*` command surface, typed — `SYNCDESKTOP.md` §6.2, §6.4 items 5 and 8.
//
// Kept out of `commands.ts` because this is the one command module with no engine method
// behind it (`src-tauri/src/commands/files.rs`): the quote-PDF cache and the attachment queue
// are shell-owned directories under `$APPDATA/syncra/cache`, not outbox rows, and the wire
// types below are that module's own rather than `syncra_sync::*`.
//
// Argument keys are camelCase (`quoteId`, `ticketId`): Tauri 2 renames command ARGUMENTS on
// the JS side. Struct fields stay snake_case, because serde marshals them verbatim — which is
// why `AttachOutcome` reads `original_name` inside an object whose sibling call passes
// `quoteId`.
import { invokeCommand } from '../bridge/invoke'

/** `commands::files::RecordKind` — `RecordChatRegistry::TYPES`, the only two the server takes. */
export type RecordKind = 'deal' | 'ticket'

/**
 * `commands::files::AttachTarget`, tagged on `kind`.
 *
 * `record` runs the three-step server flow (`POST /api/attachments` -> `for-record` ->
 * `messages`) because `AttachmentPolicy` fails closed on any `attachable_type` other than
 * `Message`: there is no endpoint that hangs a file directly off a ticket or a deal.
 * `unattached` uploads with `attachable_id = NULL` and leaves the linking to whoever sends the
 * message, which on this shell nothing does — see `FileDrop.tsx` for why a drop outside a
 * record is refused instead of silently becoming an orphan row.
 */
export type AttachTarget =
  | { kind: 'unattached' }
  | { kind: 'record'; record: RecordKind; id: number }

/** `commands::files::UploadedAttachment` — one row of `AttachmentResource`. */
export interface UploadedAttachment {
  id: number
  original_name: string
  mime_type: string
  size: number
  is_image: boolean
  url: string
}

/**
 * `commands::files::AttachOutcome`, tagged on `status` — one verdict per file.
 *
 * A batch never fails as a whole, on purpose: six files dropped and one error message says
 * nothing about which five went through. `queued.reason` and `rejected.code` are both drawn
 * from the `desktop.errors.*` vocabulary, so `errorMessage()` renders either one.
 */
export type AttachOutcome =
  | {
      status: 'uploaded'
      original_name: string
      attachment: UploadedAttachment
      /** Present only for a `record` target; `unattached` links nothing yet. */
      message_id?: number
    }
  | {
      status: 'queued'
      original_name: string
      queue_id: string
      bytes: number
      /** `OFFLINE`, `AUTH_REQUIRED`, `HTTP_422`, … */
      reason: string
    }
  | {
      status: 'rejected'
      original_name: string
      /** `FILE_TYPE_REJECTED`, `FILE_TOO_LARGE`, `QUEUE_FULL` or `VALIDATION_ERROR`. */
      code: string
      /** Detail for the log; the UI renders `code`. */
      message: string
    }

/** `commands::files::CachedPdf`. */
export interface CachedPdf {
  /** Absolute path, ready to hand straight to {@link openCached}. */
  path: string
  bytes: number
  /** `true` when the file was already on disk and no request went out. */
  from_cache: boolean
}

/** `commands::files::CaptureRegion`, in virtual-desktop coordinates. */
export interface CaptureRegion {
  x: number
  y: number
  width: number
  height: number
}

/**
 * `files::cache_quote_pdf` — `GET /api/quotes/{id}/pdf` into
 * `$APPDATA/syncra/cache/quotes/{id}-{rev}.pdf`.
 *
 * Online-only when uncached (§8); a hit never touches the network, which is the whole point.
 * See {@link quotePdfNeedsRefresh} for what `refresh` must be.
 */
export function cacheQuotePdf(
  quoteId: number,
  revision: number,
  refresh: boolean,
): Promise<CachedPdf> {
  return invokeCommand<CachedPdf>('cache_quote_pdf', { quoteId, revision, refresh })
}

/**
 * Whether a cached PDF for this quote may be reused, i.e. the `refresh` argument of
 * {@link cacheQuotePdf}.
 *
 * **The rule is the command's own** (`cache_quote_pdf` rustdoc, D3), recorded there because
 * this wrapper did not exist yet: a **draft** can change under a fixed `revision` — `revision`
 * only increments when a *sent* quote is superseded, never on an edit — so `{id}-{rev}` stops
 * naming an immutable document exactly for drafts, and a cache hit would serve a stale PDF of
 * a quote the user just changed. Every other status has an immutable document under that name
 * and reads from disk, offline included.
 *
 * Asking the server for the current revision instead was considered and rejected: it costs a
 * round trip for the common case that would otherwise never make one, and it cannot work
 * offline at all.
 */
export function quotePdfNeedsRefresh(status: string): boolean {
  return status === 'draft'
}

/**
 * `files::open_cached` — hand a cached file to the OS's default application.
 *
 * `path` must be one this shell put in the cache: the command resolves it against
 * `$APPDATA/syncra/cache` and refuses anything outside.
 */
export function openCached(path: string): Promise<void> {
  return invokeCommand<void>('open_cached', { path })
}

/**
 * `files::attach_from_paths` — stage a dropped batch and deliver what it can.
 *
 * One {@link AttachOutcome} per input path, in order. The command itself only rejects when the
 * REQUEST is wrong (an empty batch, an unusable target); a bad FILE comes back as a `rejected`
 * outcome rather than failing the batch.
 */
export function attachFromPaths(
  paths: readonly string[],
  target: AttachTarget,
): Promise<AttachOutcome[]> {
  return invokeCommand<AttachOutcome[]>('attach_from_paths', { paths, target })
}

/**
 * `files::screenshot_to_ticket` — capture, write a PNG, post it into the ticket's conversation.
 *
 * `region` omitted captures the whole primary screen, which is what §6.4 item 8 asks the
 * hotkey to do before any selection overlay exists. The PNG goes through the same queue a
 * dropped file does, so a capture taken offline survives instead of being lost to an error.
 */
export function screenshotToTicket(
  ticketId: number,
  region?: CaptureRegion,
): Promise<AttachOutcome> {
  return invokeCommand<AttachOutcome>('screenshot_to_ticket', { ticketId, region })
}
