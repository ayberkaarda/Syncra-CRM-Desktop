// Web platform adapter — `SYNCDESKTOP.md` §7.1, `docs/DESKTOP-ARCHITECTURE.md` §3.4.
// Pure delegation to the existing web stack: no new behavior. Every member forwards to the
// module that already implements it today, so the web build's runtime behavior stays
// byte-identical once `platform/` is wired into an entry point (not done in this phase — nothing
// currently imports this file; see `index.ts`).
import type { AxiosRequestConfig } from 'axios'
import { api, configureHttp } from '../lib/axios'
import {
  configureRealtimeAuth,
  connectEcho,
  defaultReverbAuthorizer,
  disconnectEcho,
  getConnectionState,
  getEcho,
} from '../lib/echo'
import { toast } from '../components/ui'
import * as deals from '../features/deals/api/dealsApi'
import * as contacts from '../features/contacts/api/contactsApi'
import * as companies from '../features/companies/api/companiesApi'
import * as leads from '../features/leads/api/leadsApi'
import * as tasks from '../features/tasks/api/tasksApi'
import * as tickets from '../features/tickets/api/ticketsApi'
import * as quotes from '../features/quotes/api/quotesApi'
import * as activities from '../features/activities/api/activitiesApi'
import * as chat from '../features/chat/api'
import * as notifications from '../features/notifications/api'
import * as search from '../features/search/api/searchApi'
import * as products from '../features/products/api/productsApi'
import * as priceLists from '../features/price-lists/api/priceListsApi'
import * as exchange from '../features/exchange/api/exchangeRatesApi'
import * as savedViews from '../features/saved-views/api/savedViewsApi'
import * as users from '../features/users/api/usersApi'
import type {
  BoardFilters,
  BoardResponse,
  DealCard,
  MoveDealPayload,
  PipelineStage,
} from '../features/deals/types'
import type { AppNotification, ConnState, Platform, RealtimeChannel } from './types'

// `platform/web.ts` is the declared single source for the API base URL and Reverb connection
// params (`SYNCDESKTOP.md` §7.1) — both `lib/axios.ts` and `lib/echo.ts` already default to these
// exact values, so this only matters once a desktop adapter calls the same functions with
// bearer/desktop values.
configureHttp({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  transport: 'cookie',
})
configureRealtimeAuth({
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT),
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
  authorizer: defaultReverbAuthorizer,
})

const http: Platform['http'] = {
  get: async (url, config) => (await api.get(url, config as AxiosRequestConfig | undefined)).data,
  post: async (url, body, config) => (await api.post(url, body, config as AxiosRequestConfig | undefined)).data,
  put: async (url, body, config) => (await api.put(url, body, config as AxiosRequestConfig | undefined)).data,
  patch: async (url, body, config) => (await api.patch(url, body, config as AxiosRequestConfig | undefined)).data,
  delete: async (url, config) => (await api.delete(url, config as AxiosRequestConfig | undefined)).data,
}

// ------------------------------------------------------------------------------------------------
// The kanban board is the one group with no plain function to delegate to.
//
// `boardApi.ts` now reads through `getPlatform().data.deals.board/move/stages`, so a delegation
// back into that module would recurse. The requests were therefore MOVED here verbatim — same
// endpoints, same query parameter names (`per_stage`, `filter[...]`), same response unwrapping
// — and nothing about the web's behaviour changes.
//
// `filters.q`/`from`/`to` keep their `|| undefined`: axios omits an `undefined` param but sends
// an empty string, and `filter[q]=` is a `''` the backend would validate as a real filter.
// ------------------------------------------------------------------------------------------------
async function fetchBoard(filters: BoardFilters): Promise<BoardResponse> {
  const { data } = await api.get<BoardResponse>('/api/deals/board', {
    params: {
      per_stage: filters.per_stage,
      'filter[q]': filters.q || undefined,
      'filter[owner_id]': filters.owner_id,
      'filter[company_id]': filters.company_id,
      'filter[from]': filters.from || undefined,
      'filter[to]': filters.to || undefined,
    },
  })
  return data
}

async function moveDeal(dealId: number, payload: MoveDealPayload): Promise<DealCard> {
  const { data } = await api.patch<{ data: DealCard }>(`/api/deals/${dealId}/move`, payload)
  return data.data
}

