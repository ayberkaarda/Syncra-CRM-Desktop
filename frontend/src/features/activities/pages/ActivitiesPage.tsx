// Aktivite akışı — server-side sayfalama/sıralama/arama/filtreleme, tüm durum URL query
// string'inde (bkz. `DealsListPage`/`TasksPage` deseni).
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { ActivitySquare, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import {
  Avatar,
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
import { usePermission } from '../../auth/hooks/usePermission'
import { useTaskUserOptions } from '../../tasks/api/tasksApi'
import { relatedRecordMeta, RELATED_RECORD_SELECTABLE_TYPES, relatedRecordTypeLabel } from '../../tasks/components/relatedRecordMeta'
import { ActivityTypeBadge } from '../components/ActivityTypeBadge'
import { activityTypeOptions } from '../components/activityTypeMeta'
import { ActivityFormModal } from '../components/ActivityFormModal'
import { useActivities, useDeleteActivity } from '../api/activitiesApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import type { ActivitiesQuery, Activity } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300

type FormModalState = { mode: 'create' } | { mode: 'edit'; activity: Activity } | null

export function ActivitiesPage() {
  const { t } = useTranslation('activities')
  const { t: tEnums } = useTranslation('enums')
  const { t: tTasks } = useTranslation('tasks')
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [deleteActivityState, setDeleteActivityState] = useState<Activity | null>(null)

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

  useEffect(() => {
    const currentQ = searchParams.get('q') ?? ''
    if (debouncedSearch === currentQ) return
    updateParams({ q: debouncedSearch || null, page: '1' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const query: ActivitiesQuery = useMemo(() => {
    const userId = searchParams.get('user_id')
    return {
      page: Number(searchParams.get('page') ?? '1') || 1,
      per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
      sort: searchParams.get('sort') ?? '-occurred_at',
      q: searchParams.get('q') ?? undefined,
      type: (searchParams.get('type') ?? undefined) as ActivitiesQuery['type'],
      user_id: userId ? Number(userId) : undefined,
      activityable_type: (searchParams.get('activityable_type') ?? undefined) as ActivitiesQuery['activityable_type'],
      from: searchParams.get('from') ?? undefined,
      to: searchParams.get('to') ?? undefined,
    }
  }, [searchParams])

  const { data, isLoading, isError, refetch } = useActivities(query)
  const { data: userOptions, isForbidden: usersForbidden } = useTaskUserOptions()
  const deleteActivityMutation = useDeleteActivity()

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

  const typeFilterOptions = [{ value: '', label: t('filters.allTypes') }, ...activityTypeOptions(tEnums)]
  const userFilterOptions = [
    { value: '', label: t('filters.allUsers') },
    ...(userOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]
  const activityableFilterOptions = [
    { value: '', label: t('filters.allRecordTypes') },
    // `.concat('ticket')` ÇIKARILDI — `RELATED_RECORD_SELECTABLE_TYPES` zaten 'ticket' içeriyor
    // (bkz. `relatedRecordMeta.tsx`), eklemek "Ticket" seçeneğini React key çakışmasıyla iki kez
    // listeliyordu (Faz 14 denetim bulgusu).
    ...RELATED_RECORD_SELECTABLE_TYPES.map((type) => ({ value: type, label: relatedRecordTypeLabel(type, tTasks) })),
  ]

  const activities = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && activities.length === 0

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('breadcrumb.activities')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('list.title')}
          subtitle={t('list.subtitle', { count: total })}
          action={
            can('activities.create') && (
              <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
                {t('list.createButton')}
              </Button>
            )
          }
        />
        <CardBody noPadding>
          <div className="flex flex-col gap-3 border-b border-border-subtle p-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
              <div className="w-full lg:max-w-xs">
                <Input
                  value={searchDraft}
                  onChange={(e) => setSearchDraft(e.target.value)}
                  placeholder={t('filters.searchPlaceholder')}
                  leftIcon={<Search className="size-4" aria-hidden="true" />}
                  aria-label={t('filters.searchAria')}
                />
              </div>
              <div className="w-full lg:w-40">
                <Select
                  value={query.type ?? ''}
                  onChange={(e) => updateParams({ type: e.target.value || null, page: '1' })}
                  options={typeFilterOptions}
                  aria-label={t('filters.typeAria')}
                />
              </div>
              {!usersForbidden && (
                <div className="w-full lg:w-44">
                  <Select
                    value={query.user_id ? String(query.user_id) : ''}
                    onChange={(e) => updateParams({ user_id: e.target.value || null, page: '1' })}
                    options={userFilterOptions}
                    aria-label={t('filters.userAria')}
                  />
                </div>
              )}
              <div className="w-full lg:w-44">
                <Select
                  value={query.activityable_type ?? ''}
                  onChange={(e) => updateParams({ activityable_type: e.target.value || null, page: '1' })}
                  options={activityableFilterOptions}
                  aria-label={t('filters.recordTypeAria')}
                />
              </div>
              <div className="flex w-full items-end gap-2 lg:w-auto">
                <div className="w-full lg:w-40">
                  <Input
                    type="date"
                    value={query.from ?? ''}
                    onChange={(e) => updateParams({ from: e.target.value || null, page: '1' })}
                    aria-label={t('filters.fromDateAria')}
                    max={query.to || undefined}
                  />
                </div>
                <span className="pb-2.5 text-xs text-fg-muted">—</span>
                <div className="w-full lg:w-40">
                  <Input
                    type="date"
                    value={query.to ?? ''}
                    onChange={(e) => updateParams({ to: e.target.value || null, page: '1' })}
                    aria-label={t('filters.toDateAria')}
                    min={query.from || undefined}
                  />
                </div>
              </div>
            </div>
          </div>

          {isError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <p className="text-sm text-fg-muted">{t('list.error')}</p>
              <Button variant="secondary" onClick={() => refetch()}>
                {t('list.retry')}
              </Button>
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<ActivitySquare className="size-6" aria-hidden="true" />}
              title={t('list.empty.title')}
              description={t('list.empty.description')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th>{t('table.type')}</Th>
                  <Th sortable sortDirection={sortDirectionFor('subject')} onSort={() => toggleSort('subject')}>
                    {t('table.subject')}
                  </Th>
                  <Th>{t('table.relatedRecord')}</Th>
                  <Th>{t('table.user')}</Th>
                  <Th sortable sortDirection={sortDirectionFor('occurred_at')} onSort={() => toggleSort('occurred_at')}>
                    {t('table.occurredAt')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('duration_minutes')} onSort={() => toggleSort('duration_minutes')}>
                    {t('table.duration')}
                  </Th>
                  <Th>{t('table.outcome')}</Th>
                  <Th align="right">{t('table.actions')}</Th>
                </Tr>
              </THead>
              <TBody aria-busy={isLoading}>
                {isLoading
                  ? Array.from({ length: query.per_page ?? DEFAULT_PER_PAGE }).map((_, i) => (
                      <Tr key={i}>
                        <Td><Skeleton variant="text" width={90} /></Td>
                        <Td><Skeleton variant="text" width={180} /></Td>
                        <Td><Skeleton variant="text" width={110} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={120} /></Td>
                        <Td><Skeleton variant="text" width={50} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td align="right"><Skeleton variant="text" width={80} className="ml-auto" /></Td>
                      </Tr>
                    ))
                  : activities.map((activity) => {
                      const meta = activity.activityable ? relatedRecordMeta(activity.activityable.type, tTasks) : null
                      const Icon = meta?.icon
                      return (
                        <Tr key={activity.id}>
                          <Td>
                            <ActivityTypeBadge type={activity.type} />
                          </Td>
                          <Td className="font-medium text-fg">{activity.subject}</Td>
                          <Td>
                            {activity.activityable && meta && Icon ? (
                              <Link
                                to={meta.path(activity.activityable.id)}
                                className="inline-flex items-center gap-1.5 text-sm text-fg hover:text-primary hover:underline"
                              >
                                <Icon className="size-4 shrink-0 text-fg-muted" aria-hidden="true" />
                                <span className="max-w-40 truncate">{activity.activityable.label ?? meta.label}</span>
                              </Link>
                            ) : (
                              <span className="text-fg-muted">—</span>
                            )}
                          </Td>
                          <Td>
                            {activity.user ? (
                              <div className="flex items-center gap-2">
                                <Avatar name={activity.user.name} size="xs" />
                                <span className="truncate text-sm text-fg">{activity.user.name}</span>
                              </div>
                            ) : (
                              <span className="text-fg-muted">—</span>
                            )}
                          </Td>
                          <Td className="whitespace-nowrap">{formatDateTime(activity.occurred_at)}</Td>
                          <Td>{activity.duration_minutes !== null ? t('table.durationValue', { count: activity.duration_minutes }) : '—'}</Td>
                          <Td className="max-w-40 truncate">{activity.outcome ?? '—'}</Td>
                          <Td align="right">
                            <div className="flex items-center justify-end gap-1">
                              {/* Faz 13: `activities.update` izni yeterli değil — kaydı yazan kişi ya da
                                  `activities.delete` taşıyan bir yönetici düzenleyebilir (bkz.
                                  `ActivityPolicy::update`). İzin varken sadece bu yüzden engellendiğinde
                                  buton GİZLENMEZ, devre dışı + tooltip gösterilir. */}
                              {can('activities.update') && (
                                <IconButton
                                  label={t('table.edit')}
                                  disabled={!activity.can.update}
                                  title={activity.can.update ? t('table.edit') : t('table.editForbidden')}
                                  onClick={() => setFormModal({ mode: 'edit', activity })}
                                >
                                  <Pencil className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {/* ÖZEL DURUM — diğer tüm modüllerden FARKLI: `ActivityPolicy::delete`
                                  `activities.delete` İZNİNİ ŞART KOŞMAZ, kaydı yazan kişi izinsiz de
                                  silebilir. Bu yüzden burada `can('activities.delete')` ÖN KOŞUL olarak
                                  ARANMAZ (aranırsa yazarın kendi kaydını silme hakkı yanlışlıkla
                                  gizlenirdi) — doğrudan backend'in `activity.can.delete`'ine güvenilir;
                                  eskiden burada aynı mantığı istemcide yeniden kuran yerel bir
                                  `canDelete()` fonksiyonu vardı, artık gerek yok. */}
                              {activity.can.delete && (
                                <IconButton label={t('table.delete')} danger onClick={() => setDeleteActivityState(activity)}>
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

      <ActivityFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        activity={formModal?.mode === 'edit' ? formModal.activity : null}
      />

      <Modal
        open={!!deleteActivityState}
        onClose={() => setDeleteActivityState(null)}
        title={t('deleteModal.title')}
        description={t('deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteActivityState(null)}>
              {t('form.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteActivityMutation.isPending}
              onClick={async () => {
                if (!deleteActivityState) return
                await deleteActivityMutation.mutateAsync(deleteActivityState.id)
                setDeleteActivityState(null)
              }}
            >
              {t('table.delete')}
            </Button>
          </div>
        }
      >
        {deleteActivityState && (
          <p className="text-sm text-fg-secondary">
            <Trans
              t={t}
              i18nKey="deleteModal.confirm"
              values={{ subject: deleteActivityState.subject }}
              components={{ strong: <strong className="text-fg" /> }}
            />
          </p>
        )}
      </Modal>
    </div>
  )
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
  /** Faz 13: izin var ama bu kayıtta `can.*` false — buton görünür kalır, tıklanamaz olur. */
  disabled?: boolean
  /** Varsayılan tooltip `label`'dır; devre dışı durumda nedeni açıklayan bir metinle geçilebilir. */
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
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
        'disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-fg-muted',
        danger && 'hover:text-danger'
      )}
    >
      {children}
    </button>
  )
}
