// Teklif CRUD + durum/gönderim/revizyon veri katmanı.
//
// Hata gövdesi tüm uçlarda `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
// Bilinen hata kodları (UI bunlara özel davranır): `QUOTE_HAS_NO_ITEMS` (gönder), `QUOTE_LOCKED`
// (gönderilmiş teklifte kalem/indirim/firma/kişi değişikliği), `QUOTE_NOT_REVISABLE` (draft/accepted
// revize), `INVALID_STATUS_TRANSITION` (durum geçişi), `QUOTE_DISCOUNT_EXCEEDS_SUBTOTAL` (calculate).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios, { type AxiosRequestConfig } from 'axios'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import i18n from '../../../i18n'
import { onlineOnlyMessage } from '../../../components/shared/onlineOnlyMessage'
import { getPlatform } from '../../../platform'
import type {
  DiscountType,
  Quote,
  QuoteCalculateResult,
  QuoteItemInput,
  QuotePayload,
  QuoteStatus,
  QuotesListResponse,
  QuotesQuery,
} from '../types'

export const quotesKeys = {
  all: ['quotes'] as const,
  lists: ['quotes', 'list'] as const,
  list: (query: QuotesQuery) => ['quotes', 'list', query] as const,
  detail: (id: number) => ['quotes', 'detail', id] as const,
}

export async function fetchQuotes(query: QuotesQuery): Promise<QuotesListResponse> {
  const { data } = await api.get<QuotesListResponse>('/api/quotes', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[status]': query.status || undefined,
      'filter[deal_id]': query.deal_id,
      'filter[company_id]': query.company_id,
      'filter[contact_id]': query.contact_id,
      'filter[from]': query.from || undefined,
      'filter[to]': query.to || undefined,
      'filter[expired]': query.expired === undefined ? undefined : query.expired ? '1' : '0',
    },
  })
  return data
}

export async function fetchQuote(id: number): Promise<Quote> {
  const { data } = await api.get<{ data: Quote }>(`/api/quotes/${id}`)
  return data.data
}

export async function createQuoteRequest(payload: QuotePayload): Promise<Quote> {
  const { data } = await api.post<{ data: Quote }>('/api/quotes', payload)
  return data.data
}

export async function updateQuoteRequest(id: number, payload: Partial<QuotePayload>): Promise<Quote> {
  const { data } = await api.patch<{ data: Quote }>(`/api/quotes/${id}`, payload)
  return data.data
}

export async function deleteQuoteRequest(id: number): Promise<void> {
  await api.delete(`/api/quotes/${id}`)
}

export async function sendQuoteRequest(id: number): Promise<Quote> {
  const { data } = await api.post<{ data: Quote }>(`/api/quotes/${id}/send`)
  return data.data
}

export async function changeQuoteStatusRequest(id: number, status: QuoteStatus, reason?: string): Promise<Quote> {
  const { data } = await api.patch<{ data: Quote }>(`/api/quotes/${id}/status`, { status, reason: reason || undefined })
  return data.data
}

export async function reviseQuoteRequest(id: number): Promise<Quote> {
  // 200 döner (201 DEĞİL): parent'ın zaten bir draft revizyonu varsa yeni kayıt açmadan
  // mevcut olanı döndürür (sözleşme §6).
  const { data } = await api.post<{ data: Quote }>(`/api/quotes/${id}/revise`)
  return data.data
}

export type QuoteCalculatePayload = {
  items: QuoteItemInput[]
  discount_type: DiscountType
  discount_value: number
}

/**
 * `POST /api/quotes/calculate` — KALICI OLMAYAN hesap ucu, hiçbir şey kaydetmez.
 * `config` yalnızca debounce/yarış-durumu çözümü için `AbortController.signal` taşımak amacıyla
 * dışarıdan alınır (bkz. `hooks/useQuoteCalculate.ts`).
 */
