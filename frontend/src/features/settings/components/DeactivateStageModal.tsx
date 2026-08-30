// Açık fırsatı olan bir aşama pasifleştirilmek istendiğinde açılan ikinci adım modalı.
//
// AKIŞ (görev tanımı): `PipelineStagesTab` önce `PATCH .../pipeline-stages/{id}` ile
// `{ is_active: false }` gönderir. Backend açık fırsat varsa 422 `STAGE_HAS_OPEN_DEALS` döner
// (`open_deals_count` + `available_stages`) — bu modal O ANDA açılır. Kullanıcı hedef aşamayı
// seçip onaylayınca `PipelineStagesTab` aynı ucu bu kez `{ is_active: false, move_to_stage_id }`
// ile tekrar çağırır.
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertTriangle } from 'lucide-react'
import { Button, Modal, Select } from '../../../components/ui'
import { stageLabel } from '../../deals/utils/stageLabel'

export type DeactivateStageModalProps = {
  open: boolean
  /** Zaten çözülmüş (translate edilmiş GEREKİYORSA) etiket — çağıran (`PipelineStagesTab`)
   *  `stageLabel()` ile hazırlar, bu bileşen ham `name`/`name_key` bilmez. */
  stageName: string
  openDealsCount: number
  /** `name_key` OPSİYONELDİR: backend `PipelineStageService::availableTargets()` seed edilmiş
   *  taksonomi satırları için doldurur, admin verisi olan hedeflerde NULL/eksik kalır (bkz.
   *  `stageLabel()`). */
  availableStages: { id: number; name: string; name_key?: string | null }[]
  isSubmitting: boolean
  onClose: () => void
  onConfirm: (moveToStageId: number) => void
}

export function DeactivateStageModal({
  open,
  stageName,
  openDealsCount,
  availableStages,
  isSubmitting,
  onClose,
  onConfirm,
}: DeactivateStageModalProps) {
  const { t } = useTranslation(['settings', 'common'])
  const [selectedId, setSelectedId] = useState<string>('')

  const options = availableStages.map((stage) => ({ value: String(stage.id), label: stageLabel(t, stage) }))

  return (
    <Modal
      open={open}
      onClose={() => {
        setSelectedId('')
        onClose()
      }}
      title={t('settings:deactivateStageModal.title')}
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting}>
            {t('common:actions.cancel')}
          </Button>
          <Button
            type="button"
            variant="primary"
            loading={isSubmitting}
            disabled={!selectedId}
            onClick={() => selectedId && onConfirm(Number(selectedId))}
          >
            {t('settings:deactivateStageModal.confirm')}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="flex items-start gap-2 rounded-md bg-warning-tint px-3 py-2 text-sm text-warning">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>
            <strong>{stageName}</strong> {t('settings:deactivateStageModal.warning', { count: openDealsCount })}
          </span>
        </div>

        <Select
          label={t('settings:deactivateStageModal.targetLabel')}
          value={selectedId}
          onChange={(e) => setSelectedId(e.target.value)}
          options={options}
          placeholder={t('settings:deactivateStageModal.targetPlaceholder')}
        />
      </div>
    </Modal>
  )
}
