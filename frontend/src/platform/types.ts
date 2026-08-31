// Platform adapter contract — `SYNCDESKTOP.md` §7.1, `docs/DESKTOP-ARCHITECTURE.md` §3.3 / §E.5.
// Type-only module: every import below is an `import type`, so nothing here reaches the bundle.
// Implementations: `web.ts` (this phase) and, later, `desktop/src/platform/desktop.ts`.
import type {
  Activity,
  ActivitiesListResponse,
  ActivitiesQuery,
  ActivityPayload,
} from '../features/activities/types'
import type {
  Attachment,
  ChatUnreadCount,
  Conversation,
  ConversationCursorAck,
  ConversationsQuery,
  CreateConversationPayload,
  Message,
  MessagesPage,
  RecordConversableType,
  SendMessagePayload,
} from '../features/chat/types'
import type {
  CompaniesListResponse,
  TimelineListResponse as CompanyTimelineListResponse,
} from '../features/companies/api/companiesApi'
import type {
  CompaniesQuery,
  Company,
  CompanyPayload,
  ContactSummary,
  CustomFieldDef as CompanyCustomFieldDef,
  Tag as CompanyTag,
  UserOption as CompanyUserOption,
} from '../features/companies/types'
import type {
  ContactsListResponse,
  TimelineListResponse as ContactTimelineListResponse,
} from '../features/contacts/api/contactsApi'
import type {
  CompanyOption,
  Contact,
  ContactPayload,
  ContactsQuery,
  CustomFieldDef as ContactCustomFieldDef,
  Tag as ContactTag,
  UserOption as ContactUserOption,
} from '../features/contacts/types'
import type { DealPayload, DealsListResponse, DealsQuery } from '../features/deals/api/dealsApi'
import type {
  BoardFilters,
  BoardResponse,
  Deal,
  DealCard,
  MoveDealPayload,
  OwnerOption as DealOwnerOption,
  PipelineStage,
} from '../features/deals/types'
import type { ExchangeRatesCurrentResponse } from '../features/exchange/types'
import type { LeadPayload } from '../features/leads/api/leadsApi'
import type {
  ConvertLeadPayload,
  ConvertLeadResult,
  CustomField as LeadCustomField,
  DuplicateCandidate,
  DuplicateCheckInput,
  Lead,
  LeadsListResponse,
  LeadsQuery,
  OwnerOption,
  Tag as LeadTagOption,
} from '../features/leads/types'
import type {
  Notification,
  NotificationsListResponse,
  NotificationsQuery,
} from '../features/notifications/types'
import type {
  PriceListItemsResponse,
  PriceListPayload,
  PriceListsListResponse,
  PriceListsQuery,
} from '../features/price-lists/api/priceListsApi'
import type { PriceList, PriceListItem } from '../features/price-lists/types'
import type {
  ProductPayload,
  ProductsListResponse,
  ProductsQuery,
} from '../features/products/api/productsApi'
import type { Product, ResolvedProductPrice } from '../features/products/types'
import type { QuoteCalculatePayload } from '../features/quotes/api/quotesApi'
import type {
  Quote,
  QuoteCalculateResult,
  QuotePayload,
  QuotesListResponse,
  QuotesQuery,
  QuoteStatus,
} from '../features/quotes/types'
import type {
  SavedView,
  SavedViewModule,
  SavedViewPayload,
  UpdateSavedViewPayload,
} from '../features/saved-views/types'
import type { SearchResponse } from '../features/search/types'
import type {
  Task,
  TaskPayload,
  TasksCalendarQuery,
  TasksCalendarResponse,
  TasksListResponse,
  TasksQuery,
  UserOption as TaskUserOption,
} from '../features/tasks/types'
import type {
  Ticket,
  TicketPayload,
  TicketsListResponse,
  TicketsQuery,
  TicketStats,
  TicketStatus,
} from '../features/tickets/types'
import type {
  CreateUserPayload,
  UpdateUserPayload,
  UsersListResponse,
} from '../features/users/api/usersApi'
import type { Role, User, UsersQuery } from '../features/users/types'