export async function calculateQuote(
  payload: QuoteCalculatePayload,
  config?: AxiosRequestConfig,
): Promise<QuoteCalculateResult> {
  const { data } = await api.post<{ data: QuoteCalculateResult }>('/api/quotes/calculate', payload, config)
  return data.data
}

/** `GET /api/quotes/{id}/pdf` tam URL'i — cookie tabanlı auth, doğrudan `<iframe src>`/indirme ile kullanılır. */
export function buildQuotePdfUrl(id: number): string {
  const base = api.defaults.baseURL ?? ''
  return `${base}/api/quotes/${id}/pdf`
}

/**
 * Teklif PDF'ini BAYT olarak indirir — `QuoteDetailPage`'in "PDF Önizleme" `<iframe>`'i için
 * kullanılır (bkz. `hooks/useQuotePdfPreview.ts`). Backend'deki `SecurityHeaders` middleware'i
 * artık HER yanıtta `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` gönderiyor (clickjacking
 * koruması — KASITLI, KALDIRILMAYACAK). SPA (`:5173`) ile API (`:8000`) FARKLI origin'de olduğundan
 * `<iframe src="http://localhost:8000/...">` doğrudan çerçevelenemez; bu yüzden PDF mevcut axios
 * örneği (oturum çerezi + `X-XSRF-TOKEN` taşıyarak) ile indirilip `URL.createObjectURL()` ile
 * SAME-ORIGIN bir `blob:` URL'e çevrilir — tarayıcı `blob:` URL'lerini `X-Frame-Options`
 * denetiminden hiç geçirmez. `responseType: 'blob'`: gövde ikili PDF verisi, JSON değil.
 */
export async function fetchQuotePdfBlob(id: number): Promise<Blob> {
  const { data } = await api.get<Blob>(`/api/quotes/${id}/pdf`, { responseType: 'blob' })
  return data
}

/**
 * `responseType: 'blob'` isteklerinde axios BAŞARISIZ yanıtı da (403/404/500…) blob olarak
 * bırakır — `error.response.data` bir `Blob`'tur, `getErrorMessage()`'ın beklediği ayrıştırılmış
 * `{ errors: { message } }` nesnesi DEĞİL. Bu yüzden PDF indirme hataları için ayrı bir çözücü:
 * blob'u metne çevirip JSON ayrıştırmayı DENER (backend'in standart hata gövdesi budur), olmazsa
 * (ağ hatası, boş gövde, CORS vb.) genel `getErrorMessage` fallback'ine düşer.
 */
export async function getQuotePdfErrorMessage(error: unknown): Promise<string> {
  if (axios.isAxiosError(error) && error.response?.data instanceof Blob) {
    try {
      const text = await error.response.data.text()
      const body = JSON.parse(text) as { errors?: { message?: string } }
      if (body.errors?.message) return body.errors.message
    } catch {
      // Gövde JSON değil (ör. boş/HTML) — aşağıdaki genel fallback'e düş.
    }
  }
  return getErrorMessage(error)
}

function invalidateQuoteCaches(queryClient: ReturnType<typeof useQueryClient>, id?: number) {
  void queryClient.invalidateQueries({ queryKey: quotesKeys.lists })
  if (id !== undefined) {
    void queryClient.invalidateQueries({ queryKey: quotesKeys.detail(id) })
  }
}

export function useQuotes(query: QuotesQuery) {
  return useQuery({
    queryKey: quotesKeys.list(query),
    queryFn: () => getPlatform().data.quotes.list(query),
    placeholderData: keepPreviousData,
  })
}

