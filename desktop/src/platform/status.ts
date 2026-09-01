// The engine's status, and the one place it can be observed from.
//
// Split out of `desktop.ts` for exactly the reason `http.ts` was (see its header): the
// `platform/data/*` modules need the online/offline verdict for `SYNCDESKTOP.md` §8, and
// `desktop.ts` imports THEM (`./data`) to assemble the platform. Reaching back into it would
// close an import cycle. Nothing here imports `desktop.ts`, `./data`, or `./http`.
//
// `desktop.ts` re-exports everything this module exports that the shell already consumed
// (`ui/useEngineStatus.ts`, `ui/ConnectivityBar.tsx`, `main.desktop.tsx`), so no call site moved.
import { invokeCommand } from '../bridge/invoke'
import type { SyncStatus as EngineSyncStatus } from '../ui/commands'

/**
 * Wire shape of `syncra_sync::types::SyncStatus` (serde keeps the Rust field names).
 * Declared once in `ui/commands.ts` alongside the other command wire types.
 */
export type SyncStatus = EngineSyncStatus

/**
 * How often `connectivity.subscribe` re-reads the engine's status.
 *
 * `sync::status` is documented as a "cheap, synchronous snapshot — safe to poll"
 * (`src-tauri/src/commands/sync.rs`). The authoritative feed is `EngineEvent::StatusChanged`
 * through `bridge/events.ts`, which the entry point subscribes to; this poll is the backstop
 * for the window between process start and that subscription landing.
 */
export const STATUS_POLL_MS = 5000

/**
 * Last status read from the engine. Starts `online: true` so the shell does not flash "offline"
 * before the first read lands.
 *
 * `navigator.onLine` is deliberately NOT used anywhere in this file (§3.5): it reports "online"
 * for a machine that has a LAN but cannot reach the API host, which is the most common failure
 * mode in the closed-network deployments this product targets. The engine is the authority.
 */
let lastStatus: SyncStatus = {
  online: true,
  syncing: false,
  pending: 0,
  conflicts: 0,
  last_sync_at: null,
  write_blocked: null,
}

/**
 * Everything that wants to RENDER the engine's status, as opposed to the `connectivity`
 * adapter's coarse online/offline callback.
 *
 * The desktop chrome (`ui/ConnectivityBar.tsx`) needs `syncing`, `pending`, `conflicts`,
 * `last_sync_at` and `write_blocked`, not just a `ConnState`. It subscribes HERE rather than
 * opening its own `invoke('status')` poll, because a second poller would drift out of step
 * with this one and the two would disagree on screen — the authoritative feed is
 * `EngineEvent::StatusChanged`, which lands in `applyEngineStatus` below, and there is exactly
 * one place it can be observed from.
 */
const statusListeners = new Set<(status: SyncStatus) => void>()

function publishStatus(next: SyncStatus): void {
  lastStatus = next
  for (const listener of statusListeners) listener(next)
}

/** One `sync::status` round trip, published to every listener. */
export async function readStatus(): Promise<SyncStatus> {
  publishStatus(await invokeCommand<SyncStatus>('status'))
  return lastStatus
}

/** Adopt a status the engine pushed, so the poll and the event feed agree. */
export function applyEngineStatus(status: unknown): void {
  if (status && typeof status === 'object' && 'online' in status) {
    publishStatus(status as SyncStatus)
  }
}

/** Last known engine status. Never `null`: the optimistic default stands until the first read. */
export function getEngineStatus(): SyncStatus {
  return lastStatus
}

/** Observe {@link getEngineStatus}. Returns the unsubscribe handle. */
export function subscribeToEngineStatus(listener: (status: SyncStatus) => void): () => void {
  statusListeners.add(listener)
  return () => {
    statusListeners.delete(listener)
  }
}

/** Force one status read — after a manual sync, or when a screen that shows it opens. */
export function refreshEngineStatus(): Promise<SyncStatus> {
  return readStatus()
}

/**
 * Can the API be reached right now, according to the ENGINE?
 *
 * The single predicate behind both halves of §8: `connectivity.isOnline()` (`desktop.ts`) and
 * `onlineOnly()` (`onlineOnly.ts`) both read it, so a disabled trigger and a rejected call can
 * never disagree about what "offline" means.
 */
export function isEngineOnline(): boolean {
  return lastStatus.online
}
