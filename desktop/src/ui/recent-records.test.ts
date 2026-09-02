// The JumpList's webview half — the route table and the title fallback (§6.4, defter O85).
//
// The route table is tested for the same reason `record-context.ts`'s is: `router.tsx` has real
// siblings that a derived matcher reads as record ids (`deals/list`, `quotes/new`,
// `quotes/:id/edit`), and getting it wrong here is silent — the wrong record, or a record named
// "list", quietly appears on the user's taskbar and stays there.
//
// The round-trip test below is the one that could not be written anywhere else: this table and
// `deeplink-routes.ts`'s are inverses of each other, they are maintained by hand on purpose, and
// nothing but a test compares them.
import { describe, expect, it } from 'vitest'

import { routeForDeepLink, type DeepLinkEntity } from '../bridge/deeplink-routes'

import { entityTableOf, recentTargetOf, recentTitleOf } from './recent-records'
import type { Translate } from './useT'

/**
 * The eight §6.4 names, RESTATED rather than imported from `DeepLinkEntity`.
 *
 * Same discipline as `deep_link::tests::CONTRACT_ENTITIES`: a test that reads its expectation
 * out of the thing it is testing agrees with every future version of it, including the wrong
 * ones. This copy is what a ninth entity would have to disagree with.
 */
const CONTRACT_ENTITIES: DeepLinkEntity[] = [
  'deal',
  'lead',
  'contact',
  'company',
  'ticket',
  'quote',
  'task',
  'conversation',
]

/** A `Translate` that renders the key and its interpolations, so a test can see both. */
const t: Translate = (key, options) =>
  options === undefined ? key : `${key}(${JSON.stringify(options)})`

describe('recentTargetOf', () => {
  it('resolves every record detail route §6.4 can address', () => {
    expect(recentTargetOf('/deals/42')).toEqual({ entity: 'deal', id: '42' })
    expect(recentTargetOf('/leads/1')).toEqual({ entity: 'lead', id: '1' })
    expect(recentTargetOf('/contacts/7')).toEqual({ entity: 'contact', id: '7' })
    expect(recentTargetOf('/tickets/9')).toEqual({ entity: 'ticket', id: '9' })
    expect(recentTargetOf('/quotes/15')).toEqual({ entity: 'quote', id: '15' })
  })

  // The two spellings no de-pluralisation rule produces.
  it('maps the two routes that do not follow the entity name', () => {
    expect(recentTargetOf('/companies/3')).toEqual({ entity: 'company', id: '3' })
    expect(recentTargetOf('/chat/12')).toEqual({ entity: 'conversation', id: '12' })
  })

  // `router.tsx` declares no `tasks/:id` — see the module header and `deeplink-routes.ts`.
  it('records nothing for tasks, which have no detail route', () => {
    expect(recentTargetOf('/tasks/42')).toBeNull()
    expect(recentTargetOf('/tasks')).toBeNull()
  })

  // All three are REAL sibling routes, not hypotheticals.
  it('does not read a sibling list, create or edit route as a record', () => {
    expect(recentTargetOf('/deals/list')).toBeNull()
    expect(recentTargetOf('/quotes/new')).toBeNull()
    expect(recentTargetOf('/quotes/15/edit')).toBeNull()
    expect(recentTargetOf('/deals')).toBeNull()
    expect(recentTargetOf('/')).toBeNull()
  })

  it('ignores a deeper path under the same prefix', () => {
    expect(recentTargetOf('/deals/42/anything')).toBeNull()
  })

  it('tolerates a trailing slash', () => {
    expect(recentTargetOf('/deals/42/')).toEqual({ entity: 'deal', id: '42' })
  })

  it('holds §6.4 id shape: one to twelve digits, nothing else', () => {
    expect(recentTargetOf('/deals/123456789012')).toEqual({
      entity: 'deal',
      id: '123456789012',
    })
    for (const path of ['/deals/1234567890123', '/deals/-1', '/deals/1.5', '/deals/0x2a']) {
      expect(recentTargetOf(path), path).toBeNull()
    }
  })

  // An id is a path segment on both sides of the IPC; a leading zero is part of it.
  it('does not renumber an id', () => {
    expect(recentTargetOf('/deals/0042')?.id).toBe('0042')
  })

  // The measured `deeplink-routes.ts` failure, on this table: a plain index lookup walks the
  // prototype chain, and `window.location` is controlled by anything that can navigate.
  it('is closed against prototype keys', () => {
    for (const key of [
      'constructor',
      'toString',
      'valueOf',
      'hasOwnProperty',
      'toLocaleString',
      '__proto__',
    ]) {
      expect(recentTargetOf(`/${key}/1`), key).toBeNull()
    }
  })

  it('only ever produces one of the eight §6.4 entities', () => {
    for (const path of [
      '/deals/1',
      '/leads/1',
      '/contacts/1',
      '/companies/1',
      '/tickets/1',
      '/quotes/1',
      '/chat/1',
    ]) {
      const target = recentTargetOf(path)
      expect(target, path).not.toBeNull()
      expect(CONTRACT_ENTITIES, path).toContain(target?.entity)
    }
  })
})