export function useQuote(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: quotesKeys.detail(id ?? -1),
    queryFn: () => getPlatform().data.quotes.get(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

/** Revizyon şeridinde ebeveyn numarasını göstermek için — bkz. `QuoteDetailPage`. */
export function useParentQuote(parentId: number | null | undefined) {
  return useQuery({
    queryKey: quotesKeys.detail(parentId ?? -1),
    queryFn: () => getPlatform().data.quotes.get(parentId as number),
    enabled: parentId !== null && parentId !== undefined,
    staleTime: 60_000,
  })
}

/**
 * "Bu teklifin revizyonları" — `parent_quote_id` için LİSTE UCUNDA filtre YOK
 * (`IndexQuoteRequest::rules()`: status/deal_id/company_id/contact_id/from/to/expired, parent
 * yok). Bunun yerine `quote_number` KÖK numarasıyla (`-R2`/`-R3` soneki atılmış hâli) `q`
 * araması yapılır — sözleşme §6: bir kökün TÜM revizyonları `QTE-000007`, `QTE-000007-R2`, ...
 * örüntüsünü paylaşır ve backend `q`'yu `quote_number LIKE %term%` ile eşleştirir (bkz.
 * `QuoteRepository::applyFilters`). Sonuç istemcide kökle TAM eşleşenlere daraltılır (LIKE
 * yanlışlıkla `QTE-0000070` gibi farklı bir kökü de eşleştirebilir).
 */
function rootQuoteNumber(quoteNumber: string): string {
  return quoteNumber.replace(/-R\d+$/, '')
}

export async function fetchQuoteRevisionFamily(rootNumber: string): Promise<Quote[]> {
  const { data } = await api.get<QuotesListResponse>('/api/quotes', {
    params: { q: rootNumber, per_page: 50, sort: 'quote_number' },
  })
  return data.data.filter(
    (q) => q.quote_number === rootNumber || q.quote_number.startsWith(`${rootNumber}-R`),
  )
}

/** Verilen teklifle AYNI kökü paylaşan tüm revizyonlar (kendisi dahil) — çağıran taraf kendi id'sini eler. */
export function useQuoteRevisionFamily(quote: Quote | undefined) {
  const rootNumber = quote ? rootQuoteNumber(quote.quote_number) : null
  return useQuery({
    queryKey: ['quotes', 'revision-family', rootNumber],
    queryFn: () => getPlatform().data.quotes.revisionFamily(rootNumber as string),
    enabled: rootNumber !== null,
    staleTime: 30_000,
  })
}

export function useCreateQuote() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: QuotePayload) => getPlatform().data.quotes.create(payload),
    onSuccess: (quote) => {
      invalidateQuoteCaches(queryClient, quote.id)
      toast.success(i18n.t('quotes:toast.created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateQuote() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<QuotePayload> }) => getPlatform().data.quotes.update(id, payload),
    onSuccess: (quote) => {
      invalidateQuoteCaches(queryClient, quote.id)
      toast.success(i18n.t('quotes:toast.updated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useDeleteQuote() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => getPlatform().data.quotes.delete(id),
    onSuccess: () => {
      invalidateQuoteCaches(queryClient)
      toast.success(i18n.t('quotes:toast.deleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useSendQuote() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => getPlatform().data.quotes.send(id),
    onSuccess: (quote) => {
      invalidateQuoteCaches(queryClient, quote.id)
      toast.success(i18n.t('quotes:toast.sent'))
    },
    onError: (error) => toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error)),
  })
}

export function useChangeQuoteStatus() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, status, reason }: { id: number; status: QuoteStatus; reason?: string }) =>
      getPlatform().data.quotes.status(id, status, reason),
    onSuccess: (quote) => {
      invalidateQuoteCaches(queryClient, quote.id)
      toast.success(i18n.t('quotes:toast.statusChanged'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useReviseQuote() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => getPlatform().data.quotes.revise(id),
    onSuccess: (revised, originalId) => {
      invalidateQuoteCaches(queryClient, revised.id)
      invalidateQuoteCaches(queryClient, originalId)
      toast.success(i18n.t('quotes:toast.revised', { number: revised.quote_number }))
    },
    onError: (error) => toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error)),
  })
}
