// The desktop `DataSource`: all 128 methods of the platform contract, assembled from the four
// domain modules.
//
// The strongest guarantee here is the type annotation. `const data: DataSource = {...}` makes
// `tsc -p tsconfig.json --noEmit` (the regression gate) fail if a single method is missing,
// misspelled, or has a signature the contract does not declare — which is why there is no
// `Proxy` and no dynamic dispatch left in this layer. What the type cannot see is *how* each
// method gets its data; that is what `manifest.ts` records and
// `desktop/scripts/check-data-wiring.mjs` verifies.
import type { DataSource } from '@/platform/types'

import { companiesSource, contactsSource, dealsSource, leadsSource } from './crm'
import { activitiesSource, quotesSource, tasksSource, ticketsSource } from './work'
import { chatSource, notificationsSource, searchSource } from './comms'
import {
  exchangeSource,
  priceListsSource,
  productsSource,
  savedViewsSource,
  usersSource,
} from './catalog'
import { DATA_METHOD_MANIFEST } from './manifest'

/** The desktop implementation of `Platform['data']`. */
export const desktopData: DataSource = {
  deals: dealsSource,
  contacts: contactsSource,
  companies: companiesSource,
  leads: leadsSource,
  tasks: tasksSource,
  tickets: ticketsSource,
  quotes: quotesSource,
  activities: activitiesSource,
  chat: chatSource,
  notifications: notificationsSource,
  search: searchSource,
  products: productsSource,
  priceLists: priceListsSource,
  exchange: exchangeSource,
  savedViews: savedViewsSource,
  users: usersSource,
}

/**
 * Every `"<domain>.<method>"` the assembled object actually exposes.
 *
 * Read off the object rather than off the contract on purpose: this is what a caller can
 * really invoke, which is the thing worth comparing the manifest against.
 */
export function dataMethodNames(): string[] {
  const names: string[] = []
  for (const [domain, source] of Object.entries(desktopData)) {
    for (const [method, value] of Object.entries(source as Record<string, unknown>)) {
      if (typeof value === 'function') names.push(`${domain}.${method}`)
    }
  }
  return names.sort()
}

/**
 * Compare the manifest against the assembled object and return every disagreement.
 *
 * Empty means the two are in step. Called below in dev builds and by the check script's
 * runtime counterpart; it is cheap (a couple of hundred property reads) and it is the only
 * check that sees the *object*, not the source text.
 */
export function verifyDataWiring(): string[] {
  const problems: string[] = []
  const actual = new Set(dataMethodNames())
  const declared = new Set(Object.keys(DATA_METHOD_MANIFEST))

  for (const name of actual) {
    if (!declared.has(name)) problems.push(`${name}: implemented but missing from the manifest`)
  }
  for (const name of declared) {
    if (!actual.has(name)) problems.push(`${name}: in the manifest but not implemented`)
  }
  return problems
}

// A drift here means a method is unreachable or misclassified, and both fail silently at
// runtime — the caller just gets `undefined is not a function` three layers away. Dev builds
// say so at startup instead; production builds do not pay for a check the release pipeline
// already ran (`npm run check:data`).
if (import.meta.env.DEV) {
  const problems = verifyDataWiring()
  if (problems.length > 0) {
    throw new Error(`DataSource wiring is out of step with the manifest:\n- ${problems.join('\n- ')}`)
  }
}
