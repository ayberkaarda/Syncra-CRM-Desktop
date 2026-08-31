// The wiring table: what every one of the 128 `DataSource` methods is actually bound to.
//
// This exists so the classification can be **checked** rather than asserted in prose.
// `desktop/scripts/check-data-wiring.mjs` reads three things and cross-references them:
//
//   1. the method list, parsed out of `frontend/src/platform/types.ts`;
//   2. this manifest;
//   3. the implementation source in `crm.ts` / `work.ts` / `comms.ts` / `catalog.ts`.
//
// A method that exists in the contract but not here, a manifest entry with no implementation,
// a `kind: 'http'` method whose body calls `mutate`, or an online-only method that is not
// `kind: 'http'` all fail that script. `index.ts` re-checks the manifest against the assembled
// object at startup in dev builds, so a drift cannot survive a single run either.
//
// ## The three kinds
//
// | kind | Path | Offline |
// |---|---|---|
// | `query` | `invoke('query' \| 'search')` against the local mirror | works |
// | `mutate` | `invoke('mutate')` — local row + outbox, pushed on the next round | works |
// | `http` | `platform.http` — straight to the API | fails loudly |
//
// **`http` is never a fallback for "not wired yet".** It is the correct binding for two
// disjoint sets: the `SYNCDESKTOP.md` §8 online-only list (KARAR A15 — these must NOT reach
// `mutate()`, or the outbox would tell the user an action succeeded that never happened), and
// writes to tables that are read-only in the sync scope (`SyncEngine::mutate` refuses them).
// Each entry says which.

/** How a `DataSource` method reaches its data. */
export type MethodKind = 'query' | 'mutate' | 'http'

/** One method's binding. */
export interface MethodBinding {
  /** Which of the three paths it takes. */
  kind: MethodKind
  /**
   * What it is bound to: a `NamedQuery` tag (or several), an `entity.op` / `entity.action`
   * pair, or an HTTP method and path.
   */
  via: string
  /** Why it is online-only, for `kind: 'http'` entries. */
  reason?: 'spec-8' | 'read-only-entity' | 'server-algorithm' | 'server-get-or-create' | 'not-whitelisted'
}

/**
 * `"<domain>.<method>"` -> binding, for all 128 methods of `DataSource`.
 *
 * Ordered exactly as `frontend/src/platform/types.ts` declares them, so the two can be read
 * side by side.
 */
