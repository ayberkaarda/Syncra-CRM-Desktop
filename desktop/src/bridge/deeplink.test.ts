// `bridge/deeplink.ts` — the cold-start handshake, which is the half that was broken.
//
// The route table has its own suite (`deeplink-routes.test.ts`) and the url allowlist lives in
// Rust (`src-tauri/src/deep_link.rs`). What is left, and what these tests are about, is the
// ORDER of two lines: a link clicked while the app was closed reaches the shell in `.setup()`,
// before this bridge has subscribed, and a Tauri event with no listener is dropped. The shell
// therefore holds the target until the webview announces itself — and the announcement is only
// correct if it happens strictly AFTER `listen()` has resolved.
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { UnlistenFn } from '@tauri-apps/api/event'

import type { DeepLinkTarget } from './deeplink-routes'

// `vi.mock` factories are hoisted above the imports, so the spies they close over have to be
// hoisted with them.
const mocks = vi.hoisted(() => ({
  navigate: vi.fn(),
  listen: vi.fn(),
  emit: vi.fn(),
  warn: vi.fn(),
}))

// `frontend/src/router.tsx` calls `createBrowserRouter` at module scope, which touches
// `document`; this runner has no DOM. Mocking the module is what lets `deeplink.ts` be imported
// at all here — the same wall `deeplink-routes.ts` was split out to stay on the other side of.
vi.mock('@/router', () => ({ router: { navigate: mocks.navigate } }))
vi.mock('@tauri-apps/api/event', () => ({ listen: mocks.listen, emit: mocks.emit }))

// Below the `vi.mock` calls only for readability — those are hoisted above every import.
import { DEEP_LINK_EVENT, DEEP_LINK_READY_EVENT, startDeepLinkBridge } from './deeplink'

/** The `unlisten` a real `listen()` resolves with. */
const unlisten: UnlistenFn = () => undefined

type Handler = (event: { payload: DeepLinkTarget }) => void

/** `listen()` resolved immediately, capturing the handler the bridge registered. */
function subscribeImmediately(): { handler: () => Handler } {
  let captured: Handler | undefined
  mocks.listen.mockImplementation((_event: string, handler: Handler) => {
    captured = handler
    return Promise.resolve(unlisten)
  })
  return {
    handler: () => {
      if (!captured) throw new Error('the bridge never subscribed')
      return captured
    },
  }
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('startDeepLinkBridge', () => {
  it('subscribes to the event the Rust side emits validated targets under', async () => {
    subscribeImmediately()
    await startDeepLinkBridge()
    expect(mocks.listen).toHaveBeenCalledWith(DEEP_LINK_EVENT, expect.any(Function))
  })

  it('routes a target the shell hands it', async () => {
    const { handler } = subscribeImmediately()
    await startDeepLinkBridge()

    handler()({ payload: { entity: 'ticket', id: '8' } })

    expect(mocks.navigate).toHaveBeenCalledWith('/tickets/8')
  })

  // THE COLD-START BUG. The launch target is waiting in the shell the whole time this promise
  // is pending; announcing before it resolves would ask for the emit into the very gap that
  // dropped the link.
  it('announces the webview only AFTER the listener is really subscribed', async () => {
    let subscribed: (() => void) | undefined
    mocks.listen.mockImplementation(
      () =>
        new Promise<UnlistenFn>((resolve) => {
          subscribed = () => resolve(unlisten)
        }),
    )

    const started = startDeepLinkBridge()
    // Several microtask turns: enough for any `.then` chain that skipped the await to run.
    await Promise.resolve()
    await Promise.resolve()
    expect(mocks.emit).not.toHaveBeenCalled()

    subscribed?.()
    await started

    expect(mocks.emit).toHaveBeenCalledWith(DEEP_LINK_READY_EVENT)
  })

  it('announces itself exactly once per start, so one launch cannot route twice', async () => {
    subscribeImmediately()
    await startDeepLinkBridge()
    expect(mocks.emit).toHaveBeenCalledTimes(1)
  })

  it('ignores a payload no route exists for instead of navigating somewhere wrong', async () => {
    const { handler } = subscribeImmediately()
    await startDeepLinkBridge()

    handler()({ payload: { entity: 'setting' as DeepLinkTarget['entity'], id: '42' } })

    expect(mocks.navigate).not.toHaveBeenCalled()
  })

  it('still returns a working listener when the announcement itself fails', async () => {
    subscribeImmediately()
    mocks.emit.mockRejectedValue(new Error('ipc is down'))
    const warn = vi.spyOn(console, 'warn').mockImplementation(mocks.warn)

    // The entry calls this as `void startDeepLinkBridge()`: a rejection here would be an
    // unhandled rejection AND would lose the `unlisten` for links that arrive while running.
    await expect(startDeepLinkBridge()).resolves.toBe(unlisten)
    expect(mocks.warn).toHaveBeenCalled()

    warn.mockRestore()
  })
})
