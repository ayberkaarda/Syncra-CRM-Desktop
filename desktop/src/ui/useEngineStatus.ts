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
 * What the connectivity indicator shows, in precedence order.
 *
 * `offline` outranks `syncing`: a round that cannot reach the server is not progress, and
 * showing a spinner for it is the single most misleading thing an offline-first client can do.
 * `conflict` outranks `online` because an unresolved conflict is a state the user has to act
 * on, and a plain green "online" would hide it.
 */
export type ConnectivityState = 'offline' | 'syncing' | 'conflict' | 'online'

export function connectivityStateOf(status: SyncStatus): ConnectivityState {
  if (!status.online) return 'offline'
  if (status.syncing) return 'syncing'
  if (status.conflicts > 0) return 'conflict'
  return 'online'
}

/** The live `SyncStatus`. Re-renders on every `StatusChanged` the engine emits. */
export function useEngineStatus(): SyncStatus {
  return useSyncExternalStore(subscribeToEngineStatus, getEngineStatus, getEngineStatus)
}