export const DATA_METHOD_MANIFEST: Record<string, MethodBinding> = {
  // ---- deals ---------------------------------------------------------------------------
  'deals.list': { kind: 'query', via: 'deals_list' },
  'deals.get': { kind: 'query', via: 'rows_by_server_ids' },
  'deals.create': { kind: 'mutate', via: 'deal.create' },
  'deals.update': { kind: 'mutate', via: 'deal.update' },
  'deals.delete': { kind: 'mutate', via: 'deal.delete' },
  'deals.assign': { kind: 'mutate', via: 'deal.assign (action)' },
  // The Kanban board. `deals_board` is NOT what this reads through: that variant pins
  // `status = 'open'` and carries none of the board filters, so it cannot reproduce
  // `GET /api/deals/board`, which lists won/lost cards in their own columns.
  'deals.board': { kind: 'query', via: 'pipeline_stages + deals_list (one per stage)' },
  // KARAR P20: the wire field is `to_stage_id`; `pipeline_stage_id` rides along as the mirror
  // COLUMN so the card moves locally too (see `crm.ts`). `position` is never sent.
  'deals.move': { kind: 'mutate', via: 'deal.move (action)' },
  // Active stages in `position` order — the SAME read the board's columns come from
  // (`activeStagesOrdered()`), so the two can never disagree about which stages exist.
  'deals.stages': { kind: 'query', via: 'pipeline_stages' },
  // Same `user_list` read as `contacts.userOptions` / `companies.userOptions` /
  // `leads.ownerOptions`, through the same shared helper. NOT `users.list`: that one is
  // §8 online-only (see below), and an owner filter fed from it would be empty offline.
  'deals.ownerOptions': { kind: 'query', via: 'user_list' },

  // ---- contacts ------------------------------------------------------------------------
  'contacts.list': { kind: 'query', via: 'contact_list' },
  'contacts.get': { kind: 'query', via: 'rows_by_server_ids' },
  'contacts.timeline': { kind: 'query', via: 'activity_list + task_list' },
  'contacts.create': { kind: 'mutate', via: 'contact.create' },
  'contacts.update': { kind: 'mutate', via: 'contact.update' },
  'contacts.delete': { kind: 'mutate', via: 'contact.delete' },
  'contacts.tags': { kind: 'query', via: 'tag_list' },
  'contacts.customFields': { kind: 'query', via: 'custom_field_list' },
  'contacts.companyOptions': { kind: 'query', via: 'company_list' },
  'contacts.allCompanyOptions': { kind: 'query', via: 'company_list' },
  'contacts.userOptions': { kind: 'query', via: 'user_list' },

  // ---- companies -----------------------------------------------------------------------
  'companies.list': { kind: 'query', via: 'company_list' },
  'companies.get': { kind: 'query', via: 'rows_by_server_ids + contact_list' },
  'companies.timeline': { kind: 'query', via: 'activity_list + task_list' },
  'companies.contacts': { kind: 'query', via: 'contact_list' },
  'companies.create': { kind: 'mutate', via: 'company.create' },
  'companies.update': { kind: 'mutate', via: 'company.update' },
  'companies.delete': { kind: 'mutate', via: 'company.delete' },
  'companies.tags': { kind: 'query', via: 'tag_list' },
  'companies.customFields': { kind: 'query', via: 'custom_field_list' },
  'companies.userOptions': { kind: 'query', via: 'user_list' },

  // ---- leads ---------------------------------------------------------------------------
  'leads.list': { kind: 'query', via: 'lead_list' },
  'leads.get': { kind: 'query', via: 'rows_by_server_ids' },
  'leads.create': { kind: 'mutate', via: 'lead.create' },
  'leads.update': { kind: 'mutate', via: 'lead.update' },
  'leads.delete': { kind: 'mutate', via: 'lead.delete' },
  'leads.checkDuplicates': {
    kind: 'http',
    via: 'POST /api/leads/check-duplicates',
    reason: 'server-algorithm',
  },
  'leads.convert': { kind: 'http', via: 'POST /api/leads/{id}/convert', reason: 'spec-8' },
  'leads.assign': { kind: 'mutate', via: 'lead.assign (action)' },
  'leads.tags': { kind: 'query', via: 'tag_list' },
  'leads.createTag': { kind: 'mutate', via: 'tag.create' },
  'leads.customFields': { kind: 'query', via: 'custom_field_list' },
  'leads.ownerOptions': { kind: 'query', via: 'user_list' },

  // ---- tasks ---------------------------------------------------------------------------
  'tasks.list': { kind: 'query', via: 'task_list' },
  'tasks.calendar': { kind: 'query', via: 'task_list' },
  'tasks.get': { kind: 'query', via: 'rows_by_server_ids' },
  'tasks.create': { kind: 'mutate', via: 'task.create' },
  'tasks.update': { kind: 'mutate', via: 'task.update' },
  'tasks.delete': { kind: 'mutate', via: 'task.delete' },
  'tasks.complete': { kind: 'mutate', via: 'task.complete (action) / task.update' },
  'tasks.assign': { kind: 'mutate', via: 'task.assign (action)' },
  'tasks.userOptions': { kind: 'query', via: 'user_list' },

  // ---- tickets -------------------------------------------------------------------------
  'tickets.list': { kind: 'query', via: 'ticket_list' },
  'tickets.stats': { kind: 'query', via: 'ticket_stats' },
  'tickets.get': { kind: 'query', via: 'rows_by_server_ids' },
  'tickets.create': { kind: 'mutate', via: 'ticket.create' },
  'tickets.update': { kind: 'mutate', via: 'ticket.update' },
  'tickets.delete': { kind: 'mutate', via: 'ticket.delete' },
  'tickets.status': { kind: 'mutate', via: 'ticket.status (action)' },
  'tickets.assign': { kind: 'mutate', via: 'ticket.assign (action)' },

  // ---- quotes --------------------------------------------------------------------------
  'quotes.list': { kind: 'query', via: 'quote_list' },
  'quotes.get': { kind: 'query', via: 'rows_by_server_ids' },
  'quotes.create': { kind: 'mutate', via: 'quote.create' },
  'quotes.update': { kind: 'mutate', via: 'quote.update' },
  'quotes.delete': { kind: 'mutate', via: 'quote.delete' },
  'quotes.send': { kind: 'http', via: 'POST /api/quotes/{id}/send', reason: 'spec-8' },
  'quotes.status': { kind: 'mutate', via: 'quote.status (action)' },
  'quotes.revise': { kind: 'http', via: 'POST /api/quotes/{id}/revise', reason: 'spec-8' },
  'quotes.revisionFamily': { kind: 'query', via: 'quote_revision_family' },
  'quotes.calculate': { kind: 'http', via: 'POST /api/quotes/calculate', reason: 'spec-8' },
  'quotes.pdfBlob': { kind: 'http', via: 'GET /api/quotes/{id}/pdf', reason: 'spec-8' },

  // ---- activities ----------------------------------------------------------------------
  'activities.list': { kind: 'query', via: 'activity_list' },
  'activities.create': { kind: 'mutate', via: 'activity.create' },
  'activities.update': { kind: 'mutate', via: 'activity.update' },
  'activities.delete': { kind: 'mutate', via: 'activity.delete' },

  // ---- chat ----------------------------------------------------------------------------
  'chat.conversations': { kind: 'query', via: 'conversation_list' },
  'chat.conversation': { kind: 'query', via: 'rows_by_server_ids' },
  'chat.unreadCount': { kind: 'query', via: 'conversation_membership' },
  'chat.createConversation': { kind: 'mutate', via: 'conversation.create' },
  'chat.recordConversation': {
    kind: 'http',
    via: 'POST /api/conversations/for-record',
    reason: 'server-get-or-create',
  },
  'chat.renameConversation': { kind: 'mutate', via: 'conversation.update' },
  'chat.deleteConversation': { kind: 'mutate', via: 'conversation.delete' },
  'chat.addMembers': {
    kind: 'http',
    via: 'POST /api/conversations/{id}/members',
    reason: 'not-whitelisted',
  },
  'chat.removeMember': {
    kind: 'http',
    via: 'DELETE /api/conversations/{id}/members/{userId}',
    reason: 'not-whitelisted',
  },
  'chat.leaveConversation': {
    kind: 'http',
    via: 'POST /api/conversations/{id}/leave',
    reason: 'not-whitelisted',
  },
  'chat.muteConversation': { kind: 'mutate', via: 'conversation_user.update' },
  'chat.markRead': { kind: 'mutate', via: 'conversation.read (action)' },
  'chat.markDelivered': { kind: 'mutate', via: 'conversation.delivered (action)' },
  'chat.messages': { kind: 'query', via: 'conversation_messages' },
  'chat.sendMessage': { kind: 'mutate', via: 'message.create' },
  'chat.editMessage': { kind: 'mutate', via: 'message.update' },
  'chat.deleteMessage': { kind: 'mutate', via: 'message.delete' },
  'chat.searchMessages': { kind: 'query', via: 'search + rows_by_client_ids' },
  'chat.uploadAttachment': { kind: 'http', via: 'POST /api/attachments', reason: 'spec-8' },

  // ---- notifications -------------------------------------------------------------------
  'notifications.list': { kind: 'query', via: 'notification_list' },
  'notifications.unreadCount': { kind: 'query', via: 'notification_list (count_only)' },
  'notifications.markRead': { kind: 'mutate', via: 'notification.read (action)' },
  'notifications.markAllRead': { kind: 'mutate', via: 'notification.read_all (action, user scope)' },
  'notifications.delete': { kind: 'mutate', via: 'notification.delete' },

  // ---- search --------------------------------------------------------------------------
  'search.query': { kind: 'query', via: 'search + rows_by_client_ids' },

  // ---- products ------------------------------------------------------------------------
  'products.list': { kind: 'query', via: 'product_list' },
  'products.get': { kind: 'query', via: 'rows_by_server_ids' },
  'products.categories': { kind: 'query', via: 'product_categories' },
  'products.price': { kind: 'query', via: 'price_list_item_list + rows_by_server_ids' },
  'products.create': { kind: 'http', via: 'POST /api/products', reason: 'read-only-entity' },
  'products.update': { kind: 'http', via: 'PATCH /api/products/{id}', reason: 'read-only-entity' },
  'products.delete': { kind: 'http', via: 'DELETE /api/products/{id}', reason: 'read-only-entity' },

  // ---- price lists ---------------------------------------------------------------------
  'priceLists.list': { kind: 'query', via: 'price_list_list' },
  'priceLists.get': { kind: 'query', via: 'rows_by_server_ids' },
  'priceLists.items': { kind: 'query', via: 'price_list_item_list' },
  'priceLists.create': { kind: 'http', via: 'POST /api/price-lists', reason: 'read-only-entity' },
  'priceLists.update': {
    kind: 'http',
    via: 'PATCH /api/price-lists/{id}',
    reason: 'read-only-entity',
  },
  'priceLists.delete': {
    kind: 'http',
    via: 'DELETE /api/price-lists/{id}',
    reason: 'read-only-entity',
  },
  'priceLists.setPrice': {
    kind: 'http',
    via: 'PUT /api/price-lists/{id}/products/{productId}',
    reason: 'read-only-entity',
  },
  'priceLists.removePrice': {
    kind: 'http',
    via: 'DELETE /api/price-lists/{id}/products/{productId}',
    reason: 'read-only-entity',
  },

  // ---- exchange ------------------------------------------------------------------------
  'exchange.current': { kind: 'query', via: 'exchange_rate_list + setting_list' },

  // ---- saved views ---------------------------------------------------------------------
  'savedViews.list': { kind: 'query', via: 'saved_view_list' },
  'savedViews.create': { kind: 'http', via: 'POST /api/saved-views', reason: 'spec-8' },
  'savedViews.update': { kind: 'http', via: 'PATCH /api/saved-views/{id}', reason: 'spec-8' },
  'savedViews.delete': {
    kind: 'http',
    via: 'DELETE /api/saved-views/{id}',
    reason: 'read-only-entity',
  },

  // ---- users ---------------------------------------------------------------------------
  'users.list': { kind: 'http', via: 'GET /api/users', reason: 'spec-8' },
  'users.get': { kind: 'http', via: 'GET /api/users/{id}', reason: 'spec-8' },
  'users.create': { kind: 'http', via: 'POST /api/users', reason: 'spec-8' },
  'users.update': { kind: 'http', via: 'PATCH /api/users/{id}', reason: 'spec-8' },
  'users.delete': { kind: 'http', via: 'DELETE /api/users/{id}', reason: 'spec-8' },
  'users.setActive': { kind: 'http', via: 'PATCH /api/users/{id}/active', reason: 'spec-8' },
  'users.resetPassword': {
    kind: 'http',
    via: 'POST /api/users/{id}/reset-password',
    reason: 'spec-8',
  },
  'users.roles': { kind: 'http', via: 'GET /api/roles', reason: 'spec-8' },
}

/**
 * The `SYNCDESKTOP.md` §8 online-only list, expressed as `DataSource` method names.
 *
 * KARAR A15: every one of these MUST be `kind: 'http'`. If one ever reaches `mutate()` it
 * lands in the outbox, the UI reports success, and the user believes a quote was sent or a
 * password changed when nothing left the machine. The check script asserts this list against
 * the manifest above; §8 items with no `DataSource` counterpart (`leads.import`, `settings.*`,
 * `reports.*`, `dashboard.*`, `logs.*`, the manual exchange-rate refresh, `password change`)
 * are not listed because they are not part of this surface.
 */
export const SPEC_8_METHODS: readonly string[] = [
  'leads.convert',
  'quotes.send',
  'quotes.revise',
  'quotes.pdfBlob',
  'quotes.calculate',
  'chat.uploadAttachment',
  'savedViews.create',
  'savedViews.update',
  'users.list',
  'users.get',
  'users.create',
  'users.update',
  'users.delete',
  'users.setActive',
  'users.resetPassword',
  'users.roles',
]
