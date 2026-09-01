// Storage settings — `SYNCDESKTOP.md` §7.2, fourth item; ceilings from K8.
//
// All three fields below are prefilled from the engine itself: `readStorageStats` for the two
// ceilings carried on `StorageStats` (`max_db_bytes`, `max_outbox`) and `readStorageSettings`
// (`storage::storage_settings`, wrapping `SyncEngine::settings`) for `retention_days`, which
// `StorageStats` does not carry. There used to be no engine-side getter for `DesktopSettings` —
// only `update_settings` (write) existed — so this screen prefilled the retention window from a
// `localStorage` mirror it wrote on every successful save. That mirror could go stale (after a
// re-install, or if the window changed from somewhere else) and is gone now that the engine can
// be asked directly. The form still never submits on its own: a value only reaches the engine
// when the user presses save.
import { useCallback, useEffect, useState } from 'react'

import { Button, Input, Modal, toast } from '@/components/ui'
import { cn } from '@/lib/cn'

import type { DataLocation, DesktopSettings, StorageStats } from '../commands'
import {
  clearLocal,
  downloadArchive,
  MIN_MAX_DB_SIZE_MB,
  MIN_MAX_OUTBOX,
  MIN_RETENTION_DAYS,
  moveDataDir,
  readDataLocation,
  readStorageSettings,
  readStorageStats,
  updateStorageSettings,
} from '../commands'
import { errorCodeOf, errorMessage } from '../errors'
import { formatMegabytes } from '../format'
import { useEngineStatus } from '../useEngineStatus'
import { useIntlLocale, useT } from '../useT'

const BYTES_PER_MB = 1024 * 1024

/** `SYNCDESKTOP.md` §5.6 — the engine emits `StorageWarning` at 80% of the ceiling. */
const USAGE_WARNING_PERCENT = 80

