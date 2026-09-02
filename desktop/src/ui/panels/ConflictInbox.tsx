// Conflict Inbox — `SYNCDESKTOP.md` §7.2, third item.
//
// ## The A22 debt this screen pays (`docs/DESKTOP-ARCHITECTURE.md` EK 3)
//
// `can.*` is permissive on the desktop, so a user without the permission for an action can
// still perform it locally; the refusal only appears when the outbox is pushed, minutes or
// hours later, as a push result the client cannot apply. `sync::conflicts` returns BOTH kinds
// of unresolved result in one list:
//
//   * `FIELD_CONFLICT` — a genuine two-sided conflict. Both sides changed the same fields;
//     `conflicting_fields`, `mine` and `theirs` are all populated and a field-level merge is
//     meaningful.
//   * everything else — a one-sided REJECTION. `ONLINE_ONLY` (`SYNCDESKTOP.md` §8),
//     `UNRESOLVED_REFERENCE` (§5.4: the create this mutation depended on was itself rejected),
//     `ABILITY_REQUIRED` / `HTTP_403` (the A22 permission refusal), `RECORD_DELETED`. There is
//     no server row to merge with and offering a merge would be a lie.
//
// So the list is GROUPED BY `code`, and each group's heading is that code's sentence from
// `desktop.errors.*` — which is exactly the "why did this not happen" answer A22 asks for,
// already translated into all four languages, with no new key and no hard-coded prose. The two
// classes are visually distinct (warning vs. danger) and the merge affordance only exists where
// `conflicting_fields` is non-empty, so the difference is legible without a caption explaining
// it.
import { useCallback, useEffect, useMemo, useState } from 'react'

import { Badge, Button, Checkbox, EmptyState, toast } from '@/components/ui'
import { cn } from '@/lib/cn'

import type { Conflict, Resolution } from '../commands'
import { listConflicts, resolveConflict } from '../commands'
import { errorCodeOf, errorMessage } from '../errors'
import { formatDateTime } from '../format'
import { CheckIcon, InboxIcon } from '../icons'
import { useEngineStatus } from '../useEngineStatus'
import { useIntlLocale, useT } from '../useT'

/** A true two-sided conflict; every other code is a one-sided rejection. */
const FIELD_CONFLICT = 'FIELD_CONFLICT'

interface ConflictGroup {
  code: string
  items: Conflict[]
}

/**
 * Group by `code`, `FIELD_CONFLICT` first and the rejections after it, each sorted by size.
 * Stable ordering matters here: the user resolves these one at a time and the list reloads
 * after every resolution, so groups jumping around would move the button out from under the
 * cursor.
 */
function groupByCode(conflicts: readonly Conflict[]): ConflictGroup[] {
  const byCode = new Map<string, Conflict[]>()
  for (const conflict of conflicts) {
    const bucket = byCode.get(conflict.code)
    if (bucket) bucket.push(conflict)
    else byCode.set(conflict.code, [conflict])
  }

  return [...byCode.entries()]
    .map(([code, items]) => ({ code, items }))
    .sort((a, b) => {
      if (a.code === FIELD_CONFLICT) return -1
      if (b.code === FIELD_CONFLICT) return 1
      if (a.items.length !== b.items.length) return b.items.length - a.items.length
      return a.code.localeCompare(b.code)
    })
}

/** The fields a merge can choose between: what the server flagged, or the local payload's keys. */
function mergeableFields(conflict: Conflict): string[] {
  if (conflict.conflicting_fields.length > 0) return conflict.conflicting_fields
  return Object.keys(asRecord(conflict.mine))
}

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {}
}

