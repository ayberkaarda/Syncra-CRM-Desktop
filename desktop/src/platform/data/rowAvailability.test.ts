// Locks defter O91: a valid link/read to a record outside the local mirror's retention window
// must surface as a STRUCTURAL signal (`MissingRowError.code === 'ROW_NOT_LOCAL'`), not a generic
// failure — and every shared frontend guard/dictionary entry built on that signal has to actually
// recognise it.
//
// Three layers, each locked independently because each is where the O91 regression could sneak
// back in on its own:
//
//   1. the data layer (`readBack`, `writes.ts`) — the row genuinely missing from the mirror
//      throws `MissingRowError` with the structural code, for BOTH identities a caller can read
//      back with (a numeric DTO id — the deep-link/search-result/notification-click path every
//      `<domain>Source.get()` in `crm.ts`/`work.ts` shares — and a fresh `client_id`);
//   2. the shared frontend guard (`frontend/src/platform/errors.ts`, reached here through the `@`
//      alias `vitest.config.ts` already gives every desktop test) — it must say yes to exactly
//      this shape and no to anything else, so a detail page can branch on STRUCTURE and never on
//      `error.message` (task's explicit ban);
//   3. the error-code dictionary (`desktop/src/ui/errors.ts`) — the code must resolve to its own
//      sentence, not fall through to `desktop:errors.unknown` (finding B7's exact failure mode,
//      `check-error-codes.mjs`'s header).
//
// Runner: vitest (`desktop/vitest.config.ts`, `npm test` in `desktop/`), the pattern every other
// file in this directory (`comms.test.ts`, `mappers.test.ts`) already uses.
import assert from 'node:assert/strict'
import { describe, it, vi } from 'vitest'

// ------------------------------------------------------------------------------------------------
// The Tauri bridge, mocked — the mirror simply does not have the row, for either identity.
// ------------------------------------------------------------------------------------------------

vi.mock('../../bridge/invoke', () => ({
  CommandError: class CommandError extends Error {},
  toCommandError: (raw: unknown) => raw,
  invokeCommand: async (command: string, _args: Record<string, unknown>) => {
    if (command === 'query') {
      // Every `rows_by_server_ids` / `rows_by_client_ids` lookup answers empty — a row genuinely
      // outside the retention window, or not yet pulled, looks exactly like this.
      return []
    }
    return null
  },
}))

import { readBack } from './writes'
import { MissingRowError } from './engine'
import { isRecordNotMirrored } from '@/platform/errors'
import { errorMessage, errorCodeOf } from '../../ui/errors'

describe('O91 — a record outside the local mirror is a structural signal, not a generic error', () => {
  it('readBack(entity, numericId) throws MissingRowError with code ROW_NOT_LOCAL — the deep-link/search-result/notification path every domain .get() shares', async () => {
    await assert.rejects(readBack('deal', 12), (error: unknown) => {
      assert.ok(error instanceof MissingRowError)
      assert.equal((error as InstanceType<typeof MissingRowError>).code, 'ROW_NOT_LOCAL')
      return true
    })
  })

  it('readBack(entity, clientId) throws the same code for a client_id the mirror does not have', async () => {
    await assert.rejects(readBack('contact', 'not-a-real-client-id'), (error: unknown) => {
      assert.ok(error instanceof MissingRowError)
      assert.equal((error as InstanceType<typeof MissingRowError>).code, 'ROW_NOT_LOCAL')
      return true
    })
  })

  it('is not entity-specific — the same MissingRowError shape fires for every domain readBack() serves', async () => {
    for (const entity of ['deal', 'contact', 'company', 'lead'] as const) {
      await assert.rejects(readBack(entity, 999), (error: unknown) => {
        assert.ok(error instanceof MissingRowError)
        assert.equal((error as InstanceType<typeof MissingRowError>).code, 'ROW_NOT_LOCAL')
        return true
      })
    }
  })

  it('isRecordNotMirrored() (frontend/src/platform/errors.ts) recognises the real MissingRowError', async () => {
    let caught: unknown
    try {
      await readBack('deal', 12)
    } catch (error) {
      caught = error
    }
    assert.ok(caught, 'readBack should have thrown')
    assert.equal(isRecordNotMirrored(caught), true)
  })

  it('isRecordNotMirrored() says no to a real failure — the ban on message-text branching cuts both ways', () => {
    assert.equal(isRecordNotMirrored(new Error('network down')), false)
    assert.equal(isRecordNotMirrored({ code: 'DB_ERROR', message: 'disk full' }), false)
    // Message text alone must never trip it, even when it happens to echo the real sentence —
    // the guard has to key off `.code`, not off wording.
    assert.equal(
      isRecordNotMirrored({ message: "this record isn't in your local copy" }),
      false,
    )
    assert.equal(isRecordNotMirrored(null), false)
    assert.equal(isRecordNotMirrored(undefined), false)
  })

  it('errorMessage() resolves ROW_NOT_LOCAL to its own sentence, not desktop:errors.unknown (finding B7\'s failure mode)', () => {
    const seen: string[] = []
    const fakeT = ((key: string) => {
      seen.push(key)
      return key
    }) as Parameters<typeof errorMessage>[0]

    const resolved = errorMessage(fakeT, 'ROW_NOT_LOCAL')
    assert.equal(resolved, 'desktop:errors.ROW_NOT_LOCAL')
    assert.equal(seen[0], 'desktop:errors.ROW_NOT_LOCAL')
  })

  it('errorCodeOf() reads the code back off the thrown MissingRowError (the shape errorMessage() consumes)', async () => {
    let caught: unknown
    try {
      await readBack('deal', 12)
    } catch (error) {
      caught = error
    }
    assert.equal(errorCodeOf(caught), 'ROW_NOT_LOCAL')
  })
})
