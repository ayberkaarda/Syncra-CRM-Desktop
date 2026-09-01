// Kullanıcı Yönetimi listesi — server-side sayfalama/sıralama/arama/filtreleme, tüm durum
// gösterge deseni (yükleme/boş/hata) ve izin kontrollü işlemler.
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { KeyRound, Pencil, Plus, Power, PowerOff, Search, Trash2, UserCog } from 'lucide-react'
import {
  Avatar,
  Badge,
  Button,
  Card,
  CardBody,
  CardHeader,
  EmptyState,
  Input,
  Modal,
  Pagination,
  Select,
  Skeleton,
  Table,
  TBody,
  Td,
  THead,
  Th,
  Tr,
} from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { formatDateTime } from '../../../lib/datetime'
import { useAuth } from '../../auth/hooks/useAuth'
import { usePermission } from '../../auth/hooks/usePermission'
import { SavedViewsBar } from '../../saved-views/components/SavedViewsBar'
import { useOnlineOnly } from '../../../platform/useOnlineOnly'
import { useDeleteUser, useRoles, useToggleActive, useUsers } from '../api/usersApi'
import { UserFormModal } from '../components/UserFormModal'
import { ResetPasswordModal } from '../components/ResetPasswordModal'
import { roleLabel } from '../utils/roleMeta'
import type { User, UsersQuery } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300

function useDebouncedValue<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timeout = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timeout)
  }, [value, delay])
  return debounced
}

function IconButton({
  label,
  onClick,
  children,
  danger,
  disabled,
  title,
}: {
  label: string
  onClick: () => void
  children: ReactNode
  danger?: boolean
  /** SYNCDESKTOP §8 (O102) — set while the action is refused offline. */
  disabled?: boolean
  /** Overrides the default `title={label}` so the refusal can name its own reason. */
  title?: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      title={title ?? label}
      className={cn(
        'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
        'transition-colors duration-150 motion-reduce:transition-none',
        'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent disabled:hover:text-fg-muted',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
        danger && 'hover:text-danger'
      )}
    >
      {children}
    </button>
  )
}

type FormModalState = { mode: 'create' } | { mode: 'edit'; user: User } | null

