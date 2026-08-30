// Pipeline aşaması oluşturma/düzenleme modalı. `stage` prop'u verilmezse oluşturma modu.
//
// `is_won` / `is_lost` alanları burada HİÇ düzenlenemez — bunlar sistem aşamalarının sabit
// nitelikleridir, yeni oluşturulan bir aşama her zaman normal (ne kazanma ne kayıp) bir
// aşamadır. Düzenleme modunda sistem aşaması olduğu bir rozetle bilgilendirilir.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Input, Modal, Select } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import { useCreatePipelineStage, useUpdatePipelineStage } from '../hooks/usePipelineStages'
import { STAGE_COLOR_TOKENS } from '../types'
import type { PipelineStage } from '../types'

export type PipelineStageFormModalProps = {
  open: boolean
  onClose: () => void
  stage?: PipelineStage | null
}

export function PipelineStageFormModal({ open, onClose, stage }: PipelineStageFormModalProps) {
  const { t } = useTranslation(['settings', 'common'])
  const isEdit = !!stage

  const createStage = useCreatePipelineStage()
  const updateStage = useUpdatePipelineStage()

  const COLOR_OPTIONS = STAGE_COLOR_TOKENS.map((token) => ({
    value: token,
    label: t(`settings:pipelineStageForm.colors.${token}`),
  }))

  const [name, setName] = useState('')
  const [probability, setProbability] = useState('50')
  const [color, setColor] = useState<string>('neutral')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const openKey = open ? (stage ? `edit-${stage.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setName(stage?.name ?? '')
      setProbability(String(stage?.probability ?? 50))
      setColor(stage?.color ?? 'neutral')
      setFieldErrors({})
    }
  }

  const isPending = createStage.isPending || updateStage.isPending

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!name.trim()) errors.name = [t('settings:pipelineStageForm.errors.nameRequired')]
    const probabilityNum = Number(probability)
    if (probability.trim() === '' || Number.isNaN(probabilityNum) || probabilityNum < 0 || probabilityNum > 100) {
      errors.probability = [t('settings:pipelineStageForm.errors.probabilityInvalid')]
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    try {
      if (isEdit && stage) {
        await updateStage.mutateAsync({
          id: stage.id,
          payload: { name, probability: Number(probability), color },
        })
      } else {
        await createStage.mutateAsync({ name, probability: Number(probability), color })
      }
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? t('settings:pipelineStageForm.titleEdit') : t('settings:pipelineStageForm.titleCreate')}
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" form="pipeline-stage-form" loading={isPending}>
            {isEdit ? t('common:actions.save') : t('common:actions.create')}
          </Button>
        </div>
      }
    >
      <form id="pipeline-stage-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        {isEdit && stage && (stage.is_won || stage.is_lost) && (
          <div className="flex items-center gap-2 rounded-md bg-surface-2 px-3 py-2">
            <Badge variant={stage.is_won ? 'success' : 'danger'}>
              {stage.is_won ? t('settings:pipelineStageForm.systemBadgeWon') : t('settings:pipelineStageForm.systemBadgeLost')}
            </Badge>
            <span className="text-xs text-fg-muted">{t('settings:pipelineStageForm.systemNote')}</span>
          </div>
        )}

        <Input
          label={t('settings:pipelineStageForm.nameLabel')}
          value={name}
          onChange={(e) => setName(e.target.value)}
          error={fieldError('name')}
          required
        />

        <Input
          label={t('settings:pipelineStageForm.probabilityLabel')}
          type="number"
          min={0}
          max={100}
          value={probability}
          onChange={(e) => setProbability(e.target.value)}
          error={fieldError('probability')}
          required
        />

        <div className="flex flex-col gap-2">
          <Select
            label={t('settings:pipelineStageForm.colorLabel')}
            value={color}
            onChange={(e) => setColor(e.target.value)}
            options={COLOR_OPTIONS}
            error={fieldError('color')}
          />
          <div className="flex items-center gap-2">
            <span className="text-xs text-fg-muted">{t('settings:pipelineStageForm.previewLabel')}</span>
            <Badge variant={tokenBadgeVariant(color)}>{name || t('settings:pipelineStageForm.previewDefaultName')}</Badge>
          </div>
        </div>
      </form>
    </Modal>
  )
}
