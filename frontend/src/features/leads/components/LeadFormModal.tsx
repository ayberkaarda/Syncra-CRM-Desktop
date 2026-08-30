// Lead oluşturma/düzenleme modalı. `lead` prop'u verilmezse (null/undefined) oluşturma modu.
//
// DUPLICATE UYARISI (Faz 6/E kilit özelliği): email/phone/first_name/last_name/
// company_name alanlarından herhangi biri doluyken 500ms debounce sonrası
// `POST /api/leads/check-duplicates` çağrılır. Sonuç formu ENGELLEMEZ, yalnızca
// üstte bir uyarı paneli gösterir — kaydetme butonu her zaman aktif kalır
// (uyarı varken metni "Yine de Kaydet" olur).
import { useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Info } from 'lucide-react'
import { Button, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { useCheckDuplicates, useCreateLead, useCustomFields, useOwnerOptions, useUpdateLead } from '../api/leadsApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import { editableLeadStatusOptions, leadSourceOptions } from '../utils'
import { DuplicateWarningPanel } from './DuplicateWarningPanel'
import { TagMultiSelect } from './TagMultiSelect'
import { CustomFieldsSection } from './CustomFieldsSection'
import type { DuplicateCandidate, Lead, LeadSource, LeadStatus } from '../types'

export type LeadFormModalProps = {
  open: boolean
  onClose: () => void
  lead?: Lead | null
}

const DUPLICATE_DEBOUNCE_MS = 500

export function LeadFormModal({ open, onClose, lead }: LeadFormModalProps) {
  const { t } = useTranslation(['leads', 'common', 'enums'])
  const isEdit = !!lead
  const createLead = useCreateLead()
  const updateLead = useUpdateLead()
  const checkDuplicates = useCheckDuplicates()
  const { data: ownerOptions, isForbidden: ownersForbidden } = useOwnerOptions()
  const { data: customFields } = useCustomFields('leads')

  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [companyName, setCompanyName] = useState('')
  const [position, setPosition] = useState('')
  const [source, setSource] = useState<LeadSource>('website')
  const [status, setStatus] = useState<LeadStatus>('new')
  const [score, setScore] = useState('')
  const [ownerId, setOwnerId] = useState('')
  const [notes, setNotes] = useState('')
  const [tagIds, setTagIds] = useState<number[]>([])
  const [customFieldValues, setCustomFieldValues] = useState<Record<string, string>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [candidates, setCandidates] = useState<DuplicateCandidate[]>([])

  // Modal her açılışında (veya farklı bir lead için açıldığında) formu sıfırla/doldur —
  // `UserFormModal`'daki render-sırasında-senkronizasyon deseniyle aynı.
  const openKey = open ? (lead ? `edit-${lead.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setFirstName(lead?.first_name ?? '')
      setLastName(lead?.last_name ?? '')
      setEmail(lead?.email ?? '')
      setPhone(lead?.phone ?? '')
      setCompanyName(lead?.company_name ?? '')
      setPosition(lead?.position ?? '')
      setSource(lead?.source ?? 'website')
      setStatus((lead?.status && lead.status !== 'converted' ? lead.status : 'new') as LeadStatus)
      setScore(lead?.score !== undefined && lead?.score !== null ? String(lead.score) : '')
      setOwnerId(lead?.owner ? String(lead.owner.id) : '')
      setNotes(lead?.notes ?? '')
      setTagIds(lead?.tags.map((t) => t.id) ?? [])
      setCustomFieldValues(lead?.custom_fields ?? {})
      setFieldErrors({})
      setCandidates([])
    }
  }

  const dupInput = useMemo(
    () => ({
      email: email.trim(),
      phone: phone.trim(),
      first_name: firstName.trim(),
      last_name: lastName.trim(),
      company_name: companyName.trim(),
    }),
    [email, phone, firstName, lastName, companyName]
  )
  const debouncedDupKey = useDebouncedValue(JSON.stringify(dupInput), DUPLICATE_DEBOUNCE_MS)

  // Boş girdide state'i senkron temizlemek yerine (bkz. `react-hooks/set-state-in-effect`),
  // görünürlük render sırasında `dupInput`'tan türetilir (`hasDuplicateInput` — aşağıda).
  // Bu effect yalnızca doluyken ASENKRON mutasyonu tetikler.
  useEffect(() => {
    if (!open) return
    const parsed = JSON.parse(debouncedDupKey) as typeof dupInput
    const hasAny = Object.values(parsed).some((v) => v !== '')
    if (!hasAny) return
    checkDuplicates.mutate(
      {
        email: parsed.email || undefined,
        phone: parsed.phone || undefined,
        first_name: parsed.first_name || undefined,
        last_name: parsed.last_name || undefined,
        company_name: parsed.company_name || undefined,
        exclude_lead_id: lead?.id,
      },
      {
        onSuccess: (data) => setCandidates(data),
        onError: () => setCandidates([]),
      }
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedDupKey, open])

  const hasDuplicateInput = Object.values(dupInput).some((v) => v !== '')
  const visibleCandidates = hasDuplicateInput ? candidates : []

  const isPending = createLead.isPending || updateLead.isPending

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!firstName.trim()) errors.first_name = [t('leads:form.validation.firstNameRequired')]
    if (!lastName.trim()) errors.last_name = [t('leads:form.validation.lastNameRequired')]
    if (!source) errors.source = [t('leads:form.validation.sourceRequired')]
    if (score.trim() !== '') {
      const n = Number(score)
      if (Number.isNaN(n) || n < 0 || n > 100) errors.score = [t('leads:form.validation.scoreRange')]
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const payload = {
      first_name: firstName.trim(),
      last_name: lastName.trim(),
      email: email.trim() || null,
      phone: phone.trim() || null,
      company_name: companyName.trim() || null,
      position: position.trim() || null,
      source,
      status,
      score: score.trim() === '' ? null : Number(score),
      owner_id: ownerId ? Number(ownerId) : null,
      notes: notes.trim() || null,
      tag_ids: tagIds,
      custom_fields: customFieldValues,
    }

    try {
      if (isEdit && lead) {
        await updateLead.mutateAsync({ id: lead.id, payload })
      } else {
        await createLead.mutateAsync(payload)
      }
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  const ownerSelectOptions = [
    { value: '', label: t('leads:form.ownerUnassigned') },
    ...(ownerOptions ?? []).map((owner) => ({ value: String(owner.id), label: owner.name })),
  ]

  const hasDuplicateWarning = visibleCandidates.length > 0

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? t('leads:form.titleEdit') : t('leads:form.titleCreate')}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('leads:form.cancel')}
          </Button>
          <Button type="submit" form="lead-form" loading={isPending}>
            {hasDuplicateWarning ? t('leads:form.submitSaveAnyway') : isEdit ? t('leads:form.submitEdit') : t('leads:form.submitCreate')}
          </Button>
        </div>
      }
    >
      <form id="lead-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        {(hasDuplicateWarning || checkDuplicates.isPending) && (
          <DuplicateWarningPanel candidates={visibleCandidates} loading={checkDuplicates.isPending} />
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('leads:form.firstNameLabel')}
            value={firstName}
            onChange={(e) => setFirstName(e.target.value)}
            error={fieldError('first_name')}
            required
          />
          <Input
            label={t('leads:form.lastNameLabel')}
            value={lastName}
            onChange={(e) => setLastName(e.target.value)}
            error={fieldError('last_name')}
            required
          />
          <Input
            label={t('leads:form.emailLabel')}
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            error={fieldError('email')}
          />
          <Input label={t('leads:form.phoneLabel')} value={phone} onChange={(e) => setPhone(e.target.value)} error={fieldError('phone')} />
          <Input
            label={t('leads:form.companyNameLabel')}
            value={companyName}
            onChange={(e) => setCompanyName(e.target.value)}
            error={fieldError('company_name')}
          />
          <Input
            label={t('leads:form.positionLabel')}
            value={position}
            onChange={(e) => setPosition(e.target.value)}
            error={fieldError('position')}
          />
          <Select
            label={t('leads:form.sourceLabel')}
            value={source}
            onChange={(e) => setSource(e.target.value as LeadSource)}
            options={leadSourceOptions(t)}
            error={fieldError('source')}
          />
          <Select
            label={t('leads:form.statusLabel')}
            value={status}
            onChange={(e) => setStatus(e.target.value as LeadStatus)}
            options={editableLeadStatusOptions(t)}
            error={fieldError('status')}
          />
          <Input
            label={t('leads:form.scoreLabel')}
            type="number"
            min={0}
            max={100}
            value={score}
            onChange={(e) => setScore(e.target.value)}
            error={fieldError('score')}
          />
          {!ownersForbidden && (
            <Select
              label={t('leads:form.ownerLabel')}
              value={ownerId}
              onChange={(e) => setOwnerId(e.target.value)}
              options={ownerSelectOptions}
              error={fieldError('owner_id')}
            />
          )}
        </div>

        <TagMultiSelect selectedIds={tagIds} onChange={setTagIds} />

        <Textarea
          label={t('leads:form.notesLabel')}
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          error={fieldError('notes')}
        />

        <CustomFieldsSection
          fields={customFields ?? []}
          values={customFieldValues}
          onChange={(key, value) => setCustomFieldValues((prev) => ({ ...prev, [key]: value }))}
        />

        {hasDuplicateWarning && (
          <div className="flex items-start gap-2 rounded-md bg-warning-tint p-3 text-xs text-warning">
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>{t('leads:form.duplicateNotice')}</p>
          </div>
        )}
      </form>
    </Modal>
  )
}
