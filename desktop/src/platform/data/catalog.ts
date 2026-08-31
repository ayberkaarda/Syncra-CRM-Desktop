// `DataSource` implementations for the read-only half of the mirror: products, price lists,
// exchange rates, saved views and users.
//
// **Every write in this file goes to `platform.http`, and that is structural, not a shortcut.**
// All five tables are read-only in the sync scope (`SYNCDESKTOP.md` §4.1), and
// `SyncEngine::mutate` refuses an RO entity outright ("`{entity}` is read-only") before it
// writes anything. Routing them to the outbox would produce a queue of mutations the engine
// itself rejects. `users.*`, `roles` and `saved-views create/update` are on the §8 online-only
// list as well; `saved-views delete`, product and price-list writes are online-only for the
// RO reason alone, which is recorded here rather than left implicit.
import type {
  PriceListItemsResponse,
  PriceListPayload,
  PriceListsListResponse,
} from '@/features/price-lists/api/priceListsApi'
import type { PriceList, PriceListItem } from '@/features/price-lists/types'
import type { ProductsListResponse } from '@/features/products/api/productsApi'
import type { Product, ResolvedProductPrice } from '@/features/products/types'
import type { SavedView } from '@/features/saved-views/types'
import type { Role, User } from '@/features/users/types'
import type { UsersListResponse } from '@/features/users/api/usersApi'
import type { ExchangeRateCurrentRow, ExchangeRatesCurrentResponse } from '@/features/exchange/types'
import type {
  ExchangeSource,
  PriceListsSource,
  ProductsSource,
  SavedViewsSource,
  UsersSource,
} from '@/platform/types'

import { http } from '../http'
import { sessionUserId } from '../session'
import { listPage, MAX_PAGE, num, rowId, runQuery, str, text, type LocalRow } from './engine'
import { loadCounts, loadRefs } from './refs'
import { mapPriceList, mapPriceListItem, mapProduct, mapSavedView } from './mappers'
import { loadTagRefs } from './crm'
import { readBack } from './writes'

// ------------------------------------------------------------------------------------------------
// Products
// ------------------------------------------------------------------------------------------------

export const productsSource: ProductsSource = {
  list: async (query): Promise<ProductsListResponse> =>
    listPage(
      {
        query: 'product_list',
        q: query.q,
        category: query.category,
        is_active: query.is_active,
        tag_id: query.tag_id,
        price_min: query.price_min,
        price_max: query.price_max,
        in_stock: query.in_stock,
      },
      query,
      async (rows) => {
        const tags = await loadTagRefs(rows)
        return rows.map((row) => mapProduct(row, tags))
      },
    ),

  get: async (id): Promise<Product> => {
    const row = await readBack('product', id)
    return mapProduct(row, await loadTagRefs([row]))
  },

  categories: async (): Promise<string[]> => {
    const rows = await runQuery({ query: 'product_categories' }, {})
    return rows.map((row) => text(row.category)).filter((value) => value !== '')
  },

  /**
   * Resolve the price a product sells at, optionally through a price list.
   *
   * The rule is the one `features/price-lists/types.ts` states: the list overrides
   * `unit_price` and **only** `unit_price` — `tax_rate` and `currency` always come from the
   * product. That is a lookup, not a calculation, which is why it is safe to do locally while
   * `quotes.calculate` is not.
   */
  price: async (productId, priceListId): Promise<ResolvedProductPrice> => {
    const product = await readBack('product', productId)
    const fallback: ResolvedProductPrice = {
      product_id: rowId(product),
      unit_price: num(product.unit_price),
      tax_rate: num(product.tax_rate),
      currency: text(product.currency),
      source: 'catalog',
      price_list: null,
    }
    if (priceListId === undefined) return fallback

    const [override] = await runQuery(
      { query: 'price_list_item_list', price_list_id: priceListId, product_id: productId },
      { limit: 1 },
    )
    if (!override) return fallback

    const list = await readBack('price_list', priceListId)
    return {
      ...fallback,
      unit_price: num(override.unit_price),
      source: 'price_list',
      price_list: { id: rowId(list), name: text(list.name) },
    }
  },

  /** ONLINE-ONLY: `products` is read-only in the sync scope; `mutate()` refuses it. */
  create: (payload) => http.post<{ data: Product }>('/api/products', payload).then((body) => body.data),

  /** ONLINE-ONLY, same reason as `create`. */
  update: (id, payload) =>
    http.patch<{ data: Product }>(`/api/products/${id}`, payload).then((body) => body.data),

  /** ONLINE-ONLY, same reason as `create`. */
  delete: (id) => http.delete<void>(`/api/products/${id}`),
}

