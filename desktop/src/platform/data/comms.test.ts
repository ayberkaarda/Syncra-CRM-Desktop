// Tests for the unified global search (defter O43 / `SYNCDESKTOP.md` §7.2).
//
// §7.2 asks for "command palette lokal FTS + online sunucu **birleşik** (kaynak etiketi)".
// Three things about that sentence can each be wrong on their own, silently:
//
//   1. the two halves are fetched but not actually merged (or merged the wrong way round, so a
//      record the user has edited offline shows the server's older copy);
//   2. a record both halves know about appears TWICE, which reads as data corruption;
//   3. the server half fails and takes the local results down with it — the palette then
//      answers "nothing found" for a record that is sitting in the local mirror.
//
// (3) is the one that motivated this file: it is invisible in every online test run, and it
// turns the offline-first feature into an online-only one at exactly the moment the network
// is gone. It is locked here.
//
// `unifiedSearch` takes its two halves as parameters precisely so this file can exercise the
// real composition against fakes — no Tauri host, no HTTP server, no module mocking. The
// halves themselves (`localSearchGroups` / `serverSearchGroups`) are thin: one runs the FTS
// query the mirror already serves, the other is the same `GET /api/search` the web adapter
// calls, and `desktop/scripts/check-data-wiring.mjs` asserts that both are really reached
// (the `hybrid` kind).
//
// Runner: `vitest` (`desktop/vitest.config.ts`, `npm test` in `desktop/`) — defter O53. The
// file previously declared `node:test` and was executed by hand through a scratchpad loader
// shim, so a break in it reached nobody. `describe`/`it` now come from the runner that the
// gate actually invokes; the assertions stay on `node:assert/strict`, which vitest runs
// unchanged, so not one assertion in this file was rewritten to fit the move.
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

import assert from 'node:assert/strict'
import { beforeEach, describe, it, vi } from 'vitest'

// ------------------------------------------------------------------------------------------------
// The Tauri bridge, mocked — for the `sendMessage` half of this file (KARAR A29)
//
// `vi.mock` is hoisted above every import, so the state it closes over has to be hoisted with
// it. The fake is a MINIATURE MIRROR rather than a canned row, because the bug being locked
// here lives in the round trip: `sendMessage` writes a payload, the applier turns it into a
// row, and `mapMessage` reads that row back. A fake that echoed the payload unchanged would
// pass even for a payload the real applier discards.
//
// Two properties of the real applier are reproduced, and they are the two that matter:
//
//   * **only real columns survive** (`syncra_sync::sync::local` copies payload keys that
//     `columns(tx, entity.table())` knows). `mentions` is not a column and must not appear on
//     the row; the four `attachment_*` fields ARE columns since migration `0004` and must.
//   * **the column list is not invented here.** It is read off `wire-fixtures/pull/
//     message.row.json`, the contract this fix has to agree with — so if the server ever stops
//     sending one of the four, this fake stops accepting it too.
// ------------------------------------------------------------------------------------------------

const bridge = vi.hoisted(() => ({
  /** Every `LocalMutation` handed to `data::mutate` since the last reset. */
  mutations: [] as Record<string, unknown>[],
  /** `client_id -> row`, the mirror as the fake applier built it. */
  rows: new Map<string, Record<string, unknown>>(),
  /** Real columns of `messages`; filled from the fixture below, before any test runs. */
  columns: [] as string[],
}))

