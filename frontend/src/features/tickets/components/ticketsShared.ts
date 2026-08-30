// Yardımcı lookup'lar — etiketler, özel alan tanımları (`entity_type=tickets`), seçili firmaya
// göre filtrelenmiş kişi listesi, atanan/oluşturan için kullanıcı listesi ve formun ARANABİLİR
// firma combobox'ı. Desen `features/deals/components/dealsShared.ts` ile AYNIDIR (o dosya
// C şeridin/deals modülünündür, DOKUNULMAZ — burası tickets'ın kendi kopyası).
import axios from 'axios'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../../lib/axios'
import type { CompanyOption, ContactOption, TicketCustomField, UserOption } from '../types'

export const ticketTagsKeys = { all: ['tags'] as const }
export const ticketCustomFieldsKeys = { tickets: ['custom-fields', 'tickets'] as const }
export const ticketContactOptionsKeys = {
  forCompany: (companyId: number | undefined, q: string) => ['tickets', 'contact-options', companyId ?? null, q] as const,
}
export const ticketCompanyOptionsSearchKeys = { search: (q: string) => ['tickets', 'company-options', 'search', q] as const }
export const ticketUserOptionsKeys = { all: ['tickets', 'user-options'] as const }

async function fetchTicketTags(): Promise<{ id: number; name: string; color: string | null }[]> {
  const { data } = await api.get<{ data: { id: number; name: string; color: string | null }[] }>('/api/tags')
  return data.data
}

async function fetchTicketCustomFields(): Promise<TicketCustomField[]> {
  const { data } = await api.get<{ data: TicketCustomField[] }>('/api/custom-fields', {
    params: { entity_type: 'tickets' },
  })
  return data.data
}

async function fetchTicketContactOptions(companyId: number | undefined, search: string): Promise<ContactOption[]> {
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

async function fetchTicketCompanyOptionsSearch(search: string): Promise<CompanyOption[]> {
  const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', {
    params: { q: search || undefined, per_page: 20, sort: 'name' },
  })
  return data.data
}

async function fetchTicketUserOptions(): Promise<UserOption[]> {
  const { data } = await api.get<{ data: UserOption[] }>('/api/users', { params: { per_page: 100 } })
  return data.data
}

export function useTicketTags() {
  return useQuery({
    queryKey: ticketTagsKeys.all,
    queryFn: fetchTicketTags,
    staleTime: 5 * 60_000,
  })
}

export function useTicketCustomFields() {
  return useQuery({
    queryKey: ticketCustomFieldsKeys.tickets,
    queryFn: fetchTicketCustomFields,
    staleTime: 5 * 60_000,
  })
}

export function useTicketContactOptions(companyId: number | undefined, search = '', options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ticketContactOptionsKeys.forCompany(companyId, search),
    queryFn: () => fetchTicketContactOptions(companyId, search),
    enabled: options?.enabled ?? true,
  })
}

/** Form içindeki ARANABİLİR firma combobox'ı için. */
export function useTicketCompanyOptionsSearch(search: string, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: ticketCompanyOptionsSearchKeys.search(search),
    queryFn: () => fetchTicketCompanyOptionsSearch(search),
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
    queryFn: async () => {
      const { data } = await api.get<{ data: CompanyOption[] }>('/api/companies', { params: { per_page: 100, sort: 'name' } })
      return data.data
    },
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
    queryFn: fetchTicketUserOptions,
    staleTime: 300_000,
    retry: false,
  })
  const isForbidden = axios.isAxiosError(query.error) && query.error.response?.status === 403
  return { ...query, isForbidden }
}
