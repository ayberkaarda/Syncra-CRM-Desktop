// The current pathname, for chrome that lives outside `RouterProvider`.
//
// ## Why this polls instead of subscribing to the router
//
// `router.subscribe()` would be the exact tool, and it is refused for the same reason
// `DesktopPanel.tsx` refuses `router._internalSetRoutes`: react-router 7.18 marks it
// `@private PRIVATE - DO NOT USE` in the published typings
// (`react-router/dist/development/index-react-server.d.ts`), i.e. it is not part of the
// supported surface and a patch release may change it with no compile error here. The one
// router API this shell does use — `router.navigate(to)` in `bridge/deeplink.ts` — carries no
// such marker, which is the whole difference.
//
// `popstate` alone is not enough either: a data router pushes through the History API, and a
// `pushState` fires no event. So the listener covers back/forward instantly and a slow poll
// covers everything else. The consumer is a pair of icon buttons appearing on a record screen,
// so up to {@link ROUTE_POLL_MS} of lag is invisible; nothing correctness-bearing reads this —
// `FileDrop` resolves its target from `window.location` at drop time, which is always current.
import { useSyncExternalStore } from 'react'

/** How often the pathname is re-read. Slow on purpose: this drives visibility, not behaviour. */
export const ROUTE_POLL_MS = 400

function readPath(): string {
  return window.location.pathname
}

function subscribe(onStoreChange: () => void): () => void {
  window.addEventListener('popstate', onStoreChange)
  const timer = setInterval(onStoreChange, ROUTE_POLL_MS)
  return () => {
    window.removeEventListener('popstate', onStoreChange)
    clearInterval(timer)
  }
}

/**
 * The window's current pathname, re-rendering the caller when it changes.
 *
 * `useSyncExternalStore` compares the snapshot by identity and `readPath` returns a primitive,
 * so the 400 ms tick only re-renders when the path actually moved.
 */
export function useRoutePath(): string {
  return useSyncExternalStore(subscribe, readPath, readPath)
}
