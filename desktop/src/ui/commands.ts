// The `sync::*`, `storage::*` and `auth::*` commands this phase's screens call, typed.
//
// `platform/data/engine.ts` is the same seam for `data::*`; this is its counterpart for the
// commands that are not part of the `DataSource` surface. Every name below is the Rust
// function name, because `tauri::generate_handler!` registers commands by function name and
// NOT by module path (`src-tauri/src/lib.rs`) — a module-qualified name never reaches the
// wire. `storage`'s accounting command is therefore named `storage_stats` on the Rust side
// too, which is the name `SYNCDESKTOP.md` §6.2 fixes (ledger O5); `npm run check:commands`
// compares the Rust fn, this literal and the §6.2 contract on every run.
//
// Argument keys are camelCase: Tauri 2 renames command arguments to camelCase on the JS side
// by default, so `extra_days` is passed as `extraDays`. Struct FIELDS stay snake_case (serde
// marshals them verbatim), which is why the payload objects below do not follow the same rule
// as the argument objects around them.
import { invokeCommand } from '../bridge/invoke'
import type { EntityName, LocalRow, QueryParams } from '../platform/data/engine'
import { runQuery } from '../platform/data/engine'

// ------------------------------------------------------------------------------------------------
// Wire types — `syncra_sync::types` / `syncra_sync::config` / `commands::auth`
// ------------------------------------------------------------------------------------------------

/** `syncra_sync::WriteBlockReason`. */
export type WriteBlockReason = 'disk_full' | 'outbox_full'

/** `syncra_sync::SyncStatus`. */
export interface SyncStatus {
  online: boolean
  syncing: boolean
  pending: number
  conflicts: number
  last_sync_at: string | null
  write_blocked: WriteBlockReason | null
}

/** `syncra_sync::SyncReport`. */
export interface SyncReport {
  pushed: number
  applied: number
  duplicates: number
  conflicts: number
  rejected: number
  deferred: number
  pulled_rows: number
  deletions: number
  tables_changed: EntityName[]
}

/**
 * `syncra_sync::Conflict` — one push result the client could not apply silently.
 *
 * `code` is the discriminator this phase leans on: `FIELD_CONFLICT` is a true two-sided
 * conflict with `conflicting_fields` populated, while `ONLINE_ONLY`, `UNRESOLVED_REFERENCE`,
 * `ABILITY_REQUIRED` and the `HTTP_4xx` codes are one-sided REJECTIONS — the server refused the
 * mutation outright and there is nothing to merge. `docs/DESKTOP-ARCHITECTURE.md` EK 3 (A22)
 * makes telling those two apart this screen's job.
 */
export interface Conflict {
  id: string
  outbox_id: string | null
  entity: EntityName
  client_id: string | null
  code: string
  conflicting_fields: string[]
  mine: unknown
  theirs: unknown
  created_at: string
}

/**
 * `syncra_sync::Resolution`, adjacently tagged (`tag = "kind"`, `content = "fields"`).
 * `Merge` keeps the listed fields from the local change and adopts the server row for the rest.
 */
export type Resolution =
  | { kind: 'keep_mine' }
  | { kind: 'take_server' }
  | { kind: 'merge'; fields: string[] }

/** `syncra_sync::StorageStats`. */
export interface StorageStats {
  db_bytes: number
  max_db_bytes: number
  cached_file_bytes: number
  outbox_count: number
  max_outbox: number
  db_usage_percent: number
}

/** `syncra_sync::DesktopSettings` — the user-tunable subset of `SyncConfig`. */
export interface DesktopSettings {
  retention_days: number
  max_db_size_mb: number
  max_outbox: number
  clipboard_capture: boolean
}

/** `commands::auth::DeviceSummary` — one row of `GET /api/me/devices`. */
export interface DeviceSummary {
  id: number
  name: string
  platform: string | null
  last_used_at: string | null
  created_at: string
  is_current: boolean
}

// ------------------------------------------------------------------------------------------------
// K8 lower bounds (`SYNCDESKTOP.md` K8)
//
// The engine clamps anything below these (`DesktopSettings::clamped`), so they hold whatever
// the UI sends. They are repeated here so the form can refuse the value BEFORE the round trip
// instead of silently accepting "1 day" and storing 7.
// ------------------------------------------------------------------------------------------------

