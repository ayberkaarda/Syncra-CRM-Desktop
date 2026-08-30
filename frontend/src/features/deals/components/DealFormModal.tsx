// Fırsat oluşturma/düzenleme modalı. `deal` prop'u verilmezse (null/undefined) oluşturma
// modu. Backend KISITI (görev tanımı): `PATCH /api/deals/{id}` `pipeline_stage_id`,
// `position`, `version`, `status` alanlarını 422 ile REDDEDER — bu alanlar yalnızca
// `PATCH /api/deals/{id}/move` (C şeridin Kanban'ı) üzerinden değişir. Bu yüzden
// düzenleme modunda aşama ve durum salt okunur gösterilir, hiçbir zaman gövdeye eklenmez.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { DealCompanyCombobox } from './DealCompanyCombobox'
import { DealCustomFieldsSection } from './DealCustomFieldsSection'
import { DealStageBadge } from './DealStageBadge'
import { DealStatusBadge } from './DealStatusBadge'
import { DealTagMultiSelect } from './DealTagMultiSelect'
import { useDealContactOptions, useDealCustomFields, useDealTags } from './dealsShared'
import type { ContactOption } from './dealsShared'
import { useCreateDeal, useUpdateDeal } from '../api/dealsApi'
import { useDealOwnerOptions, usePipelineStages } from '../api/boardApi'
import type { CompanyOption, Deal, DealTag } from '../types'

export type DealFormModalProps = {
  open: boolean
  onClose: () => void
  /** Verilirse düzenleme, yoksa oluşturma modu. */
  deal?: Deal | null
  /** Panodan "+ Fırsat" ile açılınca aşama Select'i bununla önceden seçili gelir. */
  defaultStageId?: number
}