export type PlatformKind = 'web' | 'desktop'
export type ConnState = 'online' | 'offline'
export type Capability = 'offline' | 'deep-link' | 'hotkey' | 'tray' | 'files' | 'clipboard' | 'screenshot'

/** Normalized shape of every command/HTTP failure. `code` maps to a `desktop.errors.<code>` i18n key. */
export interface PlatformError {
  code: string
  message: string
  fields?: Record<string, string[]>
}

/** `SYNCDESKTOP.md` §8 — an action rejected because the platform is offline. */
export interface OnlineOnlyError extends PlatformError {
  code: 'ONLINE_ONLY'
  action: string
}

export interface HttpClient {
  get<T>(url: string, config?: unknown): Promise<T>
  post<T>(url: string, body?: unknown, config?: unknown): Promise<T>
  put<T>(url: string, body?: unknown, config?: unknown): Promise<T>
  patch<T>(url: string, body?: unknown, config?: unknown): Promise<T>
  delete<T>(url: string, config?: unknown): Promise<T>
}

/** Cancellation handle for the one data method that needs it (`quotes.calculate`, debounced). */
export interface RequestOptions {
  signal?: AbortSignal
}

/**
 * A mirror row's replication state (`SYNCDESKTOP.md` §5.3, `0001_init.sql`
 * `CHECK(sync_state IN ('synced','pending','conflict','tombstone'))`).
 *
 * It lives in the platform contract rather than in a feature's types because it is not a
 * property of the domain record at all: it describes where a copy of that record stands
 * relative to the server, and only a platform that keeps copies can answer it.
 */
export type SyncState = 'synced' | 'pending' | 'conflict' | 'tombstone'

/**
 * A record DTO as an offline-capable platform produces it: the feature shape, plus the
 * replication state of the local copy it was read from.
 *
 * **`sync_state` is optional, and that is the whole design.** The web adapter never writes it,
 * so every existing DTO stays exactly as it was and no web code path changes; the desktop
 * adapter fills it, and `SyncStateBadge` renders nothing for `undefined` or `'synced'`. That
 * pairing is what lets one shared page carry the indicator with no `isDesktop` branch anywhere
 * in component code (KARAR A19: the platform layer differs, the components do not).
 *
 * Mappers declare it as their return type so the field is not an excess property on their
 * object literals; the value then travels inside `Deal[]` / `Contact[]` / … untouched, because
 * a wider object is still assignable to the narrower list the feature hooks are typed with.
 */
export type WithSyncState<T> = T & { sync_state?: SyncState }

// ------------------------------------------------------------------------------------------------
// DataSource — `docs/DESKTOP-ARCHITECTURE.md` §E.5 / KARAR A19
//
// Verb-based, plain `Promise`-returning methods. NO React: no hooks, no `Keys` factory, no
// `queryClient`. Every method below is the 1:1 counterpart of a function that already exists in a
// feature's api module (annotated with `<-` below) — no method is invented (§3.3 "yeni metot
// ICAT EDILMEZ"). Shared hooks keep their names, signatures, return types, query keys and
// options; only their `queryFn`/`mutationFn` body now calls through `platform.data.<domain>`
// (KARAR A4), which is what lets a desktop adapter serve them from the local engine without
// rewriting any UI (K1).
//
// URL builders (`buildQuotePdfUrl`, `buildImportTemplateUrl`, `buildExportUrl`,
// `buildReportExportUrl`) are NOT here: they derive a string from `api.defaults.baseURL` and
// issue no request.
// ------------------------------------------------------------------------------------------------

/**
 * `features/deals/api/dealsApi.ts`, plus the reads/writes the kanban board is built on
 * (`features/deals/api/boardApi.ts`).
 *
 * The board's verbs live here rather than in a surface of their own because they are the
 * only part of `boardApi.ts` that talks to a backend at all: everything else in that module —
 * `boardKeys`, `normalizeFilters` and the pure column/card cache helpers — operates on a
 * `BoardResponse` that is already in hand and is platform-independent by construction.
 *
 * `stages` is a deals verb rather than a top-level surface of its own: a pipeline stage exists
 * only as a deal's context (the backend says so too — `GET /api/pipeline-stages` has no policy
 * of its own and authorizes through `DealPolicy::viewAny`), and `board` already reads the very
 * same rows.
 */