vi.mock('../../bridge/invoke', () => ({
  CommandError: class CommandError extends Error {},
  toCommandError: (raw: unknown) => raw,
  invokeCommand: async (command: string, args: Record<string, unknown>) => {
    if (command === 'mutate') {
      const mutation = args.mutation as Record<string, unknown>
      bridge.mutations.push(mutation)
      const clientId = String(mutation.client_id ?? '')
      const payload = (mutation.payload ?? {}) as Record<string, unknown>
      const row: Record<string, unknown> = { client_id: clientId, id: null }
      for (const [key, value] of Object.entries(payload)) {
        // The applier's rule, not a convenience: a key that is not a column is dropped.
        if (bridge.columns.includes(key) && value !== undefined) row[key] = value
      }
      bridge.rows.set(clientId, row)
      return clientId
    }
    if (command === 'query') {
      const query = (args.query ?? {}) as Record<string, unknown>
      if (query.query === 'rows_by_client_ids' && query.entity === 'message') {
        const ids = (query.client_ids ?? []) as string[]
        return ids.map((id) => bridge.rows.get(id)).filter(Boolean)
      }
      // Everything else (the `users` ref lookup) answers empty: `chatUser(null)` is `null`, and
      // no assertion below reads the message's author.
      return []
    }
    if (command === 'search') return []
    return null
  },
}))

/** `POST /api/attachments` is the only network call the attachment path makes. */
const network = vi.hoisted(() => ({ post: vi.fn() }))

