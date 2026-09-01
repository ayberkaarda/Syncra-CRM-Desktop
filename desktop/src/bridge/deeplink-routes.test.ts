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
})
