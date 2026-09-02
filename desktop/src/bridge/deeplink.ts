// Deep-link delivery — `SYNCDESKTOP.md` §6.4, F5-4.
//
// The Rust half (`src-tauri/src/deep_link.rs`) owns the security boundary: it parses the url
// against §6.4's `^[a-z]+/[0-9]{1,12}$` and the eight-name entity allowlist, refuses everything
// else without a word to the user, brings the main window forward, and emits the validated
// `{entity, id}` under the `deep-link` event. Nothing unvalidated ever reaches this file — the
// plugin's own `deep-link://new-url` event, which carries the RAW url, is deliberately not
// listened to anywhere in `desktop/src`.
//
// The entity -> route table is in `deeplink-routes.ts`; see that file for why it is separate.
import { emit, listen, type UnlistenFn } from '@tauri-apps/api/event'

import { router } from '@/router'

import { routeForDeepLink, type DeepLinkTarget } from './deeplink-routes'

/** Tauri event name `src-tauri/src/deep_link.rs` emits validated targets under. */
export const DEEP_LINK_EVENT = 'deep-link'

/**
 * Tauri event this bridge emits UP to the shell once it is subscribed — `deep_link.rs`'s
 * `DEEP_LINK_READY_EVENT`, and the only thing this file ever emits.
 *
 * ## Why it exists: the cold start was losing the link
 *
 * A link clicked while the app is CLOSED starts the process with the url on its command line,
 * and `deep_link::install` reads it from `.setup()` — before the webview has run a line of
 * JavaScript, and therefore before the `listen()` below has subscribed. A Tauri event with no
 * listener is not queued, it is dropped: the app opened and the route stayed at `/`. (The link
 * was being parsed correctly all along — a hostile launch url still logged its rejection.)
 *
 * So the shell now HOLDS the launch target and waits to be told the webview is listening. This
 * event is that acknowledgement. It is an event rather than a `take_launch_deep_link` command
 * because a command would widen the §6.2 command surface — the spec, `check-command-wiring.mjs`'s
 * `CONTRACT`, and everything quoting them — for one string; `core:event:default` already permits
 * both directions, so nothing else in the app moves.
 *
 * Links that arrive while the app is RUNNING never touch this path: `on_open_url` emits them
 * straight to the listener that has been up since boot.
 */
export const DEEP_LINK_READY_EVENT = 'deep-link-ready'

/**
 * Start routing deep links. Called once from the entry, before the first render.
 *
 * Navigation goes through the router SINGLETON rather than a `useNavigate` hook, for the same
 * reason `DesktopPanel` is chrome and not a route (KARAR A27): this listener is armed outside
 * the React tree — a link can arrive while the app sits on `/login`, or in the same tick the
 * process starts — and `router.navigate` is the supported way to move a data router from
 * outside a component.
 *
 * An unauthenticated deep link is not special-cased: `RequireAuth` sends it to `/login` like
 * any other route, which is the correct behaviour and not this bridge's decision to make.
 */
export async function startDeepLinkBridge(): Promise<UnlistenFn> {
  // AWAITED, and the announcement below is what makes the await matter. `listen()` resolves
  // only after the Rust side has actually registered this listener; announcing before that
  // point would ask the shell to emit the held launch target into the same gap that lost it in
  // the first place. The two statements are in this order for that one reason — do not merge,
  // reorder, or drop the `await`.
  const unlisten = await listen<DeepLinkTarget>(DEEP_LINK_EVENT, (event) => {
    const path = routeForDeepLink(event.payload)
    if (path !== null) void router.navigate(path)
  })

  // Non-fatal, and deliberately not rethrown: the entry calls this as `void
  // startDeepLinkBridge()`, so a rejection here would surface as an unhandled rejection and
  // take the listener's `unlisten` down with it. Everything except the cold-start link keeps
  // working without this announcement.
  try {
    await emit(DEEP_LINK_READY_EVENT)
  } catch (error) {
    console.warn(`[deeplink] could not announce the webview with ${DEEP_LINK_READY_EVENT}`, error)
  }

  return unlisten
}
