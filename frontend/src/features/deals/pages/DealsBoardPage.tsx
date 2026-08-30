// Fırsatlar — Kanban panosu (`/deals`, varsayılan görünüm).
//
// Sayfa yalnızca düzen ve URL durumundan sorumludur; sürükle-bırak/iyimser güncelleme
// `useDealBoard`, gerçek zamanlı senkron `useDealRealtime` içinde. Filtrelerin tamamı query
// string'de: pano bağlantısı paylaşılabilir ve tarayıcı geri tuşu beklendiği gibi çalışır.
import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { KanbanSquare, List, Plus, RefreshCw, TriangleAlert, WifiOff } from 'lucide-react'
import { Button, EmptyState, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { usePermission } from '../../auth/hooks/usePermission'
import { useDealBoard } from '../hooks/useDealBoard'
import { useDealRealtime } from '../hooks/useDealRealtime'
import { BoardFilters } from '../components/board/BoardFilters'
import type { BoardFilterValues } from '../components/board/BoardFilters'
import { BoardSkeleton } from '../components/board/BoardSkeleton'
import { DealBoard } from '../components/board/DealBoard'
import { StageReasonModal } from '../components/board/StageReasonModal'
import { formatAmount } from '../components/board/boardUtils'
import { DealFormModal } from '../components/DealFormModal'
import type { BoardFilters as BoardFilterQuery } from '../types'

const PER_STAGE = 50
/** Bağlantı uyarısını göstermeden önce beklenen süre — ilk el sıkışma sırasında yanıp sönmesin. */
const OFFLINE_GRACE_MS = 2000

const FILTER_KEYS: Array<keyof BoardFilterValues> = ['q', 'owner_id', 'company_id', 'from', 'to']

export function DealsBoardPage() {
  const { t } = useTranslation('deals')
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()
  const canMove = can('deals.move')
  const canCreate = can('deals.create')

  const [createStageId, setCreateStageId] = useState<number | null>(null)
  const [formOpen, setFormOpen] = useState(false)

  const filterValues = useMemo<BoardFilterValues>(
    () => ({
      q: searchParams.get('q') ?? '',
      owner_id: searchParams.get('owner_id') ?? '',
      company_id: searchParams.get('company_id') ?? '',
      from: searchParams.get('from') ?? '',
      to: searchParams.get('to') ?? '',
    }),
    [searchParams]
  )

  const filters = useMemo<BoardFilterQuery>(
    () => ({
      q: filterValues.q || undefined,
      owner_id: filterValues.owner_id ? Number(filterValues.owner_id) : undefined,
      company_id: filterValues.company_id ? Number(filterValues.company_id) : undefined,
      from: filterValues.from || undefined,
      to: filterValues.to || undefined,
      per_stage: PER_STAGE,
    }),
    [filterValues]
  )

  const dnd = useDealBoard(filters)
  const realtime = useDealRealtime(filters)

  // Bağlantı uyarısı TÜRETİLMİŞ bir değerdir: "kopukluk yeterince sürdü" bayrağı ile o anki
  // bağlantı durumunun bileşimi. Bağlantı geri geldiğinde uyarı, efektte bir state yazmadan,
  // doğrudan `isConnected` üzerinden kaybolur.
  const [offlineArmed, setOfflineArmed] = useState(false)
  useEffect(() => {
    if (realtime.isConnected) return
    const timeout = setTimeout(() => setOfflineArmed(true), OFFLINE_GRACE_MS)
    return () => clearTimeout(timeout)
  }, [realtime.isConnected])
  const showOffline = offlineArmed && !realtime.isConnected

  function applyFilters(patch: Partial<BoardFilterValues>) {
    setSearchParams((previous) => {
      const next = new URLSearchParams(previous)
      for (const [key, value] of Object.entries(patch)) {
        if (!value) next.delete(key)
        else next.set(key, value)
      }
      return next
    })
  }

  function clearFilters() {
    setSearchParams((previous) => {
      const next = new URLSearchParams(previous)
      FILTER_KEYS.forEach((key) => next.delete(key))
      return next
    })
  }

  function openCreate(stageId?: number) {
    setCreateStageId(stageId ?? null)
    setFormOpen(true)
  }

  const board = dnd.board
  const isEmpty = board !== undefined && board.data.every((column) => column.meta.count === 0)

  return (
    <div className="flex h-full min-h-0 flex-col gap-4">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-col gap-0.5">
          <h1 className="text-xl font-semibold text-fg">{t('board.title')}</h1>
          {board ? (
            <p className="text-sm text-fg-muted">
              {t('board.openTotalLabel')}{' '}
              <span className="font-medium text-fg-secondary">
                {formatAmount(board.meta.total_open_amount, board.meta.currency)}
              </span>
            </p>
          ) : (
            <Skeleton height={20} width={220} />
          )}
        </div>

        <div className="flex items-center gap-2">
          {/* Görünüm değiştirici — pano ile liste aynı veriyi iki farklı biçimde gösterir. */}
          <div
            className="flex items-center gap-1 rounded-lg border border-border bg-surface-1 p-1"
            role="group"
            aria-label={t('board.viewSwitcher.aria')}
          >
            <span
              aria-current="page"
              className={cn(
                'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium',
                'bg-primary-tint text-primary'
              )}
            >
              <KanbanSquare className="size-4" aria-hidden="true" />
              {t('board.viewSwitcher.board')}
            </span>
            <Link
              to="/deals/list"
              className="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium text-fg-muted hover:bg-surface-2 hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              <List className="size-4" aria-hidden="true" />
              {t('board.viewSwitcher.list')}
            </Link>
          </div>

          <Button
            variant="secondary"
            leftIcon={<RefreshCw className="size-4" aria-hidden="true" />}
            onClick={dnd.refetch}
            loading={dnd.isFetching && !dnd.isLoading}
          >
            {t('board.refresh')}
          </Button>

          {canCreate && (
            <Button
              leftIcon={<Plus className="size-4" aria-hidden="true" />}
              onClick={() => openCreate()}
            >
              {t('board.newDeal')}
            </Button>
          )}
        </div>
      </header>

      <BoardFilters values={filterValues} onChange={applyFilters} onClear={clearFilters} />

      {showOffline && (
        <p
          role="status"
          className="flex items-center gap-2 rounded-lg border border-warning bg-warning-tint px-3 py-2 text-sm text-warning"
        >
          <WifiOff className="size-4 shrink-0" aria-hidden="true" />
          {t('board.offlineWarning')}
        </p>
      )}

      {!canMove && (
        <p className="text-xs text-fg-muted">{t('board.readOnlyHint')}</p>
      )}

      <div className="min-h-96 flex-1">
        {dnd.isLoading && <BoardSkeleton />}

        {dnd.isError && (
          <EmptyState
            icon={<TriangleAlert className="size-6" aria-hidden="true" />}
            title={t('board.loadError.title')}
            description={t('board.loadError.description')}
            action={
              <Button
                variant="secondary"
                leftIcon={<RefreshCw className="size-4" aria-hidden="true" />}
                onClick={dnd.refetch}
              >
                {t('board.loadError.retry')}
              </Button>
            }
          />
        )}

        {board && !dnd.isError && isEmpty && (
          <EmptyState
            icon={<KanbanSquare className="size-6" aria-hidden="true" />}
            title={t('board.empty.title')}
            description={t('board.empty.description')}
            action={
              canCreate ? (
                <Button
                  leftIcon={<Plus className="size-4" aria-hidden="true" />}
                  onClick={() => openCreate()}
                >
                  {t('board.empty.create')}
                </Button>
              ) : undefined
            }
          />
        )}

        {board && !dnd.isError && !isEmpty && (
          <DealBoard
            board={board}
            dnd={dnd}
            dragEnabled={canMove}
            canCreate={canCreate}
            onCreate={openCreate}
            recentlyMoved={realtime.recentlyMoved}
          />
        )}
      </div>

      <StageReasonModal
        pending={dnd.pendingReason}
        onSubmit={dnd.submitReason}
        onCancel={dnd.cancelReason}
      />

      <DealFormModal
        open={formOpen}
        onClose={() => {
          setFormOpen(false)
          setCreateStageId(null)
        }}
        defaultStageId={createStageId ?? undefined}
      />
    </div>
  )
}