export function StorageSettings() {
  const t = useT()
  const locale = useIntlLocale()
  const status = useEngineStatus()

  const [stats, setStats] = useState<StorageStats | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  // `update_settings` takes the WHOLE `DesktopSettings` struct, so this screen has to hand
  // back the two booleans it does not own (`clipboard_capture`, `close_to_tray` — both live in
  // `DesktopPreferences`). It used to send `clipboard_capture: false` unconditionally, which
  // meant every retention change silently switched the K10 opt-in back off.
  const [settings, setSettings] = useState<DesktopSettings | null>(null)
  const [retentionDays, setRetentionDays] = useState<number>(MIN_RETENTION_DAYS)
  const [maxDbSizeMb, setMaxDbSizeMb] = useState<number>(MIN_MAX_DB_SIZE_MB)
  const [maxOutbox, setMaxOutbox] = useState<number>(MIN_MAX_OUTBOX)
  const [busy, setBusy] = useState(false)
  const [confirmClear, setConfirmClear] = useState(false)
  // F8/1 (K15). `location` is where the mirror lives; `leftover` is the one outcome of a
  // SUCCESSFUL move that still needs saying — the old directory survived its own deletion, so
  // a second encrypted copy of the mirror is on disk somewhere the user has not been told
  // about. It is kept in state rather than shown as a toast because a toast that scrolls away
  // is not how you tell someone where their data still is.
  const [location, setLocation] = useState<DataLocation | null>(null)
  const [leftover, setLeftover] = useState<string | null>(null)
  const [confirmMove, setConfirmMove] = useState(false)

  const load = useCallback(async () => {
    try {
      const [next, persisted, where] = await Promise.all([
        readStorageStats(),
        readStorageSettings(),
        readDataLocation(),
      ])
      setStats(next)
      setSettings(persisted)
      setLocation(where)
      setMaxDbSizeMb(Math.round(next.max_db_bytes / BYTES_PER_MB))
      setMaxOutbox(next.max_outbox)
      setRetentionDays(persisted.retention_days)
      setLoadError(null)
    } catch (error) {
      setLoadError(errorMessage(t, errorCodeOf(error)))
    }
  }, [t])

  useEffect(() => {
    void load()
  }, [load])

  const offline = !status.online

  async function run(action: () => Promise<void>, successMessage?: string): Promise<void> {
    setBusy(true)
    try {
      await action()
      if (successMessage) toast.success(successMessage)
    } catch (error) {
      toast.error(errorMessage(t, errorCodeOf(error)))
    } finally {
      setBusy(false)
      await load()
    }
  }

  function handleSave(): void {
    // Clamped here as well as in the engine (`DesktopSettings::clamped`), so the field the user
    // is looking at shows the value that will actually be stored.
    const next: DesktopSettings = {
      retention_days: Math.max(MIN_RETENTION_DAYS, retentionDays),
      max_db_size_mb: Math.max(MIN_MAX_DB_SIZE_MB, maxDbSizeMb),
      max_outbox: Math.max(MIN_MAX_OUTBOX, maxOutbox),
      // Not this screen's fields — passed straight back from what the engine has persisted.
      // The `?? default` arms only fire if the load failed, and they repeat the engine's own
      // defaults (`DesktopSettings::default`) rather than inventing a value.
      clipboard_capture: settings?.clipboard_capture ?? false,
      close_to_tray: settings?.close_to_tray ?? true,
    }
    setRetentionDays(next.retention_days)
    setMaxDbSizeMb(next.max_db_size_mb)

    void (async () => {
      setBusy(true)
      try {
        await updateStorageSettings(next)
        toast.success(t('desktop:storage.saveSuccess'))
      } catch (error) {
        toast.error(`${t('desktop:storage.saveError')} ${errorMessage(t, errorCodeOf(error))}`)
      } finally {
        setBusy(false)
        await load()
      }
    })()
  }

  /**
   * Open the folder picker (it runs in Rust) and move the data if the user chooses one.
   *
   * Deliberately NOT routed through `run()`: that helper toasts on every failure and reloads
   * afterwards, and a dismissed picker is neither a failure nor a change. The engine is closed
   * for the duration of the call, so `busy` has to gate the whole panel — a second command
   * issued mid-move would reject against a database that is not open.
   */
  async function handleMove(): Promise<void> {
    setConfirmMove(false)
    setBusy(true)
    try {
      const outcome = await moveDataDir()
      if (!outcome.moved) return
      setLeftover(outcome.old_dir_remaining)
      toast.success(t('desktop:storage.dataLocation.success', { path: outcome.path }))
    } catch (error) {
      toast.error(errorMessage(t, errorCodeOf(error)))
    } finally {
      setBusy(false)
      await load()
    }
  }

  const usagePercent = stats ? Math.min(100, stats.db_usage_percent) : 0
  const usageTone =
    usagePercent >= 100 ? 'bg-danger' : usagePercent >= USAGE_WARNING_PERCENT ? 'bg-warning' : 'bg-primary'

  return (
    <div className="flex flex-col gap-6">
      {loadError && (
        <p className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">{loadError}</p>
      )}

      <section className="flex flex-col gap-2">
        <div className="flex items-baseline justify-between">
          <span className="text-sm font-medium text-fg">{t('desktop:storage.usage.label')}</span>
          <span className="text-sm text-fg-muted">
            {stats
              ? t('desktop:storage.usage.value', {
                  used: formatMegabytes(locale, stats.db_bytes + stats.cached_file_bytes),
                  total: formatMegabytes(locale, stats.max_db_bytes),
                })
              : t('common:states.loading')}
          </span>
        </div>
        <div
          className="h-2 w-full overflow-hidden rounded-full bg-surface-2"
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={usagePercent}
          aria-label={t('desktop:storage.usage.label')}
        >
          <div
            className={cn('h-full rounded-full transition-all duration-150', usageTone)}
            style={{ width: `${usagePercent}%` }}
          />
        </div>
      </section>

      <section className="grid gap-4 sm:grid-cols-2">
        <Input
          type="number"
          inputMode="numeric"
          min={MIN_RETENTION_DAYS}
          value={retentionDays}
          onChange={(event) => setRetentionDays(Number(event.target.value))}
          label={t('desktop:storage.retention.label')}
          hint={t('desktop:storage.retention.description')}
        />
        <Input
          type="number"
          inputMode="numeric"
          min={MIN_MAX_DB_SIZE_MB}
          value={maxDbSizeMb}
          onChange={(event) => setMaxDbSizeMb(Number(event.target.value))}
          label={t('desktop:storage.cap.label')}
          hint={t('desktop:storage.cap.description')}
        />
      </section>

      {/*
        `SYNCDESKTOP.md` §10 F8 item 1 (KARAR K15) — "Veri konumu", in the Storage tab rather
        than in a panel of its own, which the spec spells out: `DesktopPanel.tsx` already
        carries a `storage` tab and this belongs to it.
      */}
      <section className="flex flex-col gap-2">
        <div className="flex flex-col gap-1">
          <span className="text-sm font-medium text-fg">
            {t('desktop:storage.dataLocation.label')}
          </span>
          <span className="text-sm text-fg-muted">
            {t('desktop:storage.dataLocation.description')}
          </span>
        </div>

        <p className="break-all rounded-md bg-surface-2 px-3 py-2 font-mono text-xs text-fg-secondary">
          {location ? location.path : t('common:states.loading')}
        </p>

        {location?.unavailable_path && (
          <p className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
            {t('desktop:storage.dataLocation.unavailable', { path: location.unavailable_path })}
          </p>
        )}

        {leftover && (
          <p className="rounded-md bg-warning-tint px-3 py-2 text-sm text-warning">
            {t('desktop:storage.dataLocation.leftover', { path: leftover })}
          </p>
        )}

        <div className="flex flex-wrap items-center gap-2">
          <Button variant="secondary" disabled={busy} onClick={() => setConfirmMove(true)}>
            {t('desktop:storage.dataLocation.change')}
          </Button>
          <span className="text-xs text-fg-muted">
            {t('desktop:storage.dataLocation.fixedDiskOnly')}
          </span>
        </div>
      </section>

      <div className="flex flex-wrap items-center gap-2">
        <Button disabled={busy} onClick={handleSave}>
          {t('common:actions.save')}
        </Button>

        <Button
          variant="secondary"
          // K12: the archive is fetched from the server, so it is unavailable offline. This is
          // the `SYNCDESKTOP.md` §8 pattern (disabled + tooltip) applied to a storage action;
          // there is no `desktop.onlineOnly.*` key for it, and `errors.OFFLINE` says the same
          // thing without inventing one.
          disabled={busy || offline}
          title={offline ? t('desktop:errors.OFFLINE') : t('desktop:storage.downloadArchive.description')}
          onClick={() =>
            void run(async () => {
              await downloadArchive(Math.max(MIN_RETENTION_DAYS, retentionDays))
            })
          }
        >
          {t('desktop:storage.downloadArchive.button')}
        </Button>

        <Button
          variant="danger"
          className="ml-auto"
          disabled={busy}
          onClick={() => setConfirmClear(true)}
        >
          {t('desktop:storage.clearLocal.button')}
        </Button>
      </div>

      <Modal
        open={confirmMove}
        onClose={() => setConfirmMove(false)}
        title={t('desktop:storage.dataLocation.title')}
        description={t('desktop:storage.dataLocation.confirm')}
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmMove(false)}>
              {t('common:actions.cancel')}
            </Button>
            <Button loading={busy} onClick={() => void handleMove()}>
              {t('common:actions.confirm')}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-fg-secondary">
          {t('desktop:storage.dataLocation.fixedDiskOnly')}
        </p>
      </Modal>

      <Modal
        open={confirmClear}
        onClose={() => setConfirmClear(false)}
        title={t('desktop:storage.clearLocal.title')}
        description={t('desktop:storage.clearLocal.description')}
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmClear(false)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={busy}
              onClick={() => {
                setConfirmClear(false)
                void run(clearLocal)
              }}
            >
              {t('common:actions.confirm')}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-fg-secondary">{t('desktop:storage.clearLocal.confirmSuffix')}</p>
      </Modal>
    </div>
  )
}
