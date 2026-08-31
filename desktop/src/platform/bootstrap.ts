// First-run download — `SYNCDESKTOP.md` §10 F3 ("bootstrap ekranı"), §5.5.
//
// ## WHY THIS NOW DRIVES `bootstrap` AND NOT `download_archive`
//
// AUTH-1 had to imitate the bootstrap, for two reasons that were both shell/crate bugs:
//
//  1. `SyncEngine::bootstrap()` — the method that exists precisely for this, and the only one
//     that emits `BootstrapProgress` — was **not registered as a Tauri command**, so the
//     webview could not call it (U15).
//  2. `SyncEngine::sync_now()` pulled with `window_days: 0`, which `POST /api/sync/pull`
//     validates as `min:1`, so every round failed at the pull step (U3).
//
// The imitation was `download_archive(0)` plus a 400 ms poll that re-counted the mirror to
// guess how far along the download was. Both are fixed: `sync::bootstrap` is in
// `generate_handler!` and forwards every `BootstrapProgress` tick to the webview as a
// `bootstrap-progress` Tauri event. So this module calls the real command and listens for real
// progress — no second call shape, no poll, and the numbers on screen are the engine's own.
//
// ## WHAT THE EVENT CAN AND CANNOT SAY
//
// `BootstrapProgress` (`sync/mod.rs`, `pull_until_drained`) carries `{entity, rows_loaded,
// tables_done, tables_total}` where `rows_loaded` is the CUMULATIVE row count for the whole
// bootstrap and `entity` is the table the next request will drain — not a per-table total. So
// the per-table itemisation the screen shows is read from the mirror ONCE, when the run
// settles, rather than derived from the ticks: a single summary read, not a progress poll.
import { listen, type UnlistenFn } from '@tauri-apps/api/event'

import { invokeCommand } from '../bridge/invoke'
import { errorCodeOf } from '../ui/errors'
import { countRows, type EntityName, type NamedQuery } from './data/engine'

/** Tauri event name `src-tauri/src/events.rs` forwards each `BootstrapProgress` under. */
const BOOTSTRAP_PROGRESS = 'bootstrap-progress'

/** `syncra_sync::BootstrapProgress` (serde keeps the Rust field names). */
interface BootstrapProgress {
  entity: EntityName
  rows_loaded: number
  tables_done: number
  tables_total: number
}

/**
 * The mirror tables the completed screen itemises, each with the whitelisted query that can
 * count it.
 *
 * Not every entity in the pull has a countable named query (`conversation_user`, `message`,
 * `price_list_item`, …), and the ones missing here are still downloaded — they are simply not
 * itemised. Order is the order the tables are shown in. This list does NOT drive the progress
 * bar any more: `tables_total` comes from the engine, which knows the real granted set.
 */
const COUNTED: readonly (readonly [EntityName, NamedQuery])[] = [
  ['company', { query: 'company_list' }],
  ['contact', { query: 'contact_list' }],
  ['lead', { query: 'lead_list' }],
  ['deal', { query: 'deals_list' }],
  ['quote', { query: 'quote_list' }],
  ['task', { query: 'task_list' }],
  ['activity', { query: 'activity_list' }],
  ['ticket', { query: 'ticket_list' }],
  ['conversation', { query: 'conversation_list' }],
  ['notification', { query: 'notification_list' }],
  ['pipeline_stage', { query: 'pipeline_stages' }],
  ['custom_field', { query: 'custom_field_list' }],
  ['tag', { query: 'tag_list' }],
  ['setting', { query: 'setting_list' }],
  ['user', { query: 'user_list' }],
]

export type BootstrapPhase = 'idle' | 'running' | 'done' | 'error'

export interface BootstrapState {
  phase: BootstrapPhase
  /** The table the engine is draining, from the last progress event. */
  entity: EntityName | null
  /** Rows this bootstrap has written so far, across all tables. */
  rowsLoaded: number
  /** Tables the engine reports as fully drained — the numerator of the progress bar. */
  tablesDone: number
  /** Tables in this bootstrap, as the engine counted them (the granted set). */
  tablesTotal: number
  /** Per-table row counts, read once when the run settles. Empty while it runs. */
  counts: Partial<Record<EntityName, number>>
  /** `desktop.errors.*` code when `phase === 'error'`. */
  errorCode: string | null
}

const IDLE: BootstrapState = {
  phase: 'idle',
  entity: null,
  rowsLoaded: 0,
  tablesDone: 0,
  tablesTotal: 0,
  counts: {},
  errorCode: null,
}

let state: BootstrapState = IDLE

const listeners = new Set<() => void>()

/** Current bootstrap state. Stable identity between changes (`useSyncExternalStore`). */
export function getBootstrapState(): BootstrapState {
  return state
}

/** Observe {@link getBootstrapState}. */
export function subscribeToBootstrap(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

function publish(next: BootstrapState): void {
  state = next
  for (const listener of listeners) listener()
}

async function readCounts(): Promise<Partial<Record<EntityName, number>>> {
  const counts: Partial<Record<EntityName, number>> = {}
  await Promise.all(
    COUNTED.map(async ([entity, query]) => {
      try {
        counts[entity] = await countRows(query)
      } catch {
        // A table the local schema cannot count yet is reported as absent, not as a failure:
        // the download itself is what this screen is about.
      }
    }),
  )
  return counts
}

let running: Promise<void> | null = null

/**
 * Download everything inside the retention window and report the engine's own progress while
 * it happens.
 *
 * Concurrent callers share the round already in flight — the engine coalesces anyway, and two
 * subscriptions writing the same state would fight.
 */
export function startBootstrap(): Promise<void> {
  if (running !== null) return running

  publish({ ...IDLE, phase: 'running' })

  running = (async () => {
    let unlisten: UnlistenFn | null = null
    try {
      // Subscribed BEFORE the command is invoked: a tick emitted while nothing is listening is
      // gone, the Tauri event bus does not replay it.
      unlisten = await listen<BootstrapProgress>(BOOTSTRAP_PROGRESS, ({ payload }) => {
        if (state.phase !== 'running') return
        publish({
          ...state,
          entity: payload.entity,
          rowsLoaded: payload.rows_loaded,
          tablesDone: payload.tables_done,
          tablesTotal: payload.tables_total,
        })
      })

      await invokeCommand<void>('bootstrap')
      publish({ ...state, phase: 'done', entity: null, counts: await readCounts() })
    } catch (error) {
      publish({
        ...state,
        phase: 'error',
        entity: null,
        counts: await readCounts(),
        errorCode: errorCodeOf(error),
      })
    } finally {
      unlisten?.()
      running = null
    }
  })()

  return running
}

/**
 * Run the first download only if the mirror is actually empty.
 *
 * Used on the restore and login paths: an already-populated device must not re-download 237
 * rows and sit behind a progress screen every time it opens.
 */
export async function startBootstrapIfEmpty(): Promise<void> {
  const counts = await readCounts()
  const total = Object.values(counts).reduce<number>((sum, value) => sum + (value ?? 0), 0)
  if (total > 0) return
  await startBootstrap()
}

/** Dismiss the completed (or failed) screen. */
export function dismissBootstrap(): void {
  if (state.phase === 'running') return
  publish({ ...state, phase: 'idle' })
}
