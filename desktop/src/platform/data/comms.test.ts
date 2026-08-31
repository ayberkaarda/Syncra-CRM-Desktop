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
import assert from 'node:assert/strict'
import { describe, it } from 'vitest'

import { mergeSearchGroups, unifiedSearch } from './comms'
import type { SearchResponse, SearchResultItem, SearchResultType } from '@/features/search/types'
import type { SearchResultSource, WithSearchSource } from '@/platform/types'

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
