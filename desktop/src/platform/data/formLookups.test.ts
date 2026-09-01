// Locks defter O42: the deal form's and the ticket form's lookup pickers answer from the local
// mirror.
//
// Before this item those ten reads were raw `api.get` calls inside
// `frontend/src/features/deals/components/dealsShared.ts` and
// `frontend/src/features/tickets/components/ticketsShared.ts`, so on the desktop the tag picker,
// the custom-field section, the contact picker, the company combobox and the assignee select
// were all empty the moment the network was — while the Kanban board right next to them (O36)
// was not. Moving them onto the `DataSource` contract is only half the fix; the half that can
// regress silently is WHICH mirror read each one lands on, and that is what this file pins:
//
//   1. **the entity type of the custom-field read.** `custom_field_list` is one query with an
//      `entity_type` parameter, and `contacts.customFields` already reads it pinned to
//      `contacts`. A deal form bound to that verb would render the CONTACT module's fields and
//      look perfectly wired doing it — `tsc` and `check:data` both stay green, because the
//      binding really is a local query. Only the parameter tells the two apart.
//   2. **`tickets.userOptions` not being `users.list`.** `SYNCDESKTOP.md` §8 makes `users.*`
//      online-only (KARAR A15, `manifest.ts` `kind: 'http'`), so an assignee picker fed from
//      there is empty offline — the exact trap `deals.ownerOptions` was added to avoid in O40.
//      Here it is asserted that this verb reaches `user_list` and never `platform.http`.
//   3. **the narrowing and paging of the two option lists.** `per_page=20` vs `per_page=100`,
//      `sort=last_name` vs `sort=name`, and the `filter[company_id]` that must DISAPPEAR when
//      no company is selected rather than become `company_id = 0`.
//
// The Tauri bridge is mocked as a miniature mirror that records every `NamedQuery` it is handed,
// so each assertion is about the request the adapter actually issued, not about a shape the test
// itself supplied. `../http` is mocked to THROW: a lookup that quietly fell back to the network
// would pass a result-shape test and fail this one.
//
// Runner: vitest (`desktop/vitest.config.ts`, `npm test` in `desktop/`), the pattern
// `comms.test.ts` / `mappers.test.ts` / `rowAvailability.test.ts` already use.
import assert from 'node:assert/strict'
import { beforeEach, describe, it, vi } from 'vitest'

const bridge = vi.hoisted(() => ({
  /** Every `{ query, params }` pair handed to `data::query` since the last reset. */
  calls: [] as { query: Record<string, unknown>; params: Record<string, unknown> }[],
}))

vi.mock('../../bridge/invoke', () => ({
  CommandError: class CommandError extends Error {},
  toCommandError: (raw: unknown) => raw,
  invokeCommand: async (command: string, args: Record<string, unknown>) => {
    if (command !== 'query') return null
    const query = args.query as Record<string, unknown>
    const params = (args.params ?? {}) as Record<string, unknown>
    bridge.calls.push({ query, params })

    switch (query.query) {
      case 'tag_list':
        return [
          { server_id: 3, name: 'VIP', color: 'red' },
          // A tag the user never gave a colour. The pickers type `color` as `string | null`
          // and render a neutral chip for `null`; flattening it to `''` here would be a
          // colour the API never sent.
          { server_id: 4, name: 'Yenileme', color: null },
        ]
      case 'custom_field_list':
        // Echoes the entity type back so the assertion can be about the REQUEST, not about a
        // row the fake decided to return.
        return [
          {
            server_id: 9,
            entity_type: query.entity_type,
            name: 'Bütçe onayı',
            key: 'budget_ok',
            type: 'boolean',
            options: null,
            is_required: 1,
            position: 2,
          },
        ]
      case 'contact_list':
        return [
          { server_id: 11, first_name: 'Ayşe', last_name: 'Yıldız' },
          { server_id: 12, first_name: 'Mert', last_name: '' },
        ]
      case 'company_list':
        return [{ server_id: 21, name: 'Acme A.Ş.' }]
      case 'user_list':
        return [{ server_id: 31, name: 'Deniz Kaya' }]
      default:
        return []
    }
  },
}))

