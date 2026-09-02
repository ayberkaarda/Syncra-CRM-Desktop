// First-run download screen — `SYNCDESKTOP.md` §10 F3 ("bootstrap ekranı").
//
// Shell chrome (KARAR A27), not a route: it covers the whole viewport while the mirror fills
// up, so nothing behind it renders a list that is about to change under the user. Everything
// it says comes from `desktop.bootstrap.*`, which already exists in all four dictionaries.
//
// The progress it shows is REPORTED, not measured: every number comes from a
// `bootstrap-progress` Tauri event carrying the engine's own `BootstrapProgress`. The 400 ms
// row-count poll AUTH-1 had to imitate it with is gone. The per-table list at the bottom is the
// settled summary, read once when the run finishes — see `platform/bootstrap.ts`.
import { Button } from '@/components/ui'

import { errorMessage } from './errors'
import { useT } from './useT'
import {
  dismissBootstrap,
  getBootstrapState,
  startBootstrap,
  subscribeToBootstrap,
} from '../platform/bootstrap'
import { useSyncExternalStore } from 'react'

/** Reactive view of the bootstrap store. */
function useBootstrapState() {
  return useSyncExternalStore(subscribeToBootstrap, getBootstrapState, getBootstrapState)
}

export function BootstrapScreen() {
  const t = useT()
  const state = useBootstrapState()

  if (state.phase === 'idle') return null

  const percent =
    state.tablesTotal === 0 ? 0 : Math.round((state.tablesDone / state.tablesTotal) * 100)
  const loaded = Object.entries(state.counts).filter(([, count]) => (count ?? 0) > 0)
  // "Preparing" only until the engine has actually started moving rows. `rowsLoaded` matters
  // as well as `tablesDone`: the first table can take several requests to drain, and a bar that
  // still said "preparing" while thousands of rows landed would be wrong.
  const started = state.tablesDone > 0 || state.rowsLoaded > 0

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={t('desktop:bootstrap.title')}
      className="fixed inset-0 z-50 flex items-center justify-center bg-surface-0 px-6"
    >
      <div className="flex w-full max-w-md flex-col gap-5">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold tracking-tight text-fg">
            {state.phase === 'done' ? t('desktop:bootstrap.completed') : t('desktop:bootstrap.title')}
          </h1>
          <p className="text-sm text-fg-muted">
            {state.phase === 'done'
              ? t('desktop:bootstrap.completedDescription')
              : t('desktop:bootstrap.description')}
          </p>
        </div>

        {state.phase !== 'error' && (
          <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between text-xs text-fg-muted">
              <span>
                {started ? t('desktop:bootstrap.downloading') : t('desktop:bootstrap.preparing')}
              </span>
              <span>{t('desktop:bootstrap.progressLabel', { percent })}</span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-2">
              <div
                className="h-full rounded-full bg-primary transition-[width] duration-300 motion-reduce:transition-none"
                style={{ width: `${percent}%` }}
              />
            </div>
            {/* The table the engine is draining right now. Its own name, nothing appended:
                `rows_loaded` is cumulative across the whole bootstrap, so pairing it with one
                entity through `bootstrap.tableProgress` would state a number that is not that
                table's. The honest per-table figures appear in the settled list below. */}
            {state.entity !== null && (
              <p className="text-xs text-fg-muted">{t(`desktop:entities.${state.entity}`)}</p>
            )}
          </div>
        )}

        {loaded.length > 0 && (
          <ul className="flex max-h-56 flex-col gap-1 overflow-y-auto text-xs text-fg-secondary">
            {loaded.map(([entity, count]) => (
              <li key={entity} className="flex justify-between gap-4">
                <span>
                  {t('desktop:bootstrap.tableProgress', {
                    table: t(`desktop:entities.${entity}`),
                    count: count ?? 0,
                  })}
                </span>
              </li>
            ))}
          </ul>
        )}

        {state.phase === 'error' && (
          <p className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
            {`${t('desktop:bootstrap.error')} ${errorMessage(t, state.errorCode ?? 'unknown')}`}
          </p>
        )}

        <div className="flex justify-end gap-2">
          {state.phase === 'error' && (
            <Button variant="primary" onClick={() => void startBootstrap()}>
              {t('desktop:bootstrap.retry')}
            </Button>
          )}
          {state.phase !== 'running' && (
            <Button variant={state.phase === 'error' ? 'ghost' : 'primary'} onClick={dismissBootstrap}>
              {t('desktop:bootstrap.continue')}
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}
