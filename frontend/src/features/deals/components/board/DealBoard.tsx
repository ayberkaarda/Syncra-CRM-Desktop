// Sürükle-bırak bağlamı ve sütun şeridi.
//
// `closestCorners`: çok kapsayıcılı (multi-container) sortable için doğru toplayıcı.
// `closestCenter` dar ve uzun sütunlarda, imleç komşu sütunun üzerindeyken bile kendi
// sütunundaki bir kartı "en yakın" bulup kartın sütun değiştirmesini engeller.
//
// `MeasuringStrategy.Always`: kartlar sürükleme sırasında yer değiştirdiği için sütun
// kutuları sürekli değişir. Varsayılan strateji ölçümü sürüklemenin başında dondurur ve
// uzun sütunlarda bırakma hedefi kaymış rect'lere göre hesaplanır.
import { DndContext, DragOverlay, MeasuringStrategy, closestCorners } from '@dnd-kit/core'
import type { Announcements, ScreenReaderInstructions } from '@dnd-kit/core'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { parseColumnId } from '../../hooks/useDealBoard'
import { BoardStageColumn } from './BoardStageColumn'
import { DealCardPreview } from './DealBoardCard'
import { stageLabel } from '../../utils/stageLabel'
import type { UseDealBoardResult } from '../../hooks/useDealBoard'
import type { BoardResponse } from '../../types'

function cardTitle(board: BoardResponse, id: string | number, t: TFunction): string {
  for (const column of board.data) {
    const deal = column.deals.find((entry) => String(entry.id) === String(id))
    if (deal) return deal.title
  }
  return t('board.announcements.defaultCardName')
}

function stageName(board: BoardResponse, overId: string | number | undefined, t: TFunction): string | null {
  if (overId === undefined) return null
  const stageId = parseColumnId(overId)
  if (stageId !== null) {
    const column = board.data.find((entry) => entry.stage.id === stageId)
    return column ? stageLabel(t, column.stage) : null
  }
  const raw = String(overId)
  const column = board.data.find((entry) => entry.deals.some((deal) => String(deal.id) === raw))
  return column ? stageLabel(t, column.stage) : null
}

export type DealBoardProps = {
  board: BoardResponse
  dnd: UseDealBoardResult
  dragEnabled: boolean
  canCreate: boolean
  onCreate: (stageId: number) => void
  recentlyMoved: Record<number, string>
}

export function DealBoard({
  board,
  dnd,
  dragEnabled,
  canCreate,
  onCreate,
  recentlyMoved,
}: DealBoardProps) {
  const { t } = useTranslation('deals')

  const screenReaderInstructions: ScreenReaderInstructions = {
    draggable: t('board.announcements.keyboardInstructions'),
  }

  const announcements: Announcements = {
    onDragStart: ({ active }) =>
      t('board.announcements.pickedUp', { title: cardTitle(board, active.id, t) }),
    onDragOver: ({ active, over }) => {
      const stage = stageName(board, over?.id, t)
      return stage
        ? t('board.announcements.overStage', { title: cardTitle(board, active.id, t), stage })
        : t('board.announcements.overNone', { title: cardTitle(board, active.id, t) })
    },
    onDragEnd: ({ active, over }) => {
      const stage = stageName(board, over?.id, t)
      return stage
        ? t('board.announcements.droppedOnStage', { title: cardTitle(board, active.id, t), stage })
        : t('board.announcements.droppedBack', { title: cardTitle(board, active.id, t) })
    },
    onDragCancel: ({ active }) =>
      t('board.announcements.cancelled', { title: cardTitle(board, active.id, t) }),
  }

  return (
    <DndContext
      sensors={dnd.sensors}
      collisionDetection={closestCorners}
      measuring={{ droppable: { strategy: MeasuringStrategy.Always } }}
      accessibility={{ announcements, screenReaderInstructions }}
      onDragStart={dnd.onDragStart}
      onDragOver={dnd.onDragOver}
      onDragEnd={dnd.onDragEnd}
      onDragCancel={dnd.onDragCancel}
    >
      <div className="flex h-full gap-3 overflow-x-auto pb-2">
        {board.data.map((column) => (
          <BoardStageColumn
            key={column.stage.id}
            column={column}
            currency={board.meta.currency}
            dragEnabled={dragEnabled}
            canCreate={canCreate}
            onCreate={onCreate}
            recentlyMoved={recentlyMoved}
          />
        ))}
      </div>

      <DragOverlay>{dnd.activeCard ? <DealCardPreview card={dnd.activeCard} /> : null}</DragOverlay>
    </DndContext>
  )
}
