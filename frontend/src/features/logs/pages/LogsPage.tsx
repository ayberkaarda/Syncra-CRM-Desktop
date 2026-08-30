// Loglar sayfası — dört sekmeli log arayüzü (Oturum/Gezinme/Aksiyon/Canlı Akış), ortak
// filtre çubuğu, dışa aktarma ve online kullanıcılar paneli. Tüm filtre/sayfa/sıralama durumu
// URL query string'inde tutulur (`UsersPage` ile aynı desen, bkz. Faz 2).
import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Select, Tab, TabList, TabPanel, Tabs } from '../../../components/ui'
import { Card, CardHeader, CardBody, Pagination } from '../../../components/ui'
import { usePermission } from '../../auth/hooks/usePermission'
import {
  useActivityLogs,
  useLogUserOptions,
  usePageVisitLogs,
  useSessionLogs,
} from '../api/logsApi'
import { ActivitiesTable } from '../components/ActivitiesTable'
import { ActivityDetailModal } from '../components/ActivityDetailModal'
import { ExportMenu } from '../components/ExportMenu'
import { LiveStreamTab } from '../components/LiveStreamTab'
import { LogsFilterBar } from '../components/LogsFilterBar'
import { OnlineUsersPanel } from '../components/OnlineUsersPanel'
import { PageVisitsTable } from '../components/PageVisitsTable'
import { SessionsTable } from '../components/SessionsTable'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import type {
  ActivitiesQuery,
  ActivityFilterEvent,
  ActivityLog,
  ExportType,
  LogsTab,
  PageVisitsQuery,
  SessionEvent,
  SessionsQuery,
} from '../types'
import { activityFilterEventOptions, sessionEventOptions, subjectTypeOptions } from '../utils'

const DEFAULT_PER_PAGE = 25
const SEARCH_DEBOUNCE_MS = 300
const VALID_TABS: LogsTab[] = ['sessions', 'page-visits', 'activities', 'live']

