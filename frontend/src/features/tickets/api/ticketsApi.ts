// Destek Talebi (Ticket) CRUD veri katmanı — liste, istatistik özeti, detay,
// oluşturma/güncelleme/silme, durum geçişi ve atama. Hata gövdesi tüm uçlarda
// `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
//
// Durum YALNIZCA `changeTicketStatusRequest` (`PATCH /api/tickets/{id}/status`) ile değişir —
// `updateTicketRequest` (`PATCH /api/tickets/{id}`) gövdesinde `status` GÖNDERİLMEZ, backend
// 422 ile reddeder (bkz. `docs/SLA-DESIGN.md` §4, `types.ts` başındaki not).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import type {
  Ticket,
  TicketPayload,
  TicketsListResponse,
  TicketsQuery,
  TicketStats,
  TicketStatus,
} from '../types'

export const ticketsKeys = {
  all: ['tickets'] as const,
  lists: ['tickets', 'list'] as const,
  list: (query: TicketsQuery) => ['tickets', 'list', query] as const,
  detail: (id: number) => ['tickets', 'detail', id] as const,
  stats: ['tickets', 'stats'] as const,
}

async function fetchTickets(query: TicketsQuery): Promise<TicketsListResponse> {
  const { data } = await api.get<TicketsListResponse>('/api/tickets', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[status]': query.status || undefined,
      'filter[priority]': query.priority || undefined,
      'filter[assigned_to]': query.assigned_to,
      'filter[company_id]': query.company_id,
      'filter[contact_id]': query.contact_id,
      'filter[category]': query.category || undefined,
      'filter[tag_id]': query.tag_id,
      'filter[sla_breached]': query.sla_breached ? 1 : undefined,
      'filter[from]': query.from || undefined,
      'filter[to]': query.to || undefined,
    },
  })
  return data
}

async function fetchTicketStats(): Promise<TicketStats> {
  const { data } = await api.get<{ data: TicketStats }>('/api/tickets/stats')
  return data.data
}

async function fetchTicket(id: number): Promise<Ticket> {
  const { data } = await api.get<{ data: Ticket }>(`/api/tickets/${id}`)
  return data.data
}

async function createTicketRequest(payload: TicketPayload): Promise<Ticket> {
  const { data } = await api.post<{ data: Ticket }>('/api/tickets', payload)
  return data.data
}

async function updateTicketRequest(id: number, payload: Partial<TicketPayload>): Promise<Ticket> {
  const { data } = await api.patch<{ data: Ticket }>(`/api/tickets/${id}`, payload)
  return data.data
}

async function deleteTicketRequest(id: number): Promise<void> {
  await api.delete(`/api/tickets/${id}`)
}

async function changeTicketStatusRequest(id: number, status: TicketStatus): Promise<Ticket> {
  const { data } = await api.patch<{ data: Ticket }>(`/api/tickets/${id}/status`, { status })
  return data.data
}

async function assignTicketRequest(id: number, assignedTo: number | null): Promise<Ticket> {
  const { data } = await api.patch<{ data: Ticket }>(`/api/tickets/${id}/assign`, { assigned_to: assignedTo })
  return data.data
}

/**
 * `refetchInterval: 60_000` — `docs/SLA-DESIGN.md` §6 istemci geri sayım kuralının 3. adımı:
 * yerel sayaç yalnızca bir TAHMİNDİR, gerçeği periyodik olarak sunucudan tazelemek gerekir
 * (uyku/sekme askıya alma sonrası sapmayı da düzeltir). `private-tickets` kanalından gelen
 * olaylar (`useTicketRealtime`) bunu olay-bazlı tamamlar; ikisi birbirinin YERİNE değil,
 * BİRLİKTE çalışır.
 */
export function useTickets(query: TicketsQuery) {
  return useQuery({
    queryKey: ticketsKeys.list(query),
    queryFn: () => fetchTickets(query),
    placeholderData: keepPreviousData,
    refetchInterval: 60_000,
  })
}

/**
 * `GET /api/tickets/stats` — filtrelerden bağımsız genel özet (toplam, duruma/önceliğe göre
 * kırılım, ihlal/risk sayısı, ortalama çözüm süresi). Listenin filtre durumundan BAĞIMSIZ ayrı
 * bir sorgudur; 60 sn'lik SLA senkron döngüsüyle aynı `staleTime`'ı taşımaz — realtime olayları
 * `useTicketRealtime` bunu ayrıca invalidate eder.
 */
export function useTicketStats() {
  return useQuery({
    queryKey: ticketsKeys.stats,
    queryFn: fetchTicketStats,
  })
}

/** Aynı 60 sn'lik SLA senkron gerekçesi — bkz. `useTickets` başındaki not. */
export function useTicket(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ticketsKeys.detail(id ?? -1),
    queryFn: () => fetchTicket(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
    refetchInterval: 60_000,
  })
}

function invalidateTicketCaches(queryClient: ReturnType<typeof useQueryClient>, id?: number) {
  void queryClient.invalidateQueries({ queryKey: ticketsKeys.lists })
  void queryClient.invalidateQueries({ queryKey: ticketsKeys.stats })
  if (id !== undefined) {
    void queryClient.invalidateQueries({ queryKey: ticketsKeys.detail(id) })
  }
}

export function useCreateTicket() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('tickets')
  return useMutation({
    mutationFn: createTicketRequest,
    onSuccess: (ticket) => {
      invalidateTicketCaches(queryClient, ticket.id)
      toast.success(t('toast.created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useUpdateTicket() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('tickets')
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<TicketPayload> }) => updateTicketRequest(id, payload),
    onSuccess: (ticket) => {
      invalidateTicketCaches(queryClient, ticket.id)
      toast.success(t('toast.updated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useDeleteTicket() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('tickets')
  return useMutation({
    mutationFn: (id: number) => deleteTicketRequest(id),
    onSuccess: () => {
      invalidateTicketCaches(queryClient)
      toast.success(t('toast.deleted'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useChangeTicketStatus() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('tickets')
  return useMutation({
    mutationFn: ({ id, status }: { id: number; status: TicketStatus }) => changeTicketStatusRequest(id, status),
    onSuccess: (ticket) => {
      invalidateTicketCaches(queryClient, ticket.id)
      toast.success(t('toast.statusUpdated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export function useAssignTicket() {
  const queryClient = useQueryClient()
  const { t } = useTranslation('tickets')
  return useMutation({
    mutationFn: ({ id, assignedTo }: { id: number; assignedTo: number | null }) => assignTicketRequest(id, assignedTo),
    onSuccess: (ticket) => {
      invalidateTicketCaches(queryClient, ticket.id)
      toast.success(t('toast.assignUpdated'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}