export interface DealsSource {
  /** <- `fetchDeals` */
  list(query: DealsQuery): Promise<DealsListResponse>
  /** <- `fetchDeal` */
  get(id: number): Promise<Deal>
  /** <- `createDealRequest` */
  create(payload: DealPayload): Promise<Deal>
  /** <- `updateDealRequest` */
  update(id: number, payload: Partial<DealPayload>): Promise<Deal>
  /** <- `deleteDealRequest` */
  delete(id: number): Promise<void>
  /** <- `assignDealRequest` */
  assign(id: number, ownerId: number | null): Promise<Deal>
  /** <- `fetchBoard` (`boardApi.ts`) — every active stage as a column, `per_stage` cards each. */
  board(filters: BoardFilters): Promise<BoardResponse>
  /**
   * <- `moveDealRequest` (`boardApi.ts`).
   *
   * Resolves with the card as it now stands, which is what the caller re-seats in its cache.
   * `MoveDealPayload` carries neighbours, never a `position`: the sort key is generated under
   * a row lock on the server (`MoveDealRequest` rejects a client `position` outright), so an
   * offline implementation owns the column a card is in, never its rank inside that column.
   */
  move(id: number, payload: MoveDealPayload): Promise<DealCard>
  /**
   * <- `fetchPipelineStages` (`boardApi.ts`) — `GET /api/pipeline-stages` with no
   * `include_inactive`, i.e. the ACTIVE stages in `position` order, which is exactly the column
   * set `board` builds from. Backs the board's stage filter, the deal form's stage select and
   * the deals list's stage column.
   */
  stages(): Promise<PipelineStage[]>
  /**
   * <- `fetchOwnerOptions` (`boardApi.ts`) — `GET /api/users?per_page=100`, the owner filter /
   * owner select options.
   *
   * A verb of its own rather than a call into `users.list()`: `SYNCDESKTOP.md` §8 makes
   * `users.*` online-only (KARAR A15), so an owner dropdown fed from there would be EMPTY
   * offline. The `users` mirror is a read-only projection that is never windowed (§4.1, K2),
   * which is what lets this one answer offline. Every other domain that needs the same list
   * declares it the same way — `contacts.userOptions`, `companies.userOptions`,
   * `tasks.userOptions`, `leads.ownerOptions` — and all of them read one `user_list` query.
   */
  ownerOptions(): Promise<DealOwnerOption[]>
}

/** `features/contacts/api/contactsApi.ts` */
export interface ContactsSource {
  /** <- `fetchContacts` */
  list(query: ContactsQuery): Promise<ContactsListResponse>
  /** <- `fetchContactById` */
  get(id: number): Promise<Contact>
  /** <- `fetchContactTimeline` */
  timeline(id: number, page: number): Promise<ContactTimelineListResponse>
  /** <- `createContactRequest` */
  create(payload: ContactPayload): Promise<Contact>
  /** <- `updateContactRequest` */
  update(id: number, payload: Partial<ContactPayload>): Promise<Contact>
  /** <- `deleteContactRequest` */
  delete(id: number): Promise<void>
  /** <- `fetchTags` */
  tags(): Promise<ContactTag[]>
  /** <- `fetchCustomFields` (`entity_type=contacts`) */
  customFields(): Promise<ContactCustomFieldDef[]>
  /** <- `fetchCompanyOptions` (per_page=20, search) */
  companyOptions(search: string): Promise<CompanyOption[]>
  /** <- `fetchAllCompanyOptions` (per_page=100) */
  allCompanyOptions(): Promise<CompanyOption[]>
  /** <- `fetchUserOptions` */
  userOptions(): Promise<ContactUserOption[]>
}