describe('the route table and deeplink-routes.ts are inverses', () => {
  // Both tables are hand-written on purpose (`router.tsx` is not derivable) and they describe
  // the same mapping from opposite ends. Nothing but this test compares them, so a route
  // renamed on one side and not the other is otherwise silent: the deep link would open
  // `/companies/3` while the jump list recorded nothing for that page.
  it('round-trips every entity that has a detail route', () => {
    for (const entity of CONTRACT_ENTITIES) {
      if (entity === 'task') continue // no `tasks/:id` — asserted separately below
      const path = routeForDeepLink({ entity, id: '7' })
      expect(path, entity).not.toBeNull()
      expect(recentTargetOf(path as string), entity).toEqual({ entity, id: '7' })
    }
  })

  // The one deliberate asymmetry, pinned so it cannot drift into a silent one: a
  // `syncra://task/42` link opens the task LIST, and a user standing on that list is not
  // standing on a record.
  it('records nothing for the entity whose deep link opens a list', () => {
    expect(routeForDeepLink({ entity: 'task', id: '42' })).toBe('/tasks')
    expect(recentTargetOf('/tasks')).toBeNull()
  })
})

describe('entityTableOf', () => {
  it('passes every §6.4 entity through as the engine table name', () => {
    for (const entity of CONTRACT_ENTITIES) {
      expect(entityTableOf(entity)).toBe(entity)
    }
  })
})

describe('recentTitleOf', () => {
  const target = { entity: 'deal', id: '29' } as const

  it('prefers the row title', () => {
    expect(recentTitleOf(t, target, { title: 'Acme — yıllık sözleşme' })).toBe(
      'Acme — yıllık sözleşme'
    )
  })

  it('reads the other name columns the mirror shapes use', () => {
    expect(recentTitleOf(t, { entity: 'ticket', id: '1' }, { subject: 'Yazıcı arızası' })).toBe(
      'Yazıcı arızası'
    )
    expect(recentTitleOf(t, { entity: 'company', id: '1' }, { name: 'Acme A.Ş.' })).toBe(
      'Acme A.Ş.'
    )
    expect(
      recentTitleOf(t, { entity: 'contact', id: '1' }, { first_name: 'Ayşe', last_name: 'Yılmaz' })
    ).toBe('Ayşe Yılmaz')
  })

  // A record opened from a deep link before the first sync, or one outside the retention
  // window, has no local row at all — and it still has to say something on the menu.
  it('falls back to the entity label and the id when the mirror has no row', () => {
    expect(recentTitleOf(t, target, null)).toBe(
      'desktop:jumpList.fallbackTitle({"entity":"desktop:entities.deal","id":"29"})'
    )
  })

  // Same treatment as no row: an entry labelled '' is a nameless line the user cannot tell
  // apart from the next one.
  it('falls back when every known name column is blank', () => {
    expect(recentTitleOf(t, target, { title: '   ', name: '', client_id: 'abc' })).toBe(
      'desktop:jumpList.fallbackTitle({"entity":"desktop:entities.deal","id":"29"})'
    )
  })

  // The fallback names an `entities.*` key for the entity, and those keys are what §6.4's eight
  // resolve through — a name the dictionary does not define would render as a raw key on the
  // taskbar.
  it('builds a fallback for every one of the eight entities', () => {
    for (const entity of CONTRACT_ENTITIES) {
      expect(recentTitleOf(t, { entity, id: '1' }, null)).toContain(
        `desktop:entities.${entity}`
      )
    }
  })

  it('trims the name it takes from the row', () => {
    expect(recentTitleOf(t, target, { title: '  Acme  ' })).toBe('Acme')
  })
})
