// `bridge/deeplink-routes.ts` — the entity -> route table (`SYNCDESKTOP.md` §6.4, F5-4).
//
// The security half of deep links is tested in Rust (`deep_link::tests`, including the §9
// item 5 fifty-sample fuzz); nothing hostile can reach this module. What CAN still be wrong
// here is the mapping, and it fails silently in the worst way: a link opens the not-found route
// and the user concludes the record was deleted.
//
// The two entities a naive `/${entity}s/${id}` would get wrong — `company` and `conversation` —
// and the one that has no detail route at all — `task` — are asserted by name.
import { describe, expect, it } from 'vitest'

import { routeForDeepLink, type DeepLinkEntity } from './deeplink-routes'

const ENTITIES: readonly DeepLinkEntity[] = [
  'deal',
  'lead',
  'contact',
  'company',
  'ticket',
  'quote',
  'task',
  'conversation',
]

describe('routeForDeepLink', () => {
  it('routes all eight §6.4 entities', () => {
    for (const entity of ENTITIES) {
      expect(routeForDeepLink({ entity, id: '42' }), entity).not.toBeNull()
    }
  })

  it('maps each entity to the path router.tsx actually declares', () => {
    const routed = Object.fromEntries(
      ENTITIES.map((entity) => [entity, routeForDeepLink({ entity, id: '42' })]),
    )

    expect(routed).toEqual({
      deal: '/deals/42',
      lead: '/leads/42',
      contact: '/contacts/42',
      // Not `/companys/42`.
      company: '/companies/42',
      ticket: '/tickets/42',
      quote: '/quotes/42',
      // `router.tsx` declares `tasks` and no `tasks/:id`; the id is deliberately dropped.
      task: '/tasks',
      // Not `/conversations/42`.
      conversation: '/chat/42',
    })
  })

  it('passes the id through verbatim rather than renumbering it', () => {
    expect(routeForDeepLink({ entity: 'deal', id: '0042' })).toBe('/deals/0042')
    expect(routeForDeepLink({ entity: 'deal', id: '999999999999' })).toBe('/deals/999999999999')
  })

  // Unreachable through the Rust parser, which allowlists the same eight names — so a `null`
  // here means the two tables have drifted, and staying put is better than a silent redirect.
  it('is null for an entity this build has no route for', () => {
    expect(routeForDeepLink({ entity: 'setting' as DeepLinkEntity, id: '1' })).toBeNull()
  })

  // --- the second line of defence -------------------------------------------------------
  //
  // Everything below assumes the FIRST line has failed. That is not hypothetical: the §9
  // item 5 negative control widens `deep_link::ENTITIES` on purpose to prove the Rust fuzz
  // can go red, and while it is widened this table is all that is left. It has to hold on
  // its own.

  // `ROUTES[entity]` used to be a prototype-chain lookup. Measured before the fix:
  // `constructor` -> `[String: '42']` (a boxed String, not a path), `toString` ->
  // `'[object Undefined]'`, and `valueOf` / `hasOwnProperty` / `toLocaleString` / `__proto__`
  // each threw a TypeError inside the deep-link event callback.
  it('is null for a key inherited from Object.prototype rather than declared in the table', () => {
    for (const inherited of [
      'constructor',
      'toString',
      'valueOf',
      'hasOwnProperty',
      'toLocaleString',
      'isPrototypeOf',
      'propertyIsEnumerable',
      '__proto__',
    ]) {
      expect(
        routeForDeepLink({ entity: inherited as DeepLinkEntity, id: '42' }),
        inherited,
      ).toBeNull()
    }
  })

  // The id is interpolated into a navigation path, so §6.4's `[0-9]{1,12}` is restated here
  // rather than taken on trust from the other process.
  it('is null for an id outside §6.4 shape, however the entity checks out', () => {
    for (const id of [
      '../admin',
      '..',
      '42?redirect=https://evil.example',
      '42#/settings',
      '<script>',
      '42 ',
      '-1',
      '4.2',
      '0x2a',
      '',
      '1234567890123', // thirteen digits — one past the regex bound
    ]) {
      expect(routeForDeepLink({ entity: 'deal', id }), JSON.stringify(id)).toBeNull()
    }
  })

  // The guards must not have narrowed the accepted set: the bounds §6.4 does allow still route.
  it('still routes the id bounds §6.4 permits', () => {
    expect(routeForDeepLink({ entity: 'deal', id: '1' })).toBe('/deals/1')
    expect(routeForDeepLink({ entity: 'deal', id: '999999999999' })).toBe('/deals/999999999999')
  })
})
