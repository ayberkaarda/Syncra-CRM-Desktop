// Pano sütunu — bir pipeline aşaması.
//
// Sütunun KENDİSİ de bir bırakma hedefidir (`useDroppable`), yalnızca içindeki kartlar değil:
// boş bir aşamaya ya da son kartın altındaki boşluğa bırakabilmenin başka yolu yok.
//
// Başlıktaki tutar `meta.total_amount`'tan gelir: aşamadaki TÜM kartların toplamıdır,
// yüklenen kart sayısından bağımsızdır. Yüklü kartların tutarlarını toplamak `has_more`
// olan bir sütunda gerçeğin altında bir sayı gösterirdi.
import { useDroppable } from '@dnd-kit/core'
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { useTranslation } from 'react-i18next'
import { CircleSlash2, Plus, Trophy } from 'lucide-react'
import { Badge, Button } from '../../../../components/ui'
import { cn } from '../../../../lib/cn'
import { columnDroppableId } from '../../hooks/useDealBoard'
import { formatAmount, stageAccentClass } from './boardUtils'
import { DealBoardCard } from './DealBoardCard'
import { stageLabel } from '../../utils/stageLabel'
import type { BoardColumn } from '../../types'

export type BoardStageColumnProps = {
  column: BoardColumn
  currency: string
  dragEnabled: boolean
  canCreate: boolean
  onCreate: (stageId: number) => void
  recentlyMoved: Record<number, string>
}

export function BoardStageColumn({
  column,
  currency,
  dragEnabled,
  canCreate,
  onCreate,
  recentlyMoved,
}: BoardStageColumnProps) {
  const { t } = useTranslation('deals')
  const { stage, deals, meta } = column
  const { setNodeRef, isOver } = useDroppable({ id: columnDroppableId(stage.id) })

  const notLoaded = Math.max(0, meta.count - deals.length)
  const label = stageLabel(t, stage)

  return (
    <section
      aria-label={t('board.column.aria', { stage: label, count: meta.count })}
      className="flex h-full w-80 shrink-0 flex-col overflow-hidden rounded-xl border border-border-subtle bg-surface-2"
    >
      <div className={cn('h-1 w-full', stageAccentClass(stage.color))} aria-hidden="true" />

      <header
        className={cn(
          'flex flex-col gap-0.5 border-b border-border-subtle px-3 py-2.5',
          // Kazanıldı/Kaybedildi aşamaları panonun geri kalanından görsel olarak ayrışır:
          // bunlar bir sonraki adım değil, hattın SONUDUR.
          stage.is_won && 'bg-success-tint',
          stage.is_lost && 'bg-danger-tint'
        )}
      >
        <div className="flex items-center justify-between gap-2">
          <h2
            className={cn(
              'flex min-w-0 items-center gap-1.5 text-sm font-semibold',
              stage.is_won ? 'text-success' : stage.is_lost ? 'text-danger' : 'text-fg'
            )}
          >
            {stage.is_won && <Trophy className="size-4 shrink-0" aria-hidden="true" />}
            {stage.is_lost && <CircleSlash2 className="size-4 shrink-0" aria-hidden="true" />}
            <span className="truncate">{label}</span>
          </h2>
          <Badge size="sm" variant="neutral">
            {meta.count}
          </Badge>
        </div>
        <p className="text-xs text-fg-secondary">{formatAmount(meta.total_amount, currency)}</p>
      </header>

      <div
        ref={setNodeRef}
        className={cn(
          'flex-1 overflow-y-auto p-2 transition-colors motion-reduce:transition-none',
          isOver && 'bg-surface-3'
        )}
      >
        <SortableContext items={deals.map((deal) => deal.id)} strategy={verticalListSortingStrategy}>
          <div className="flex flex-col gap-2">
            {deals.map((deal) => (
              <DealBoardCard
                key={deal.id}
                card={deal}
                dragEnabled={dragEnabled}
                movedBy={recentlyMoved[deal.id]}
              />
            ))}
          </div>
        </SortableContext>

        {deals.length === 0 && (
          <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-xs text-fg-muted">
            {t('board.column.empty')}
          </p>
        )}

        {meta.has_more && (
          <p className="pt-2 text-center text-xs text-fg-muted">
            {t('board.column.moreHidden', { count: notLoaded })}
          </p>
        )}
      </div>

      {canCreate && (
        <footer className="border-t border-border-subtle p-2">
          <Button
            variant="ghost"
            size="sm"
            fullWidth
            leftIcon={<Plus className="size-4" aria-hidden="true" />}
            onClick={() => onCreate(stage.id)}
          >
            {t('board.column.addDeal')}
          </Button>
        </footer>
      )}
    </section>
  )
}