vi.mock('../http', () => ({
  http: {
    get: vi.fn(),
    post: network.post,
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  setDeviceToken: vi.fn(),
  getDeviceToken: vi.fn(),
}))

import { chatSource, mergeSearchGroups, unifiedSearch } from './comms'
import type { Attachment } from '@/features/chat/types'
import type { SearchResponse, SearchResultItem, SearchResultType } from '@/features/search/types'
import type { SearchResultSource, WithSearchSource } from '@/platform/types'

// ------------------------------------------------------------------------------------------------
// The contract this file's second half is written against
// ------------------------------------------------------------------------------------------------

/** `wire-fixtures/pull/message.row.json`, resolved from this file, never edited by it. */
const MESSAGE_FIXTURE = JSON.parse(
  readFileSync(fileURLToPath(new URL('../../../../wire-fixtures/pull/message.row.json', import.meta.url)), 'utf8'),
) as {
  row: Record<string, unknown>
  envelope_keys: string[]
}

/**
 * The columns a `messages` row really has, taken from the fixture minus the three envelope
 * keys `upsert_row` consumes itself (`id`, `client_id`, `sync_version`).
 */
bridge.columns = Object.keys(MESSAGE_FIXTURE.row).filter(
  (key) => !MESSAGE_FIXTURE.envelope_keys.includes(key),
)

/** One result, as either half would produce it. */
function hit(
  type: SearchResultType,
  id: number,
  title: string,
  source: SearchResultSource,
): WithSearchSource<SearchResultItem> {
  return { type, id, title, subtitle: null, link: `/${type}s/${id}`, search_source: source }
}

/** The label the palette would render for an item, read the way the palette reads it. */
function sourceOf(item: SearchResultItem): SearchResultSource | undefined {
  return (item as WithSearchSource<SearchResultItem>).search_source
}

function titlesOf(items: SearchResultItem[] | undefined): string[] {
  return (items ?? []).map((item) => item.title)
}

function sourcesOf(items: SearchResultItem[] | undefined): (SearchResultSource | undefined)[] {
  return (items ?? []).map(sourceOf)
}

/** A half that answers with a fixed response and records the terms it was asked for. */
function half(response: SearchResponse) {
  const terms: string[] = []
  return {
    terms,
    fn: async (term: string): Promise<SearchResponse> => {
      terms.push(term)
      return response
    },
  }
}

describe('unifiedSearch', () => {
  it('online: merges both halves, each item labelled with the index it came from', async () => {
    const local = half({
      deals: [hit('deal', 1, 'Acme renewal', 'local')],
      contacts: [hit('contact', 5, 'Ada Lovelace', 'local')],
    })
    // `quotes` exists only on the server: the mirror keeps a retention window, so a record
    // that was never pulled is exactly the case this whole feature is for.
    const server = half({
      deals: [hit('deal', 2, 'Globex expansion', 'server')],
      quotes: [hit('quote', 9, 'Q-2026-0009', 'server')],
    })

    const merged = await unifiedSearch('acme', local.fn, server.fn)

    assert.deepEqual(local.terms, ['acme'], 'the local half is asked for the term')
    assert.deepEqual(server.terms, ['acme'], 'the server half is asked for the same term')

    assert.deepEqual(titlesOf(merged.deals), ['Acme renewal', 'Globex expansion'])
    assert.deepEqual(sourcesOf(merged.deals), ['local', 'server'])
    assert.deepEqual(sourcesOf(merged.contacts), ['local'], 'local-only group survives')
    assert.deepEqual(sourcesOf(merged.quotes), ['server'], 'server-only group survives')
  })

  it('deduplicates a record both halves return, keeping the LOCAL copy', async () => {
    // The same deal, seen twice: `server_id` 7 in the mirror is id 7 from `GET /api/search`.
    // The local title carries an edit that has not been pushed yet — which is why local wins.
    const local = half({ deals: [hit('deal', 7, 'Acme renewal (my unpushed edit)', 'local')] })
    const server = half({
      deals: [hit('deal', 7, 'Acme renewal', 'server'), hit('deal', 8, 'Initech pilot', 'server')],
    })

    const merged = await unifiedSearch('acme', local.fn, server.fn)

    assert.equal(merged.deals?.length, 2, 'deal 7 appears once, not twice')
    assert.deepEqual(
      merged.deals?.map((item) => item.id),
      [7, 8],
    )
    assert.equal(merged.deals?.[0]?.title, 'Acme renewal (my unpushed edit)', 'local copy kept')
    assert.equal(sourceOf(merged.deals![0]!), 'local')
    assert.equal(sourceOf(merged.deals![1]!), 'server')
  })

  it('server half fails: local results are still returned, and the failure is logged', async () => {
    const local = half({ deals: [hit('deal', 1, 'Acme renewal', 'local')] })
    const failure = new Error('Request failed with status code 500')
    const warnings: unknown[][] = []
    const originalWarn = console.warn
    console.warn = (...args: unknown[]) => {
      warnings.push(args)
    }

    let merged: SearchResponse
    try {
      merged = await unifiedSearch('acme', local.fn, async () => {
        throw failure
      })
    } finally {
      console.warn = originalWarn
    }

    // The point of the test: search does NOT collapse to nothing because the server is down.
    assert.deepEqual(titlesOf(merged.deals), ['Acme renewal'])
    assert.deepEqual(sourcesOf(merged.deals), ['local'])
    // Degraded, not silent.
    assert.equal(warnings.length, 1, 'the failure is logged exactly once')
    assert.match(String(warnings[0]?.[0]), /server half failed/)
    assert.equal(warnings[0]?.[1], failure, 'the original error is logged, not a summary of it')
  })

  it('offline: local only, every item labelled local, the server is never asked', async () => {
    const local = half({
      deals: [hit('deal', 1, 'Acme renewal', 'local')],
      tickets: [hit('ticket', 3, 'Cannot log in', 'local')],
    })
    // `null` is how the caller says "the engine reports offline" (`searchSource.query`): there
    // is no server function to call, so no doomed request can be attempted and no timeout can
    // be waited out on a machine that already knows it is offline.
    const merged = await unifiedSearch('acme', local.fn, null)

    assert.deepEqual(local.terms, ['acme'], 'the local half still runs')
    assert.deepEqual(Object.keys(merged).sort(), ['deals', 'tickets'], 'local groups, nothing else')
    for (const items of Object.values(merged)) {
      for (const item of items ?? []) assert.equal(sourceOf(item), 'local')
    }
  })
})

describe('mergeSearchGroups', () => {
  it('never collides a locally created record with a server one (negative vs positive id)', () => {
    // A row created offline has no `server_id` and reports `-local_rowid` (`engine.ts::rowId`).
    const merged = mergeSearchGroups(
      { deals: [hit('deal', -4, 'Drafted on the plane', 'local')] },
      { deals: [hit('deal', 4, 'Someone else’s deal', 'server')] },
    )
    assert.equal(merged.deals?.length, 2)
  })

  it('drops groups that end up empty rather than emitting a bare heading', () => {
    assert.deepEqual(mergeSearchGroups({ deals: [] }, { deals: [] }), {})
  })
})

// ------------------------------------------------------------------------------------------------
// `sendMessage` — the attachment a user sends (KARAR A29, `SYNCDESKTOP.md` §4.4)
//
// Two failures were measured on this path, and neither is visible in a mapper test:
//
//   1. **the card vanished.** The local row carried `attachment_id` and none of the four
//      `attachment_*` fields, so `mapMessage` answered `attachment: null`. The optimistic
//      bubble (built from `variables.attachment`, correct) was then REPLACED by that `null` in
//      `useSendMessage`'s `onSuccess` — the file the user had just dragged in disappeared from
//      their own screen and stayed gone until the next pull.
//   2. **the type flipped.** `type` was the constant `'text'` even with an attachment, while
//      `MessageService::create()` writes `file` for exactly that row. The first pull corrected
//      it, so the message changed type under the user with no interaction at all.
//
// Both are asserted through the REAL round trip (composer -> fake applier -> `mapMessage`),
// against the column set the fixture pins.
// ------------------------------------------------------------------------------------------------

/** `AttachmentResource`, as `POST /api/attachments` really answers it. */
function uploaded(id: number, name: string, mime: string, size: number): Attachment {
  return {
    id,
    original_name: name,
    mime_type: mime,
    size,
    // `isInlineEligibleImage()`, the upload endpoint's definition — deliberately NOT the one
    // the pull row's `attachment_is_image` uses. See `sendMessage`'s docblock.
    is_image: mime.startsWith('image/'),
    url: `/api/attachments/${id}`,
  }
}

/** Upload one file through the real `uploadAttachment`, with the network answering. */
async function upload(attachment: Attachment): Promise<Attachment> {
  network.post.mockResolvedValueOnce({ data: attachment })
  return chatSource.uploadAttachment(new File([], attachment.original_name), undefined, undefined)
}

/** The mirror row `sendMessage` produced, read back the way `readBack` reads it. */
function lastRow(): Record<string, unknown> {
  const clientId = String(bridge.mutations[bridge.mutations.length - 1]?.client_id ?? '')
  const row = bridge.rows.get(clientId)
  assert.ok(row, 'the send wrote a row')
  return row
}

describe('chatSource.sendMessage — attachment metadata and type (KARAR A29)', () => {
  beforeEach(() => {
    bridge.mutations.length = 0
    bridge.rows.clear()
    network.post.mockReset()
  })

  it('an attached message keeps its card: the returned DTO carries a filled `attachment`', async () => {
    const attachment = uploaded(1, 'ekran-goruntusu.png', 'image/png', 7079718)
    assert.deepEqual(await upload(attachment), attachment, 'upload still returns what it always did')

    const message = await chatSource.sendMessage(1, { body: 'bak', attachment_id: 1 })

    // THE bug: this was `null`, and `onSuccess` overwrote a correct optimistic bubble with it.
    assert.ok(message.attachment, 'the sent message carries its attachment, not null')
    assert.equal(message.attachment.id, 1)
    assert.equal(message.attachment.original_name, 'ekran-goruntusu.png')
    assert.equal(message.attachment.mime_type, 'image/png')
    assert.equal(message.attachment.size, 7079718)
    assert.equal(message.attachment.url, '/api/attachments/1')
    // `mapAttachment` forces this false on desktop (a bearer-auth `<img src>` would 401); the
    // send path must not be a back door around that decision.
    assert.equal(message.attachment.is_image, false)

    // The three fields really reached the mirror — a DTO built from a row the applier rejected
    // would be a fix that only works in this test.
    const row = lastRow()
    assert.equal(row.attachment_name, 'ekran-goruntusu.png')
    assert.equal(row.attachment_mime, 'image/png')
    assert.equal(row.attachment_size, 7079718)
  })

  it('an attached message is typed `file`, the same value MessageService::create() writes', async () => {
    await upload(uploaded(2, 'Sözleşme_Taslağı.pdf', 'application/pdf', 204800))
    const message = await chatSource.sendMessage(1, { attachment_id: 2 })

    assert.equal(message.type, 'file', 'not `text`: the server derives `file` from the attachment')
    assert.equal(lastRow().type, 'file', 'the mirror row agrees, so the first pull changes nothing')
    // The fixture is the server's own answer for an attached message.
    assert.equal(MESSAGE_FIXTURE.row.type, 'file', 'the fixture pins the same value')
  })

  it('a plain message is unchanged: no attachment, type `text`, no attachment columns written', async () => {
    const message = await chatSource.sendMessage(1, { body: 'merhaba', mentions: [4] })

    assert.equal(message.attachment, null)
    assert.equal(message.type, 'text')

    const row = lastRow()
    assert.ok(!('attachment_name' in row), 'no metadata is invented for a message that has none')
    assert.ok(!('attachment_mime' in row))
    assert.ok(!('attachment_size' in row))
    assert.ok(!('attachment_id' in row))
    // `mentions` is a payload field the SERVER reads, not a mirror column — it must survive on
    // the mutation and be absent from the row.
    assert.ok(!('mentions' in row), '`mentions` is not a column')
    const payload = bridge.mutations[0]?.payload as Record<string, unknown>
    assert.deepEqual(payload.mentions, [4], '`mentions` still reaches the push payload')
  })

  it('`attachment_is_image` is never written locally: the upload and the pull disagree on it', async () => {
    // The upload endpoint says `is_image` through `Attachment::isInlineEligibleImage()` (a
    // config allowlist); the pull row says it through `str_starts_with($mime, "image/")`
    // (`SyncPullService::attachMessageAttachments()`). Same name, different definitions — so
    // the client writes NEITHER and lets the next pull settle it (K7).
    await upload(uploaded(3, 'ekran-goruntusu.png', 'image/png', 7079718))
    await chatSource.sendMessage(1, { attachment_id: 3 })

    const row = lastRow()
    assert.ok(!('attachment_is_image' in row), 'the column is left for the pull to fill')
    assert.ok(
      bridge.columns.includes('attachment_is_image'),
      'and it is genuinely a column — the assertion above is a choice, not a missing mirror',
    )
  })

  it('the metadata map does not leak: an entry is consumed by the send that uses it', async () => {
    await upload(uploaded(4, 'rapor.pdf', 'application/pdf', 1024))

    const first = await chatSource.sendMessage(1, { attachment_id: 4 })
    assert.ok(first.attachment, 'the send that owns the upload gets the metadata')

    // Sending the same attachment id again with no new upload finds nothing — which is what
    // "removed after use" means, observed from outside the module. The row is still written
    // (`attachment_id` survives) and the next pull fills the four fields in; the degradation is
    // the pre-A29 behaviour, never a wrong value.
    const second = await chatSource.sendMessage(1, { attachment_id: 4 })
    assert.equal(second.attachment, null, 'the entry was dropped, not retained')
    assert.equal(lastRow().attachment_id, 4)
    assert.equal(lastRow().type, 'file', 'the type still comes from the attachment id, not the map')
  })

  it('the metadata map is bounded: uploads beyond the cap evict the oldest entry', async () => {
    // 33 uploads against a 32-entry ceiling. The first one must be gone; the last must not.
    const total = 33
    for (let id = 1; id <= total; id += 1) {
      await upload(uploaded(id, `dosya-${id}.pdf`, 'application/pdf', 10 * id))
    }

    const evicted = await chatSource.sendMessage(1, { attachment_id: 1 })
    assert.equal(evicted.attachment, null, 'the oldest entry was evicted, so the map cannot grow')

    const kept = await chatSource.sendMessage(1, { attachment_id: total })
    assert.ok(kept.attachment, 'the newest entry is still there')
    assert.equal(kept.attachment.size, 10 * total)
  })
})
