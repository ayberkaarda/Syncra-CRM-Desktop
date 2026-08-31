// Storage settings — `SYNCDESKTOP.md` §7.2, fourth item; ceilings from K8.
//
// ## Known gap: the engine has no settings GETTER
//
// `SyncEngine`'s public API (`SYNCDESKTOP.md` §5.2) is frozen and exposes `update_settings` but
// nothing that reads `DesktopSettings` back. `StorageStats` carries two of the three ceilings
// (`max_db_bytes`, `max_outbox`) and NOT `retention_days`, which lives only in `SyncConfig` and
// the `desktop_settings` table. So the two ceilings below are prefilled from the engine and the
// retention window is prefilled from a device-local mirror this screen writes on every
// successful save, falling back to the K8 default (30). The mirror can be stale — after a
// re-install, or if the window is ever changed from somewhere else — which is why the form
// never submits on its own: the value only reaches the engine when the user presses save.
// `docs/DESKTOP-ARCHITECTURE.md` §9 already puts device-local preferences in `localStorage`
// (D-4 measured it as durable under WebView2).
import { useCallback, useEffect, useState } from 'react'

import { Button, Input, Modal, toast } from '@/components/ui'
import { cn } from '@/lib/cn'

import type { DesktopSettings, StorageStats } from '../commands'
import {
  clearLocal,
  downloadArchive,
  MIN_MAX_DB_SIZE_MB,
  MIN_MAX_OUTBOX,
  MIN_RETENTION_DAYS,
  readStorageStats,
  updateStorageSettings,
} from '../commands'
import { errorCodeOf, errorMessage } from '../errors'
import { formatMegabytes } from '../format'
import { useEngineStatus } from '../useEngineStatus'
import { useIntlLocale, useT } from '../useT'

/** `SyncConfig::new`'s `DEFAULT_RETENTION_DAYS` (`crates/syncra-sync/src/config.rs`). */
const DEFAULT_RETENTION_DAYS = 30

/** Where the retention window is mirrored; see the module comment. */
const RETENTION_MIRROR_KEY = 'syncra-desktop-retention-days'

const BYTES_PER_MB = 1024 * 1024

/** `SYNCDESKTOP.md` §5.6 — the engine emits `StorageWarning` at 80% of the ceiling. */
const USAGE_WARNING_PERCENT = 80

function readRetentionMirror(): number {
  try {
    const stored = Number(window.localStorage.getItem(RETENTION_MIRROR_KEY))
    return Number.isFinite(stored) && stored >= MIN_RETENTION_DAYS ? stored : DEFAULT_RETENTION_DAYS
  } catch {
    // Storage denied: the default stands, the screen still works.
    return DEFAULT_RETENTION_DAYS
  }
}

function writeRetentionMirror(days: number): void {
  try {
    window.localStorage.setItem(RETENTION_MIRROR_KEY, String(days))
  } catch {
    // A stale prefill next time is the whole cost. Not worth surfacing.
  }
}

export function StorageSettings() {
  const t = useT()
  const locale = useIntlLocale()
  const status = useEngineStatus()

  const [stats, setStats] = useState<StorageStats | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [retentionDays, setRetentionDays] = useState<number>(readRetentionMirror)
  const [maxDbSizeMb, setMaxDbSizeMb] = useState<number>(MIN_MAX_DB_SIZE_MB)
  const [maxOutbox, setMaxOutbox] = useState<number>(MIN_MAX_OUTBOX)
  const [busy, setBusy] = useState(false)
  const [confirmClear, setConfirmClear] = useState(false)

  const load = useCallback(async () => {
    try {
      const next = await readStorageStats()
      setStats(next)
      setMaxDbSizeMb(Math.round(next.max_db_bytes / BYTES_PER_MB))
      setMaxOutbox(next.max_outbox)
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
    const settings: DesktopSettings = {
      retention_days: Math.max(MIN_RETENTION_DAYS, retentionDays),
      max_db_size_mb: Math.max(MIN_MAX_DB_SIZE_MB, maxDbSizeMb),
      max_outbox: Math.max(MIN_MAX_OUTBOX, maxOutbox),
      // K10: clipboard capture is opt-in and default-off, and this phase ships no UI for it.
      // Sending `false` is the documented default, not a silent reset of a user choice.
      clipboard_capture: false,
    }
    setRetentionDays(settings.retention_days)
    setMaxDbSizeMb(settings.max_db_size_mb)

    void (async () => {
      setBusy(true)
      try {
        await updateStorageSettings(settings)
        writeRetentionMirror(settings.retention_days)
        toast.success(t('desktop:storage.saveSuccess'))
      } catch (error) {
        toast.error(`${t('desktop:storage.saveError')} ${errorMessage(t, errorCodeOf(error))}`)
      } finally {
        setBusy(false)
        await load()
      }
    })()
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
          title={offline ? t('desktop:errors.OFFLINE') : undefined}
          onClick={() =>
            void run(async () => {
              await downloadArchive(Math.max(MIN_RETENTION_DAYS, retentionDays))
            })
          }
        >
          {t('desktop:storage.downloadArchive')}
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
