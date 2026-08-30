// Pipeline Aşamaları sekmesi — mevcut Kanban'ı (features/deals) KIRMADAN aşama
// ekleme/düzenleme/sıralama/pasifleştirme.
//
// SÜRÜKLE-BIRAK SIRALAMA: `@dnd-kit/core` + `@dnd-kit/sortable`, `features/deals` Kanban'ındaki
// desenle aynı (`PointerSensor` + `activationConstraint.distance` — eşik olmadan tıklama bile
// sürüklemeyi tetikler; `KeyboardSensor` + `sortableKeyboardCoordinates` — WCAG 2.1 AA). Burada
// tek bir dikey liste olduğu için `useDealBoard` kadar karmaşık bir onDragOver hesaplaması
// GEREKMEZ: bırakınca `arrayMove` ile yeni id sırası hesaplanır ve `useReorderPipelineStages`
// çağrılır — o hook cache'i iyimser günceller, hata olursa kendi içinde geri alır (bkz.
// `hooks/usePipelineStages.ts`).
//
// PASİFLEŞTİRME İKİ ADIMLI (görev tanımı — KESİN KARAR):
// 1. `PATCH .../pipeline-stages/{id}` `{ is_active: false }`.
// 2. Backend açık fırsat varsa 422 `STAGE_HAS_OPEN_DEALS` döner → `DeactivateStageModal` açılır.
// 3. Kullanıcı hedef aşamayı seçince aynı uç `{ is_active: false, move_to_stage_id }` ile
//    tekrar çağrılır. Başarıda taşınan fırsat sayısını içeren özel bir toast gösterilir.
//
// SİSTEM AŞAMALARI: `is_won` / `is_lost` aşamalar SİLİNEMEZ VE PASİFLEŞTİRİLEMEZ (backend 422
// `STAGE_IS_SYSTEM`). Bu yüzden bu aşamalarda pasifleştir kontrolü baştan DEVRE DIŞI
// bırakılır — kullanıcı 422'yi hiç görmez. DELETE ucu/butonu YOK; aşamalar hiçbir zaman
// silinemez, yalnızca pasifleştirilip aktifleştirilebilir.
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import type { DragEndEvent } from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { CircleSlash2, GripVertical, Lock, Pencil, Plus, Power, PowerOff, Trophy } from 'lucide-react'
import { Badge, Button, EmptyState, Skeleton, toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import { cn } from '../../../lib/cn'
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import { extractStageHasOpenDeals } from '../api'
import { usePipelineStages, useReorderPipelineStages, useUpdatePipelineStage } from '../hooks/usePipelineStages'
import { PipelineStageFormModal } from './PipelineStageFormModal'
import { DeactivateStageModal } from './DeactivateStageModal'
import { stageLabel } from '../../deals/utils/stageLabel'
import type { PipelineStage, StageHasOpenDealsPayload } from '../types'

type FormModalState = { mode: 'create' } | { mode: 'edit'; stage: PipelineStage } | null

type PendingDeactivation = {
  stage: PipelineStage
  details: StageHasOpenDealsPayload
} | null

export function PipelineStagesTab() {
  const { t } = useTranslation(['settings', 'common'])
  const { data, isLoading, isError, refetch } = usePipelineStages()
  const reorderStages = useReorderPipelineStages()
  const updateStage = useUpdatePipelineStage()

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [pendingDeactivation, setPendingDeactivation] = useState<PendingDeactivation>(null)
  const [busyStageId, setBusyStageId] = useState<number | null>(null)

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  const stages = [...(data ?? [])].sort((a, b) => a.position - b.position)

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event
    if (!over || active.id === over.id) return

    const oldIndex = stages.findIndex((stage) => stage.id === active.id)
    const newIndex = stages.findIndex((stage) => stage.id === over.id)
    if (oldIndex === -1 || newIndex === -1) return

    const reordered = arrayMove(stages, oldIndex, newIndex)
    reorderStages.mutate(reordered.map((stage) => stage.id))
  }

  async function handleToggleActive(stage: PipelineStage) {
    setBusyStageId(stage.id)
    try {
      if (!stage.is_active) {
        await updateStage.mutateAsync({ id: stage.id, payload: { is_active: true } })
        toast.success(t('settings:pipeline.toast.activated', { name: stageLabel(t, stage) }))
        return
      }

      try {
        await updateStage.mutateAsync({ id: stage.id, payload: { is_active: false } })
        toast.success(t('settings:pipeline.toast.deactivated', { name: stageLabel(t, stage) }))
      } catch (error) {
        const details = extractStageHasOpenDeals(error)
        if (details) {
          setPendingDeactivation({ stage, details })
          return
        }
        toast.error(getErrorMessage(error))
      }
    } finally {
      setBusyStageId(null)
    }
  }

  async function handleConfirmMoveAndDeactivate(moveToStageId: number) {
    if (!pendingDeactivation) return
    const { stage, details } = pendingDeactivation
    const targetStage = details.available_stages.find((s) => s.id === moveToStageId)

    setBusyStageId(stage.id)
    try {
      await updateStage.mutateAsync({
        id: stage.id,
        payload: { is_active: false, move_to_stage_id: moveToStageId },
      })
      toast.success(
        t('settings:pipeline.toast.movedAndDeactivated', {
          count: details.open_deals_count,
          target: targetStage ? stageLabel(t, targetStage) : t('settings:pipeline.movedTargetFallback'),
        })
      )
      setPendingDeactivation(null)
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setBusyStageId(null)
    }
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true">
        {Array.from({ length: 5 }).map((_, i) => (
          <Skeleton key={i} variant="rect" height={56} />
        ))}
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('settings:pipeline.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-fg-muted">{t('settings:pipeline.dragHint')}</p>
        <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
          {t('settings:pipeline.newStage')}
        </Button>
      </div>

      {stages.length === 0 ? (
        <EmptyState title={t('settings:pipeline.empty.title')} description={t('settings:pipeline.empty.description')} />
      ) : (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
          <SortableContext items={stages.map((stage) => stage.id)} strategy={verticalListSortingStrategy}>
            <div className="flex flex-col gap-2">
              {stages.map((stage) => (
                <StageRow
                  key={stage.id}
                  stage={stage}
                  busy={busyStageId === stage.id}
                  t={t}
                  onEdit={() => setFormModal({ mode: 'edit', stage })}
                  onToggleActive={() => void handleToggleActive(stage)}
                />
              ))}
            </div>
          </SortableContext>
        </DndContext>
      )}

      <PipelineStageFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        stage={formModal?.mode === 'edit' ? formModal.stage : null}
      />

      {pendingDeactivation && (
        <DeactivateStageModal
          open
          stageName={stageLabel(t, pendingDeactivation.stage)}
          openDealsCount={pendingDeactivation.details.open_deals_count}
          availableStages={pendingDeactivation.details.available_stages}
          isSubmitting={busyStageId === pendingDeactivation.stage.id}
          onClose={() => setPendingDeactivation(null)}
          onConfirm={(moveToStageId) => void handleConfirmMoveAndDeactivate(moveToStageId)}
        />
      )}
    </div>
  )
}

type StageRowProps = {
  stage: PipelineStage
  busy: boolean
  t: TFunction
  onEdit: () => void
  onToggleActive: () => void
}

function StageRow({ stage, busy, t, onEdit, onToggleActive }: StageRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: stage.id })
  const isSystemStage = stage.is_won || stage.is_lost
  // Liste GÖSTERİMİ çevrilir; düzenleme formu (`PipelineStageFormModal`) ise ham `stage.name`
  // gösterir — admin DÜZENLERKEN ne yazdığını görmelidir, çevrilmiş etiketi değil (bkz. görev
  // tanımı ve `PipelineStageFormModal.tsx`da `value={name}` state'inin `stage?.name`den
  // KURULMASI, `stageLabel()` HİÇ ÇAĞRILMAMASI).
  const label = stageLabel(t, stage)

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        'flex items-center gap-3 rounded-lg border border-border-subtle bg-surface-1 px-3 py-2.5 shadow-card',
        isDragging && 'opacity-50',
        !stage.is_active && 'opacity-60'
      )}
    >
      <button
        type="button"
        {...attributes}
        {...listeners}
        aria-label={t('settings:pipeline.reorderAria', { name: label })}
        className="cursor-grab touch-none rounded-sm p-1 text-fg-muted hover:bg-surface-2 hover:text-fg active:cursor-grabbing focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        <GripVertical className="size-4" aria-hidden="true" />
      </button>

      <Badge variant={tokenBadgeVariant(stage.color)} size="sm">
        {label}
      </Badge>

      <span className="text-xs text-fg-muted">%{stage.probability}</span>

      {/* `deals_count`: pasifleştirmeden önce kullanıcıya bağlam verir (bkz. görev tanımı). */}
      <Badge variant="neutral" size="sm">
        {t('settings:pipeline.dealsCount', { count: stage.deals_count })}
      </Badge>

      {stage.is_won && (
        <Badge variant="success" size="sm">
          <Trophy className="size-3" aria-hidden="true" /> {t('settings:pipeline.systemWon')}
        </Badge>
      )}
      {stage.is_lost && (
        <Badge variant="danger" size="sm">
          <CircleSlash2 className="size-3" aria-hidden="true" /> {t('settings:pipeline.systemLost')}
        </Badge>
      )}

      <Badge variant={stage.is_active ? 'success' : 'neutral'} size="sm">
        {stage.is_active ? t('settings:status.active') : t('settings:status.inactive')}
      </Badge>

      <div className="ml-auto flex items-center gap-1">
        <Button variant="ghost" size="sm" leftIcon={<Pencil className="size-3.5" aria-hidden="true" />} onClick={onEdit}>
          {t('common:actions.edit')}
        </Button>
        <Button
          variant="ghost"
          size="sm"
          leftIcon={
            isSystemStage ? (
              <Lock className="size-3.5" aria-hidden="true" />
            ) : stage.is_active ? (
              <PowerOff className="size-3.5" aria-hidden="true" />
            ) : (
              <Power className="size-3.5" aria-hidden="true" />
            )
          }
          disabled={isSystemStage || busy}
          loading={busy}
          onClick={onToggleActive}
          title={isSystemStage ? t('settings:pipeline.deactivateDisabledTitle') : undefined}
        >
          {stage.is_active ? t('settings:customFields.deactivate') : t('settings:customFields.activate')}
        </Button>
      </div>
    </div>
  )
}
