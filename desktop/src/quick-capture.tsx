import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { getCurrentWindow } from '@tauri-apps/api/window'

// Second desktop entry — the quick-capture popup (`SYNCDESKTOP.md` §6.4 item 3, F5-3).
//
// ## What this file deliberately does NOT do
//
// `main.desktop.tsx` sets the platform, installs the desktop auth transport, primes the
// connectivity snapshot, opens the engine-event bridge and arms the realtime bridge — all
// before its first render. None of that belongs here:
//
//   * the popup renders no shared screen, so nothing calls `getPlatform()`;
//   * it issues no HTTP request — its one write goes through `data::mutate`, whose session
//     lives in Rust, so there is no token for a transport to carry;
//   * a SECOND engine-event subscription would double every query invalidation the main
//     window already handles, and a second realtime bridge would hand the engine every Reverb
//     frame twice.
//
// What it does need is the same i18n gate (`i18nReady`) `main.desktop.tsx` documents under
// KARAR A7: `tr` is eager and `en/de/fr` are lazy chunks, so rendering before the selected
// locale has landed shows a Turkish popup to an English user.
import '@/index.css'
import { i18nReady } from '@/i18n'

import { QuickCapture } from './ui/QuickCapture'

/**
 * Hide rather than close.
 *
 * `quick_capture::open` on the Rust side shows the existing window when there is one and only
 * builds a new one otherwise, so hiding keeps the second hotkey press instant. Closing would
 * destroy the webview and pay the whole boot again — for a window whose entire promise is that
 * it appears immediately.
 *
 * `core:window:allow-hide` is granted to this window in `capabilities/default.json`; the
 * capability's `windows` list names `quick-capture` explicitly.
 */
function dismiss(): void {
  void getCurrentWindow().hide()
}

void i18nReady.then(() => {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <QuickCapture onDismiss={dismiss} />
    </StrictMode>,
  )
})