export const MIN_RETENTION_DAYS = 7
export const MIN_MAX_DB_SIZE_MB = 100
export const MIN_MAX_OUTBOX = 500

// ------------------------------------------------------------------------------------------------
// Commands
// ------------------------------------------------------------------------------------------------

/** `sync::status` — cheap synchronous snapshot, safe to poll. */
export function readStatus(): Promise<SyncStatus> {
  return invokeCommand<SyncStatus>('status')
}

/** `sync::sync_now` — one manual push-then-pull round. */
export function syncNow(): Promise<SyncReport> {
  return invokeCommand<SyncReport>('sync_now')
}

/** `sync::conflicts` — everything waiting in the Conflict Inbox. */
export function listConflicts(): Promise<Conflict[]> {
  return invokeCommand<Conflict[]>('conflicts')
}

/** `sync::resolve_conflict` — settle one entry. */
export function resolveConflict(id: string, choice: Resolution): Promise<void> {
  return invokeCommand<void>('resolve_conflict', { id, choice })
}

/** `sync::download_archive` — widen the retention window and pull the extra history (K12). */
export function downloadArchive(extraDays: number): Promise<void> {
  return invokeCommand<void>('download_archive', { extraDays })
}

/** `storage::storage_stats` — local storage accounting. */
export function readStorageStats(): Promise<StorageStats> {
  return invokeCommand<StorageStats>('storage_stats')
}

/** `storage::update_settings` — values below the K8 minimums are clamped by the engine. */
export function updateStorageSettings(settings: DesktopSettings): Promise<void> {
  return invokeCommand<void>('update_settings', { settings })
}

/** `storage::clear_local` — wipe the mirror and the file cache, KEEPING the session. */
export function clearLocal(): Promise<void> {
  return invokeCommand<void>('clear_local')
}

/** `auth::list_devices` — `GET /api/me/devices`; needs the network. */
export function listDevices(): Promise<DeviceSummary[]> {
  return invokeCommand<DeviceSummary[]>('list_devices')
}

/** `auth::revoke_device` — `DELETE /api/me/devices/{token}`. */
export function revokeDevice(tokenId: number): Promise<void> {
  return invokeCommand<void>('revoke_device', { tokenId })
}

// ------------------------------------------------------------------------------------------------
// Pending / conflicted rows — the record badges (`SYNCDESKTOP.md` §7.2)
// ------------------------------------------------------------------------------------------------

/**
 * `sync_state` as the local mirror stores it (`SYNCDESKTOP.md` §5.3).
 * `tombstone` rows are excluded by `NamedQuery::PendingRows` itself.
 */
export type SyncState = 'synced' | 'pending' | 'conflict' | 'tombstone'

/**
 * Entities the desktop can write, i.e. the only ones that can ever hold a `pending` or
 * `conflict` state. The read-only mirrors (`product`, `price_list`, `exchange_rate`,
 * `setting`, `user`, …) never enter the outbox, so querying them would be one round trip per
 * refresh for a guaranteed empty result.
 *
 * Order is the outbox's topological order (`SYNCDESKTOP.md` §5.4), so the list reads in the
 * order the rows will actually be pushed.
 */
export const WRITABLE_ENTITIES: readonly EntityName[] = [
  'company',
  'contact',
  'lead',
  'deal',
  'quote',
  'task',
  'activity',
  'ticket',
  'conversation',
  'message',
  'notification',
]

/** The `sync_state` column of a mirror row, when the row carries one. */
export function syncStateOf(row: LocalRow): SyncState | null {
  const state = row.sync_state
  return state === 'pending' || state === 'conflict' || state === 'synced' || state === 'tombstone'
    ? state
    : null
}

/** One entity's unpushed rows (`NamedQuery::PendingRows`). */
export function listPendingRows(entity: EntityName, params: QueryParams = {}): Promise<LocalRow[]> {
  return runQuery({ query: 'pending_rows', entity }, params)
}
