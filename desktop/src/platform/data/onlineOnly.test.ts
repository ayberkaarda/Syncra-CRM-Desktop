// Locks defter O102: `SYNCDESKTOP.md` §8's "cevrimdisinda devre disi + tooltip" is actually WIRED.
//
// Before this item the mechanism existed in three disconnected pieces — the contract
// (`Platform.onlineOnly`, `OnlineOnlyError`), the text (17 `desktop:onlineOnly.*` leaves in four
// languages) and nothing joining them: `grep -c 'onlineOnly(' platform/desktop.ts` was 0, no
// component consumed `OnlineOnlyError`, and `npm run i18n:dead-keys` reported all 17 keys as
// referenced by nobody. Offline, a §8 action went to axios and came back as a transport failure,
// so the user was shown a generic error that named neither the action nor the reason.
//
// Three things can each regress silently, and each is pinned here:
//
//   1. **a §8 verb stops being guarded.** The table below is asserted to cover `SPEC_8_METHODS`
//      EXACTLY, so adding a method to that list without guarding it fails this file rather than
//      shipping an unguarded action.
//   2. **the guard runs but the request goes out anyway.** `../http` is mocked to record every
//      call; the offline assertions require ZERO recorded calls, which is what separates "we
//      rejected after the network failed" from "we never touched the network".
//   3. **the web build starts refusing actions.** `platform/web.ts`'s `onlineOnly` is the
//      identity, and that is the ONLY reason the shared guard is inert in the web bundle. It is
//      asserted against the real source text, because if someone gives the web adapter a real
//      implementation, every §8 trigger in the web app silently starts disabling itself.
//
// Runner: vitest (`desktop/vitest.config.ts`, `npm test` in `desktop/`), node environment —
// the pattern `formLookups.test.ts` / `rowAvailability.test.ts` already use.
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

import assert from 'node:assert/strict'
import { beforeEach, describe, it, vi } from 'vitest'

import type { Platform } from '@/platform/types'

const state = vi.hoisted(() => ({
  /** What the mocked engine status reports. */
  online: false,
  /** Every URL `../http` was asked for since the last reset. */
  httpCalls: [] as string[],
}))

// The engine's verdict, mocked at its single source (`platform/status.ts` -> `isEngineOnline`).
// Mocking the STATUS rather than `onlineOnly` itself keeps the real `requireOnline` — the code
// under test — in the path.
vi.mock('../status', () => ({
  isEngineOnline: () => state.online,
}))

// Every method records and then rejects with a sentinel. Recording is what proves the offline
// path never got here; rejecting means an online call cannot accidentally look like a pass
// because a fake returned a plausible body.
vi.mock('../http', () => {
  const record = (url: string) => {
    state.httpCalls.push(url)
    return Promise.reject(new Error('HTTP_REACHED'))
  }
  return {
    http: {
      get: (url: string) => record(url),
      post: (url: string) => record(url),
      put: (url: string) => record(url),
      patch: (url: string) => record(url),
      delete: (url: string) => record(url),
    },
  }
})

// The domain modules reach the mirror through `./engine` -> `../../bridge/invoke`, which loads
// `@tauri-apps/api`. Nothing in this file exercises a local read, so the bridge is stubbed out
// rather than simulated.
vi.mock('../../bridge/invoke', () => ({
  CommandError: class CommandError extends Error {},
  toCommandError: (raw: unknown) => raw,
  invokeCommand: async () => null,
}))

const { isActionOffline, isOnlineOnlyError, onlineOnlyActionOf, ONLINE_ONLY_ACTIONS } =
  await import('@/platform/onlineOnly')
const { SPEC_8_METHODS } = await import('./manifest')
const { leadsSource } = await import('./crm')
const { quotesSource } = await import('./work')
const { chatSource } = await import('./comms')
const { savedViewsSource, usersSource } = await import('./catalog')

/**
 * One §8 `DataSource` method, the action it must report, and how to call it.
 *
 * `method` is the `SPEC_8_METHODS` name, so the coverage assertion below is an exact set
 * comparison rather than a count. Arguments are minimal: none of them reaches a request body,
 * because the whole point of the offline case is that no request is built.
 */
interface GuardedVerb {
  method: string
  action: string
  call: () => Promise<unknown>
}