// ------------------------------------------------------------------------------------------------
// Price lists
// ------------------------------------------------------------------------------------------------

export const priceListsSource: PriceListsSource = {
  list: async (query): Promise<PriceListsListResponse> =>
    listPage(
      {
        query: 'price_list_list',
        q: query.q,
        is_active: query.is_active,
        is_default: query.is_default,
      },
      query,
      async (rows) => {
        const counts = await loadCounts('price_list_items', rows.map(rowId))
        return rows.map((row) => mapPriceList(row, counts))
      },
    ),

  get: async (id): Promise<PriceList> => {
    const row = await readBack('price_list', id)
    const counts = await loadCounts('price_list_items', [id])
    return mapPriceList(row, counts)
  },

  items: async (id, page, perPage): Promise<PriceListItemsResponse> =>
    listPage(
      { query: 'price_list_item_list', price_list_id: id },
      { page, per_page: perPage },
      async (rows) => {
        const products = await loadRefs('product', rows, ['product_id', 'product_client_id'])
        return rows.map((row) => mapPriceListItem(row, products))
      },
    ),

  /** ONLINE-ONLY: `price_lists` is read-only in the sync scope. */
  create: (payload: PriceListPayload) =>
    http.post<{ data: PriceList }>('/api/price-lists', payload).then((body) => body.data),

  /** ONLINE-ONLY, same reason as `create`. */
  update: (id, payload) =>
    http.patch<{ data: PriceList }>(`/api/price-lists/${id}`, payload).then((body) => body.data),

  /** ONLINE-ONLY, same reason as `create`. */
  delete: (id) => http.delete<void>(`/api/price-lists/${id}`),

  /** ONLINE-ONLY: `price_list_items` is read-only in the sync scope. PUT, upsert semantics. */
  setPrice: (priceListId, productId, unitPrice) =>
    http
      .put<{ data: PriceListItem }>(`/api/price-lists/${priceListId}/products/${productId}`, {
        unit_price: unitPrice,
      })
      .then((body) => body.data),

  /** ONLINE-ONLY, same reason as `setPrice`. */
  removePrice: (priceListId, productId) =>
    http.delete<void>(`/api/price-lists/${priceListId}/products/${productId}`),
}

// ------------------------------------------------------------------------------------------------
// Exchange rates
// ------------------------------------------------------------------------------------------------

/** `rate_date` older than this many calendar days counts as stale (the type file's rule). */
const STALE_AFTER_DAYS = 4

function daysSince(dateText: string | null): number {
  if (!dateText) return 0
  const at = Date.parse(dateText)
  if (!Number.isFinite(at)) return 0
  return Math.max(0, Math.floor((Date.now() - at) / 86_400_000))
}

