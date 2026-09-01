// Consumer 3 of `wire-fixtures/`: **the TypeScript mutation composers**.
//
// # The gap this layer closes, and nothing else does
//
// The crate proves that `OutboxRow::to_wire()` produces the canonical body
// (`desktop/crates/syncra-sync/tests/wire_fixtures.rs`). The backend proves that the same body
// is applied when POSTed at a live MariaDB (`backend/tests/Feature/Sync/WireFixtureTest.php`).
// Between them they cover Rust <-> PHP completely — and they still cannot see the O47 class of
// failure, where the crate serialises perfectly and TypeScript hands it an empty or misspelled
// payload in the first place. `runAction('deal', id, 'move', {})` would satisfy both of the
// other two consumers and be dead on the wire.
//
// So this file drives the real `DataSource` write verbs — `desktopData.deals.move(...)`,
// `desktopData.notifications.markAllRead()` — with the Tauri bridge mocked, captures the
// `LocalMutation` that reaches `data::mutate`, and compares it to the fixture's
// `local_mutation`. Same JSON files as the other two consumers, no copy anywhere.
//
// # What is compared, and what is not
//
// The comparison is a JSON round-trip on both sides, because JSON is what actually crosses the
// Tauri boundary: a key whose value is `undefined` disappears there, and comparing the live
// objects instead would assert a shape the crate never sees. `work.ts::status` passing
// `reason || undefined` is exactly that case.
//
// `seq`, `idempotency_key`, `occurred_at` and `scope` are NOT compared here and are absent from
// `local_mutation` on purpose: none of them are the composer's to produce. `outbox::enqueue`
// adds them, and the crate consumer holds them against the same fixture's `outbox` block.
//
// # Runner
//
// `vitest` (`desktop/vitest.config.ts`, `npm test` in `desktop/`) — defter O53. Before that
// there was no TS runner in this repo at all, and the two test files that existed were run by
// hand through a scratchpad shim.
import { readFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

import assert from 'node:assert/strict'
import { afterEach, beforeEach, describe, it, vi } from 'vitest'

// ------------------------------------------------------------------------------------------------
// The bridge, mocked
//
// `vi.mock` is hoisted above every import, so the state it closes over has to be hoisted with
// it — hence `vi.hoisted`. The handler answers the three commands the data layer issues:
//
//   * `mutate` — recorded. This is the thing under test.
//   * `query`  — answered with the fixture's own `composer.row`, whatever was asked for. The
//     mirror row is what `clientIdFor` turns a DTO id into and what `readBack` returns
//     afterwards, and the mappers that run on it only read fields; a ref lookup that gets the
//     same row back produces a useless ref map, which is harmless because no assertion in this
//     file looks at the returned DTO. The assertion is on the mutation, and only on that.
//   * `search` — nothing; no write verb reaches it.
// ------------------------------------------------------------------------------------------------

const bridge = vi.hoisted(() => ({
  /** Every `LocalMutation` handed to `data::mutate` since the last reset. */
  mutations: [] as Record<string, unknown>[],
  /** The mirror row every `data::query` answers with. */
  row: {} as Record<string, unknown>,
}))

vi.mock('../../bridge/invoke', () => ({
  // The data layer imports `invokeCommand` and nothing else; the class is here so that a module
  // which starts importing it does not fail to resolve rather than failing an assertion.
  CommandError: class CommandError extends Error {},
  toCommandError: (raw: unknown) => raw,
  invokeCommand: async (command: string, args: Record<string, unknown>) => {
    if (command === 'mutate') {
      const mutation = args.mutation as Record<string, unknown>
      bridge.mutations.push(mutation)
      return String(mutation.client_id ?? '')
    }
    if (command === 'query') {
      const params = (args.params ?? {}) as Record<string, unknown>
      // `countRows` asks the same query with `count_only`, and the callers read `rows[0].total`.
      return params.count_only ? [{ total: 1 }] : [bridge.row]
    }
    if (command === 'search') return []
    return null
  },
}))

// Imported AFTER the mock declaration for readability only — `vi.mock` is hoisted, so the order
// of these lines does not affect what `desktopData` gets.
import { desktopData } from './index'
import { mapMessage, mapTicket } from './mappers'
import { EMPTY_REFS } from './refs'
import type { TicketRefs } from './mappers'
import type { LocalRow } from './engine'

// ------------------------------------------------------------------------------------------------
// Fixtures
// ------------------------------------------------------------------------------------------------

/** `wire-fixtures/push/`, resolved from this file rather than from the working directory. */
const PUSH_DIR = fileURLToPath(new URL('../../../../wire-fixtures/push/', import.meta.url))

type PushFixture = {
  id: string
  why: string
  local_mutation: Record<string, unknown>
  composer: {
    domain: string
    method: string
    args: unknown[]
    row: Record<string, unknown>
    note?: string
  }
}

function loadPushFixtures(): { name: string; fixture: PushFixture }[] {
  return readdirSync(PUSH_DIR)
    .filter((file) => file.endsWith('.json'))
    .sort()
    .map((file) => ({
      name: path.basename(file, '.json'),
      fixture: JSON.parse(readFileSync(path.join(PUSH_DIR, file), 'utf8')) as PushFixture,
    }))
}

/** What actually crosses the Tauri boundary: `undefined` values are gone, order is irrelevant. */
function overTheWire(value: unknown): unknown {
  return JSON.parse(JSON.stringify(value))
}

/**
 * Pin `crypto.randomUUID`.
 *
 * `writes.ts::createRow` mints the local identity itself, so a create's `client_id` is random by
 * design and could never equal a fixture's. Pinning it is better than excluding the field: the
 * fixture then describes the whole mutation, and a create that stopped carrying a `client_id`
 * at all would still be caught.
 */
function pinClientId(value: string): () => void {
  const original = globalThis.crypto.randomUUID
  Object.defineProperty(globalThis.crypto, 'randomUUID', {
    value: () => value,
    configurable: true,
    writable: true,
  })
  return () => {
    Object.defineProperty(globalThis.crypto, 'randomUUID', {
      value: original,
      configurable: true,
      writable: true,
    })
  }
}

// ------------------------------------------------------------------------------------------------
// The suite
// ------------------------------------------------------------------------------------------------

const fixtures = loadPushFixtures()

describe('wire fixtures — the TypeScript composers produce the canonical mutation', () => {
  let restoreUuid: (() => void) | null = null

  beforeEach(() => {
    bridge.mutations.length = 0
    bridge.row = {}
  })

  afterEach(() => {
    if (restoreUuid) restoreUuid()
    restoreUuid = null
  })

  it('there is at least one fixture to check', () => {
    assert.ok(fixtures.length > 0, `no fixtures found under ${PUSH_DIR}`)
  })

  for (const { name, fixture } of fixtures) {
    it(`${name}: ${fixture.composer.domain}.${fixture.composer.method}() composes it`, async () => {
      bridge.row = fixture.composer.row

      const clientId = fixture.local_mutation.client_id
      if (fixture.local_mutation.op === 'create' && typeof clientId === 'string') {
        restoreUuid = pinClientId(clientId)
      }

      const source = (desktopData as unknown as Record<string, Record<string, unknown>>)[
        fixture.composer.domain
      ]
      assert.ok(source, `DataSource has no \`${fixture.composer.domain}\` domain`)

      const verb = source[fixture.composer.method]
      assert.equal(
        typeof verb,
        'function',
        `\`${fixture.composer.domain}.${fixture.composer.method}\` is not a DataSource method`,
      )

      await (verb as (...args: unknown[]) => Promise<unknown>).apply(
        source,
        fixture.composer.args,
      )

      assert.equal(
        bridge.mutations.length,
        1,
        `expected exactly one queued mutation, got ${bridge.mutations.length}. ` +
          `A verb that queues none is offline-dead; one that queues two writes the record twice.`,
      )

      assert.deepEqual(
        overTheWire(bridge.mutations[0]),
        overTheWire(fixture.local_mutation),
        `${name}: the composed mutation is not the fixture's.\n  ${fixture.why}`,
      )
    })
  }

  /**
   * NEGATIVE CONTROL for the whole file.
   *
   * Everything above passes if the comparison itself is broken — a `deepEqual` against a value
   * derived from the same source, a mock that records nothing. This asserts the suite can fail:
   * take one real fixture, corrupt exactly the field B1 was about, and require a mismatch.
   */
  it('NEGATIVE CONTROL: a fixture whose action is entity-qualified no longer matches', async () => {
    const move = fixtures.find((entry) => entry.name === 'deal.action.move')
    assert.ok(move, 'deal.action.move fixture is missing')

    bridge.row = move.fixture.composer.row
    const source = (desktopData as unknown as Record<string, Record<string, unknown>>).deals
    await (source[move.fixture.composer.method] as (...a: unknown[]) => Promise<unknown>).apply(
      source,
      move.fixture.composer.args,
    )

    // The B1 dialect: what the server used to be tested with, and what no client ever sends.
    const corrupted = { ...move.fixture.local_mutation, action: 'deal.move' }

    assert.notDeepEqual(
      overTheWire(bridge.mutations[0]),
      overTheWire(corrupted),
      'the comparison cannot tell `move` from `deal.move`, so every assertion above is worthless',
    )
  })
})

// ------------------------------------------------------------------------------------------------
// Consumer 3, pull side: `wire-fixtures/pull/*.json`'s `mapped` block against `./mappers.ts`.
//
// The crate proves every non-envelope key in a pull fixture's `row` has a mirror column
// (`crates/syncra-sync/tests/wire_fixtures.rs::every_pulled_key_has_a_mirror_column`). The
// backend proves the row really is a subset of what `SyncPullService` sends (e.g.
// `backend/tests/Feature/Sync/SyncPullMessageAttachmentTest.php`). Neither of those two can see
// a mapper-side failure: a column the mirror correctly stores but a mapper drops, renames, or
// misreads on the way to the DTO — exactly what happened before KARAR A29 (defter O90), where
// `mapMessage` hard-coded `attachment: null` no matter what the row carried. This block is the
// third leg: it drives `fixture.row` through the real mapper and checks the fixture's `mapped`
// block against what came out.
//
// Assertions are per-entity, not generic — the DTO shape differs by entity (a message's
// attachment metadata nests under `attachment.*`, a ticket's SLA fields are flat), and there are
// only two entities under `wire-fixtures/pull/` today. A fixture with an `entity` this file does
// not recognise fails loudly (`assert.fail`) rather than being silently skipped, so a third pull
// fixture cannot land here uncovered.
// ------------------------------------------------------------------------------------------------

const PULL_DIR = fileURLToPath(new URL('../../../../wire-fixtures/pull/', import.meta.url))

type PullFixture = {
  id: string
  why: string
  entity: string
  row: Record<string, unknown>
  mapped?: Record<string, unknown>
}

function loadPullFixtures(): { name: string; fixture: PullFixture }[] {
  return readdirSync(PULL_DIR)
    .filter((file) => file.endsWith('.json'))
    .sort()
    .map((file) => ({
      name: path.basename(file, '.json'),
      fixture: JSON.parse(readFileSync(path.join(PULL_DIR, file), 'utf8')) as PullFixture,
    }))
}

describe('wire fixtures — pull rows map to the DTO shape the fixture pins (consumer 3 of wire-fixtures/pull/)', () => {
  const pullFixtures = loadPullFixtures()
  const ticketRefs: TicketRefs = { companies: EMPTY_REFS, contacts: EMPTY_REFS, users: EMPTY_REFS, tags: EMPTY_REFS }

  it('there is at least one pull fixture with a `mapped` block to check', () => {
    assert.ok(
      pullFixtures.some(({ fixture }) => fixture.mapped),
      `no wire-fixtures/pull/*.json carries a \`mapped\` block`,
    )
  })

  for (const { name, fixture } of pullFixtures) {
    if (!fixture.mapped) continue
    const mapped = fixture.mapped
    const row = fixture.row as LocalRow

    it(`${name}: the mapper reads the row into the DTO the fixture's \`mapped\` block pins`, () => {
      if (fixture.entity === 'message') {
        const message = mapMessage(row, EMPTY_REFS)
        assert.equal(message.body, mapped.body)
        assert.equal(message.type, mapped.type)
        assert.ok(message.attachment, `${name}: expected an attachment, mapMessage returned null`)
        assert.equal(message.attachment?.original_name, mapped.attachment_name)
        assert.equal(message.attachment?.mime_type, mapped.attachment_mime)
        assert.equal(message.attachment?.size, mapped.attachment_size)
        // The one field the fixture's wire value does NOT pin 1:1 to the DTO: the wire says
        // `attachment_is_image: true`, but `is_image` is forced `false` on every attachment,
        // image or not (KARAR A29 — see `mapAttachment`'s docblock in `./mappers.ts`).
        assert.equal(message.attachment?.is_image, false)
      } else if (fixture.entity === 'ticket') {
        const ticket = mapTicket(row, ticketRefs)
        assert.equal(ticket.subject, mapped.subject)
        assert.equal(ticket.status, mapped.status)
        assert.equal(ticket.priority, mapped.priority)
        assert.equal(ticket.sla_remaining_seconds, mapped.sla_remaining_seconds)
        assert.equal(ticket.sla_total_seconds, mapped.sla_total_seconds)
        assert.equal(ticket.sla_target_hours, mapped.sla_target_hours)
        assert.equal(ticket.sla_breached, mapped.sla_breached)
      } else {
        assert.fail(`${name}: no mapper wired up here for entity "${fixture.entity}" — add one, do not skip it`)
      }
    })
  }
})