export function DealFormModal({ open, onClose, deal, defaultStageId }: DealFormModalProps) {
  const { t } = useTranslation('deals')
  const isEdit = !!deal

  const CURRENCY_OPTIONS = [
    { value: 'TRY', label: t('form.currency.try') },
    { value: 'USD', label: t('form.currency.usd') },
    { value: 'EUR', label: t('form.currency.eur') },
    { value: 'GBP', label: t('form.currency.gbp') },
  ]

  const { data: pipelineStages } = usePipelineStages()
  const { data: tagOptions, isLoading: tagsLoading } = useDealTags()
  const { data: customFieldDefs } = useDealCustomFields()
  const { data: ownerOptions, isForbidden: ownersForbidden } = useDealOwnerOptions()
  const createDeal = useCreateDeal()
  const updateDeal = useUpdateDeal()

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [amount, setAmount] = useState('')
  const [currency, setCurrency] = useState('TRY')
  const [probability, setProbability] = useState('')
  const [expectedCloseDate, setExpectedCloseDate] = useState('')
  const [company, setCompany] = useState<CompanyOption | null>(null)
  const [contact, setContact] = useState<ContactOption | null>(null)
  const [ownerId, setOwnerId] = useState('')
  const [tags, setTags] = useState<DealTag[]>([])
  const [customFieldValues, setCustomFieldValues] = useState<Record<string, string>>({})
  const [stageId, setStageId] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const openKey = open ? (deal ? `edit-${deal.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  const [lastCompanyId, setLastCompanyId] = useState<number | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setTitle(deal?.title ?? '')
      setDescription(deal?.description ?? '')
      setAmount(deal?.amount !== undefined && deal?.amount !== null ? String(deal.amount) : '')
      setCurrency(deal?.currency ?? 'TRY')
      setProbability(deal?.probability !== undefined && deal?.probability !== null ? String(deal.probability) : '')
      setExpectedCloseDate(deal?.expected_close_date ?? '')
      setCompany(deal?.company ?? null)
      setContact(deal?.contact ?? null)
      setOwnerId(deal?.owner ? String(deal.owner.id) : '')
      setTags(deal?.tags ?? [])
      setCustomFieldValues(deal?.custom_fields ?? {})
      setStageId(defaultStageId ? String(defaultStageId) : '')
      setFieldErrors({})
      setLastCompanyId(deal?.company?.id ?? null)
    }
  }

  // Şirket seçimi değiştiğinde kişi seçimini sıfırlar (aşağıdaki Select seçili firmaya göre
  // filtrelenir). Bu, useEffect YERİNE render-phase state ayarlama deseniyle yapılır (React'ın
  // önerdiği "prop/durum değiştiğinde state sıfırlama" deseni, bkz. `ContactFormModal`'daki
  // `openKey`/`lastOpenKey` ile aynı yaklaşım) — böylece modalın açılışında formu doldururken
  // (yukarıdaki blok) yanlışlıkla tetiklenmez: o blok `lastCompanyId`'yi de aynı anda senkronize
  // eder, bu yüzden aşağıdaki karşılaştırma yalnızca KULLANICININ firma seçimini gerçekten
  // değiştirdiği durumda eşleşmez.
  const currentCompanyId = company?.id ?? null
  if (currentCompanyId !== lastCompanyId) {
    setLastCompanyId(currentCompanyId)
    setContact(null)
  }

  const { data: contactOptions, isLoading: contactsLoading } = useDealContactOptions(company?.id, '', { enabled: open })

  // Oluşturmada aşama seçilmemişse (defaultStageId de yoksa) Select'in gösterdiği/gönderdiği
  // değer, aşamalar yüklenince ilk aktif aşamaya türetilir — ayrı bir state+effect YERİNE
  // render sırasında hesaplanır (kullanıcı elle bir aşama seçerse `stageId` onu geçersiz kılar).
  const firstActiveStageId = (pipelineStages ?? []).find((s) => s.is_active)?.id
  const effectiveStageId = stageId || (firstActiveStageId !== undefined ? String(firstActiveStageId) : '')

  const isPending = createDeal.isPending || updateDeal.isPending

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  const customFieldErrorMap: Record<string, string> = Object.fromEntries(
    Object.entries(fieldErrors)
      .filter(([key]) => key.startsWith('custom_fields'))
      .map(([key, messages]) => [key, messages[0]])
  )

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!title.trim()) errors.title = [t('form.validation.titleRequired')]
    if (amount === '') errors.amount = [t('form.validation.amountRequired')]
    else if (Number.isNaN(Number(amount)) || Number(amount) < 0) errors.amount = [t('form.validation.amountInvalid')]
    if (probability !== '' && (Number(probability) < 0 || Number(probability) > 100)) {
      errors.probability = [t('form.validation.probabilityRange')]
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const basePayload = {
      title,
      description: description || undefined,
      amount: amount === '' ? undefined : Number(amount),
      currency: currency || undefined,
      probability: probability === '' ? undefined : Number(probability),
      expected_close_date: expectedCloseDate || undefined,
      company_id: company?.id ?? null,
      contact_id: contact?.id ?? null,
      owner_id: ownerId ? Number(ownerId) : null,
      tag_ids: tags.map((t) => t.id),
      custom_fields: customFieldValues,
    }

    try {
      if (isEdit && deal) {
        await updateDeal.mutateAsync({ id: deal.id, payload: basePayload })
      } else {
        await createDeal.mutateAsync({
          ...basePayload,
          pipeline_stage_id: effectiveStageId ? Number(effectiveStageId) : undefined,
        })
      }
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  const ownerSelectOptions = [
    { value: '', label: t('form.ownerPlaceholder') },
    ...(ownerOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]

  const contactSelectOptions = [
    { value: '', label: contactOptions === undefined ? t('form.contactLoading') : t('form.contactNone') },
    ...(contactOptions ?? []).map((c) => ({ value: String(c.id), label: c.full_name })),
  ]

  const activeStages = (pipelineStages ?? []).filter((s) => s.is_active)
  const stageSelectOptions = activeStages.map((s) => ({ value: String(s.id), label: s.name }))
  const selectedStageForHint = isEdit
    ? (deal?.pipeline_stage ?? null)
    : (pipelineStages ?? []).find((s) => String(s.id) === effectiveStageId) ?? null

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
          <Button type="submit" form="deal-form" loading={isPending}>
            {isEdit ? t('form.submitEdit') : t('form.submitCreate')}
          </Button>
        </div>
      }
    >
      <form id="deal-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Input label={t('form.titleLabel')} value={title} onChange={(e) => setTitle(e.target.value)} error={fieldError('title')} required />

        <Textarea
          label={t('form.descriptionLabel')}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          error={fieldError('description')}
        />

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Input
            label={t('form.amountLabel')}
            type="number"
            min={0}
            step="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            error={fieldError('amount')}
            required
          />
          <Select
            label={t('form.currencyLabel')}
            value={currency}
            onChange={(e) => setCurrency(e.target.value)}
            options={CURRENCY_OPTIONS}
            error={fieldError('currency')}
          />
          <Input
            label={t('form.probabilityLabel')}
            type="number"
            min={0}
            max={100}
            value={probability}
            onChange={(e) => setProbability(e.target.value)}
            placeholder={selectedStageForHint ? String(selectedStageForHint.probability) : undefined}
            hint={
              selectedStageForHint
                ? t('form.probabilityHint', { value: selectedStageForHint.probability })
                : undefined
            }
            error={fieldError('probability')}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('form.expectedCloseDateLabel')}
            type="date"
            value={expectedCloseDate}
            onChange={(e) => setExpectedCloseDate(e.target.value)}
            error={fieldError('expected_close_date')}
          />
          {isEdit ? (
            <div className="flex flex-col gap-1.5">
              <span className="text-xs font-medium text-fg-muted">{t('form.stageLabel')}</span>
              <div className="flex h-10 items-center gap-2 rounded-md border border-border-subtle bg-surface-2 px-3">
                <DealStageBadge stage={deal?.pipeline_stage ?? null} />
              </div>
              <p className="text-xs text-fg-muted">{t('form.stageReadonlyHint')}</p>
            </div>
          ) : (
            <Select
              label={t('form.stageLabel')}
              value={effectiveStageId}
              onChange={(e) => setStageId(e.target.value)}
              options={stageSelectOptions}
              hint={t('form.stageHint')}
              error={fieldError('pipeline_stage_id')}
            />
          )}
        </div>

        {isEdit && (
          <div className="flex flex-col gap-1.5">
            <span className="text-xs font-medium text-fg-muted">{t('form.statusLabel')}</span>
            <div className="flex h-10 items-center gap-2 rounded-md border border-border-subtle bg-surface-2 px-3">
              {deal && <DealStatusBadge status={deal.status} />}
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <DealCompanyCombobox value={company} onChange={setCompany} error={fieldError('company_id')} />
          <Select
            label={t('form.contactLabel')}
            value={contact ? String(contact.id) : ''}
            onChange={(e) => {
              const id = e.target.value ? Number(e.target.value) : null
              const found = (contactOptions ?? []).find((c) => c.id === id) ?? null
              setContact(found)
            }}
            options={contactSelectOptions}
            hint={!company ? t('form.contactHint') : undefined}
            disabled={contactsLoading}
            error={fieldError('contact_id')}
          />
        </div>

        {!ownersForbidden && (
          <Select
            label={t('form.ownerLabel')}
            value={ownerId}
            onChange={(e) => setOwnerId(e.target.value)}
            options={ownerSelectOptions}
            error={fieldError('owner_id')}
          />
        )}

        <DealTagMultiSelect value={tags} onChange={setTags} options={tagOptions ?? []} isLoading={tagsLoading} />

        <DealCustomFieldsSection
          fields={customFieldDefs ?? []}
          values={customFieldValues}
          onChange={(key, value) => setCustomFieldValues((prev) => ({ ...prev, [key]: value }))}
          errors={customFieldErrorMap}
        />
      </form>
    </Modal>
  )
}
