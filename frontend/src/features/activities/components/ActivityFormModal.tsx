// Aktivite oluşturma/düzenleme modalı. `activity` verilmezse (null/undefined) oluşturma modu.
// `user_id` HİÇBİR ZAMAN gönderilmez — sunucu her zaman isteği yapan kullanıcıyı yazar (bkz.
// backend `StoreActivityRequest` başlığındaki not).
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { RelatedRecordPicker } from '../../tasks/components/RelatedRecordPicker'
import type { RelatedRecordValue } from '../../tasks/components/RelatedRecordPicker'
import { isoToLocalInput, localInputToIso, nowLocalInput } from '../../tasks/components/dateTimeInput'
import { activityTypeOptions } from './activityTypeMeta'
import { useCreateActivity, useUpdateActivity } from '../api/activitiesApi'
import type { Activity } from '../types'

export type ActivityFormModalProps = {
  open: boolean
  onClose: () => void
  /** Verilirse düzenleme, yoksa oluşturma modu. */
  activity?: Activity | null
}

export function ActivityFormModal({ open, onClose, activity }: ActivityFormModalProps) {
  const { t } = useTranslation('activities')
  const { t: tEnums } = useTranslation('enums')
  const isEdit = !!activity

  const createActivity = useCreateActivity()
  const updateActivity = useUpdateActivity()

  const [type, setType] = useState<string>('call')
  const [subject, setSubject] = useState('')
  const [occurredAt, setOccurredAt] = useState('')
  const [durationMinutes, setDurationMinutes] = useState('')
  const [outcome, setOutcome] = useState('')
  const [body, setBody] = useState('')
  const [related, setRelated] = useState<RelatedRecordValue>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [occurredClientError, setOccurredClientError] = useState<string | undefined>(undefined)

  const openKey = open ? (activity ? `edit-${activity.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setType(activity?.type ?? 'call')
      setSubject(activity?.subject ?? '')
      setOccurredAt(activity ? isoToLocalInput(activity.occurred_at) : nowLocalInput())
      setDurationMinutes(
        activity?.duration_minutes !== undefined && activity?.duration_minutes !== null
          ? String(activity.duration_minutes)
          : ''
      )
      setOutcome(activity?.outcome ?? '')
      setBody(activity?.body ?? '')
      setRelated(
        activity?.activityable
          ? { type: activity.activityable.type, id: activity.activityable.id, label: activity.activityable.label ?? '' }
          : null
      )
      setFieldErrors({})
      setOccurredClientError(undefined)
    }
  }

  const isPending = createActivity.isPending || updateActivity.isPending
  const nowLocal = nowLocalInput()

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!subject.trim()) errors.subject = [t('form.validation.subjectRequired')]
    if (!occurredAt) errors.occurred_at = [t('form.validation.occurredAtRequired')]
    const inFuture = !!occurredAt && occurredAt > nowLocal
    setOccurredClientError(inFuture ? t('form.validation.occurredAtFuture') : undefined)
    if (inFuture) errors.occurred_at = [t('form.validation.occurredAtFuture')]
    if (durationMinutes !== '' && (Number(durationMinutes) < 0 || Number(durationMinutes) > 1440)) {
      errors.duration_minutes = [t('form.validation.durationRange')]
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const basePayload = {
      type: type as Activity['type'],
      subject,
      body: body || undefined,
      occurred_at: localInputToIso(occurredAt) as string,
      duration_minutes: durationMinutes === '' ? undefined : Number(durationMinutes),
      outcome: outcome || undefined,
      activityable_type: related && related.id > 0 ? related.type : null,
      activityable_id: related && related.id > 0 ? related.id : null,
    }

    try {
      if (isEdit && activity) {
        await updateActivity.mutateAsync({ id: activity.id, payload: basePayload })
      } else {
        await createActivity.mutateAsync(basePayload)
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
      title={isEdit ? t('form.titleEdit') : t('form.titleCreate')}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('form.cancel')}
          </Button>
          <Button type="submit" form="activity-form" loading={isPending}>
            {isEdit ? t('form.submitEdit') : t('form.submitCreate')}
          </Button>
        </div>
      }
    >
      <form id="activity-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select label={t('form.fields.type')} value={type} onChange={(e) => setType(e.target.value)} options={activityTypeOptions(tEnums)} error={fieldError('type')} required />
          <Input label={t('form.fields.subject')} value={subject} onChange={(e) => setSubject(e.target.value)} error={fieldError('subject')} required />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('form.fields.occurredAt')}
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
            label={t('form.fields.durationMinutes')}
            type="number"
            min={0}
            max={1440}
            value={durationMinutes}
            onChange={(e) => setDurationMinutes(e.target.value)}
            error={fieldError('duration_minutes')}
          />
        </div>

        <Input label={t('form.fields.outcome')} value={outcome} onChange={(e) => setOutcome(e.target.value)} error={fieldError('outcome')} />

        <Textarea label={t('form.fields.body')} value={body} onChange={(e) => setBody(e.target.value)} error={fieldError('body')} />

        <RelatedRecordPicker
          value={related}
          onChange={setRelated}
          typeError={fieldError('activityable_type')}
          idError={fieldError('activityable_id')}
        />
      </form>
    </Modal>
  )
}