/** A non-empty string, or `null` if the value isn't one. */
function asLabel(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

/**
 * A human-readable label for the record a conflict is about, read from whatever the mutation
 * payload happens to carry.
 *
 * `mine` and `theirs` are not full records — they are the fields the offline mutation actually
 * touched (`Conflict.mine`/`.theirs` in `../commands`), so a name field is present only when it
 * happened to be part of the edit. This checks the handful of fields `desktop:fields.*` already
 * names as an entity's identifying label (`title`, `name`, `subject`, a contact's
 * first/last name, or `company_name` for a lead/company) on the local side first, then the
 * server side. It never invents a label: when none of those fields are present, the caller falls
 * back to the translated entity type instead of calling this.
 */
function conflictDisplayName(conflict: Conflict): string | null {
  for (const side of [conflict.mine, conflict.theirs]) {
    const record = asRecord(side)
    const direct = asLabel(record.title) ?? asLabel(record.name) ?? asLabel(record.subject) ?? asLabel(record.company_name)
    if (direct) return direct

    const fullName = [asLabel(record.first_name), asLabel(record.last_name)].filter(Boolean).join(' ')
    if (fullName) return fullName
  }
  return null
}

/**
 * One side's value for one field, as text.
 *
 * The em dash stands in for "this side has no value here". It is a glyph, not a sentence, and
 * deliberately not a dictionary key — a translated "(empty)" in a two-column diff reads as data
 * the server sent.
 */
function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

export function ConflictInbox() {
  const t = useT()
  const locale = useIntlLocale()

  const [conflicts, setConflicts] = useState<Conflict[] | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set())
  const [busy, setBusy] = useState(false)
  const [mergeOpen, setMergeOpen] = useState<string | null>(null)

  // The engine's own count, not a poll: `EngineEvent::StatusChanged` (`refresh_status` in
  // `syncra_sync::sync::mod`) recomputes `conflicts` on every push and every resolution and
  // publishes it, and `DesktopPanel`'s tab badge already renders it live through this same hook.
  // Reloading the list whenever this number changes reuses that existing feed instead of opening
  // a second one: previously `load()` only ran once on mount, so a conflict that arrived while
  // this panel was already open bumped the badge (subscribed to the live feed) without the list
  // ever re-fetching (subscribed to nothing after its first render).
  const conflictCount = useEngineStatus().conflicts

  const load = useCallback(async () => {
    try {
      const rows = await listConflicts()
      setConflicts(rows)
      setLoadError(null)
      // Anything that was resolved (or resolved elsewhere) must leave the selection, or a bulk
      // action would address ids the engine no longer knows.
      const live = new Set(rows.map((row) => row.id))
      setSelected((current) => new Set([...current].filter((id) => live.has(id))))
    } catch (error) {
      setConflicts([])
      setLoadError(errorMessage(t, errorCodeOf(error)))
    }
  }, [t])

  useEffect(() => {
    void load()
    // `conflictCount` is intentionally in the dependency list: it is the signal, not just a
    // value read inside `load`. Without it this effect only re-runs when `load` itself changes
    // (i.e. never, since `load` only depends on `t`), so a conflict that arrives while the panel
    // is already open updates the badge but never re-fetches this list.
  }, [load, conflictCount])

  const groups = useMemo(() => groupByCode(conflicts ?? []), [conflicts])

  /** Resolve a list of ids with one choice, then reload. */
  const applyResolution = useCallback(
    async (ids: readonly string[], choice: Resolution) => {
      if (ids.length === 0) return
      setBusy(true)
      try {
        for (const id of ids) {
          await resolveConflict(id, choice)
        }
        toast.success(t('desktop:conflicts.resolvedToast'))
      } catch (error) {
        toast.error(`${t('desktop:conflicts.resolveError')} ${errorMessage(t, errorCodeOf(error))}`)
      } finally {
        setBusy(false)
        setMergeOpen(null)
        await load()
      }
    },
    [load, t]
  )

  function toggle(id: string): void {
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  if (conflicts === null) {
    return <p className="p-6 text-sm text-fg-muted">{t('common:states.loading')}</p>
  }

  const allIds = conflicts.map((conflict) => conflict.id)
  const targetIds = selected.size > 0 ? [...selected] : allIds
  const bulkLabel = selected.size > 0 ? 'desktop:conflicts.resolveSelected' : 'desktop:conflicts.resolveAll'

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm text-fg-muted">{t('desktop:conflicts.subtitle')}</p>

      {loadError && (
        <p className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">{loadError}</p>
      )}

      {conflicts.length === 0 ? (
        <EmptyState
          icon={<InboxIcon className="size-6" />}
          title={t('desktop:conflicts.empty.title')}
          description={t('desktop:conflicts.empty.description')}
        />
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border-subtle bg-surface-2 px-3 py-2">
            <Checkbox
              checked={selected.size > 0 && selected.size === allIds.length}
              indeterminate={selected.size > 0 && selected.size < allIds.length}
              onChange={(event) =>
                setSelected(event.target.checked ? new Set(allIds) : new Set())
              }
              label={t(bulkLabel)}
            />
            <div className="ml-auto flex gap-2">
              <Button
                size="sm"
                variant="secondary"
                disabled={busy}
                onClick={() => void applyResolution(targetIds, { kind: 'keep_mine' })}
              >
                {t('desktop:conflicts.keepMine')}
              </Button>
              <Button
                size="sm"
                variant="secondary"
                disabled={busy}
                onClick={() => void applyResolution(targetIds, { kind: 'take_server' })}
              >
                {t('desktop:conflicts.takeServer')}
              </Button>
            </div>
          </div>

          {groups.map((group) => (
            <section key={group.code} className="flex flex-col gap-2">
              <header className="flex items-center gap-2">
                <Badge
                  dot
                  size="sm"
                  variant={group.code === FIELD_CONFLICT ? 'warning' : 'danger'}
                >
                  {group.items.length}
                </Badge>
                <h3
                  className={cn(
                    'text-sm font-medium',
                    group.code === FIELD_CONFLICT ? 'text-warning' : 'text-danger'
                  )}
                >
                  {errorMessage(t, group.code)}
                </h3>
              </header>

              <ul className="flex flex-col gap-2">
                {group.items.map((conflict) => (
                  <ConflictRow
                    key={conflict.id}
                    conflict={conflict}
                    locale={locale}
                    busy={busy}
                    selected={selected.has(conflict.id)}
                    mergeOpen={mergeOpen === conflict.id}
                    onToggleSelected={() => toggle(conflict.id)}
                    onToggleMerge={() =>
                      setMergeOpen((current) => (current === conflict.id ? null : conflict.id))
                    }
                    onResolve={(choice) => void applyResolution([conflict.id], choice)}
                  />
                ))}
              </ul>
            </section>
          ))}
        </>
      )}
    </div>
  )
}