const GUARDED: GuardedVerb[] = [
  {
    method: 'leads.convert',
    action: 'leads.convert',
    call: () => leadsSource.convert(1, {} as never),
  },
  { method: 'quotes.send', action: 'quotes.send', call: () => quotesSource.send(1) },
  { method: 'quotes.revise', action: 'quotes.revise', call: () => quotesSource.revise(1) },
  {
    method: 'quotes.calculate',
    action: 'quotes.calculate',
    call: () => quotesSource.calculate({} as never),
  },
  { method: 'quotes.pdfBlob', action: 'quotes.pdf', call: () => quotesSource.pdfBlob(1) },
  {
    method: 'chat.uploadAttachment',
    action: 'attachments.upload',
    // A real `File` is not constructed: `uploadAttachment` only appends it to a `FormData`, and
    // offline it must not get that far either.
    call: () => chatSource.uploadAttachment({ name: 'a.pdf' } as unknown as File),
  },
  {
    method: 'savedViews.create',
    action: 'savedViews.create',
    call: () => savedViewsSource.create({} as never),
  },
  {
    method: 'savedViews.update',
    action: 'savedViews.update',
    call: () => savedViewsSource.update(1, {} as never),
  },
  { method: 'users.list', action: 'users', call: () => usersSource.list({} as never) },
  { method: 'users.get', action: 'users', call: () => usersSource.get(1) },
  { method: 'users.create', action: 'users', call: () => usersSource.create({} as never) },
  { method: 'users.update', action: 'users', call: () => usersSource.update(1, {} as never) },
  { method: 'users.delete', action: 'users', call: () => usersSource.delete(1) },
  { method: 'users.setActive', action: 'users', call: () => usersSource.setActive(1, false) },
  {
    method: 'users.resetPassword',
    action: 'users',
    call: () => usersSource.resetPassword(1, 'secret'),
  },
  { method: 'users.roles', action: 'roles', call: () => usersSource.roles() },
]

beforeEach(() => {
  state.online = false
  state.httpCalls = []
})

describe('SYNCDESKTOP §8 — every online-only verb is guarded (O102)', () => {
  it('the guarded table covers SPEC_8_METHODS exactly', () => {
    assert.deepEqual(
      GUARDED.map((entry) => entry.method).sort(),
      [...SPEC_8_METHODS].sort(),
      'a §8 method is missing from the guard table (or the table names one that is not §8)',
    )
  })

  it('every action names a key the desktop dictionary actually has', () => {
    for (const { method, action } of GUARDED) {
      assert.ok(
        (ONLINE_ONLY_ACTIONS as readonly string[]).includes(action),
        `${method}: reports action '${action}', which is not a desktop:onlineOnly.* leaf`,
      )
    }
  })

  for (const { method, action, call } of GUARDED) {
    it(`${method} rejects with ONLINE_ONLY (action '${action}') and issues no request`, async () => {
      state.online = false
      const caught = await call().then(
        () => undefined,
        (error: unknown) => error,
      )

      assert.ok(isOnlineOnlyError(caught), `${method}: did not reject with an OnlineOnlyError`)
      assert.equal(onlineOnlyActionOf(caught), action)
      // The whole point: `fn` was never called, so axios never saw a request. A guard that
      // rejected only AFTER the transport failed would leave this array non-empty.
      assert.deepEqual(state.httpCalls, [], `${method}: reached the network while offline`)
    })

    it(`${method} runs normally when the engine reports online`, async () => {
      state.online = true
      const caught = await call().then(
        () => undefined,
        (error: unknown) => error,
      )

      assert.equal(
        isOnlineOnlyError(caught),
        false,
        `${method}: refused while online — the guard is inverted`,
      )
      assert.equal(state.httpCalls.length, 1, `${method}: did not reach platform.http while online`)
    })
  }
})

describe('the shared probe (frontend/src/platform/onlineOnly.ts)', () => {
  /** Just enough `Platform` for `isActionOffline`, which reads exactly one member. */
  function platformWith(onlineOnly: Platform['onlineOnly']): Platform {
    return { onlineOnly } as unknown as Platform
  }

  it('reports offline when the adapter refuses', () => {
    const desktopLike = platformWith((action) => ({
      code: 'ONLINE_ONLY' as const,
      action,
      message: 'no',
    }))
    assert.equal(isActionOffline(desktopLike, 'quotes.send'), true)
  })

  it('reports online when the adapter runs the action', () => {
    const webLike = platformWith((_action, fn) => fn())
    assert.equal(isActionOffline(webLike, 'quotes.send'), false)
  })

  it('never fires on any other failure shape', () => {
    for (const shape of [null, undefined, new Error('network down'), { code: 'HTTP_ERROR' }]) {
      assert.equal(isOnlineOnlyError(shape), false)
      assert.equal(onlineOnlyActionOf(shape), undefined)
    }
  })

  // The single fact that keeps the web bundle's behaviour unchanged. Asserted against the real
  // source because the web adapter cannot be imported in a node run (axios, echo, the whole
  // feature tree come with it) — the same "transcribed, not derived" trade
  // `desktop/src/ui/errors.ts` makes for the error-code list.
  it('platform/web.ts still implements onlineOnly as the identity', () => {
    const webSource = readFileSync(
      fileURLToPath(new URL('../../../../frontend/src/platform/web.ts', import.meta.url)),
      'utf8',
    )
    assert.match(
      webSource,
      /function onlineOnly<T>\(_action: string, fn: \(\) => T\): T \{\s*return fn\(\)\s*\}/,
      'web.ts no longer returns fn() unconditionally — every §8 trigger in the WEB app would start disabling itself whenever navigator.onLine is false, which §8 never asked for',
    )
  })
})
