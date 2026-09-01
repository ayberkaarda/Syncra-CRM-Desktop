// Shell behaviour settings — the desktop-only preferences that are not about storage.
//
// `StorageSettings` owns the K8 ceilings and the local mirror; this screen owns what §6.4 makes
// configurable about the shell itself. Separate tabs because they answer different questions
// ("how much disk may I use" versus "how does this app behave on my machine") and because they
// save through different paths: the hotkey is a `localStorage` preference the OS has to accept,
// while the toggles are fields of the engine's `DesktopSettings` row.
//
// ## Both screens write the same struct
//
// `storage::update_settings` takes the WHOLE `DesktopSettings`, so each screen reads the
// persisted row first and sends back the fields it does not own. That read happens on mount —
// `TabPanel` renders nothing while inactive (`components/ui/Tabs.tsx`), so opening this tab is
// what loads it, and the value is never older than the click that revealed it.
import { useCallback, useEffect, useState } from 'react'

import { Button, Checkbox, Input, toast } from '@/components/ui'

import type { DesktopSettings } from '../commands'
import { readAutostart, readStorageSettings, setAutostart, updateStorageSettings } from '../commands'
import { errorCodeOf, errorMessage } from '../errors'
import { applyHotkey, readHotkey } from '../hotkey'
import { useT } from '../useT'