/** `features/companies/api/companiesApi.ts` */
export interface CompaniesSource {
  /** <- `fetchCompanies` */
  list(query: CompaniesQuery): Promise<CompaniesListResponse>
  /** <- `fetchCompanyById` */
  get(id: number): Promise<Company>
  /** <- `fetchCompanyTimeline` */
  timeline(id: number, page: number): Promise<CompanyTimelineListResponse>
  /** <- `fetchCompanyContacts` (primary contact hoisted client-side) */
  contacts(id: number): Promise<ContactSummary[]>
  /** <- `createCompanyRequest` */
  create(payload: CompanyPayload): Promise<Company>
  /** <- `updateCompanyRequest` */
  update(id: number, payload: Partial<CompanyPayload>): Promise<Company>
  /** <- `deleteCompanyRequest` */
  delete(id: number): Promise<void>
  /** <- `fetchTags` */
  tags(): Promise<CompanyTag[]>
  /** <- `fetchCustomFields` (`entity_type=companies`) */
  customFields(): Promise<CompanyCustomFieldDef[]>
  /** <- `fetchUserOptions` */
  userOptions(): Promise<CompanyUserOption[]>
}

/** `features/leads/api/leadsApi.ts`. CSV import (`importApi.ts`) is a separate module, outside this surface. */
export interface LeadsSource {
  /** <- `fetchLeads` */
  list(query: LeadsQuery): Promise<LeadsListResponse>
  /** <- `fetchLead` */
  get(id: number): Promise<Lead>
  /** <- `createLeadRequest` */
  create(payload: LeadPayload): Promise<Lead>
  /** <- `updateLeadRequest` */
  update(id: number, payload: Partial<LeadPayload>): Promise<Lead>
  /** <- `deleteLeadRequest` */
  delete(id: number): Promise<void>
  /** <- `checkDuplicatesRequest` */
  checkDuplicates(input: DuplicateCheckInput): Promise<DuplicateCandidate[]>
  /** <- `convertLeadRequest` (online-only, `SYNCDESKTOP.md` §8) */
  convert(id: number, payload: ConvertLeadPayload): Promise<ConvertLeadResult>
  /** <- `assignLeadRequest` */
  assign(id: number, ownerId: number): Promise<Lead>
  /** <- `fetchTags` (per_page=100) */
  tags(): Promise<LeadTagOption[]>
  /** <- `createTagRequest` */
  createTag(payload: { name: string; color?: string }): Promise<LeadTagOption>
  /** <- `fetchCustomFields` */
  customFields(entityType: string): Promise<LeadCustomField[]>
  /** <- `fetchOwnerOptions` */
  ownerOptions(): Promise<OwnerOption[]>
}

/** `features/tasks/api/tasksApi.ts` */
export interface TasksSource {
  /** <- `fetchTasks` */
  list(query: TasksQuery): Promise<TasksListResponse>
  /** <- `fetchTasksCalendar` (no pagination, `from`/`to` required) */
  calendar(query: TasksCalendarQuery): Promise<TasksCalendarResponse>
  /** <- `fetchTask` */
  get(id: number): Promise<Task>
  /** <- `createTaskRequest` */
  create(payload: TaskPayload): Promise<Task>
  /** <- `updateTaskRequest` */
  update(id: number, payload: Partial<TaskPayload>): Promise<Task>
  /** <- `deleteTaskRequest` */
  delete(id: number): Promise<void>
  /** <- `completeTaskRequest` (also backs the quiet, optimistic `completeTaskQuiet`) */
  complete(id: number, completed: boolean): Promise<Task>
  /** <- `assignTaskRequest` */
  assign(id: number, assignedTo: number | null): Promise<Task>
  /** <- `fetchUserOptions` */
  userOptions(): Promise<TaskUserOption[]>
}