/**
 * No `include_inactive` param, exactly as before: on this route the backend defaults it to
 * FALSE, so the response is the active stages in `position` order. Sending `include_inactive=0`
 * would be equivalent but is a different request, so it is not sent.
 */
async function fetchPipelineStages(): Promise<PipelineStage[]> {
  const { data } = await api.get<{ data: PipelineStage[] }>('/api/pipeline-stages')
  return data.data
}

// ------------------------------------------------------------------------------------------------
// DataSource — the web implementation is pure delegation to the plain functions each feature's api
// module already exports (`docs/DESKTOP-ARCHITECTURE.md` §3.4 / KARAR A19). Every member is an
// arrow wrapper rather than a bare function reference on purpose: the shared hooks now import
// `getPlatform()` from `platform/index.ts`, which imports this file, which imports the api modules
// back — a legitimate ES-module cycle. Resolving `<ns>.<fn>` at CALL time instead of at
// module-evaluation time keeps that cycle harmless no matter which module the bundler happens to
// evaluate first.
// ------------------------------------------------------------------------------------------------
const data: Platform['data'] = {
  deals: {
    list: (query) => deals.fetchDeals(query),
    get: (id) => deals.fetchDeal(id),
    create: (payload) => deals.createDealRequest(payload),
    update: (id, payload) => deals.updateDealRequest(id, payload),
    delete: (id) => deals.deleteDealRequest(id),
    assign: (id, ownerId) => deals.assignDealRequest(id, ownerId),
    board: (filters) => fetchBoard(filters),
    move: (id, payload) => moveDeal(id, payload),
    stages: () => fetchPipelineStages(),
    // The SAME plain function `leads.ownerOptions` delegates to, deliberately shared rather
    // than copied: both verbs are `GET /api/users?per_page=100`, and a second local fetcher
    // would be a second place for that request to drift.
    ownerOptions: () => leads.fetchOwnerOptions(),
  },
  contacts: {
    list: (query) => contacts.fetchContacts(query),
    get: (id) => contacts.fetchContactById(id),
    timeline: (id, page) => contacts.fetchContactTimeline(id, page),
    create: (payload) => contacts.createContactRequest(payload),
    update: (id, payload) => contacts.updateContactRequest(id, payload),
    delete: (id) => contacts.deleteContactRequest(id),
    tags: () => contacts.fetchTags(),
    customFields: () => contacts.fetchCustomFields(),
    companyOptions: (search) => contacts.fetchCompanyOptions(search),
    allCompanyOptions: () => contacts.fetchAllCompanyOptions(),
    userOptions: () => contacts.fetchUserOptions(),
  },
  companies: {
    list: (query) => companies.fetchCompanies(query),
    get: (id) => companies.fetchCompanyById(id),
    timeline: (id, page) => companies.fetchCompanyTimeline(id, page),
    contacts: (id) => companies.fetchCompanyContacts(id),
    create: (payload) => companies.createCompanyRequest(payload),
    update: (id, payload) => companies.updateCompanyRequest(id, payload),
    delete: (id) => companies.deleteCompanyRequest(id),
    tags: () => companies.fetchTags(),
    customFields: () => companies.fetchCustomFields(),
    userOptions: () => companies.fetchUserOptions(),
  },
  leads: {
    list: (query) => leads.fetchLeads(query),
    get: (id) => leads.fetchLead(id),
    create: (payload) => leads.createLeadRequest(payload),
    update: (id, payload) => leads.updateLeadRequest(id, payload),
    delete: (id) => leads.deleteLeadRequest(id),
    checkDuplicates: (input) => leads.checkDuplicatesRequest(input),
    convert: (id, payload) => leads.convertLeadRequest(id, payload),
    assign: (id, ownerId) => leads.assignLeadRequest(id, ownerId),
    tags: () => leads.fetchTags(),
    createTag: (payload) => leads.createTagRequest(payload),
    customFields: (entityType) => leads.fetchCustomFields(entityType),
    ownerOptions: () => leads.fetchOwnerOptions(),
  },
  tasks: {
    list: (query) => tasks.fetchTasks(query),
    calendar: (query) => tasks.fetchTasksCalendar(query),
    get: (id) => tasks.fetchTask(id),
    create: (payload) => tasks.createTaskRequest(payload),
    update: (id, payload) => tasks.updateTaskRequest(id, payload),
    delete: (id) => tasks.deleteTaskRequest(id),
    complete: (id, completed) => tasks.completeTaskRequest(id, completed),
    assign: (id, assignedTo) => tasks.assignTaskRequest(id, assignedTo),
    userOptions: () => tasks.fetchUserOptions(),
  },
  tickets: {
    list: (query) => tickets.fetchTickets(query),
    stats: () => tickets.fetchTicketStats(),
    get: (id) => tickets.fetchTicket(id),
    create: (payload) => tickets.createTicketRequest(payload),
    update: (id, payload) => tickets.updateTicketRequest(id, payload),
    delete: (id) => tickets.deleteTicketRequest(id),
    status: (id, status) => tickets.changeTicketStatusRequest(id, status),
    assign: (id, assignedTo) => tickets.assignTicketRequest(id, assignedTo),
  },
  quotes: {
    list: (query) => quotes.fetchQuotes(query),
    get: (id) => quotes.fetchQuote(id),
    create: (payload) => quotes.createQuoteRequest(payload),
    update: (id, payload) => quotes.updateQuoteRequest(id, payload),
    delete: (id) => quotes.deleteQuoteRequest(id),
    send: (id) => quotes.sendQuoteRequest(id),
    status: (id, status, reason) => quotes.changeQuoteStatusRequest(id, status, reason),
    revise: (id) => quotes.reviseQuoteRequest(id),
    revisionFamily: (rootNumber) => quotes.fetchQuoteRevisionFamily(rootNumber),
    // `RequestOptions` carries only the debounce/race `AbortSignal`; widening it to the axios
    // config type here keeps `AxiosRequestConfig` out of the platform contract.
    calculate: (payload, options) => quotes.calculateQuote(payload, options),
    pdfBlob: (id) => quotes.fetchQuotePdfBlob(id),
  },
  activities: {
    list: (query) => activities.fetchActivities(query),
    create: (payload) => activities.createActivityRequest(payload),
    update: (id, payload) => activities.updateActivityRequest(id, payload),
    delete: (id) => activities.deleteActivityRequest(id),
  },
  chat: {
    conversations: (query) => chat.fetchConversations(query),
    conversation: (id) => chat.fetchConversation(id),
    unreadCount: () => chat.fetchChatUnreadCount(),
    createConversation: (payload) => chat.createConversationRequest(payload),
    recordConversation: (conversableType, conversableId) =>
      chat.fetchOrCreateRecordConversation(conversableType, conversableId),
    renameConversation: (id, name) => chat.renameConversationRequest(id, name),
    deleteConversation: (id) => chat.deleteConversationRequest(id),
    addMembers: (id, userIds) => chat.addConversationMembersRequest(id, userIds),
    removeMember: (id, userId) => chat.removeConversationMemberRequest(id, userId),
    leaveConversation: (id) => chat.leaveConversationRequest(id),
    muteConversation: (id, isMuted) => chat.muteConversationRequest(id, isMuted),
    markRead: (id, messageId) => chat.markConversationReadRequest(id, messageId),
    markDelivered: (id, messageId) => chat.markConversationDeliveredRequest(id, messageId),
    messages: (conversationId, before, perPage) => chat.fetchMessages(conversationId, before, perPage),
    sendMessage: (conversationId, payload) => chat.sendMessageRequest(conversationId, payload),
    editMessage: (messageId, body) => chat.editMessageRequest(messageId, body),
    deleteMessage: (messageId) => chat.deleteMessageRequest(messageId),
    searchMessages: (q, conversationId) => chat.searchMessagesRequest(q, conversationId),
    uploadAttachment: (file, onProgress, signal) => chat.uploadAttachmentRequest(file, onProgress, signal),
  },
  notifications: {
    list: (query) => notifications.fetchNotifications(query),
    unreadCount: () => notifications.fetchUnreadCount(),
    markRead: (id) => notifications.markNotificationReadRequest(id),
    markAllRead: () => notifications.markAllNotificationsReadRequest(),
    delete: (id) => notifications.deleteNotificationRequest(id),
  },
  search: {
    // One index, so no source label — and note HOW that is expressed: not by a branch, but by
    // this call staying exactly what it was. `search_source` (`WithSearchSource`, types.ts) is
    // an optional field that only a platform with two indexes fills; the API body has no such
    // field, `fetchGlobalSearch` returns it untouched, and `CommandPalette` renders no label
    // for the `undefined` it therefore reads. The desktop adapter unifies local FTS with this
    // same endpoint and tags both halves (`desktop/src/platform/data/comms.ts`, SYNCDESKTOP.md
    // §7.2) — the SyncStateBadge pairing, applied to search (KARAR A19).
    query: (term) => search.fetchGlobalSearch(term),
  },
  products: {
    list: (query) => products.fetchProducts(query),
    get: (id) => products.fetchProduct(id),
    categories: () => products.fetchProductCategories(),
    price: (productId, priceListId) => products.fetchProductPrice(productId, priceListId),
    create: (payload) => products.createProductRequest(payload),
    update: (id, payload) => products.updateProductRequest(id, payload),
    delete: (id) => products.deleteProductRequest(id),
  },
  priceLists: {
    list: (query) => priceLists.fetchPriceLists(query),
    get: (id) => priceLists.fetchPriceList(id),
    items: (id, page, perPage) => priceLists.fetchPriceListItems(id, page, perPage),
    create: (payload) => priceLists.createPriceListRequest(payload),
    update: (id, payload) => priceLists.updatePriceListRequest(id, payload),
    delete: (id) => priceLists.deletePriceListRequest(id),
    setPrice: (priceListId, productId, unitPrice) => priceLists.setPriceRequest(priceListId, productId, unitPrice),
    removePrice: (priceListId, productId) => priceLists.removePriceRequest(priceListId, productId),
  },
  exchange: {
    current: () => exchange.fetchCurrentExchangeRates(),
  },
  savedViews: {
    list: (module) => savedViews.fetchSavedViews(module),
    create: (payload) => savedViews.createSavedViewRequest(payload),
    update: (id, payload) => savedViews.updateSavedViewRequest(id, payload),
    delete: (id) => savedViews.deleteSavedViewRequest(id),
  },
  users: {
    list: (query) => users.fetchUsers(query),
    get: (id) => users.fetchUserById(id),
    create: (payload) => users.createUserRequest(payload),
    update: (id, payload) => users.updateUserRequest(id, payload),
    delete: (id) => users.deleteUserRequest(id),
    setActive: (id, isActive) => users.toggleActiveRequest(id, isActive),
    resetPassword: (id, password) => users.resetPasswordRequest(id, password),
    roles: () => users.fetchRoles(),
  },
}