export function LogsPage() {
  const { t } = useTranslation('logs')
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()

  const rawTab = searchParams.get('tab')
  const tab: LogsTab = (VALID_TABS as string[]).includes(rawTab ?? '')
    ? (rawTab as LogsTab)
    : 'sessions'

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [detailActivity, setDetailActivity] = useState<ActivityLog | null>(null)

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

  function switchTab(nextTab: string) {
    if (nextTab === tab) return
    setSearchDraft('')
    // Sekmeye özel filtreler (olay/varlık tipi) ve sayfalama/sıralama sıfırlanır — bir sonraki
    // sekmenin beyaz listesiyle uyumsuz bir değer 422'ye düşmesin diye. Kullanıcı/tarih/arama
    // ortak filtreler olduğundan korunur.
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      next.set('tab', nextTab)
      next.delete('page')
      next.delete('sort')
      next.delete('event')
      next.delete('subject_type')
      next.delete('q')
      return next
    })
  }

  const userIdParam = searchParams.get('user_id') ?? ''
  const fromParam = searchParams.get('from') ?? ''
  const toParam = searchParams.get('to') ?? ''
  const eventParam = searchParams.get('event') ?? ''
  const subjectTypeParam = searchParams.get('subject_type') ?? ''
  const page = Number(searchParams.get('page') ?? '1') || 1
  const perPage =
    Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE
  const sort = searchParams.get('sort') ?? undefined
  const q = searchParams.get('q') ?? undefined
  const userId = userIdParam ? Number(userIdParam) : undefined

  const sessionsQuery: SessionsQuery = useMemo(
    () => ({
      page,
      per_page: perPage,
      sort,
      q,
      user_id: userId,
      from: fromParam || undefined,
      to: toParam || undefined,
      event: (eventParam || undefined) as SessionEvent | undefined,
    }),
    [page, perPage, sort, q, userId, fromParam, toParam, eventParam],
  )
  const pageVisitsQuery: PageVisitsQuery = useMemo(
    () => ({
      page,
      per_page: perPage,
      sort,
      q,
      user_id: userId,
      from: fromParam || undefined,
      to: toParam || undefined,
    }),
    [page, perPage, sort, q, userId, fromParam, toParam],
  )
  const activitiesQuery: ActivitiesQuery = useMemo(
    () => ({
      page,
      per_page: perPage,
      sort,
      q,
      user_id: userId,
      from: fromParam || undefined,
      to: toParam || undefined,
      event: (eventParam || undefined) as ActivityFilterEvent | undefined,
      subject_type: subjectTypeParam || undefined,
    }),
    [page, perPage, sort, q, userId, fromParam, toParam, eventParam, subjectTypeParam],
  )

  const sessionsResult = useSessionLogs(sessionsQuery, tab === 'sessions')
  const pageVisitsResult = usePageVisitLogs(pageVisitsQuery, tab === 'page-visits')
  const activitiesResult = useActivityLogs(activitiesQuery, tab === 'activities')

  const userOptionsQuery = useLogUserOptions()
  const userOptions = userOptionsQuery.data ?? []
  const showUserFilter = !userOptionsQuery.isError

  function sortDirectionFor(field: string): 'asc' | 'desc' | null {
    if (sort === field) return 'asc'
    if (sort === `-${field}`) return 'desc'
    return null
  }

  function toggleSort(field: string) {
    let nextSort: string | null
    if (sort === field) nextSort = `-${field}`
    else if (sort === `-${field}`) nextSort = null
    else nextSort = field
    updateParams({ sort: nextSort, page: '1' })
  }

  const activeTotal =
    tab === 'sessions'
      ? sessionsResult.data?.meta.pagination.total
      : tab === 'page-visits'
        ? pageVisitsResult.data?.meta.pagination.total
        : tab === 'activities'
          ? activitiesResult.data?.meta.pagination.total
          : undefined

  const exportFilters = {
    q,
    sort,
    user_id: userId,
    from: fromParam || undefined,
    to: toParam || undefined,
    event: eventParam || undefined,
    subject_type: subjectTypeParam || undefined,
  }
  const exportType: ExportType =
    tab === 'sessions' ? 'sessions' : tab === 'page-visits' ? 'page-visits' : 'activities'

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('logs:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('logs:breadcrumb.logs')}</span>
      </nav>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_280px]">
        <Card className="min-w-0">
          <CardHeader
            title={t('logs:page.title')}
            subtitle={activeTotal !== undefined ? t('logs:page.subtitle', { count: activeTotal }) : undefined}
            action={
              tab !== 'live' &&
              can('logs.export') && <ExportMenu type={exportType} filters={exportFilters} />
            }
          />

          <Tabs value={tab} onValueChange={switchTab}>
            <TabList className="px-5 pt-3">
              <Tab value="sessions">{t('logs:tabs.sessions')}</Tab>
              <Tab value="page-visits">{t('logs:tabs.pageVisits')}</Tab>
              <Tab value="activities">{t('logs:tabs.activities')}</Tab>
              <Tab value="live">{t('logs:tabs.live')}</Tab>
            </TabList>

            <CardBody noPadding>
              <TabPanel value="sessions">
                <LogsFilterBar
                  searchDraft={searchDraft}
                  onSearchDraftChange={setSearchDraft}
                  userId={userIdParam}
                  onUserIdChange={(v) => updateParams({ user_id: v || null, page: '1' })}
                  userOptions={userOptions}
                  showUserFilter={showUserFilter}
                  from={fromParam}
                  onFromChange={(v) => updateParams({ from: v || null, page: '1' })}
                  to={toParam}
                  onToChange={(v) => updateParams({ to: v || null, page: '1' })}
                >
                  <div className="w-full lg:w-48">
                    <Select
                      value={eventParam}
                      onChange={(e) => updateParams({ event: e.target.value || null, page: '1' })}
                      options={[{ value: '', label: t('logs:filterBar.allEvents') }, ...sessionEventOptions(t)]}
                      aria-label={t('logs:filterBar.eventFilterAria')}
                    />
                  </div>
                </LogsFilterBar>

                <SessionsTable
                  data={sessionsResult.data?.data ?? []}
                  isLoading={sessionsResult.isLoading}
                  isError={sessionsResult.isError}
                  onRetry={() => sessionsResult.refetch()}
                  perPage={perPage}
                  sortDirectionFor={sortDirectionFor}
                  onSortToggle={toggleSort}
                />

                {!sessionsResult.isError && (sessionsResult.data?.data.length ?? 0) > 0 && (
                  <div className="border-t border-border-subtle p-4">
                    <Pagination
                      currentPage={page}
                      totalItems={sessionsResult.data?.meta.pagination.total ?? 0}
                      pageSize={perPage}
                      onPageChange={(p) => updateParams({ page: String(p) })}
                    />
                  </div>
                )}
              </TabPanel>

              <TabPanel value="page-visits">
                <LogsFilterBar
                  searchDraft={searchDraft}
                  onSearchDraftChange={setSearchDraft}
                  userId={userIdParam}
                  onUserIdChange={(v) => updateParams({ user_id: v || null, page: '1' })}
                  userOptions={userOptions}
                  showUserFilter={showUserFilter}
                  from={fromParam}
                  onFromChange={(v) => updateParams({ from: v || null, page: '1' })}
                  to={toParam}
                  onToChange={(v) => updateParams({ to: v || null, page: '1' })}
                />

                <PageVisitsTable
                  data={pageVisitsResult.data?.data ?? []}
                  isLoading={pageVisitsResult.isLoading}
                  isError={pageVisitsResult.isError}
                  onRetry={() => pageVisitsResult.refetch()}
                  perPage={perPage}
                  sortDirectionFor={sortDirectionFor}
                  onSortToggle={toggleSort}
                />

                {!pageVisitsResult.isError && (pageVisitsResult.data?.data.length ?? 0) > 0 && (
                  <div className="border-t border-border-subtle p-4">
                    <Pagination
                      currentPage={page}
                      totalItems={pageVisitsResult.data?.meta.pagination.total ?? 0}
                      pageSize={perPage}
                      onPageChange={(p) => updateParams({ page: String(p) })}
                    />
                  </div>
                )}
              </TabPanel>

              <TabPanel value="activities">
                <LogsFilterBar
                  searchDraft={searchDraft}
                  onSearchDraftChange={setSearchDraft}
                  userId={userIdParam}
                  onUserIdChange={(v) => updateParams({ user_id: v || null, page: '1' })}
                  userOptions={userOptions}
                  showUserFilter={showUserFilter}
                  from={fromParam}
                  onFromChange={(v) => updateParams({ from: v || null, page: '1' })}
                  to={toParam}
                  onToChange={(v) => updateParams({ to: v || null, page: '1' })}
                >
                  <div className="w-full lg:w-44">
                    <Select
                      value={eventParam}
                      onChange={(e) => updateParams({ event: e.target.value || null, page: '1' })}
                      options={[
                        { value: '', label: t('logs:filterBar.allEvents') },
                        ...activityFilterEventOptions(t),
                      ]}
                      aria-label={t('logs:filterBar.eventFilterAria')}
                    />
                  </div>
                  <div className="w-full lg:w-44">
                    <Select
                      value={subjectTypeParam}
                      onChange={(e) =>
                        updateParams({ subject_type: e.target.value || null, page: '1' })
                      }
                      options={[{ value: '', label: t('logs:filterBar.allSubjectTypes') }, ...subjectTypeOptions(t)]}
                      aria-label={t('logs:filterBar.subjectTypeFilterAria')}
                    />
                  </div>
                </LogsFilterBar>

                <ActivitiesTable
                  data={activitiesResult.data?.data ?? []}
                  isLoading={activitiesResult.isLoading}
                  isError={activitiesResult.isError}
                  onRetry={() => activitiesResult.refetch()}
                  perPage={perPage}
                  sortDirectionFor={sortDirectionFor}
                  onSortToggle={toggleSort}
                  onViewDetail={setDetailActivity}
                />

                {!activitiesResult.isError && (activitiesResult.data?.data.length ?? 0) > 0 && (
                  <div className="border-t border-border-subtle p-4">
                    <Pagination
                      currentPage={page}
                      totalItems={activitiesResult.data?.meta.pagination.total ?? 0}
                      pageSize={perPage}
                      onPageChange={(p) => updateParams({ page: String(p) })}
                    />
                  </div>
                )}
              </TabPanel>

              <TabPanel value="live">
                <LiveStreamTab />
              </TabPanel>
            </CardBody>
          </Tabs>
        </Card>

        <OnlineUsersPanel />
      </div>

      <ActivityDetailModal activity={detailActivity} onClose={() => setDetailActivity(null)} />
    </div>
  )
}
