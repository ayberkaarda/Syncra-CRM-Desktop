// Kayıp/kazanma nedeni sorgusu — kart kapanış aşamasına bırakıldığında, İSTEK GÖNDERİLMEDEN
// ÖNCE açılır.
//
// Neden önce sorulur: `lost_reason` sunucuda ZORUNLUDUR. Önce istek gönderip 422 aldıktan
// sonra sormak, kullanıcıya önce bir hata gösterip sonra form açmak demektir; üstelik iyimser
// güncelleme bu arada geri alınıp tekrar uygulanacağı için kart iki kez zıplar.
//
// İptal, "kartı yine de taşı" anlamına GELMEZ: iyimser güncelleme geri alınır ve istek hiç
// gitmez (bkz. `useDealBoard.cancelReason`).
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal, Textarea } from '../../../../components/ui'
import type { PendingReasonMove } from '../../hooks/useDealBoard'

export type StageReasonModalProps = {
  pending: PendingReasonMove | null
  onSubmit: (reason: string) => void
  onCancel: () => void
}

export function StageReasonModal({ pending, onSubmit, onCancel }: StageReasonModalProps) {
  const { t } = useTranslation('deals')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | undefined>(undefined)

  // Yeni bir bekleyen taşıma geldiğinde alan sıfırlanır; aksi hâlde bir önceki kartın
  // nedeni yeni kartta hazır dururdu. Bu, efektle değil RENDER SIRASINDA prop değişimine
  // uyum deseniyle yapılır: efektle yazılsaydı modal bir kare eski metinle çizilir, sonra
  // ikinci bir render'da temizlenirdi.
  const pendingKey = pending ? `${pending.dealId}-${pending.kind}` : null
  const [lastPendingKey, setLastPendingKey] = useState<string | null>(pendingKey)
  if (pendingKey !== lastPendingKey) {
    setLastPendingKey(pendingKey)
    setReason('')
    setError(undefined)
  }

  const isLost = pending?.kind === 'lost'

  function handleSubmit() {
    if (isLost && reason.trim() === '') {
      setError(t('stageReason.lostRequired'))
      return
    }
    onSubmit(reason)
  }

  return (
    <Modal
      open={pending !== null}
      onClose={onCancel}
      // Zorunlu alan varken arka plana tıklayarak kapatmak, kullanıcının farkında olmadan
      // taşımayı iptal etmesine yol açar.
      closeOnBackdrop={!isLost}
      size="md"
      title={isLost ? t('stageReason.titleLost') : t('stageReason.titleWon')}
      description={
        pending
          ? t('stageReason.description', { dealTitle: pending.dealTitle, stageName: pending.stageName })
          : undefined
      }
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onCancel}>
            {t('stageReason.cancel')}
          </Button>
          <Button variant={isLost ? 'danger' : 'primary'} onClick={handleSubmit}>
            {isLost ? t('stageReason.submitLost') : t('stageReason.submitWon')}
          </Button>
        </div>
      }
    >
      <Textarea
        label={isLost ? t('stageReason.fieldLabelLost') : t('stageReason.fieldLabelWon')}
        value={reason}
        onChange={(event) => {
          setReason(event.target.value)
          if (error) setError(undefined)
        }}
        error={error}
        maxLength={255}
        rows={3}
        placeholder={isLost ? t('stageReason.placeholderLost') : t('stageReason.placeholderWon')}
        hint={isLost ? t('stageReason.hintLost') : t('stageReason.hintWon')}
      />
    </Modal>
  )
}