const connectivity: Platform['connectivity'] = {
  isOnline: () => typeof navigator === 'undefined' || navigator.onLine,
  subscribe(cb) {
    if (typeof window === 'undefined') return () => {}
    const onOnline = () => cb('online')
    const onOffline = () => cb('offline')
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
    return () => {
      window.removeEventListener('online', onOnline)
      window.removeEventListener('offline', onOffline)
    }
  },
}

function wrapChannel(name: string): RealtimeChannel {
  const echoChannel = getEcho()?.channel(name)
  const wrapped: RealtimeChannel = {
    listen(event, callback) {
      echoChannel?.listen(event, callback)
      return wrapped
    },
    stopListening(event) {
      echoChannel?.stopListening(event)
      return wrapped
    },
  }
  return wrapped
}

const realtime: Platform['realtime'] = {
  connect: () => void connectEcho(),
  disconnect: disconnectEcho,
  channel: wrapChannel,
  state: (): ConnState => (getConnectionState() === 'connected' ? 'online' : 'offline'),
}

function notify({ level, message }: AppNotification): void {
  toast[level](message)
}

// Web is always online-only by definition (SYNCDESKTOP.md §7.1/§3.4) — `fn` runs unconditionally,
// `action` is unused here but part of the shared signature (karar S9).
function onlineOnly<T>(_action: string, fn: () => T): T {
  return fn()
}

export const webPlatform: Platform = {
  kind: 'web',
  http,
  data,
  connectivity,
  realtime,
  notify,
  capabilities: new Set(),
  onlineOnly,
}
