// Yardımcı lookup'lar — etiketler, özel alan tanımları (`entity_type=tickets`), seçili firmaya
// göre filtrelenmiş kişi listesi, atanan/oluşturan için kullanıcı listesi ve formun ARANABİLİR
// firma combobox'ı. Desen `features/deals/components/dealsShared.ts` ile AYNIDIR (o dosya
// C şeridin/deals modülünündür, DOKUNULMAZ — burası tickets'ın kendi kopyası).
import axios from 'axios'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/axios'
import { getPlatform } from '../../../platform'
import type { CompanyOption, ContactOption, TicketCustomField, UserOption } from '../types'

/**
 * A tag as the ticket pickers consume it. `color` is `string | null` because that is what
 * `GET /api/tags` returns for an uncoloured tag; `../types` declares no `Tag` of its own.
 * Named rather than inline so `platform/types.ts` can declare `tickets.tags` against exactly
 * the shape this module always returned.
 */
export type TicketTagOption = { id: number; name: string; color: string | null }

export const ticketTagsKeys = { all: ['tags'] as const }
export const ticketCustomFieldsKeys = { tickets: ['custom-fields', 'tickets'] as const }
export const ticketContactOptionsKeys = {
  forCompany: (companyId: number | undefined, q: string) => ['tickets', 'contact-options', companyId ?? null, q] as const,
}
export const ticketCompanyOptionsSearchKeys = { search: (q: string) => ['tickets', 'company-options', 'search', q] as const }
export const ticketUserOptionsKeys = { all: ['tickets', 'user-options'] as const }

// The six fetchers below are the WEB half of `platform.data.tickets.{tags,customFields,
// contactOptions,companyOptions,allCompanyOptions,userOptions}`; `platform/web.ts` delegates to
// them by name, so each request stays defined exactly once, next to the query key it fills. The
// hooks underneath go through `getPlatform()` instead of calling them directly, which is what
// lets the desktop adapter answer the same six lookups from the local mirror (defter O42).

export async function fetchTicketTags(): Promise<TicketTagOption[]> {
  const { data } = await api.get<{ data: TicketTagOption[] }>('/api/tags')
  return data.data
}

export async function fetchTicketCustomFields(): Promise<TicketCustomField[]> {
  const { data } = await api.get<{ data: TicketCustomField[] }>('/api/custom-fields', {
    params: { entity_type: 'tickets' },
  })
  return data.data
}

export async function fetchTicketContactOptions(companyId: number | undefined, search: string): Promise<ContactOption[]> {
  const { data } = await api.get<{ data: ContactOption[] }>('/api/contacts', {
    params: {
      q: search || undefined,
      per_page: 20,
      sort: 'last_name',
      'filter[company_id]': companyId || undefined,
    },
  })
  return data.data
}

export async function fetchTicketCompanyOptionsSearch(search: string): Promise<CompanyOption[]> {
  const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', {
    params: { q: search || undefined, per_page: 20, sort: 'name' },
  })
  return data.data
}

export async function fetchTicketAllCompanyOptions(): Promise<CompanyOption[]> {
  const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', { params: { per_page: 100, sort: 'name' } })
  return data.data
}

export async function fetchTicketUserOptions(): Promise<UserOption[]> {
  const { data } = await api.get<{ data: UserOption[] }>('/api/users', { params: { per_page: 100 } })
  return data.data
}

export function useTicketTags() {
  return useQuery({
    queryKey: ticketTagsKeys.all,
    queryFn: () => getPlatform().data.tickets.tags(),
    staleTime: 5 * 60_000,
  })
}

export function useTicketCustomFields() {
  return useQuery({
    queryKey: ticketCustomFieldsKeys.tickets,
    queryFn: () => getPlatform().data.tickets.customFields(),
    staleTime: 5 * 60_000,
  })
}

export function useTicketContactOptions(companyId: number | undefined, search = '', options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ticketContactOptionsKeys.forCompany(companyId, search),
    queryFn: () => getPlatform().data.tickets.contactOptions(companyId, search),
    enabled: options?.enabled ?? true,
  })
}

/** Form içindeki ARANABİLİR firma combobox'ı için. */
export function useTicketCompanyOptionsSearch(search: string, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ticketCompanyOptionsSearchKeys.search(search),
    queryFn: () => getPlatform().data.tickets.companyOptions(search),
    enabled: options?.enabled ?? true,
  })
}

/**
 * Sabit (arama yapmayan) firma listesi — liste sayfasındaki filtre Select'i için. Formdaki
 * aranabilir combobox `useTicketCompanyOptionsSearch`'ü kullanır (farklı query key, aynı uçtan
 * beslenir).
 */
export function useTicketCompanyOptions() {
  const query = useQuery({
    queryKey: ['tickets', 'company-options', 'all'] as const,
    queryFn: () => getPlatform().data.tickets.allCompanyOptions(),
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}

/** Atanan/filtre için kullanıcı listesi. `users.view` ister — 403 → `isForbidden` (çağıran taraf
 * filtreyi/alanı GİZLER, bkz. görev tanımı). */
export function useTicketUserOptions() {
  const query = useQuery({
    queryKey: ticketUserOptionsKeys.all,
    queryFn: () => getPlatform().data.tickets.userOptions(),
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}