export const exchangeSource: ExchangeSource = {
  /**
   * The mirror keeps the server's seven-day window (`SYNCDESKTOP.md` §4.1), newest first, so
   * the newest row per currency is the current rate.
   *
   * Staleness is computed rather than mirrored, and that is safe: `features/exchange/types.ts`
   * states the rule in full (`rate_date` older than four calendar days), unlike the SLA
   * countdown or the quote totals, which are server-owned formulas.
   */
  current: async (): Promise<ExchangeRatesCurrentResponse> => {
    const [rateRows, settingRows] = await Promise.all([
      runQuery({ query: 'exchange_rate_list' }, { limit: MAX_PAGE }),
      runQuery({ query: 'setting_list' }, { limit: MAX_PAGE }),
    ])

    const baseSetting = settingRows.find((row) => text(row.key) === 'base_currency')
    const baseCurrency = baseSetting ? text(baseSetting.value) || 'TRY' : 'TRY'

    // Newest first (the query's natural order is `rate_date DESC`), so the first row wins.
    const newest = new Map<string, LocalRow>()
    for (const row of rateRows) {
      const currency = text(row.currency)
      if (!currency || currency === baseCurrency) continue
      if (!newest.has(currency)) newest.set(currency, row)
    }

    const rates: ExchangeRateCurrentRow[] = [...newest.entries()]
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([currency, row]) => {
        const rateDate = str(row.rate_date)
        const rate = str(row.rate)
        const days = rate === null ? 0 : daysSince(rateDate)
        return {
          currency,
          rate,
          rate_date: rate === null ? null : rateDate,
          is_stale: rate !== null && days > STALE_AFTER_DAYS,
          days_stale: days,
        }
      })

    // `as_of` is the OLDEST `rate_date` among rows that actually have a rate.
    const dated = rates.filter((row) => row.rate !== null && row.rate_date !== null)
    const asOf = dated.length === 0 ? null : dated.map((row) => row.rate_date as string).sort()[0]
    const asOfDays = daysSince(asOf)

    return {
      base_currency: baseCurrency,
      as_of: asOf,
      is_stale: asOf !== null && asOfDays > STALE_AFTER_DAYS,
      days_stale: asOf === null ? 0 : asOfDays,
      rates,
    }
  },
}

// ------------------------------------------------------------------------------------------------
// Saved views
// ------------------------------------------------------------------------------------------------

export const savedViewsSource: SavedViewsSource = {
  list: async (module): Promise<SavedView[]> => {
    const rows = await runQuery({ query: 'saved_view_list', module }, { limit: MAX_PAGE })
    const users = await loadRefs('user', rows, ['user_id', 'user_client_id'])
    return rows.map((row) => mapSavedView(row, users, sessionUserId()))
  },

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8) — and `saved_views` is read-only in the sync scope. */
  create: (payload) =>
    http.post<{ data: SavedView }>('/api/saved-views', payload).then((body) => body.data),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8), same reason as `create`. */
  update: (id, payload) =>
    http.patch<{ data: SavedView }>(`/api/saved-views/${id}`, payload).then((body) => body.data),

  /**
   * ONLINE-ONLY. Not named in §8 (which lists create/update), but `saved_views` is read-only
   * in the sync scope, so there is no local delete to queue.
   */
  delete: (id) => http.delete<void>(`/api/saved-views/${id}`),
}

// ------------------------------------------------------------------------------------------------
// Users
//
// `users.*` and `roles` are online-only in full (`SYNCDESKTOP.md` §8). The `users` mirror is a
// six-column projection §4.1 pins down ("başka kolon YASAK") and exists to name owners and
// assignees — not to back the user-management screens, which need `role`, `last_login_at` and
// `must_change_password`. Serving those screens from the projection would show a page where
// every role column is empty, which reads as data loss rather than as "not available offline".
// ------------------------------------------------------------------------------------------------

export const usersSource: UsersSource = {
  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8). */
  list: (query) =>
    http.get<UsersListResponse>('/api/users', {
      params: {
        page: query.page,
        per_page: query.per_page,
        sort: query.sort || undefined,
        q: query.q || undefined,
        'filter[role]': query.role || undefined,
        'filter[is_active]': query.is_active,
      },
    }),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8). */
  get: (id) => http.get<{ data: User }>(`/api/users/${id}`).then((body) => body.data),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8). */
  create: (payload) => http.post<{ data: User }>('/api/users', payload).then((body) => body.data),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8). */
  update: (id, payload) =>
    http.patch<{ data: User }>(`/api/users/${id}`, payload).then((body) => body.data),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8). */
  delete: (id) => http.delete<void>(`/api/users/${id}`),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8). */
  setActive: (id, isActive) =>
    http.patch<{ data: User }>(`/api/users/${id}/active`, { is_active: isActive }).then((body) => body.data),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8). */
  resetPassword: (id, password) => http.post<void>(`/api/users/${id}/reset-password`, { password }),

  /** ONLINE-ONLY (`SYNCDESKTOP.md` §8 lists `roles`); roles are not mirrored at all. */
  roles: () => http.get<{ data: Role[] }>('/api/roles').then((body) => body.data),
}
