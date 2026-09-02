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

/**
 * `syncra_sync::DesktopSettings` — the user-tunable subset of `SyncConfig`.
 *
 * `update_settings` takes the WHOLE struct, so every screen that saves it has to send back the
 * fields it does not own. `StorageSettings` used to hard-code `clipboard_capture: false`, which
 * silently reset that opt-in on every retention change; both booleans are now read from
 * `storage_settings` and passed through. Adding a field here without adding it to every save
 * path re-creates that bug.
 */
export interface DesktopSettings {
  retention_days: number
  max_db_size_mb: number
  max_outbox: number
  /** K10 — clipboard capture is opt-in and off by default. */
  clipboard_capture: boolean
  /**
   * §6.4 — whether closing the main window hides it to the tray instead (D-8).
   *
   * Defaults to `true`, both in `syncra_sync::DesktopSettings` and for a settings row written
   * before the field existed: that is the behaviour every install already had, and a
   * deserialisation default must not change how an app someone is already using behaves.
   */
  close_to_tray: boolean
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

/** `storage::storage_settings` — the settings the engine actually has persisted. */
export function readStorageSettings(): Promise<DesktopSettings> {
  return invokeCommand<DesktopSettings>('storage_settings')
}

/** `storage::clear_local` — wipe the mirror and the file cache, KEEPING the session. */
export function clearLocal(): Promise<void> {
  return invokeCommand<void>('clear_local')
}

/**
 * `commands::storage::DataLocation` — where the encrypted mirror and the blob cache live
 * (`SYNCDESKTOP.md` §10 F8 item 1, KARAR K15).
 *
 * Paths are display strings. Nothing in the UI may derive a filesystem operation from them:
 * every path that travels back to Rust is re-validated there (`crate::data_dir::validate_target`),
 * so this type carries information, never authority.
 */
export interface DataLocation {
  /** The data root in use right now. */
  path: string
  /** `<app_data_dir>/syncra` — where an install that has never moved keeps its data. */
  default_path: string
  /** Whether `path` is that default. */
  is_default: boolean
  /**
   * A configured root that was NOT reachable at startup.
   *
   * Non-null means the app fell back to the default and is running on a different (probably
   * empty) mirror while the real one — possibly holding an outbox of unpushed changes — sits on
   * a volume that is not attached. The Storage tab must surface this; it is not cosmetic.
   */
  unavailable_path: string | null
}

/** `commands::storage::MoveOutcome`. */
export interface MoveDataDirOutcome {
  /**
   * `false` when the user dismissed the folder picker. Nothing was touched and this is NOT an
   * error — a cancelled dialog must not raise a toast that reads like a failure.
   */
  moved: boolean
  /** The data root in use after the call. */
  path: string
  /** The old data root, when one was actually vacated. */
  previous_path: string | null
  /**
   * Set when everything succeeded EXCEPT deleting the old directory: a second, still-encrypted
   * copy of the mirror is sitting at this path. Shown to the user, never swallowed.
   */
  old_dir_remaining: string | null
}

/** `storage::data_location` — cheap, synchronous on the Rust side; safe to call on mount. */
export function readDataLocation(): Promise<DataLocation> {
  return invokeCommand<DataLocation>('data_location')
}

/**
 * `storage::move_data_dir` — move the mirror and the blob cache to another folder.
 *
 * Called with **no argument**, which is what opens the OS folder picker: the picker runs in
 * Rust, because `desktop/package.json` carries `@tauri-apps/api` and no `@tauri-apps/plugin-dialog`
 * (the `dialog:allow-open` capability gates the webview's route to that plugin, which this
 * shell does not use — the same shape `capabilities/default.json` already records for the
 * clipboard). The optional `target` exists for a caller that already has a path.
 *
 * Long-running: the whole mirror plus its cache is copied and then verified table by table.
 * The engine is closed for the duration, so every other command will fail while it runs — the
 * caller must block its own UI rather than let a second call in.
 *
 * Rejects with `DATA_DIR_INVALID` (bad folder), `DATA_DIR_UNSUPPORTED` (removable or network
 * volume — SQLite's WAL mode is only trusted on a fixed disk) or `DATA_DIR_MOVE_FAILED`. In
 * every one of those cases the previous data directory is intact and the engine has been
 * reopened against it.
 */
export function moveDataDir(target?: string): Promise<MoveDataDirOutcome> {
  return invokeCommand<MoveDataDirOutcome>('move_data_dir', { target: target ?? null })
}

/** `auth::list_devices` — `GET /api/me/devices`; needs the network. */
export function listDevices(): Promise<DeviceSummary[]> {
  return invokeCommand<DeviceSummary[]>('list_devices')
}

/** `auth::revoke_device` — `DELETE /api/me/devices/{token}`. */
export function revokeDevice(tokenId: number): Promise<void> {
  return invokeCommand<void>('revoke_device', { tokenId })
}

/**
 * `os::get_autostart` — whether launch-at-login is on, read from the OS.
 *
 * The settings toggle has to call this on open. Autostart is not part of `DesktopSettings`
 * (the engine never sees it — it is a registry value on Windows, a launch agent on macOS, a
 * `.desktop` file on Linux), so there is no local copy to render from, and `set_autostart`
 * only reports the state it just wrote. Reading it from JS with an npm package was rejected:
 * that would be a second source of truth for a value only the OS holds, reporting its errors
 * outside the `{code, message}` shape §6.2 fixes for this whole surface.
 */
export function readAutostart(): Promise<boolean> {
  return invokeCommand<boolean>('get_autostart')
}

/**
 * `os::set_autostart` — turn launch-at-login on or off.
 *
 * Resolves with the state the OS **actually holds afterwards**, read back rather than echoed:
 * a platform where the plugin cannot write the entry reports the unchanged value instead of a
 * success the user would only discover was a lie at the next reboot. The settings screen must
 * therefore render the RETURNED boolean, not the one it sent. Rejects with `OS_ERROR` when the
 * registry key / launch agent / `.desktop` file could not be written.
 *
 * Opt-in and structurally so: registering `tauri_plugin_autostart` enables nothing, and this
 * call is the only path in the whole shell to an enabled entry.
 */
export function setAutostart(enabled: boolean): Promise<boolean> {
  return invokeCommand<boolean>('set_autostart', { enabled })
}

/**
 * The quick-capture accelerator `SYNCDESKTOP.md` §6.4 names, and the one
 * `src-tauri/src/quick_capture.rs` claims at `.setup()`.
 *
 * Transcribed rather than read from Rust — there is no channel that could carry it before the
 * first command call — and `quick_capture::tests::the_default_accelerator_is_the_one_the_spec_names`
 * holds the other side to the same string.
 */
export const DEFAULT_HOTKEY = 'CmdOrCtrl+Shift+Space'

/**
 * `os::register_hotkey` — claim `accelerator` for the quick-capture window.
 *
 * Rejects with `VALIDATION_ERROR` (unparseable, or no modifier) or `OS_ERROR` (the combination
 * is already owned by another application). A rejected change leaves the PREVIOUS shortcut
 * registered, so the caller does not have to undo anything. Re-applying the accelerator
 * already held is a no-op success.
 */
export function registerHotkey(accelerator: string): Promise<void> {
  return invokeCommand<void>('register_hotkey', { accelerator })
}

/**
 * `os::set_tray_language` — point the tray menu and tooltip at `language` (defter C1).
 *
 * Not a `SYNCDESKTOP.md` §6.2 command; it is declared in `check-command-wiring.mjs`'s
 * `UNDOCUMENTED_COMMANDS`. It exists because the tray resolves its own language from the
 * session and the OS, neither of which knows about the per-install override i18next keeps in
 * `localStorage` — Rust cannot read that store, so the webview pushes instead.
 */
export function setTrayLanguage(language: string): Promise<void> {
  return invokeCommand<void>('set_tray_language', { language })
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

// ------------------------------------------------------------------------------------------------
// Native notifications and the taskbar badge (`SYNCDESKTOP.md` §6.4 items 2 and 7)
// ------------------------------------------------------------------------------------------------

/**
 * `commands::os::NotificationInput` — the text one native toast shows.
 *
 * Both fields are **already-resolved sentences**, never i18n keys. `os::notification_text`
 * refuses a `notifications.<type>.title`-shaped string outright (`VALIDATION_ERROR`), because
 * the row's `data` column is written in key mode (`CrmNotification::toArray`) and only the
 * webview can render it — which is exactly why this command takes text rather than the row.
 * `mapNotification` already resolves it through `resolveNotificationText`, so the mapped
 * `Notification` is the correct source; the raw `LocalRow` is not.
 */
export interface NativeNotification {
  title: string
  body: string
}

/**
 * `os::notify` — hand one toast to the OS notification centre.
 *
 * A resolved promise means "handed over", **not** "displayed": the plugin's desktop `show()`
 * spawns the OS call onto Tauri's runtime and `commands::os::notify` cannot observe the
 * outcome. Nothing in the UI may therefore claim a notification was shown.
 */
export function notify(notification: NativeNotification): Promise<void> {
  return invokeCommand<void>('notify', { notification })
}

/**
 * `os::set_badge` — the unread count on the taskbar/dock entry of the `main` window.
 *
 * `0` clears it; a negative count is refused with `VALIDATION_ERROR` rather than clamped, so
 * callers must pass a real count and not a difference.
 */
export function setBadge(count: number): Promise<void> {
  return invokeCommand<void>('set_badge', { count })
}

// ------------------------------------------------------------------------------------------------
// The Windows JumpList (`SYNCDESKTOP.md` §6.4 item "JumpList: son 5 kayıt", defter O85)
// ------------------------------------------------------------------------------------------------

/**
 * `os::record_opened` — remember that this record was opened, and rebuild the taskbar jump list.
 *
 * Not a `SYNCDESKTOP.md` §6.2 command *yet*; it is declared in `check-command-wiring.mjs`'s
 * `UNDOCUMENTED_COMMANDS` with that reason, the same way `set_tray_language` and `auth::session`
 * were before the spec caught up. Adding the §6.2 line is the tech lead's call.
 *
 * `id` is a **string**, not a number: it goes straight back out as a `syncra://<entity>/<id>`
 * path segment on the Rust side, and round-tripping it through a number would rewrite `0042` as
 * `42`. `DeepLinkTarget.id` is a string for the same reason.
 *
 * `title` and `categoryLabel` are already-resolved text, never i18n keys — the shell has no
 * dictionary (§0.6), exactly as with `notify`. Rust validates the entity against the eight §6.4
 * names, the id against `^[0-9]{1,12}$` and both strings against a length/control-character
 * rule before anything reaches disk.
 *
 * Rejects with `VALIDATION_ERROR` or `OS_ERROR`. Callers treat both as non-fatal: nothing the
 * user asked for has failed if a taskbar menu did not update.
 *
 * Windows-only in effect. On macOS and Linux the command exists, validates, and does nothing —
 * the same shape `set_badge` uses for a platform difference behind one name.
 */
export function recordOpened(
  entity: string,
  id: string,
  title: string,
  categoryLabel: string
): Promise<void> {
  return invokeCommand<void>('record_opened', { entity, id, title, categoryLabel })
}
