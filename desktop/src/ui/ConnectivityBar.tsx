// Connectivity bar — `SYNCDESKTOP.md` §7.2, first item.
//
// ## Why it floats instead of taking a row
//
// `AppLayout` is `flex h-screen overflow-hidden` (`frontend/src/components/layout/AppLayout.tsx`)
// and this strand cannot edit it. A bar in normal flow above it would make the page
// `100vh + bar` tall (a scrollbar on every screen), and clipping the overflow instead would cut
// the bottom off the sidebar and the scroll container. A fixed element consumes no layout
// height, so the shared layout keeps behaving exactly as it does on the web — which is the
// whole point of K1.
//
// Bottom-LEFT rather than bottom-right: on this app's minimum window (960px, `tauri.conf.json`)
// the sidebar is always expanded, so the bar sits over the sidebar's own empty tail instead of
// over page content, and it never lands on the chat composer or a table's last row.
import { useEffect, useState } from 'react'

import { toast } from '@/components/ui'
import { cn } from '@/lib/cn'

import { refreshEngineStatus } from '../platform/desktop'
import { errorCodeOf, errorMessage } from './errors'
import { formatElapsed } from './format'
import { AlertIcon, OfflineIcon, OnlineIcon, RefreshIcon } from './icons'
import { syncNow } from './commands'
import { connectivityStateOf, useEngineStatus, type ConnectivityState } from './useEngineStatus'
import { useIntlLocale, useT } from './useT'

/**
 * How often the "last synced N minutes ago" label recomputes.
 *
 * Nothing else on this bar is time-derived: the counts and the state all arrive on
 * `EngineEvent::StatusChanged`. This interval exists so a window left open does not keep
 * claiming "1 minute ago" an hour later.
 */
const ELAPSED_TICK_MS = 30_000

const STATE_STYLES: Record<ConnectivityState, { dot: string; text: string }> = {
  online: { dot: 'bg-success', text: 'text-fg-secondary' },
  syncing: { dot: 'bg-primary animate-pulse', text: 'text-fg-secondary' },
  conflict: { dot: 'bg-warning', text: 'text-warning' },
  offline: { dot: 'bg-fg-muted', text: 'text-fg-muted' },
}

const STATE_LABEL_KEYS: Record<ConnectivityState, string> = {
  online: 'desktop:sync.status.online',
  syncing: 'desktop:sync.status.syncing',
  conflict: 'desktop:sync.status.conflict',
  offline: 'desktop:sync.status.offline',
}

export interface ConnectivityBarProps {
  /** Opens the desktop panel (Conflict Inbox / storage / devices). */
  onOpen: () => void
}

export function ConnectivityBar({ onOpen }: ConnectivityBarProps) {
  const t = useT()
  const locale = useIntlLocale()
  const status = useEngineStatus()
  const [, setTick] = useState(0)
  const [manualSyncing, setManualSyncing] = useState(false)

  useEffect(() => {
    const timer = setInterval(() => setTick((value) => value + 1), ELAPSED_TICK_MS)
    return () => clearInterval(timer)
  }, [])

  const state = connectivityStateOf(status)
  const styles = STATE_STYLES[state]
  const statusLabel = t(STATE_LABEL_KEYS[state])

  const lastSynced = status.last_sync_at ? new Date(status.last_sync_at) : null
  const lastSyncedLabel =
    lastSynced && !Number.isNaN(lastSynced.getTime())
      ? t('desktop:sync.lastSynced', { time: formatElapsed(locale, lastSynced) })
      : t('desktop:sync.lastSyncedNever')

  const syncDisabled = !status.online || status.syncing || manualSyncing

  async function handleSyncNow(): Promise<void> {
    setManualSyncing(true)
    try {
      await syncNow()
    } catch (error) {
      toast.error(errorMessage(t, errorCodeOf(error)))
    } finally {
      setManualSyncing(false)
      // `sync_now` emits `StatusChanged` itself; this covers the failure path, where the
      // counters moved (attempts, conflicts) but no event was emitted.
      void refreshEngineStatus().catch(() => undefined)
    }
  }

  return (
    <div className="fixed bottom-3 left-3 z-40 flex items-center gap-1 rounded-lg border border-border-subtle bg-surface-1 p-1 shadow-popover">
      <button
        type="button"
        onClick={onOpen}
        aria-label={statusLabel}
        className={cn(
          'flex items-center gap-2 rounded-md px-2 py-1 text-xs',
          'transition-colors duration-150 motion-reduce:transition-none hover:bg-surface-2',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface-1'
        )}
      >
        {state === 'offline' ? (
          <OfflineIcon className="size-3.5 text-fg-muted" />
        ) : state === 'conflict' ? (
          <AlertIcon className="size-3.5 text-warning" />
        ) : (
          <OnlineIcon className={cn('size-3.5', state === 'syncing' ? 'text-primary' : 'text-success')} />
        )}

        <span className={cn('size-1.5 rounded-full', styles.dot)} aria-hidden="true" />
        <span className={cn('font-medium', styles.text)}>{statusLabel}</span>

        {status.pending > 0 && (
          <>
            <span className="text-fg-muted" aria-hidden="true">
              ·
            </span>
            <span className="text-fg-muted">
              {t('desktop:sync.pendingChanges', { count: status.pending })}
            </span>
          </>
        )}

        <span className="text-fg-muted" aria-hidden="true">
          ·
        </span>
        <span className="text-fg-muted">{lastSyncedLabel}</span>

        {status.write_blocked !== null && (
          <span className="flex items-center gap-1 text-danger">
            <AlertIcon className="size-3.5" />
            {t('desktop:errors.WRITE_BLOCKED')}
          </span>
        )}
      </button>

      <button
        type="button"
        onClick={() => void handleSyncNow()}
        disabled={syncDisabled}
        title={status.online ? t('desktop:sync.syncNow') : t('desktop:errors.OFFLINE')}
        aria-label={t('desktop:sync.syncNow')}
        className={cn(
          'rounded-md p-1.5 text-fg-muted',
          'transition-colors duration-150 motion-reduce:transition-none hover:bg-surface-2 hover:text-fg',
          'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface-1'
        )}
      >
        <RefreshIcon
          className={cn('size-3.5', (status.syncing || manualSyncing) && 'animate-spin')}
        />
      </button>
    </div>
  )
}
