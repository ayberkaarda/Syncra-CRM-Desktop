// The quick-capture popup — `SYNCDESKTOP.md` §6.4 item 3, F5-3.
//
// §6.4: "quick-capture window (always-on-top, 480×360, frameless), 4 types
// (lead/note/task/activity), works offline (`mutate`)".
//
// The window itself — size, always-on-top, frameless, and the global hotkey that opens it —
// belongs to `src-tauri/src/quick_capture.rs`; this is only what is inside it. The payload
// composition lives one module over in `capture.ts`, which is the part `capture.test.ts` runs.
//
// ## Why every write goes through `createRow`
//
// "Works offline" is not a property of this form, it is a property of the path it writes on.
// `createRow` (`platform/data/writes.ts`) applies the row to the local mirror and queues it in
// the outbox in ONE transaction, so the record exists the moment the user presses save whether
// or not there is a network. A direct `platform.http` POST would fail with no queue behind it,
// which is precisely what a capture window must never do — the whole point is that the thought
// is not lost.
import { useCallback, useEffect, useMemo, useState } from 'react'

import { Button, Input, Select, Textarea } from '@/components/ui'

import { createRow } from '../platform/data/writes'

import {
  ACTIVITY_TYPES,
  CAPTURE_TYPES,
  composeCapture,
  EMPTY_CAPTURE,
  type ActivityType,
  type CaptureType,
} from './capture'
import { errorCodeOf, errorMessage } from './errors'
import { useT } from './useT'

export interface QuickCaptureProps {
  /** Called after a successful save, and when the user presses Escape. */
  onDismiss: () => void
}

export function QuickCapture({ onDismiss }: QuickCaptureProps) {
  const t = useT()
  const [type, setType] = useState<CaptureType>('lead')
  const [form, setForm] = useState(EMPTY_CAPTURE)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const set = useCallback(<K extends keyof typeof EMPTY_CAPTURE>(key: K, value: (typeof EMPTY_CAPTURE)[K]) => {
    setForm((current) => ({ ...current, [key]: value }))
  }, [])

  // Escape closes the popup. It is the only way out: the window is frameless (§6.4), so there
  // is no title bar and no close button, and the OS shortcut for closing a window does not
  // apply to a `skipTaskbar` popup either.
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onDismiss()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [onDismiss])

  const composed = useMemo(
    () => composeCapture(type, { ...form, now: () => new Date() }),
    [type, form],
  )

  function submit(): void {
    if (composed === null || busy) return
    setBusy(true)
    setError(null)
    void (async () => {
      try {
        await createRow(composed.entity, composed.payload)
        setForm(EMPTY_CAPTURE)
        onDismiss()
      } catch (raw) {
        setError(errorMessage(t, errorCodeOf(raw)))
      } finally {
        setBusy(false)
      }
    })()
  }

  return (
    <form
      className="flex h-screen w-screen flex-col overflow-hidden border border-border-strong bg-surface-1"
      onSubmit={(event) => {
        event.preventDefault()
        submit()
      }}
    >
      {/*
        The scrollable body and the action row are deliberately two siblings, not one flexed
        column with `mt-auto` on the buttons. `mt-auto` only pushes the row to the bottom of
        whatever room is LEFT inside the flex container — it does nothing once the content
        taller than the window, because the container itself still clips at `overflow-hidden`
        and the pushed-down row goes with it. A fixed height (480x360, `resizable(false)` in
        `src-tauri/src/quick_capture.rs`) makes that the common case, not an edge case: the
        Lead and Activity tabs alone overflow it by ~40px.

        Making the BODY the scroll container and the action row a sibling outside it means the
        row's height is never part of what can overflow — it is reserved by the flex layout
        (`shrink-0`) before the body's `overflow-y-auto` claims what's left. This holds for any
        window height, not just 360, so a future resize of the window (or a fifth field added to
        a tab) cannot reproduce the bug.
      */}
      <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-4">
        <div className="flex items-center justify-between">
          <h1 className="text-sm font-semibold text-fg">{t('desktop:quickCapture.title')}</h1>
          <span className="text-xs text-fg-muted">{t('desktop:quickCapture.dismissHint')}</span>
        </div>

        <div className="flex gap-1" role="tablist" aria-label={t('desktop:quickCapture.title')}>
          {CAPTURE_TYPES.map((candidate) => (
            <button
              key={candidate}
              type="button"
              role="tab"
              aria-selected={candidate === type}
              className={
                candidate === type
                  ? 'flex-1 rounded-md bg-primary px-2 py-1.5 text-xs font-medium text-primary-fg'
                  : 'flex-1 rounded-md bg-surface-2 px-2 py-1.5 text-xs font-medium text-fg-secondary'
              }
              onClick={() => {
                setType(candidate)
                setError(null)
              }}
            >
              {t(`desktop:quickCapture.types.${candidate}`)}
            </button>
          ))}
        </div>

        <Input
          autoFocus
          value={form.title}
          onChange={(event) => set('title', event.target.value)}
          label={t(
            type === 'lead'
              ? 'desktop:fields.name'
              : type === 'task'
                ? 'desktop:fields.title'
                : 'desktop:fields.subject',
          )}
        />

        {type === 'activity' && (
          <Select
            value={form.activityType}
            onChange={(event) =>
              set('activityType', event.target.value as ActivityType)
            }
            label={t('desktop:fields.type')}
            options={ACTIVITY_TYPES.map((value) => ({
              value,
              label: t(`desktop:quickCapture.activityTypes.${value}`),
            }))}
          />
        )}

        {type === 'lead' && (
          <div className="grid grid-cols-2 gap-2">
            <Input
              value={form.email}
              onChange={(event) => set('email', event.target.value)}
              label={t('desktop:fields.email')}
              type="email"
            />
            <Input
              value={form.phone}
              onChange={(event) => set('phone', event.target.value)}
              label={t('desktop:fields.phone')}
            />
          </div>
        )}

        <Textarea
          rows={3}
          value={form.body}
          onChange={(event) => set('body', event.target.value)}
          label={t(
            type === 'lead'
              ? 'desktop:fields.notes'
              : type === 'task'
                ? 'desktop:fields.description'
                : 'desktop:fields.body',
          )}
        />

        {error !== null && (
          <p className="rounded-md bg-danger-tint px-3 py-2 text-xs text-danger">{error}</p>
        )}
      </div>

      <div
        data-testid="quick-capture-actions"
        className="flex shrink-0 justify-end gap-2 border-t border-border-strong bg-surface-1 p-4"
      >
        <Button type="button" variant="secondary" onClick={onDismiss}>
          {t('common:actions.cancel')}
        </Button>
        <Button type="submit" loading={busy} disabled={composed === null}>
          {t('common:actions.save')}
        </Button>
      </div>
    </form>
  )
}
