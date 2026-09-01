// React binding for the engine status the shell already tracks.
//
// The store lives in `platform/desktop.ts` because that module owns the `EngineEvent::
// StatusChanged` handler and the backstop poll; this hook only subscribes to it. Nothing here
// calls `invoke('status')` on its own — see the comment on `subscribeToEngineStatus`.
import { useSyncExternalStore } from 'react'

import {
  getEngineStatus,
  subscribeToEngineStatus,
} from '../platform/desktop'
import type { SyncStatus } from './commands'

/**
 * `SyncStatus` (`ui/commands.ts`) plus whether the background sync loop is paused.
 *
 * `paused` is a shell-only fact, not something the sync engine itself has a concept of
 * (defter O71): pausing stops `AppState::scheduler` in the Rust shell, and `SyncStatus` — the
 * type the engine actually produces — never carried it. The Rust side layers it on
 * (`src-tauri/src/events.rs`'s `StatusWithPause`, `#[serde(flatten)]`ed onto every `status()`
 * reply and every `StatusChanged` event) rather than widening `SyncStatus` in `ui/commands.ts`
 * itself (out of this change's file scope, and shared with the web-facing wire types there).
 * Declaring it here as an intersection with an optional field keeps a plain `SyncStatus`
 * assignable to `EngineStatus` — nothing has to cast.
 */
export type EngineStatus = SyncStatus & { paused?: boolean }

/**
 * What the connectivity indicator shows, in precedence order.
 *
 * `offline` outranks `syncing`: a round that cannot reach the server is not progress, and
 * showing a spinner for it is the single most misleading thing an offline-first client can do.
 * `conflict` outranks `online` because an unresolved conflict is a state the user has to act
 * on, and a plain green "online" would hide it. `paused` sits last, right above the plain
 * `online` fallback: it is the resting state of a paused loop, not an alarm, and any of the
 * three states above it (no connection, a round actually in flight, an unresolved conflict)
 * says something more urgent than "the automatic loop is off".
 */
export type ConnectivityState = 'offline' | 'syncing' | 'conflict' | 'paused' | 'online'

export function connectivityStateOf(status: EngineStatus): ConnectivityState {
  if (!status.online) return 'offline'
  if (status.syncing) return 'syncing'
  if (status.conflicts > 0) return 'conflict'
  if (status.paused) return 'paused'
  return 'online'
}

/** The live `EngineStatus`. Re-renders on every `StatusChanged` the engine emits. */
export function useEngineStatus(): EngineStatus {
  return useSyncExternalStore(subscribeToEngineStatus, getEngineStatus, getEngineStatus)
}