/** `features/tickets/api/ticketsApi.ts` */
export interface TicketsSource {
  /** <- `fetchTickets` */
  list(query: TicketsQuery): Promise<TicketsListResponse>
  /** <- `fetchTicketStats` */
  stats(): Promise<TicketStats>
  /** <- `fetchTicket` */
  get(id: number): Promise<Ticket>
  /** <- `createTicketRequest` */
  create(payload: TicketPayload): Promise<Ticket>
  /** <- `updateTicketRequest` — never carries `status` (`docs/SLA-DESIGN.md` §4) */
  update(id: number, payload: Partial<TicketPayload>): Promise<Ticket>
  /** <- `deleteTicketRequest` */
  delete(id: number): Promise<void>
  /** <- `changeTicketStatusRequest` — the ONLY way a ticket status changes */
  status(id: number, status: TicketStatus): Promise<Ticket>
  /** <- `assignTicketRequest` */
  assign(id: number, assignedTo: number | null): Promise<Ticket>
}

/** `features/quotes/api/quotesApi.ts`. The catalog picker (`catalogApi.ts`) is a separate module, outside this surface. */
export interface QuotesSource {
  /** <- `fetchQuotes` */
  list(query: QuotesQuery): Promise<QuotesListResponse>
  /** <- `fetchQuote` */
  get(id: number): Promise<Quote>
  /** <- `createQuoteRequest` */
  create(payload: QuotePayload): Promise<Quote>
  /** <- `updateQuoteRequest` */
  update(id: number, payload: Partial<QuotePayload>): Promise<Quote>
  /** <- `deleteQuoteRequest` */
  delete(id: number): Promise<void>
  /** <- `sendQuoteRequest` (online-only, §8) */
  send(id: number): Promise<Quote>
  /** <- `changeQuoteStatusRequest` */
  status(id: number, status: QuoteStatus, reason?: string): Promise<Quote>
  /** <- `reviseQuoteRequest` (online-only, §8) */
  revise(id: number): Promise<Quote>
  /** <- `fetchQuoteRevisionFamily` */
  revisionFamily(rootNumber: string): Promise<Quote[]>
  /**
   * <- `calculateQuote` (online-only, §8 — `docs/QUOTE-FINANCIALS.md` stays the single source;
   * the arithmetic is NOT reimplemented locally). `options` carries only the debounce/race
   * `AbortSignal`; the axios-specific config type stays inside `quotesApi.ts`.
   */
  calculate(payload: QuoteCalculatePayload, options?: RequestOptions): Promise<QuoteCalculateResult>
  /** <- `fetchQuotePdfBlob` (online-only when uncached, §8) */
  pdfBlob(id: number): Promise<Blob>
}

/** `features/activities/api/activitiesApi.ts` */
export interface ActivitiesSource {
  /** <- `fetchActivities` */
  list(query: ActivitiesQuery): Promise<ActivitiesListResponse>
  /** <- `createActivityRequest` */
  create(payload: ActivityPayload): Promise<Activity>
  /** <- `updateActivityRequest` */
  update(id: number, payload: Partial<ActivityPayload>): Promise<Activity>
  /** <- `deleteActivityRequest` */
  delete(id: number): Promise<void>
}