// A lookup that reached the network instead of the mirror is the whole bug. Any use of the
// shared client fails loudly rather than returning plausible data.
vi.mock('../http', () => ({
  http: new Proxy(
    {},
    {
      get(_target, verb: string) {
        return () => {
          throw new Error(`platform.http.${verb} was called — a form lookup must read the mirror`)
        }
      },
    },
  ),
  getDeviceToken: () => undefined,
  setDeviceToken: () => {},
}))

import { dealsSource } from './crm'
import { ticketsSource } from './work'

/** The single `{ query, params }` pair issued for a `NamedQuery` tag; fails if there is not exactly one. */
function only(tag: string) {
  const matches = bridge.calls.filter((call) => call.query.query === tag)
  assert.equal(matches.length, 1, `expected exactly one '${tag}' read, saw ${matches.length}`)
  return matches[0]
}

beforeEach(() => {
  bridge.calls.length = 0
})

describe('O42 — the deal form and the ticket form read their pickers from the local mirror', () => {
  // ----------------------------------------------------------------------------------------
  // 1. Custom fields — the entity type is the whole verb
  // ----------------------------------------------------------------------------------------

  it('deals.customFields() asks custom_field_list for entity_type=deals, not the contacts fields', async () => {
    const fields = await dealsSource.customFields()
    assert.equal(only('custom_field_list').query.entity_type, 'deals')
    assert.equal(fields[0].entity_type, 'deals')
  })

  it('tickets.customFields() asks the same query for entity_type=tickets', async () => {
    const fields = await ticketsSource.customFields()
    assert.equal(only('custom_field_list').query.entity_type, 'tickets')
    assert.equal(fields[0].entity_type, 'tickets')
  })

  it('the definition survives the mirror round trip in the shape the form sections declare', async () => {
    const [field] = await dealsSource.customFields()
    // `is_required` arrives as SQLite's `1` and `options` as SQL NULL; the form renders a
    // required boolean field, not a required-looking `1`.
    assert.deepEqual(field, {
      id: 9,
      entity_type: 'deals',
      name: 'Bütçe onayı',
      key: 'budget_ok',
      type: 'boolean',
      options: null,
      is_required: true,
      position: 2,
    })
  })

  // ----------------------------------------------------------------------------------------
  // 2. The assignee picker — user_list, never the §8 users.* surface
  // ----------------------------------------------------------------------------------------

  it('tickets.userOptions() reads the user_list projection, which is what makes it answer offline', async () => {
    const users = await ticketsSource.userOptions()
    const call = only('user_list')
    // The same read `contacts.userOptions` / `deals.ownerOptions` / `tasks.userOptions` use:
    // active users only, and the whole (non-windowed) projection rather than a page of it.
    assert.equal(call.query.is_active, true)
    assert.deepEqual(users, [{ id: 31, name: 'Deniz Kaya' }])
  })

  // ----------------------------------------------------------------------------------------
  // 3. The contact picker — narrowing, paging, sorting
  // ----------------------------------------------------------------------------------------

  it('deals.contactOptions() carries the selected company, the typed search, per_page=20 and sort=last_name', async () => {
    await dealsSource.contactOptions(7, 'ay')
    const call = only('contact_list')
    assert.equal(call.query.company_id, 7)
    assert.equal(call.query.q, 'ay')
    assert.deepEqual(call.params, { limit: 20, sort_by: 'last_name', sort_dir: 'asc' })
  })

  it('contactOptions() DROPS both narrowings when no company is selected and nothing is typed', async () => {
    // The web request omits `filter[company_id]` and `q` entirely in this case. Sending
    // `company_id: 0` instead would match no contact at all and the picker would look empty
    // for a reason nobody could see.
    await dealsSource.contactOptions(undefined, '')
    const call = only('contact_list')
    assert.equal(call.query.company_id, undefined)
    assert.equal(call.query.q, undefined)
  })

  it('contactOptions() rebuilds full_name with the server accessor, single-spaced and trimmed', async () => {
    const contacts = await dealsSource.contactOptions(7, '')
    assert.deepEqual(contacts, [
      { id: 11, full_name: 'Ayşe Yıldız' },
      // A contact with no surname is "Mert", not "Mert " — the same join the API's accessor does.
      { id: 12, full_name: 'Mert' },
    ])
  })

  it('tickets.contactOptions() issues the identical request — one definition, two forms', async () => {
    await dealsSource.contactOptions(7, 'ay')
    const dealCall = only('contact_list')
    bridge.calls.length = 0
    await ticketsSource.contactOptions(7, 'ay')
    const ticketCall = only('contact_list')
    assert.deepEqual(ticketCall, dealCall)
  })

  // ----------------------------------------------------------------------------------------
  // 4. The company pickers — the searchable combobox vs the list page's fixed filter
  // ----------------------------------------------------------------------------------------

  it('deals.companyOptions() is the searchable combobox: q, per_page=20, sort=name', async () => {
    const companies = await dealsSource.companyOptions('ac')
    const call = only('company_list')
    assert.equal(call.query.q, 'ac')
    assert.deepEqual(call.params, { limit: 20, sort_by: 'name', sort_dir: 'asc' })
    assert.deepEqual(companies, [{ id: 21, name: 'Acme A.Ş.' }])
  })

  it('tickets.companyOptions() issues the identical request', async () => {
    await dealsSource.companyOptions('ac')
    const dealCall = only('company_list')
    bridge.calls.length = 0
    await ticketsSource.companyOptions('ac')
    assert.deepEqual(only('company_list'), dealCall)
  })

  it('tickets.allCompanyOptions() is the WIDER, unsearched list the tickets filter needs (100)', async () => {
    await ticketsSource.allCompanyOptions()
    const call = only('company_list')
    assert.equal(call.query.q, undefined)
    assert.deepEqual(call.params, { limit: 100, sort_by: 'name', sort_dir: 'asc' })
  })

  // ----------------------------------------------------------------------------------------
  // 5. Tags
  // ----------------------------------------------------------------------------------------

  it('tags() reads tag_list and keeps a colourless tag as null, not as an empty string', async () => {
    const tags = await dealsSource.tags()
    only('tag_list')
    assert.deepEqual(tags, [
      { id: 3, name: 'VIP', color: 'red' },
      { id: 4, name: 'Yenileme', color: null },
    ])
    bridge.calls.length = 0
    assert.deepEqual(await ticketsSource.tags(), tags)
  })

  // ----------------------------------------------------------------------------------------
  // 6. The property that ties them together
  // ----------------------------------------------------------------------------------------

  it('not one of the ten lookups touches platform.http — that is what "works offline" means here', async () => {
    // `../http` is mocked to throw on any verb, so a fallback to the network surfaces as a
    // rejection here rather than as an empty picker on a plane.
    await Promise.all([
      dealsSource.tags(),
      dealsSource.customFields(),
      dealsSource.contactOptions(7, 'a'),
      dealsSource.companyOptions('a'),
      ticketsSource.tags(),
      ticketsSource.customFields(),
      ticketsSource.contactOptions(7, 'a'),
      ticketsSource.companyOptions('a'),
      ticketsSource.allCompanyOptions(),
      ticketsSource.userOptions(),
    ])
    assert.equal(bridge.calls.length, 10)
    // Every one of them is a whitelisted read; none is a `mutate` or an HTTP verb.
    for (const call of bridge.calls) {
      assert.ok(
        ['tag_list', 'custom_field_list', 'contact_list', 'company_list', 'user_list'].includes(
          String(call.query.query),
        ),
        `unexpected NamedQuery ${String(call.query.query)}`,
      )
    }
  })
})