interface ConflictRowProps {
  conflict: Conflict
  locale: string
  busy: boolean
  selected: boolean
  mergeOpen: boolean
  onToggleSelected: () => void
  onToggleMerge: () => void
  onResolve: (choice: Resolution) => void
}

function ConflictRow({
  conflict,
  locale,
  busy,
  selected,
  mergeOpen,
  onToggleSelected,
  onToggleMerge,
  onResolve,
}: ConflictRowProps) {
  const t = useT()
  const fields = useMemo(() => mergeableFields(conflict), [conflict])
  const displayName = useMemo(() => conflictDisplayName(conflict), [conflict])
  const entityLabel = t(`desktop:entities.${conflict.entity}`)
  const idText = conflict.client_id ?? conflict.id

  // Which fields the local change wins. Starts as "all of them", i.e. a merge nobody edits is
  // KeepMine — the safe default, because it never silently discards work the user did offline.
  const [keepMine, setKeepMine] = useState<ReadonlySet<string>>(() => new Set(fields))

  const mine = asRecord(conflict.mine)
  const theirs = asRecord(conflict.theirs)
  const createdAt = formatDateTime(locale, conflict.created_at)

  return (
    <li className="rounded-lg border border-border-subtle bg-surface-1 p-3">
      <div className="flex flex-wrap items-center gap-3">
        <Checkbox checked={selected} onChange={onToggleSelected} />

        <div className="flex min-w-0 flex-col">
          <span className="truncate text-sm text-fg">{displayName ?? entityLabel}</span>
          <span className="truncate font-mono text-xs text-fg-muted">
            {displayName ? `${entityLabel} · ${idText}` : idText}
          </span>
        </div>

        {createdAt && <span className="text-xs text-fg-muted">{createdAt}</span>}

        <div className="ml-auto flex flex-wrap gap-2">
          {fields.length > 0 && (
            <Button size="sm" variant="ghost" disabled={busy} onClick={onToggleMerge}>
              {t('desktop:conflicts.mergeFields')}
            </Button>
          )}
          <Button
            size="sm"
            variant="secondary"
            disabled={busy}
            onClick={() => onResolve({ kind: 'keep_mine' })}
          >
            {t('desktop:conflicts.keepMine')}
          </Button>
          <Button
            size="sm"
            variant="secondary"
            disabled={busy}
            onClick={() => onResolve({ kind: 'take_server' })}
          >
            {t('desktop:conflicts.takeServer')}
          </Button>
        </div>
      </div>

      {mergeOpen && fields.length > 0 && (
        <div className="mt-3 rounded-md border border-border-subtle bg-surface-2 p-3">
          <p className="mb-2 text-xs font-medium uppercase tracking-wide text-fg-muted">
            {t('desktop:conflicts.fieldList.title')}
          </p>

          <div className="w-full overflow-x-auto">
            <table className="w-full border-collapse text-left text-sm">
              <thead>
                <tr className="border-b border-border-subtle text-xs text-fg-muted">
                  <th scope="col" className="py-1 pr-3 font-medium">
                    {t('desktop:conflicts.keepMine')}
                  </th>
                  <th scope="col" className="py-1 pr-3 font-medium">
                    {t('desktop:conflicts.fieldList.localValue')}
                  </th>
                  <th scope="col" className="py-1 font-medium">
                    {t('desktop:conflicts.fieldList.serverValue')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {fields.map((field) => (
                  <tr key={field} className="border-b border-border-subtle last:border-0">
                    <td className="py-1.5 pr-3 align-top">
                      <Checkbox
                        checked={keepMine.has(field)}
                        onChange={() =>
                          setKeepMine((current) => {
                            const next = new Set(current)
                            if (next.has(field)) next.delete(field)
                            else next.add(field)
                            return next
                          })
                        }
                        label={<span className="font-mono text-xs">{field}</span>}
                      />
                    </td>
                    <td className="py-1.5 pr-3 align-top font-mono text-xs text-fg">
                      {formatValue(mine[field])}
                    </td>
                    <td className="py-1.5 align-top font-mono text-xs text-fg-secondary">
                      {formatValue(theirs[field])}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-3 flex justify-end">
            <Button
              size="sm"
              disabled={busy}
              leftIcon={<CheckIcon className="size-3.5" />}
              onClick={() => onResolve({ kind: 'merge', fields: [...keepMine] })}
            >
              {t('common:actions.apply')}
            </Button>
          </div>
        </div>
      )}
    </li>
  )
}
