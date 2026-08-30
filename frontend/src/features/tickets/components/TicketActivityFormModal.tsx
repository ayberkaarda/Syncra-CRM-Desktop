// Talebe bağlı not/etkileşim ekleme modalı — YENİ bir uç YOK: C şeridin `POST /api/activities`
// ucu kullanılır, `activityable_type: 'ticket'` + `activityable_id` SABİTTİR (kullanıcı
// değiştiremez — bkz. görev tanımı "İç notlar — yeni uç YOK").
//
// `kind` iki modu ayırır:
// - `'note'`: tür her zaman `'note'`, tür seçici GÖSTERİLMEZ. Sistem kapalı devre olduğu için
//   (müşteri portalı yok) her not zaten iç nottur.
// - `'interaction'`: tür seçici `call | email | meeting` sunar (not BURADAN oluşturulamaz —
//   ayrı "+ Not Ekle" akışı var, ikisinin karışmaması için).
//
// ⚠️ `first_response_at` AYRIMI (backend `docs/SLA-DESIGN.md` §2): yalnızca `call`/`email`/
// `meeting` tipi bir aktivite (ya da ilk `open → in_progress` geçişi) `first_response_at`'i
// tetikler; `note` TETİKLEMEZ. Bu ayrım her iki modda da açıkça yazılır ki kullanıcı "not" ile
// "müşteriye yanıt" arasındaki farkı bilerek seçim yapsın.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Button, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { isoToLocalInput, localInputToIso, nowLocalInput } from '../../tasks/components/dateTimeInput'
import { useCreateActivity } from '../../activities/api/activitiesApi'
import type { Ticket } from '../types'

export type TicketActivityFormModalProps = {
  open: boolean
  onClose: () => void
  ticket: Ticket
  kind: 'note' | 'interaction'
}

function interactionTypeOptions(t: TFunction) {
  return [
    { value: 'call', label: t('enums:activity.type.call') },
    { value: 'email', label: t('enums:activity.type.email') },
    { value: 'meeting', label: t('enums:activity.type.meeting') },
  ]
}

export function TicketActivityFormModal({ open, onClose, ticket, kind }: TicketActivityFormModalProps) {
  const { t } = useTranslation(['tickets', 'enums'])
  const createActivity = useCreateActivity()

  const [type, setType] = useState<'call' | 'email' | 'meeting'>('call')
  const [subject, setSubject] = useState('')
  const [occurredAt, setOccurredAt] = useState('')
  const [durationMinutes, setDurationMinutes] = useState('')
  const [outcome, setOutcome] = useState('')
  const [body, setBody] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [occurredClientError, setOccurredClientError] = useState<string | undefined>(undefined)

  const openKey = open ? `${kind}-${ticket.id}` : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setType('call')
      setSubject('')
      setOccurredAt(isoToLocalInput(new Date().toISOString()))
      setDurationMinutes('')
      setOutcome('')
      setBody('')
      setFieldErrors({})
      setOccurredClientError(undefined)
    }
  }

  const isPending = createActivity.isPending
  const nowLocal = nowLocalInput()
  const isNote = kind === 'note'

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!subject.trim()) {
      errors.subject = [
        isNote
          ? t('tickets:activity.form.validation.titleRequired')
          : t('tickets:activity.form.validation.subjectRequired'),
      ]
    }
    if (isNote && !body.trim()) errors.body = [t('tickets:activity.form.validation.bodyRequired')]

    if (!isNote) {
      if (!occurredAt) errors.occurred_at = [t('tickets:activity.form.validation.occurredAtRequired')]
      const inFuture = !!occurredAt && occurredAt > nowLocal
      setOccurredClientError(inFuture ? t('tickets:activity.form.validation.occurredAtFuture') : undefined)
      if (inFuture) errors.occurred_at = [t('tickets:activity.form.validation.occurredAtFuture')]
      if (durationMinutes !== '' && (Number(durationMinutes) < 0 || Number(durationMinutes) > 1440)) {
        errors.duration_minutes = [t('tickets:activity.form.validation.durationRange')]
      }
    }

    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const occurredIso = isNote ? new Date().toISOString() : (localInputToIso(occurredAt) as string)

    try {
      await createActivity.mutateAsync({
        type: isNote ? 'note' : type,
        subject,
        body: body || undefined,
        occurred_at: occurredIso,
        duration_minutes: !isNote && durationMinutes !== '' ? Number(durationMinutes) : undefined,
        outcome: !isNote ? outcome || undefined : undefined,
        activityable_type: 'ticket',
        activityable_id: ticket.id,
      })
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
      title={isNote ? t('tickets:activity.form.titleNote') : t('tickets:activity.form.titleInteraction')}
      description={
        isNote
          ? t('tickets:activity.form.descriptionNote')
          : t('tickets:activity.form.descriptionInteraction')
      }
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('tickets:activity.form.cancel')}
          </Button>
          <Button type="submit" form="ticket-activity-form" loading={isPending}>
            {t('tickets:activity.form.save')}
          </Button>
        </div>
      }
    >
      <form id="ticket-activity-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        {isNote ? (
          <Input
            label={t('tickets:activity.form.titleFieldLabel')}
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            error={fieldError('subject')}
            placeholder={t('tickets:activity.form.titlePlaceholder')}
            required
          />
        ) : (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select
              label={t('tickets:activity.form.typeLabel')}
              value={type}
              onChange={(e) => setType(e.target.value as 'call' | 'email' | 'meeting')}
              options={interactionTypeOptions(t)}
              required
            />
            <Input
              label={t('tickets:activity.form.subjectLabel')}
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
              error={fieldError('subject')}
              required
            />
          </div>
        )}

        {!isNote && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label={t('tickets:activity.form.occurredAtLabel')}
              type="datetime-local"
              value={occurredAt}
              onChange={(e) => {
                setOccurredAt(e.target.value)
                setOccurredClientError(undefined)
              }}
              max={nowLocal}
              error={occurredClientError ?? fieldError('occurred_at')}
              required
            />
            <Input
              label={t('tickets:activity.form.durationLabel')}
              type="number"
              min={0}
              max={1440}
              value={durationMinutes}
              onChange={(e) => setDurationMinutes(e.target.value)}
              error={fieldError('duration_minutes')}
            />
          </div>
        )}

        {!isNote && (
          <Input
            label={t('tickets:activity.form.outcomeFieldLabel')}
            value={outcome}
            onChange={(e) => setOutcome(e.target.value)}
            error={fieldError('outcome')}
          />
        )}

        <Textarea
          label={isNote ? t('tickets:activity.form.bodyLabelNote') : t('tickets:activity.form.bodyLabelInteraction')}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          error={fieldError('body')}
          required={isNote}
        />
      </form>
    </Modal>
  )
}