/** `features/chat/api.ts` — already a plain-function module; `features/chat/hooks/**` wraps it. */
export interface ChatSource {
  /** <- `fetchConversations` */
  conversations(query: ConversationsQuery): Promise<Conversation[]>
  /** <- `fetchConversation` */
  conversation(id: number): Promise<Conversation>
  /** <- `fetchChatUnreadCount` */
  unreadCount(): Promise<ChatUnreadCount>
  /** <- `createConversationRequest` */
  createConversation(payload: CreateConversationPayload): Promise<Conversation>
  /** <- `fetchOrCreateRecordConversation` (server-side GET-OR-CREATE) */
  recordConversation(conversableType: RecordConversableType, conversableId: number): Promise<Conversation>
  /** <- `renameConversationRequest` */
  renameConversation(id: number, name: string): Promise<Conversation>
  /** <- `deleteConversationRequest` */
  deleteConversation(id: number): Promise<void>
  /** <- `addConversationMembersRequest` */
  addMembers(id: number, userIds: number[]): Promise<Conversation>
  /** <- `removeConversationMemberRequest` */
  removeMember(id: number, userId: number): Promise<void>
  /** <- `leaveConversationRequest` */
  leaveConversation(id: number): Promise<void>
  /** <- `muteConversationRequest` */
  muteConversation(id: number, isMuted: boolean): Promise<Conversation>
  /** <- `markConversationReadRequest` (cumulative cursor) */
  markRead(id: number, messageId: number): Promise<ConversationCursorAck>
  /** <- `markConversationDeliveredRequest` (cumulative cursor) */
  markDelivered(id: number, messageId: number): Promise<ConversationCursorAck>
  /** <- `fetchMessages` (cursor pagination, newest page when `before` is omitted) */
  messages(conversationId: number, before?: number, perPage?: number): Promise<MessagesPage>
  /** <- `sendMessageRequest` */
  sendMessage(conversationId: number, payload: SendMessagePayload): Promise<Message>
  /** <- `editMessageRequest` */
  editMessage(messageId: number, body: string): Promise<Message>
  /** <- `deleteMessageRequest` */
  deleteMessage(messageId: number): Promise<void>
  /** <- `searchMessagesRequest` */
  searchMessages(q: string, conversationId?: number): Promise<Message[]>
  /** <- `uploadAttachmentRequest` (multipart; queued when offline, §8) */
  uploadAttachment(file: File, onProgress?: (percent: number) => void, signal?: AbortSignal): Promise<Attachment>
}

/** `features/notifications/api.ts` — already a plain-function module; `hooks/useNotifications.ts` wraps it. */
export interface NotificationsSource {
  /** <- `fetchNotifications` */
  list(query: NotificationsQuery): Promise<NotificationsListResponse>
  /** <- `fetchUnreadCount` */
  unreadCount(): Promise<number>
  /** <- `markNotificationReadRequest` */
  markRead(id: string): Promise<Notification>
  /** <- `markAllNotificationsReadRequest` */
  markAllRead(): Promise<void>
  /** <- `deleteNotificationRequest` */
  delete(id: string): Promise<void>
}

/** `features/search/api/searchApi.ts` */
export interface SearchSource {
  /** <- `fetchGlobalSearch` (`GET /api/search?q=`; server-side permission filter) */
  query(term: string): Promise<SearchResponse>
}

/** `features/products/api/productsApi.ts`. Tag/custom-field lookups (`productsShared.ts`) are a separate module, outside this surface. */
export interface ProductsSource {
  /** <- `fetchProducts` */
  list(query: ProductsQuery): Promise<ProductsListResponse>
  /** <- `fetchProduct` */
  get(id: number): Promise<Product>
  /** <- `fetchProductCategories` */
  categories(): Promise<string[]>
  /** <- `fetchProductPrice` */
  price(productId: number, priceListId: number | undefined): Promise<ResolvedProductPrice>
  /** <- `createProductRequest` */
  create(payload: ProductPayload): Promise<Product>
  /** <- `updateProductRequest` */
  update(id: number, payload: Partial<ProductPayload>): Promise<Product>
  /** <- `deleteProductRequest` */
  delete(id: number): Promise<void>
}

/** `features/price-lists/api/priceListsApi.ts` */
export interface PriceListsSource {
  /** <- `fetchPriceLists` */
  list(query: PriceListsQuery): Promise<PriceListsListResponse>
  /** <- `fetchPriceList` */
  get(id: number): Promise<PriceList>
  /** <- `fetchPriceListItems` */
  items(id: number, page: number, perPage?: number): Promise<PriceListItemsResponse>
  /** <- `createPriceListRequest` */
  create(payload: PriceListPayload): Promise<PriceList>
  /** <- `updatePriceListRequest` */
  update(id: number, payload: Partial<PriceListPayload>): Promise<PriceList>
  /** <- `deletePriceListRequest` */
  delete(id: number): Promise<void>
  /** <- `setPriceRequest` (PUT, upsert semantics) */
  setPrice(priceListId: number, productId: number, unitPrice: number): Promise<PriceListItem>
  /** <- `removePriceRequest` */
  removePrice(priceListId: number, productId: number): Promise<void>
}