export function DesktopPreferences() {
  const t = useT()

  const [hotkey, setHotkey] = useState(readHotkey)
  const [hotkeyError, setHotkeyError] = useState<string | null>(null)
  const [hotkeyBusy, setHotkeyBusy] = useState(false)

  const [settings, setSettings] = useState<DesktopSettings | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  /**
   * Launch-at-login, which is NOT part of `DesktopSettings` and cannot be.
   *
   * The engine never sees it: it is a registry value on Windows, a launch agent on macOS and a
   * `.desktop` file on Linux, so the OS is the only store there is and `os::get_autostart`
   * (ledger D1) is the only way to render the toggle on open. `null` is the third state —
   * "not read yet, or the read failed" — and it keeps the checkbox disabled rather than
   * drawing an unchecked box that would claim the feature is off when nobody knows.
   */
  const [autostart, setAutostartState] = useState<boolean | null>(null)
  const [autostartError, setAutostartError] = useState<string | null>(null)
  const [autostartBusy, setAutostartBusy] = useState(false)

  // The stored accelerator is read once at mount and re-read if the panel is remounted; nothing
  // else in the app writes it, so there is no store to subscribe to.
  useEffect(() => {
    setHotkey(readHotkey())
  }, [])

  const load = useCallback(async () => {
    try {
      setSettings(await readStorageSettings())
      setLoadError(null)
    } catch (error) {
      setLoadError(errorMessage(t, errorCodeOf(error)))
    }
  }, [t])

  useEffect(() => {
    void load()
  }, [load])

  // Read on open, for the reason `readAutostart`'s docblock gives: `set_autostart` only reports
  // the state it just wrote, so without this read the screen would have nothing to render the
  // toggle from and would have to guess.
  useEffect(() => {
    void (async () => {
      try {
        setAutostartState(await readAutostart())
        setAutostartError(null)
      } catch (error) {
        setAutostartState(null)
        setAutostartError(errorMessage(t, errorCodeOf(error)))
      }
    })()
  }, [t])

  /**
   * Write the toggle, and adopt the state the OS reports back rather than the one just sent.
   *
   * `set_autostart` re-reads the OS after writing, so a platform that silently refused the
   * change answers with the OLD value — rendering the returned boolean is what stops the
   * checkbox from showing a setting the machine does not actually have.
   */
  const toggleAutostart = useCallback(
    (value: boolean) => {
      setAutostartBusy(true)
      setAutostartError(null)
      void (async () => {
        try {
          setAutostartState(await setAutostart(value))
          toast.success(t('desktop:preferences.autostart.saved'))
        } catch (error) {
          setAutostartError(
            `${t('desktop:preferences.autostart.saveError')} ${errorMessage(t, errorCodeOf(error))}`,
          )
          // The write failed, so what the OS holds is unknown again — re-read rather than leave
          // the box showing the value the user clicked.
          try {
            setAutostartState(await readAutostart())
          } catch {
            setAutostartState(null)
          }
        } finally {
          setAutostartBusy(false)
        }
      })()
    },
    [t],
  )

  /**
   * Write one boolean back, carrying every other field through unchanged.
   *
   * Saved on toggle rather than behind a save button: a checkbox that needs confirming is a
   * checkbox users believe they already set. The optimistic local update is reverted by the
   * `load()` in the `finally` if the engine refused.
   */
  const toggle = useCallback(
    (field: 'close_to_tray' | 'clipboard_capture', value: boolean) => {
      if (settings === null) return
      const next: DesktopSettings = { ...settings, [field]: value }
      setSettings(next)
      setBusy(true)
      void (async () => {
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
    },
    [load, settings, t],
  )

  const saveHotkey = useCallback(() => {
    setHotkeyBusy(true)
    setHotkeyError(null)
    void (async () => {
      try {
        await applyHotkey(hotkey)
        toast.success(t('desktop:preferences.hotkey.saved'))
      } catch (error) {
        // `OS_ERROR` from `register_hotkey` means one thing only on this screen: the
        // combination is already claimed by another application (§6.4 "conflict detection").
        // The generic `errors.OS_ERROR` sentence would not say that, so this screen names it —
        // and a `VALIDATION_ERROR` (unparseable, or no modifier) keeps its own sentence.
        const code = errorCodeOf(error)
        setHotkeyError(
          code === 'OS_ERROR' ? t('desktop:preferences.hotkey.conflict') : errorMessage(t, code),
        )
      } finally {
        setHotkeyBusy(false)
      }
    })()
  }, [hotkey, t])

  return (
    <div className="flex flex-col gap-6">
      {loadError !== null && (
        <p className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">{loadError}</p>
      )}

      <section className="flex flex-col gap-2">
        <Input
          value={hotkey}
          onChange={(event) => {
            setHotkey(event.target.value)
            setHotkeyError(null)
          }}
          label={t('desktop:preferences.hotkey.label')}
          hint={t('desktop:preferences.hotkey.description')}
          error={hotkeyError ?? undefined}
          spellCheck={false}
          autoComplete="off"
        />
        <div className="flex justify-end">
          <Button disabled={hotkeyBusy || hotkey.trim() === ''} onClick={saveHotkey}>
            {t('common:actions.save')}
          </Button>
        </div>
      </section>

      <section className="flex flex-col gap-4">
        {/* §6.4 item 7. Above the engine-backed toggles because it is the one setting on this
            screen that the OS owns rather than the sync engine — see the state declaration. */}
        <div className="flex flex-col gap-1">
          <Checkbox
            checked={autostart ?? false}
            disabled={autostart === null || autostartBusy}
            onChange={(event) => toggleAutostart(event.target.checked)}
            label={t('desktop:preferences.autostart.label')}
          />
          <p className="pl-6 text-xs text-fg-muted">
            {t('desktop:preferences.autostart.description')}
          </p>
          {autostartError !== null && (
            <p className="pl-6 text-xs text-danger">{autostartError}</p>
          )}
        </div>

        <Checkbox
          checked={settings?.close_to_tray ?? true}
          disabled={settings === null || busy}
          onChange={(event) => toggle('close_to_tray', event.target.checked)}
          label={t('desktop:window.closeToTray.label')}
        />

        {/* K10 / §6.4 item 6 — opt-in, and off by default. The description is not decoration:
            it is where the user is told what the feature reads and what it does not keep, and
            an opt-in nobody understands is not consent. */}
        <div className="flex flex-col gap-1">
          <Checkbox
            checked={settings?.clipboard_capture ?? false}
            disabled={settings === null || busy}
            onChange={(event) => toggle('clipboard_capture', event.target.checked)}
            label={t('desktop:clipboard.optIn.label')}
          />
          <p className="pl-6 text-xs text-fg-muted">{t('desktop:clipboard.optIn.description')}</p>
        </div>
      </section>
    </div>
  )
}
