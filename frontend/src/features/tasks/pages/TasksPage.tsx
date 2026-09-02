// Görevler — liste + takvim (tek sayfa, iki görünüm; `?view=list|calendar` URL'de tutulur).
//
// Görünüm değiştirici `/deals`'teki Pano/Liste kontrolüyle GÖRSEL olarak tutarlıdır (aynı
// konteyner/boyut/token'lar, aktif için `bg-primary-tint text-primary` + `aria-current="page"`,
// bkz. `DealsListPage.tsx`/`DealsBoardPage.tsx`). Etkileşim biçimi kasıtlı olarak FARKLI: deals'ta
// iki AYRI route (`/deals` ↔ `/deals/list`) arasında `Link` ile geçilir, burada TEK route üzerinde
// `?view=` query param'ı değiştiği için düğme + `setSearchParams` kullanılır — görsel dil aynı,
// navigasyon mekanizması sayfanın kendi yapısına uygun.
import { useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import {
  AlertTriangle,
  CalendarDays,
  List as ListIcon,
  ListTodo,
  Pencil,
  Plus,
  Search,
  Trash2,
  UserCog,
} from 'lucide-react'
import {
  Avatar,
  Button,
  Card,
  CardBody,
  CardHeader,
  Checkbox,
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
  toast,
} from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { recordSyncState } from '../../../components/shared/recordSyncState'
import { SyncStateBadge } from '../../../components/shared/SyncStateBadge'
import { getErrorMessage } from '../../../lib/axios'
import { formatDateTime } from '../../../lib/datetime'
import { useQueryClient } from '@tanstack/react-query'
import { usePermission } from '../../auth/hooks/usePermission'
import { SavedViewsBar } from '../../saved-views/components/SavedViewsBar'
import { PriorityBadge } from '../components/PriorityBadge'
import { priorityOptions } from '../components/priorityMeta'
import { TaskStatusBadge } from '../components/TaskStatusBadge'
import { statusOptions } from '../components/taskStatusMeta'
import { relatedRecordMeta, relatedRecordTypeLabel, RELATED_RECORD_SELECTABLE_TYPES } from '../components/relatedRecordMeta'
import { TaskFormModal } from '../components/TaskFormModal'
import { AssignTaskModal } from '../components/AssignTaskModal'
import { CalendarGrid } from '../components/calendar/CalendarGrid'
import { DayTasksModal } from '../components/calendar/DayTasksModal'
import { buildMonthGrid, dueAtToLocalYmd, monthLabel, monthRange } from '../components/calendar/calendarUtils'
import type { CalendarDay } from '../components/calendar/calendarUtils'
import { completeTaskQuiet, tasksKeys, useDeleteTask, useTaskUserOptions, useTasks, useTasksCalendar } from '../api/tasksApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import type { Task, TasksCalendarResponse, TasksListResponse, TasksQuery } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300

type FormModalState = { mode: 'create'; defaultDueDate?: string } | { mode: 'edit'; task: Task } | null

export function TasksPage() {
  const { t } = useTranslation(['tasks', 'common'])
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()
  const queryClient = useQueryClient()

  const view = searchParams.get('view') === 'calendar' ? 'calendar' : 'list'

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [assignTaskState, setAssignTaskState] = useState<Task | null>(null)
  const [deleteTaskState, setDeleteTaskState] = useState<Task | null>(null)
  const [completingIds, setCompletingIds] = useState<Set<number>>(new Set())
  const [selectedDay, setSelectedDay] = useState<CalendarDay | null>(null)

  // `?highlight=<task_id>` — hatırlatıcı toast'ının "Göreve git" aksiyonundan gelir
  // (bkz. `useTaskReminders.ts`). Başlıkla arama yerine id ile hedeflemek, aynı başlıklı iki
  // görev varken YANLIŞ satırın vurgulanmasını engeller.
  //
  // `consumedHighlightParam`/`highlightedTaskId` render SIRASINDA (bir effect İÇİNDE DEĞİL)
  // ayarlanır — `DealFormModal`'daki `openKey`/`lastOpenKey` ile AYNI "prop değişince state
  // ayarla" deseni (React'in kendi önerdiği bailout deseni). Bunu bir `useEffect` içinde
  // yapmak `react-hooks/set-state-in-effect` kuralını ihlal eder (efekt gövdesinde SENKRON
  // `setState` çağrısı cascading render riski taşır); aşağıdaki tek `useEffect` yalnızca
  // `highlightedTaskId` DEĞİŞTİKTEN SONRA gereken yan etkileri (DOM kaydırma, URL temizliği,
  // 2.5 sn'lik otomatik kapanma zamanlayıcısı) yürütür.
  const [consumedHighlightParam, setConsumedHighlightParam] = useState<string | null>(null)
  const [highlightedTaskId, setHighlightedTaskId] = useState<number | null>(null)
  const rowRefs = useRef<Record<number, HTMLTableRowElement | null>>({})

  const deleteTaskMutation = useDeleteTask()

  function updateParams(patch: Record<string, string | null>, options?: { replace?: boolean }) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      return next
    }, options)
  }

  useEffect(() => {
    const currentQ = searchParams.get('q') ?? ''
    if (debouncedSearch === currentQ) return
    updateParams({ q: debouncedSearch || null, page: '1' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const query: TasksQuery = useMemo(() => {
    const assignedTo = searchParams.get('assigned_to')
    return {
      page: Number(searchParams.get('page') ?? '1') || 1,
      per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
      sort: searchParams.get('sort') ?? '-due_at',
      q: searchParams.get('q') ?? undefined,
      status: (searchParams.get('status') ?? undefined) as TasksQuery['status'],
      priority: (searchParams.get('priority') ?? undefined) as TasksQuery['priority'],
      assigned_to: assignedTo ? Number(assignedTo) : undefined,
      taskable_type: (searchParams.get('taskable_type') ?? undefined) as TasksQuery['taskable_type'],
      from: searchParams.get('from') ?? undefined,
      to: searchParams.get('to') ?? undefined,
      overdue: searchParams.get('overdue') === '1' ? true : undefined,
    }
  }, [searchParams])

  const { data, isLoading, isError, refetch } = useTasks(query)
  const { data: userOptions, isForbidden: usersForbidden } = useTaskUserOptions()

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

  const statusFilterOptions = [{ value: '', label: t('tasks:filters.allStatuses') }, ...statusOptions(t)]
  const priorityFilterOptions = [{ value: '', label: t('tasks:filters.allPriorities') }, ...priorityOptions(t)]
  const assignedFilterOptions = [
    { value: '', label: t('tasks:filters.allAssignees') },
    ...(userOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]
  const taskableFilterOptions = [
    { value: '', label: t('tasks:filters.allTaskableTypes') },
    // `.concat('ticket')` ÇIKARILDI — `RELATED_RECORD_SELECTABLE_TYPES` zaten 'ticket' içeriyor
    // (bkz. `relatedRecordMeta.tsx`), eklemek "Ticket" seçeneğini React key çakışmasıyla iki kez
    // listeliyordu (Faz 14 denetim bulgusu).
    ...RELATED_RECORD_SELECTABLE_TYPES.map((type) => ({ value: type, label: relatedRecordTypeLabel(type, t) })),
  ]

  const tasks = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && tasks.length === 0

  // `highlight` parametresini TÜKETİR: liste yüklendiğinde hedef görev geçerli sayfada/filtrede
  // görünüyorsa kısa süreliğine vurgulanır; görünmüyorsa (başka bir sayfada/filtre dışında)
  // sessizce yok sayılır — filtreleri DEĞİŞTİRMEYİZ, yalnızca bulunabilirse vurgularız. Render
  // sırasında EN FAZLA BİR KEZ işlenir (`consumedHighlightParam` bekçisi), bkz. yukarıdaki not.
  const highlightParam = view === 'list' && !isLoading ? searchParams.get('highlight') : null
  if (highlightParam && highlightParam !== consumedHighlightParam) {
    setConsumedHighlightParam(highlightParam)
    const id = Number(highlightParam)
    setHighlightedTaskId(Number.isFinite(id) && tasks.some((t) => t.id === id) ? id : null)
  }

  // Yalnızca YENİ bir `highlight` parametresi TÜKETİLDİĞİNDE çalışan yan etkiler (bkz. yukarı):
  // URL'yi temizle (`replace:true` — geri tuşunda tekrar tetiklenmesin; hedef bulunamasa DAHİ
  // parametre temizlenmeli, bu yüzden bağımlılık `highlightedTaskId` DEĞİL `consumedHighlightParam`
  // — ikincisi bulunamayan durumda da null'dan dolu değere geçer, `highlightedTaskId` ise
  // "bulunamadı" durumunda null'dan null'a kalıp değişmez ve effect'i tetiklemezdi). Bulunduysa
  // satıra kaydır ve 2.5 sn sonra vurguyu kapatan zamanlayıcıyı kur.
  useEffect(() => {
    if (!consumedHighlightParam) return
    updateParams({ highlight: null }, { replace: true })
    if (highlightedTaskId === null) return
    rowRefs.current[highlightedTaskId]?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    const timer = setTimeout(() => setHighlightedTaskId(null), 2500)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [consumedHighlightParam])

  // ---------------------------------------------------------------------------
  // Hızlı tamamlama — liste satırındaki VE takvim panelindeki Checkbox ikisi de
  // buradan geçer. Hem liste (`tasksKeys.lists`) hem takvim (`tasksKeys.calendarAll`)
  // sorgu cache'leri İYİMSER güncellenir (checkbox anında tepki verir, hiçbir görünüm
  // titremez); sunucu yanıtı gelince aynı görevin SUNUCUDAKİ hâli (gerçek
  // `completed_at`/`is_overdue`) her iki cache'e de yazılır. Hata olursa dokunulan
  // TÜM sorguların ÖNCEKİ anlık görüntüsüne tek tek geri dönülür ve toast gösterilir.
  //
  // `useCompleteTask`/mutation KULLANILMAZ: o, başarıda TÜM `tasks` ağacını
  // invalidate eder ve refetch bitene kadar geçen sürede tablo/takvim boşalıp
  // yeniden dolar — iyimser güncellemenin amacı olan "anında, titremesiz" geri
  // bildirimi boşa çıkarırdı.
  // ---------------------------------------------------------------------------
  function patchTaskInCaches(taskId: number, patch: (t: Task) => Task) {
    queryClient.setQueriesData<TasksListResponse>({ queryKey: tasksKeys.lists, exact: false }, (current) => {
      if (!current) return current
      return { ...current, data: current.data.map((t) => (t.id === taskId ? patch(t) : t)) }
    })
    queryClient.setQueriesData<TasksCalendarResponse>({ queryKey: tasksKeys.calendarAll, exact: false }, (current) => {
      if (!current) return current
      return { ...current, data: current.data.map((t) => (t.id === taskId ? patch(t) : t)) }
    })
  }

  async function handleQuickComplete(task: Task, completed: boolean) {
    const previousLists = queryClient.getQueriesData<TasksListResponse>({ queryKey: tasksKeys.lists, exact: false })
    const previousCalendars = queryClient.getQueriesData<TasksCalendarResponse>({
      queryKey: tasksKeys.calendarAll,
      exact: false,
    })

    patchTaskInCaches(task.id, (t) => ({
      ...t,
      status: completed ? 'completed' : 'pending',
      completed_at: completed ? new Date().toISOString() : null,
      is_overdue: completed ? false : t.is_overdue,
    }))

    setCompletingIds((prev) => new Set(prev).add(task.id))
    try {
      const updated = await completeTaskQuiet(task.id, completed)
      patchTaskInCaches(task.id, () => updated)
    } catch (error) {
      for (const [key, data] of previousLists) queryClient.setQueryData(key, data)
      for (const [key, data] of previousCalendars) queryClient.setQueryData(key, data)
      // Faz 13: UI kuralı doğru uygulansa bile yarış olabilir (görev bu sırada başkasına
      // atandı) — `getErrorMessage` backend'in 403 mesajını ("Bu işlem için yetkiniz yok.")
      // olduğu gibi gösterir, jenerik metne düşmez (bkz. mevcut mutasyon hook'larındaki desen).
      toast.error(getErrorMessage(error))
    } finally {
      setCompletingIds((prev) => {
        const next = new Set(prev)
        next.delete(task.id)
        return next
      })
    }
  }

  // ---------------------------------------------------------------------------
  // Takvim görünümü
  // ---------------------------------------------------------------------------
  const today = new Date()
  const calYear = Number(searchParams.get('cy') ?? String(today.getFullYear())) || today.getFullYear()
  const rawCalMonth = searchParams.get('cm')
  const calMonth = rawCalMonth !== null && !Number.isNaN(Number(rawCalMonth)) ? Number(rawCalMonth) : today.getMonth()

  const range = useMemo(() => monthRange(calYear, calMonth), [calYear, calMonth])
  const calendarQuery = useMemo(
    () => ({ from: range.from, to: range.to }),
    [range.from, range.to]
  )
  const { data: calendarData, isLoading: calendarLoading } = useTasksCalendar(calendarQuery, { enabled: view === 'calendar' })

  const grid = useMemo(() => buildMonthGrid(calYear, calMonth), [calYear, calMonth])

  const tasksByDay = useMemo(() => {
    const map: Record<string, Task[]> = {}
    for (const task of calendarData?.data ?? []) {
      if (!task.due_at) continue
      const ymd = dueAtToLocalYmd(task.due_at)
      if (!ymd) continue
      if (!map[ymd]) map[ymd] = []
      map[ymd].push(task)
    }
    return map
  }, [calendarData])

  function changeMonth(delta: number) {
    const next = new Date(calYear, calMonth + delta, 1)
    updateParams({ cy: String(next.getFullYear()), cm: String(next.getMonth()) })
  }

  function goToToday() {
    updateParams({ cy: String(today.getFullYear()), cm: String(today.getMonth()) })
  }

  function handleDayClick(day: CalendarDay) {
    const dayTasks = tasksByDay[day.ymd] ?? []
    if (dayTasks.length === 0) {
      if (!can('tasks.create')) return
      setFormModal({ mode: 'create', defaultDueDate: day.ymd })
      return
    }
    setSelectedDay(day)
  }

  const selectedDayTasks = selectedDay ? (tasksByDay[selectedDay.ymd] ?? []) : []

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('tasks:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('tasks:breadcrumb.current')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('tasks:page.title')}
          subtitle={view === 'list' ? t('tasks:page.countLabel', { count: total }) : monthLabel(calYear, calMonth)}
          action={
            <div className="flex items-center gap-2">
              <div
                className="flex items-center gap-1 rounded-lg border border-border bg-surface-1 p-1"
                role="group"
                aria-label={t('tasks:page.viewGroupAria')}
              >
                <ViewButton active={view === 'list'} onClick={() => updateParams({ view: null })} icon={<ListIcon className="size-4" aria-hidden="true" />}>
                  {t('tasks:page.viewList')}
                </ViewButton>
                <ViewButton active={view === 'calendar'} onClick={() => updateParams({ view: 'calendar' })} icon={<CalendarDays className="size-4" aria-hidden="true" />}>
                  {t('tasks:page.viewCalendar')}
                </ViewButton>
              </div>
              {/* Kayıtlı görünümler yalnızca liste görünümünde anlamlı — takvim görünümü
                  filtre/sıra DEĞİL `cy`/`cm` (yıl/ay) parametreleriyle çalışır. */}
              {view === 'list' && (
                <SavedViewsBar
                  module="tasks"
                  filterKeys={['status', 'priority', 'assigned_to', 'taskable_type', 'from', 'to', 'overdue']}
                />
              )}
              {can('tasks.create') && (
                <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
                  {t('tasks:page.newTask')}
                </Button>
              )}
            </div>
          }
        />

        {view === 'list' ? (
          <CardBody noPadding>
            <div className="flex flex-col gap-3 border-b border-border-subtle p-4">
              <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
                <div className="w-full lg:max-w-xs">
                  <Input
                    value={searchDraft}
                    onChange={(e) => setSearchDraft(e.target.value)}
                    placeholder={t('tasks:filters.searchPlaceholder')}
                    leftIcon={<Search className="size-4" aria-hidden="true" />}
                    aria-label={t('tasks:filters.searchAria')}
                  />
                </div>
                <div className="w-full lg:w-44">
                  <Select
                    value={query.status ?? ''}
                    onChange={(e) => updateParams({ status: e.target.value || null, page: '1' })}
                    options={statusFilterOptions}
                    aria-label={t('tasks:filters.statusAria')}
                  />
                </div>
                <div className="w-full lg:w-40">
                  <Select
                    value={query.priority ?? ''}
                    onChange={(e) => updateParams({ priority: e.target.value || null, page: '1' })}
                    options={priorityFilterOptions}
                    aria-label={t('tasks:filters.priorityAria')}
                  />
                </div>
                {!usersForbidden && (
                  <div className="w-full lg:w-44">
                    <Select
                      value={query.assigned_to ? String(query.assigned_to) : ''}
                      onChange={(e) => updateParams({ assigned_to: e.target.value || null, page: '1' })}
                      options={assignedFilterOptions}
                      aria-label={t('tasks:filters.assignedAria')}
                    />
                  </div>
                )}
                <div className="w-full lg:w-44">
                  <Select
                    value={query.taskable_type ?? ''}
                    onChange={(e) => updateParams({ taskable_type: e.target.value || null, page: '1' })}
                    options={taskableFilterOptions}
                    aria-label={t('tasks:filters.taskableAria')}
                  />
                </div>
              </div>
              <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
                <div className="flex w-full items-end gap-2 lg:w-auto">
                  <div className="w-full lg:w-40">
                    <Input
                      type="date"
                      value={query.from ?? ''}
                      onChange={(e) => updateParams({ from: e.target.value || null, page: '1' })}
                      aria-label={t('tasks:filters.fromAria')}
                      max={query.to || undefined}
                    />
                  </div>
                  <span className="pb-2.5 text-xs text-fg-muted">—</span>
                  <div className="w-full lg:w-40">
                    <Input
                      type="date"
                      value={query.to ?? ''}
                      onChange={(e) => updateParams({ to: e.target.value || null, page: '1' })}
                      aria-label={t('tasks:filters.toAria')}
                      min={query.from || undefined}
                    />
                  </div>
                </div>
                <label className="flex h-10 items-center gap-2 text-sm text-fg">
                  <Checkbox
                    checked={query.overdue === true}
                    onChange={(e) => updateParams({ overdue: e.target.checked ? '1' : null, page: '1' })}
                  />
                  {t('tasks:filters.overdueOnly')}
                </label>
              </div>
            </div>

            {isError ? (
              <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                <p className="text-sm text-fg-muted">{t('tasks:list.loadError')}</p>
                <Button variant="secondary" onClick={() => refetch()}>
                  {t('tasks:list.retry')}
                </Button>
              </div>
            ) : isEmpty ? (
              <EmptyState
                icon={<ListTodo className="size-6" aria-hidden="true" />}
                title={t('tasks:list.emptyTitle')}
                description={t('tasks:list.emptyDescription')}
              />
            ) : (
              <Table>
                <THead>
                  <Tr>
                    <Th aria-label={t('tasks:table.completedAria')} />
                    <Th sortable sortDirection={sortDirectionFor('title')} onSort={() => toggleSort('title')}>
                      {t('tasks:table.task')}
                    </Th>
                    <Th sortable sortDirection={sortDirectionFor('priority')} onSort={() => toggleSort('priority')}>
                      {t('tasks:table.priority')}
                    </Th>
                    <Th sortable sortDirection={sortDirectionFor('status')} onSort={() => toggleSort('status')}>
                      {t('tasks:table.status')}
                    </Th>
                    <Th sortable sortDirection={sortDirectionFor('due_at')} onSort={() => toggleSort('due_at')}>
                      {t('tasks:table.dueDate')}
                    </Th>
                    <Th>{t('tasks:table.assignee')}</Th>
                    <Th>{t('tasks:table.relatedRecord')}</Th>
                    <Th align="right">{t('tasks:table.actions')}</Th>
                  </Tr>
                </THead>
                <TBody aria-busy={isLoading}>
                  {isLoading
                    ? Array.from({ length: query.per_page ?? DEFAULT_PER_PAGE }).map((_, i) => (
                        <Tr key={i}>
                          <Td><Skeleton variant="text" width={16} /></Td>
                          <Td><Skeleton variant="text" width={200} /></Td>
                          <Td><Skeleton variant="text" width={70} /></Td>
                          <Td><Skeleton variant="text" width={90} /></Td>
                          <Td><Skeleton variant="text" width={110} /></Td>
                          <Td><Skeleton variant="text" width={90} /></Td>
                          <Td><Skeleton variant="text" width={100} /></Td>
                          <Td align="right"><Skeleton variant="text" width={90} className="ml-auto" /></Td>
                        </Tr>
                      ))
                    : tasks.map((task) => {
                        const meta = task.taskable ? relatedRecordMeta(task.taskable.type, t) : null
                        const Icon = meta?.icon
                        const isCompleting = completingIds.has(task.id)
                        const isHighlighted = highlightedTaskId === task.id
                        return (
                          <Tr
                            key={task.id}
                            ref={(el) => {
                              rowRefs.current[task.id] = el
                            }}
                            className={cn(
                              'transition-colors duration-300 motion-reduce:transition-none',
                              isHighlighted && 'bg-primary-tint'
                            )}
                          >
                            <Td>
                              {/* Faz 13: `tasks.update` izni yeterli değil — görev sahibine (`assigned_to`)
                                  atanan kişi, atanmamış görev ya da `tasks.assign` taşıyan biri
                                  tamamlayabilir (bkz. `TaskPolicy::complete`). İzin varken sadece
                                  sahiplik yüzünden engellendiğinde kutu GİZLENMEZ, devre dışı + tooltip
                                  ile neden gösterilir. */}
                              {can('tasks.update') && (
                                <Checkbox
                                  checked={task.status === 'completed'}
                                  disabled={task.status === 'cancelled' || isCompleting || !task.can.complete}
                                  onChange={(e) => handleQuickComplete(task, e.target.checked)}
                                  aria-label={t('tasks:row.completeAria', { title: task.title })}
                                  title={task.can.complete ? undefined : t('tasks:row.completeDisabledTitle')}
                                />
                              )}
                            </Td>
                            <Td>
                              <div className="flex items-center gap-2">
                                <p className={cn('font-medium text-fg', task.status === 'completed' && 'text-fg-muted line-through')}>
                                  {task.title}
                                </p>
                                <SyncStateBadge state={recordSyncState(task)} compact />
                              </div>
                              {task.description && <p className="mt-0.5 max-w-xs truncate text-xs text-fg-muted">{task.description}</p>}
                            </Td>
                            <Td>
                              <PriorityBadge priority={task.priority} />
                            </Td>
                            <Td>
                              <TaskStatusBadge status={task.status} />
                            </Td>
                            <Td className={cn(task.is_overdue && 'font-medium text-danger')}>
                              <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
                                {task.is_overdue && <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" />}
                                {formatDateTime(task.due_at)}
                              </span>
                            </Td>
                            <Td>
                              {task.assignee ? (
                                <div className="flex items-center gap-2">
                                  <Avatar name={task.assignee.name} size="xs" />
                                  <span className="truncate text-sm text-fg">{task.assignee.name}</span>
                                </div>
                              ) : (
                                <span className="text-fg-muted">—</span>
                              )}
                            </Td>
                            <Td>
                              {task.taskable && meta && Icon ? (
                                <Link
                                  to={meta.path(task.taskable.id)}
                                  className="inline-flex items-center gap-1.5 text-sm text-fg hover:text-primary hover:underline"
                                >
                                  <Icon className="size-4 shrink-0 text-fg-muted" aria-hidden="true" />
                                  <span className="max-w-40 truncate">{task.taskable.label ?? meta.label}</span>
                                </Link>
                              ) : (
                                <span className="text-fg-muted">—</span>
                              )}
                            </Td>
                            <Td align="right">
                              <div className="flex items-center justify-end gap-1">
                                {can('tasks.update') && (
                                  <IconButton
                                    label={t('tasks:row.edit')}
                                    disabled={!task.can.update}
                                    title={task.can.update ? t('tasks:row.edit') : t('tasks:row.editDisabledTitle')}
                                    onClick={() => setFormModal({ mode: 'edit', task })}
                                  >
                                    <Pencil className="size-4" aria-hidden="true" />
                                  </IconButton>
                                )}
                                {/* `tasks.assign` saf izin kontrolüdür (bkz. `TaskPolicy::assign`) —
                                    sahiplik boyutu yok, gizlemek yeterli. */}
                                {can('tasks.assign') && task.can.assign && (
                                  <IconButton label={t('tasks:row.assign')} onClick={() => setAssignTaskState(task)}>
                                    <UserCog className="size-4" aria-hidden="true" />
                                  </IconButton>
                                )}
                                {/* `tasks.delete` de saf izin kontrolüdür — `TaskPolicy::delete` sahiplik
                                    sormaz (görev silme yalnızca Müdür/Admin'de, bkz. policy gerekçesi). */}
                                {can('tasks.delete') && task.can.delete && (
                                  <IconButton label={t('tasks:row.delete')} danger onClick={() => setDeleteTaskState(task)}>
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
        ) : (
          <CardBody>
            <div className="mb-4 flex items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <Button variant="secondary" onClick={() => changeMonth(-1)} aria-label={t('tasks:calendar.prevMonth')}>
                  ‹
                </Button>
                <Button variant="secondary" onClick={goToToday}>
                  {t('tasks:calendar.today')}
                </Button>
                <Button variant="secondary" onClick={() => changeMonth(1)} aria-label={t('tasks:calendar.nextMonth')}>
                  ›
                </Button>
                <span className="text-sm font-medium text-fg">{monthLabel(calYear, calMonth)}</span>
              </div>
              <p className="text-xs text-fg-muted">{t('tasks:calendar.noDueDateNote')}</p>
            </div>
            {calendarLoading ? (
              <Skeleton height={480} />
            ) : (
              <CalendarGrid days={grid} tasksByDay={tasksByDay} onDayClick={handleDayClick} />
            )}
          </CardBody>
        )}
      </Card>

      <TaskFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        task={formModal?.mode === 'edit' ? formModal.task : null}
        defaultDueDate={formModal?.mode === 'create' ? formModal.defaultDueDate : null}
      />
      <AssignTaskModal open={!!assignTaskState} onClose={() => setAssignTaskState(null)} task={assignTaskState} />

      <DayTasksModal
        open={!!selectedDay}
        onClose={() => setSelectedDay(null)}
        date={selectedDay?.date ?? null}
        tasks={selectedDayTasks}
        completingIds={completingIds}
        onAddNew={() => {
          if (!selectedDay) return
          const ymd = selectedDay.ymd
          setSelectedDay(null)
          setFormModal({ mode: 'create', defaultDueDate: ymd })
        }}
        onEdit={(task) => {
          setSelectedDay(null)
          setFormModal({ mode: 'edit', task })
        }}
        onDelete={(task) => {
          setSelectedDay(null)
          setDeleteTaskState(task)
        }}
        onToggleComplete={handleQuickComplete}
      />

      <Modal
        open={!!deleteTaskState}
        onClose={() => setDeleteTaskState(null)}
        title={t('tasks:deleteModal.title')}
        description={t('tasks:deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteTaskState(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteTaskMutation.isPending}
              onClick={async () => {
                if (!deleteTaskState) return
                await deleteTaskMutation.mutateAsync(deleteTaskState.id)
                setDeleteTaskState(null)
              }}
            >
              {t('tasks:deleteModal.confirm')}
            </Button>
          </div>
        }
      >
        {deleteTaskState && (
          <p className="text-sm text-fg-secondary">
            <Trans
              t={t}
              i18nKey="tasks:deleteModal.confirmBody"
              values={{ title: deleteTaskState.title }}
              components={{ bold: <strong className="text-fg" /> }}
            />
          </p>
        )}
      </Modal>
    </div>
  )
}

function ViewButton({
  active,
  onClick,
  icon,
  children,
}: {
  active: boolean
  onClick: () => void
  icon: ReactNode
  children: ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-current={active ? 'page' : undefined}
      className={cn(
        'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium',
        'transition-colors duration-150 motion-reduce:transition-none',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
        active ? 'bg-primary-tint text-primary' : 'text-fg-muted hover:bg-surface-2 hover:text-fg'
      )}
    >
      {icon}
      {children}
    </button>
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