export function UsersPage() {
  const { t } = useTranslation(['users', 'common', 'enums'])
  const [searchParams, setSearchParams] = useSearchParams()
  const { user: currentUser } = useAuth()
  const { can } = usePermission()

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [resetPasswordUser, setResetPasswordUser] = useState<User | null>(null)
  const [confirmDeleteUser, setConfirmDeleteUser] = useState<User | null>(null)
  const [confirmDeactivateUser, setConfirmDeactivateUser] = useState<User | null>(null)

  function updateParams(patch: Record<string, string | null>) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      return next
    })
  }

  // Arama kutusu debounce edilir; sonuç URL'e yazılır ki sayfa yenilenince kaybolmasın.
  useEffect(() => {
    const currentQ = searchParams.get('q') ?? ''
    if (debouncedSearch === currentQ) return
    updateParams({ q: debouncedSearch || null, page: '1' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const query: UsersQuery = useMemo(
    () => ({
      page: Number(searchParams.get('page') ?? '1') || 1,
      per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
      sort: searchParams.get('sort') ?? undefined,
      q: searchParams.get('q') ?? undefined,
      role: searchParams.get('role') ?? undefined,
      is_active: searchParams.has('is_active') ? searchParams.get('is_active') === 'true' : undefined,
    }),
    [searchParams]
  )

  const { data, isLoading, isError, refetch } = useUsers(query)
  const { data: roles } = useRoles()
  const deleteUser = useDeleteUser()
  const toggleActive = useToggleActive()

  function sortDirectionFor(field: string): 'asc' | 'desc' | null {
    if (query.sort === field) return 'asc'
    if (query.sort === `-${field}`) return 'desc'
    return null
  }

  function toggleSort(field: string) {
    const current = query.sort
    let nextSort: string | null
    if (current === field) nextSort = `-${field}`
    else if (current === `-${field}`) nextSort = null
    else nextSort = field
    updateParams({ sort: nextSort, page: '1' })
  }

  const roleFilterOptions = [
    { value: '', label: t('users:list.allRoles') },
    ...(roles ?? []).map((role) => ({ value: role.name, label: roleLabel(role.name, t) })),
  ]

  const statusFilterOptions = [
    { value: '', label: t('users:list.allStatuses') },
    { value: 'true', label: t('users:status.active') },
    { value: 'false', label: t('users:status.inactive') },
  ]

  const users = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && users.length === 0
  // SYNCDESKTOP §8 (O102): the whole `users.*` family is online-only, and §8 gives it ONE
  // sentence (`desktop:onlineOnly.users`) rather than one per verb — see
  // `frontend/src/platform/onlineOnly.ts`. The six mutating triggers on this page all read it.
  const usersGuard = useOnlineOnly('users')

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('users:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('users:breadcrumb.users')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('users:list.title')}
          subtitle={t('users:list.subtitle', { count: total })}
          action={
            <div className="flex items-center gap-2">
              <SavedViewsBar module="users" filterKeys={['role', 'is_active']} />
              {can('users.create') && (
                <Button
                  leftIcon={<Plus className="size-4" aria-hidden="true" />}
                  disabled={usersGuard.offline}
                  title={usersGuard.title}
                  onClick={() => setFormModal({ mode: 'create' })}
                >
                  {t('users:list.createButton')}
                </Button>
              )}
            </div>
          }
        />
        <CardBody noPadding>
          <div className="flex flex-col gap-3 border-b border-border-subtle p-4 sm:flex-row sm:items-center">
            <div className="w-full sm:max-w-xs">
              <Input
                value={searchDraft}
                onChange={(e) => setSearchDraft(e.target.value)}
                placeholder={t('users:list.searchPlaceholder')}
                leftIcon={<Search className="size-4" aria-hidden="true" />}
                aria-label={t('users:list.searchAria')}
              />
            </div>
            <div className="w-full sm:w-48">
              <Select
                value={query.role ?? ''}
                onChange={(e) => updateParams({ role: e.target.value || null, page: '1' })}
                options={roleFilterOptions}
                aria-label={t('users:list.roleFilterAria')}
              />
            </div>
            <div className="w-full sm:w-40">
              <Select
                value={query.is_active === undefined ? '' : String(query.is_active)}
                onChange={(e) => updateParams({ is_active: e.target.value || null, page: '1' })}
                options={statusFilterOptions}
                aria-label={t('users:list.statusFilterAria')}
              />
            </div>
          </div>

          {isError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <p className="text-sm text-fg-muted">{t('users:list.loadError')}</p>
              <Button variant="secondary" onClick={() => refetch()}>
                {t('common:actions.retry')}
              </Button>
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<UserCog className="size-6" aria-hidden="true" />}
              title={t('users:list.emptyTitle')}
              description={t('users:list.emptyDescription')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th sortable sortDirection={sortDirectionFor('name')} onSort={() => toggleSort('name')}>
                    {t('users:list.columns.user')}
                  </Th>
                  <Th>{t('users:list.columns.role')}</Th>
                  <Th sortable sortDirection={sortDirectionFor('department')} onSort={() => toggleSort('department')}>
                    {t('users:list.columns.department')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('is_active')} onSort={() => toggleSort('is_active')}>
                    {t('users:list.columns.status')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('last_login_at')} onSort={() => toggleSort('last_login_at')}>
                    {t('users:list.columns.lastLogin')}
                  </Th>
                  <Th align="right">{t('users:list.columns.actions')}</Th>
                </Tr>
              </THead>
              <TBody aria-busy={isLoading}>
                {isLoading
                  ? Array.from({ length: query.per_page ?? DEFAULT_PER_PAGE }).map((_, i) => (
                      <Tr key={i}>
                        <Td>
                          <div className="flex items-center gap-3">
                            <Skeleton variant="circle" width={32} height={32} />
                            <Skeleton variant="text" width={140} />
                          </div>
                        </Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={60} /></Td>
                        <Td><Skeleton variant="text" width={120} /></Td>
                        <Td align="right"><Skeleton variant="text" width={90} className="ml-auto" /></Td>
                      </Tr>
                    ))
                  : users.map((u) => {
                      const isSelf = u.id === currentUser?.id
                      return (
                        <Tr key={u.id}>
                          <Td>
                            <div className="flex items-center gap-3">
                              <Avatar name={u.name} size="sm" />
                              <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-fg">{u.name}</p>
                                <p className="truncate text-xs text-fg-muted">{u.email}</p>
                              </div>
                            </div>
                          </Td>
                          <Td>
                            {u.role ? <Badge variant="primary">{roleLabel(u.role.name, t)}</Badge> : <span className="text-fg-muted">—</span>}
                          </Td>
                          <Td>{u.department ?? '—'}</Td>
                          <Td>
                            <Badge variant={u.is_active ? 'success' : 'danger'}>
                              {u.is_active ? t('users:status.active') : t('users:status.inactive')}
                            </Badge>
                          </Td>
                          <Td>{u.last_login_at ? formatDateTime(u.last_login_at) : t('users:list.neverLoggedIn')}</Td>
                          <Td align="right">
                            <div className="flex items-center justify-end gap-1">
                              {can('users.update') && (
                                <IconButton
                                  label={t('users:list.actions.edit')}
                                  disabled={usersGuard.offline}
                                  title={usersGuard.title}
                                  onClick={() => setFormModal({ mode: 'edit', user: u })}
                                >
                                  <Pencil className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {can('users.reset_password') && (
                                <IconButton
                                  label={t('users:list.actions.resetPassword')}
                                  disabled={usersGuard.offline}
                                  title={usersGuard.title}
                                  onClick={() => setResetPasswordUser(u)}
                                >
                                  <KeyRound className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {!isSelf && can('users.toggle_active') && (
                                <IconButton
                                  label={u.is_active ? t('users:list.actions.deactivate') : t('users:list.actions.activate')}
                                  disabled={usersGuard.offline}
                                  title={usersGuard.title}
                                  onClick={() => {
                                    if (u.is_active) setConfirmDeactivateUser(u)
                                    else toggleActive.mutate({ id: u.id, is_active: true })
                                  }}
                                >
                                  {u.is_active ? (
                                    <PowerOff className="size-4" aria-hidden="true" />
                                  ) : (
                                    <Power className="size-4" aria-hidden="true" />
                                  )}
                                </IconButton>
                              )}
                              {!isSelf && can('users.delete') && (
                                <IconButton
                                  label={t('users:list.actions.delete')}
                                  danger
                                  disabled={usersGuard.offline}
                                  title={usersGuard.title}
                                  onClick={() => setConfirmDeleteUser(u)}
                                >
                                  <Trash2 className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                            </div>
                          </Td>
                        </Tr>
                      )
                    })}
              </TBody>
            </Table>
          )}

          {!isError && !isEmpty && (
            <div className="border-t border-border-subtle p-4">
              <Pagination
                currentPage={query.page ?? 1}
                totalItems={total}
                pageSize={query.per_page ?? DEFAULT_PER_PAGE}
                onPageChange={(page) => updateParams({ page: String(page) })}
              />
            </div>
          )}
        </CardBody>
      </Card>

      <UserFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        user={formModal?.mode === 'edit' ? formModal.user : null}
      />

      <ResetPasswordModal open={!!resetPasswordUser} onClose={() => setResetPasswordUser(null)} user={resetPasswordUser} />

      <Modal
        open={!!confirmDeleteUser}
        onClose={() => setConfirmDeleteUser(null)}
        title={t('users:deleteModal.title')}
        description={t('users:deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmDeleteUser(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteUser.isPending}
              onClick={async () => {
                if (!confirmDeleteUser) return
                await deleteUser.mutateAsync(confirmDeleteUser.id)
                setConfirmDeleteUser(null)
              }}
            >
              {t('common:actions.delete')}
            </Button>
          </div>
        }
      >
        {confirmDeleteUser && (
          <p className="text-sm text-fg-secondary">
            <Trans
              i18nKey="users:deleteModal.confirmText"
              values={{ name: confirmDeleteUser.name, email: confirmDeleteUser.email }}
              components={{ bold: <strong className="text-fg" /> }}
            />
          </p>
        )}
      </Modal>

      <Modal
        open={!!confirmDeactivateUser}
        onClose={() => setConfirmDeactivateUser(null)}
        title={t('users:deactivateModal.title')}
        description={t('users:deactivateModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmDeactivateUser(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={toggleActive.isPending}
              onClick={async () => {
                if (!confirmDeactivateUser) return
                await toggleActive.mutateAsync({ id: confirmDeactivateUser.id, is_active: false })
                setConfirmDeactivateUser(null)
              }}
            >
              {t('users:list.actions.deactivate')}
            </Button>
          </div>
        }
      >
        {confirmDeactivateUser && (
          <p className="text-sm text-fg-secondary">
            <Trans
              i18nKey="users:deactivateModal.confirmText"
              values={{ name: confirmDeactivateUser.name }}
              components={{ bold: <strong className="text-fg" /> }}
            />
          </p>
        )}
      </Modal>
    </div>
  )
}
