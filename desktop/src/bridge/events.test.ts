// `bridge/events.ts` — defter O60: `conflict_added` is a deliberate no-op.
//
// `syncra_sync::sync::mod::push_round` emits `EngineEvent::ConflictAdded` for every new
// conflict id, and `sync_now` unconditionally calls `refresh_status()` right after `run_round()`
// returns — so a `status_changed` carrying the fresh `conflicts` count always follows, in the
// same round, whatever `conflict_added` events fired. `ConflictInbox.tsx` already reloads its
// list when that count changes, and the tab badge already renders it live. Wiring
// `conflict_added` to anything would only be a second trigger for a refresh the count change
// already causes — so `applyEngineEvent` has no `onConflictAdded` handler at all, and this test
// locks that down both ways: the event must not throw or reach into the cache, and there must
// be no way to even ask for a handler that would receive it.
import { describe, expect, it, vi } from 'vitest'

import { applyEngineEvent, type EngineEvent, type EngineEventHandlers } from './events'

/** A `QueryClient` stand-in that only records `invalidateQueries` calls. */
function fakeQueryClient() {
  const invalidateQueries = vi.fn(() => Promise.resolve())
  const queryClient = { invalidateQueries } as unknown as Parameters<typeof applyEngineEvent>[0]
  return { queryClient, invalidateQueries }
}

describe('applyEngineEvent — conflict_added', () => {
  it('does not touch the query cache', () => {
    const { queryClient, invalidateQueries } = fakeQueryClient()
    const event: EngineEvent = { type: 'conflict_added', id: 'c1' }

    applyEngineEvent(queryClient, event)

    expect(invalidateQueries).not.toHaveBeenCalled()
  })

  it('calls none of the OTHER handlers either — it is a pure no-op, not a mis-routed event', () => {
    const { queryClient } = fakeQueryClient()
    const handlers: EngineEventHandlers = {
      onTablesChanged: vi.fn(),
      onStatusChanged: vi.fn(),
      onStorageWarning: vi.fn(),
      onAuthLost: vi.fn(),
      onProtocolMismatch: vi.fn(),
    }

    applyEngineEvent(queryClient, { type: 'conflict_added', id: 'c1' }, handlers)

    for (const handler of Object.values(handlers)) {
      expect(handler).not.toHaveBeenCalled()
    }
  })

  it('does not throw for any id shape the engine could send', () => {
    const { queryClient } = fakeQueryClient()
    expect(() =>
      applyEngineEvent(queryClient, { type: 'conflict_added', id: '' })
    ).not.toThrow()
  })

  // NEGATIVE CONTROL — proves the two tests above would actually catch a regression, rather
  // than passing regardless of what `conflict_added` does. If `onConflictAdded` were wired back
  // up (the shape defter O60 rejected), this same event WOULD reach a handler.
  it('NEGATIVE CONTROL: a real consumer-bearing event (status_changed) DOES reach its handler', () => {
    const { queryClient } = fakeQueryClient()
    const onStatusChanged = vi.fn()
    const status = { online: true, conflicts: 3 }

    applyEngineEvent(queryClient, { type: 'status_changed', status }, { onStatusChanged })

    expect(onStatusChanged).toHaveBeenCalledWith(status)
  })
})

/**
 * Compile-time guard: the type parameter is constrained to `never`, so this only type-checks
 * while `Extract<'onConflictAdded', keyof EngineEventHandlers>` resolves to `never` — i.e.
 * while the interface has no `onConflictAdded` member. `tsc -b` (part of `npm run
 * build:desktop`) refuses to compile this file the moment `onConflictAdded` is reintroduced,
 * which is exactly the friction that puts the O60 decision back in front of whoever reopens it.
 */
function assertNoHandlerFor<T extends never>(): void {
  void (0 as unknown as T)
}

describe('EngineEventHandlers — O60 shape lock', () => {
  it('has no onConflictAdded member', () => {
    assertNoHandlerFor<Extract<'onConflictAdded', keyof EngineEventHandlers>>()

    const handlers: EngineEventHandlers = {}
    expect(Object.keys(handlers)).toEqual([])
  })
})