/** `features/exchange/api/exchangeRatesApi.ts` */
export interface ExchangeSource {
  /** <- `fetchCurrentExchangeRates` */
  current(): Promise<ExchangeRatesCurrentResponse>
}

/** `features/saved-views/api/savedViewsApi.ts` */
export interface SavedViewsSource {
  /** <- `fetchSavedViews` */
  list(module: SavedViewModule): Promise<SavedView[]>
  /** <- `createSavedViewRequest` (online-only, §8) */
  create(payload: SavedViewPayload): Promise<SavedView>
  /** <- `updateSavedViewRequest` (online-only, §8) */
  update(id: number, payload: UpdateSavedViewPayload): Promise<SavedView>
  /** <- `deleteSavedViewRequest` */
  delete(id: number): Promise<void>
}

/** `features/users/api/usersApi.ts` — online-only in full (`SYNCDESKTOP.md` §8: `users.*`, `roles`). */
export interface UsersSource {
  /** <- `fetchUsers` */
  list(query: UsersQuery): Promise<UsersListResponse>
  /** <- `fetchUserById` */
  get(id: number): Promise<User>
  /** <- `createUserRequest` */
  create(payload: CreateUserPayload): Promise<User>
  /** <- `updateUserRequest` */
  update(id: number, payload: UpdateUserPayload): Promise<User>
  /** <- `deleteUserRequest` */
  delete(id: number): Promise<void>
  /** <- `toggleActiveRequest` */
  setActive(id: number, isActive: boolean): Promise<User>
  /** <- `resetPasswordRequest` */
  resetPassword(id: number, password: string): Promise<void>
  /** <- `fetchRoles` */
  roles(): Promise<Role[]>
}

/**
 * Field names = feature directory names. RW domains per `docs/DESKTOP-ARCHITECTURE.md` §3.3;
 * RO domains (products, price-lists, exchange, saved-views, users) per `SYNCDESKTOP.md` §4.1/§8.
 */
export interface DataSource {
  deals: DealsSource
  contacts: ContactsSource
  companies: CompaniesSource
  leads: LeadsSource
  tasks: TasksSource
  tickets: TicketsSource
  quotes: QuotesSource
  activities: ActivitiesSource
  chat: ChatSource
  notifications: NotificationsSource
  search: SearchSource
  products: ProductsSource
  priceLists: PriceListsSource
  exchange: ExchangeSource
  savedViews: SavedViewsSource
  users: UsersSource
}

/** Thin, transport-agnostic channel handle — web wraps a laravel-echo `Channel`, desktop (later) bridges `engine.handle_realtime`. */
export interface RealtimeChannel {
  listen(event: string, callback: (payload: unknown) => void): RealtimeChannel
  stopListening(event: string): RealtimeChannel
}

export interface RealtimeAdapter {
  connect(): void
  disconnect(): void
  /** Web: laravel-echo. Desktop (later): Echo(bearer) → `engine.handle_realtime` bridge (architecture §6). */
  channel(name: string): RealtimeChannel
  state(): ConnState
}

export type NotificationLevel = 'success' | 'error' | 'warning' | 'info'

export interface AppNotification {
  level: NotificationLevel
  message: string
}

export interface Platform {
  kind: PlatformKind
  http: HttpClient
  data: DataSource
  connectivity: {
    isOnline(): boolean
    subscribe(cb: (state: ConnState) => void): () => void
  }
  realtime: RealtimeAdapter
  notify(notification: AppNotification): void
  capabilities: Set<Capability>
  /**
   * `action` names the caller's action (e.g. `'leads.convert'`, per `SYNCDESKTOP.md` §8) so the
   * offline tooltip can resolve `desktop.onlineOnly.<action>` at the call site — the tooltip key
   * cannot be recovered from `fn` alone (architecture §3.3, karar S9).
   */
  onlineOnly<T>(action: string, fn: () => T): T | OnlineOnlyError
}
