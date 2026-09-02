// `record-context.ts` and `attach-report.ts` — the two tables between a `files::*` command and
// what the user sees.
//
// The route table is tested because `router.tsx` has three near-misses that a derived matcher
// would get wrong (`deals/list`, `quotes/new`, `quotes/:id/edit`), and because getting it wrong
// is silent: a drop target would simply appear on the wrong screen and attach files to a record
// id that came out of a URL segment reading "list". The report table is tested because a
// `queued` file announced as `uploaded` is a document the user believes is on the record and is
// not.
import { describe, expect, it } from 'vitest'

import { reportForOutcome } from './attach-report'
import type { AttachOutcome } from './files'
import { quotePdfNeedsRefresh } from './files'
import { quoteIdOf, recordContextOf } from './record-context'

describe('recordContextOf', () => {
  it('resolves the two record routes the file commands can address', () => {
    expect(recordContextOf('/deals/42')).toEqual({ kind: 'deal', id: 42 })
    expect(recordContextOf('/tickets/7')).toEqual({ kind: 'ticket', id: 7 })
  })

  // `deals/list` is a REAL route (`router.tsx:141`), not a hypothetical.
  it('does not read a sibling list route as a record id', () => {
    expect(recordContextOf('/deals/list')).toBeNull()
    expect(recordContextOf('/deals')).toBeNull()
    expect(recordContextOf('/tickets')).toBeNull()
  })

  it('is null for every route that is not a deal or a ticket detail', () => {
    for (const path of [
      '/',
      '/quotes/9',
      '/companies/3',
      '/chat/12',
      '/leads/4',
      '/notifications',
    ]) {
      expect(recordContextOf(path), path).toBeNull()
    }
  })

  it('ignores a deeper path under the same prefix', () => {
    expect(recordContextOf('/deals/42/anything')).toBeNull()
  })

  it('tolerates a trailing slash', () => {
    expect(recordContextOf('/tickets/7/')).toEqual({ kind: 'ticket', id: 7 })
  })
})

describe('quoteIdOf', () => {
  it('resolves the quote detail route', () => {
    expect(quoteIdOf('/quotes/15')).toBe(15)
  })

  // `quotes/new` and `quotes/:id/edit` are both real siblings (`router.tsx:249,257`).
  it('does not match the create or edit routes', () => {
    expect(quoteIdOf('/quotes/new')).toBeNull()
    expect(quoteIdOf('/quotes/15/edit')).toBeNull()
  })

  it('is null on a deal or ticket detail', () => {
    expect(quoteIdOf('/deals/15')).toBeNull()
    expect(quoteIdOf('/tickets/15')).toBeNull()
  })
})

describe('quotePdfNeedsRefresh', () => {
  // The rule is `cache_quote_pdf`'s own rustdoc (D3): a draft can change under a fixed
  // `revision`, so `{id}-{rev}` stops naming an immutable document for that one status.
  it('bypasses the cache for a draft and uses it for every other status', () => {
    expect(quotePdfNeedsRefresh('draft')).toBe(true)
    for (const status of ['sent', 'accepted', 'rejected', 'expired']) {
      expect(quotePdfNeedsRefresh(status), status).toBe(false)
    }
  })
})

describe('reportForOutcome', () => {
  it('announces an upload as a success with no error code', () => {
    const outcome: AttachOutcome = {
      status: 'uploaded',
      original_name: 'teklif.pdf',
      attachment: {
        id: 1,
        original_name: 'teklif.pdf',
        mime_type: 'application/pdf',
        size: 2048,
        is_image: false,
        url: '/api/attachments/1',
      },
      message_id: 9,
    }
    expect(reportForOutcome(outcome)).toEqual({
      level: 'success',
      key: 'desktop:files.attach.uploaded',
      name: 'teklif.pdf',
      code: null,
    })
  })

  // The one that must not read as a success: the file is on disk and nothing was sent.
  it('announces a queued file as a warning carrying the reason code', () => {
    const outcome: AttachOutcome = {
      status: 'queued',
      original_name: 'ekran.png',
      queue_id: '0f2a2b1e-0000-4000-8000-000000000000',
      bytes: 1024,
      reason: 'OFFLINE',
    }
    expect(reportForOutcome(outcome)).toEqual({
      level: 'warning',
      key: 'desktop:files.attach.queued',
      name: 'ekran.png',
      code: 'OFFLINE',
    })
  })

  it('announces a refusal as an error carrying the refusal code', () => {
    const outcome: AttachOutcome = {
      status: 'rejected',
      original_name: 'virus.exe',
      code: 'FILE_TYPE_REJECTED',
      message: 'extension `exe` is not in the allowlist',
    }
    expect(reportForOutcome(outcome)).toEqual({
      level: 'error',
      key: 'desktop:files.attach.rejected',
      name: 'virus.exe',
      code: 'FILE_TYPE_REJECTED',
    })
  })

  // The codes the report can carry have to be codes `errorMessage()` recognises, or the user
  // gets "an unknown error occurred" for a refusal the shell knew the reason for (defter O48).
  it('only produces codes the desktop error dictionary defines', () => {
    const codes = ['OFFLINE', 'FILE_TYPE_REJECTED', 'FILE_TOO_LARGE', 'QUEUE_FULL']
    for (const code of codes) {
      const report = reportForOutcome({
        status: 'rejected',
        original_name: 'f',
        code,
        message: '',
      })
      expect(report.code, code).toBe(code)
    }
  })
})
