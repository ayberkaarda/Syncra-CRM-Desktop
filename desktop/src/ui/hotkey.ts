// The configured quick-capture accelerator — `SYNCDESKTOP.md` §6.4 ("configurable").
//
// ## Why `localStorage` and not `DesktopSettings`
//
// `syncra_sync::DesktopSettings` is the engine's persisted settings row and its public API is
// frozen (`SYNCDESKTOP.md` §5.2): it carries `retention_days`, `max_db_size_mb`, `max_outbox`,
// `clipboard_capture` and `close_to_tray`, and this strand cannot add a sixth field. The shell
// could have grown a settings file of its own next to the database, but that would be a second
// preferences store on disk for exactly one string.
//
// `localStorage` is where this app already keeps per-install preferences — the language
// override lives there (`frontend/src/i18n/index.ts`, `syncra-locale`) and the tray reads it
// through the same push mechanism (`set_tray_language`). A keyboard shortcut is per-install by
// nature: it is a property of the machine's other software, not of the user's account, and
// syncing it to a second computer with a different keyboard layout and different conflicting
// applications would be wrong.
//
// The storage is keyed off `tauri.conf.json`'s `identifier`, which
// `scripts/check-identifier.mjs` pins for exactly this reason.
import { DEFAULT_HOTKEY, registerHotkey } from './commands'

/** `localStorage` key. Namespaced like `syncra-locale`, the app's other per-install setting. */
export const HOTKEY_STORAGE_KEY = 'syncra-desktop-hotkey'

/**
 * Read the stored accelerator, or the §6.4 default.
 *
 * A `localStorage` read can throw outright (a webview with site data disabled), so the whole
 * access is guarded: a popup that cannot open because the preferences read threw would be a
 * far worse failure than falling back to the documented default.
 */
export function readHotkey(): string {
  try {
    const stored = window.localStorage.getItem(HOTKEY_STORAGE_KEY)
    return stored !== null && stored.trim() !== '' ? stored : DEFAULT_HOTKEY
  } catch {
    return DEFAULT_HOTKEY
  }
}

/**
 * Claim `accelerator`, and remember it only if the OS accepted it.
 *
 * The order matters and is the whole point of §6.4's "conflict detection": storing first and
 * registering after would persist a shortcut that does not work, and the app would then
 * re-apply — and re-fail — that same dead accelerator on every subsequent boot. `register_hotkey`
 * rejects with `OS_ERROR` when another application already owns the combination and with
 * `VALIDATION_ERROR` when the string is not a usable accelerator; both reach the caller
 * unchanged so the settings screen can say which.
 */
export async function applyHotkey(accelerator: string): Promise<void> {
  await registerHotkey(accelerator)
  try {
    window.localStorage.setItem(HOTKEY_STORAGE_KEY, accelerator)
  } catch {
    // The shortcut is registered and working for this session; only the memory of it is lost.
  }
}

/**
 * Re-apply the stored accelerator at boot.
 *
 * `.setup()` on the Rust side has already claimed the default (`quick_capture::install_default`),
 * so a user who never changed it has a working hotkey before the webview exists and this call
 * is an idempotent no-op. Failure is swallowed on purpose: the tray's `Quick capture` item
 * opens the same window, and a stored shortcut that some newly installed application has since
 * taken must not stop the app from starting.
 */
export function restoreHotkey(): void {
  const stored = readHotkey()
  if (stored === DEFAULT_HOTKEY) return
  void applyHotkey(stored).catch(() => undefined)
}
