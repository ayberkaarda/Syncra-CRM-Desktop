// Kullanıcı Yönetimi API katmanı — backend sözleşmesi görev tanımında belirtildi.
// Hata gövdesi tüm uçlarda: `{ errors: { message, code, fields? } }` (bkz. `lib/axios.ts`).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getErrorMessage } from '../../../lib/axios'
import { toast } from '../../../components/ui'
import i18n from '../../../i18n'
import { onlineOnlyMessage } from '../../../components/shared/onlineOnlyMessage'
import type { Role, User, UsersQuery } from '../types'
import { getPlatform } from '../../../platform'

type Pagination = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type UsersListResponse = {
  data: User[]
  meta: { pagination: Pagination }
}

export const usersKeys = {
  all: ['users'] as const,
  list: (query: UsersQuery) => ['users', 'list', query] as const,
  detail: (id: number) => ['users', 'detail', id] as const,
}

export const rolesKeys = {
  all: ['roles'] as const,
}

export async function fetchUsers(query: UsersQuery): Promise<UsersListResponse> {
  const { data } = await api.get<UsersListResponse>('/api/users', {
    params: {
      page: query.page,
      per_page: query.per_page,
      sort: query.sort || undefined,
      q: query.q || undefined,
      'filter[role]': query.role || undefined,
      'filter[is_active]': query.is_active,
    },
  })
  return data
}

export async function fetchUserById(id: number): Promise<User> {
  const { data } = await api.get<{ data: User }>(`/api/users/${id}`)
  return data.data
}

export type CreateUserPayload = {
  name: string
  email: string
  password: string
  role: string
  department?: string
}

export async function createUserRequest(payload: CreateUserPayload): Promise<User> {
  const { data } = await api.post<{ data: User }>('/api/users', payload)
  return data.data
}

export type UpdateUserPayload = {
  name?: string
  email?: string
  role?: string
  department?: string
}

export async function updateUserRequest(id: number, payload: UpdateUserPayload): Promise<User> {
  const { data } = await api.patch<{ data: User }>(`/api/users/${id}`, payload)
  return data.data
}

export async function deleteUserRequest(id: number): Promise<void> {
  await api.delete(`/api/users/${id}`)
}

export async function toggleActiveRequest(id: number, is_active: boolean): Promise<User> {
  const { data } = await api.patch<{ data: User }>(`/api/users/${id}/active`, { is_active })
  return data.data
}

export async function resetPasswordRequest(id: number, password: string): Promise<void> {
  await api.post(`/api/users/${id}/reset-password`, { password })
}

export async function fetchRoles(): Promise<Role[]> {
  const { data } = await api.get<{ data: Role[] }>('/api/roles')
  return data.data
}

/** Server-side sayfalama/sıralama/arama/filtreleme destekli kullanıcı listesi. */
export function useUsers(query: UsersQuery) {
  return useQuery({
    queryKey: usersKeys.list(query),
    queryFn: () => getPlatform().data.users.list(query),
    // Filtre/sayfa değişirken tablo boşalıp titremesin diye önceki veri korunur.
    placeholderData: keepPreviousData,
  })
}

export function useUser(id: number | undefined, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: usersKeys.detail(id ?? -1),
    queryFn: () => getPlatform().data.users.get(id as number),
    enabled: (options?.enabled ?? true) && id !== undefined,
  })
}

export function useRoles() {
  return useQuery({
    queryKey: rolesKeys.all,
    queryFn: () => getPlatform().data.users.roles(),
    staleTime: 5 * 60_000,
  })
}

export function useCreateUser() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: CreateUserPayload) => getPlatform().data.users.create(payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: usersKeys.all })
      toast.success(i18n.t('users:toast.created'))
    },
    onError: (error) => {
      toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error))
    },
  })
}

export function useUpdateUser() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: UpdateUserPayload }) => getPlatform().data.users.update(id, payload),
    onSuccess: (updatedUser) => {
      void queryClient.invalidateQueries({ queryKey: usersKeys.all })
      void queryClient.invalidateQueries({ queryKey: usersKeys.detail(updatedUser.id) })
      toast.success(i18n.t('users:toast.updated'))
    },
    onError: (error) => {
      toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error))
    },
  })
}

export function useDeleteUser() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => getPlatform().data.users.delete(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: usersKeys.all })
      toast.success(i18n.t('users:toast.deleted'))
    },
    onError: (error) => {
      toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error))
    },
  })
}

export function useToggleActive() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => getPlatform().data.users.setActive(id, is_active),
    onSuccess: (updatedUser) => {
      void queryClient.invalidateQueries({ queryKey: usersKeys.all })
      void queryClient.invalidateQueries({ queryKey: usersKeys.detail(updatedUser.id) })
      toast.success(updatedUser.is_active ? i18n.t('users:toast.activated') : i18n.t('users:toast.deactivated'))
    },
    onError: (error) => {
      toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error))
    },
  })
}

export function useResetPassword() {
  return useMutation({
    mutationFn: ({ id, password }: { id: number; password: string }) => getPlatform().data.users.resetPassword(id, password),
    onSuccess: () => {
      toast.success(i18n.t('users:toast.passwordReset'))
    },
    onError: (error) => {
      toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error))
    },
  })
}
