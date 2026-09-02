// Dönüştürme modalı — lead'i Kişi (+ opsiyonel Firma, + opsiyonel Fırsat) yapar.
//
// MEVCUT KAYDA BAĞLAMA (çift müşteri kaydını önleyen asıl mekanizma): modal
// açılınca lead'in kendi alanlarıyla `check-duplicates` çağrılır. Güçlü
// (`level==='strong'`) bir KİŞİ (contact) adayı varsa kullanıcıya "Yeni kişi
// oluştur" / "Mevcut kişiye bağla" seçimi sunulur — seçilirse `contact_id`
// gönderilir ve backend yeni bir Contact açmak yerine mevcut kayda bağlanır.
//
// NOT: `check-duplicates` yalnızca `lead`/`contact` tipi aday döner — ayrı bir
// "firma adayı" sinyali backend'de yok (bkz. DuplicateDetector, yalnızca
// collectLeads/collectContacts tarar). Bu yüzden firma bağlama burada elle
// firma seçimi olarak değil, yalnızca kişi eşleşmesi üzerinden sunulur; kişi
// zaten bir firmaya bağlıysa backend onu koruyarak günceller (bkz.
// LeadConversionService::resolveContact).
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AlertCircle } from 'lucide-react'
import { Button, Checkbox, Input, Modal } from '../../../components/ui'
import { useOnlineOnly } from '../../../platform/useOnlineOnly'
import { getErrorMessage } from '../../../lib/axios'
import { useCheckDuplicates, useConvertLead } from '../api/leadsApi'
import { DuplicateWarningPanel } from './DuplicateWarningPanel'
import type { DuplicateCandidate, Lead } from '../types'

export type ConvertLeadModalProps = {
  open: boolean
  onClose: () => void
  lead: Lead | null
}

export function ConvertLeadModal({ open, onClose, lead }: ConvertLeadModalProps) {
  const { t } = useTranslation('leads')
  const navigate = useNavigate()
  const checkDuplicates = useCheckDuplicates()
  const convertLead = useConvertLead()
  const convertGuard = useOnlineOnly('leads.convert')

  const [createDeal, setCreateDeal] = useState(false)
  const [dealTitle, setDealTitle] = useState('')
  const [dealAmount, setDealAmount] = useState('')
  const [contactChoice, setContactChoice] = useState('new')
  const [candidates, setCandidates] = useState<DuplicateCandidate[]>([])
  const [submitError, setSubmitError] = useState<string | null>(null)

  // Modal her açılışında (veya farklı bir lead için açıldığında) formu sıfırla —
  // render-sırasında-senkronizasyon deseni (bkz. `LeadFormModal`/`UserFormModal`),
  // `useEffect` içinde doğrudan setState çağrısı YERİNE.
  const openKey = open && lead ? `convert-${lead.id}` : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setCreateDeal(false)
      setDealTitle('')
      setDealAmount('')
      setContactChoice('new')
      setCandidates([])
      setSubmitError(null)
    }
  }

  // Duplicate kontrolü tetikleyicisi — asıl state sıfırlama yukarıda (render
  // sırasında) yapıldığından, bu effect yalnızca ASENKRON mutasyonu tetikler;
  // `setCandidates` çağrısı `onSuccess`/`onError` içinde (asenkron) yapılır.
  useEffect(() => {
    if (!open || !lead) return
    checkDuplicates.mutate(
      {
        email: lead.email || undefined,
        phone: lead.phone || undefined,
        first_name: lead.first_name || undefined,
        last_name: lead.last_name || undefined,
        company_name: lead.company_name || undefined,
        exclude_lead_id: lead.id,
      },
      {
        onSuccess: (data) => setCandidates(data),
        onError: () => setCandidates([]),
      }
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, lead?.id])

  if (!lead) return null

  const strongContactMatches = candidates.filter((c) => c.type === 'contact' && c.level === 'strong')

  async function handleConvert() {
    if (!lead) return
    setSubmitError(null)
    try {
      const result = await convertLead.mutateAsync({
        id: lead.id,
        payload: {
          create_deal: createDeal,
          deal_title: createDeal && dealTitle.trim() ? dealTitle.trim() : undefined,
          deal_amount: createDeal && dealAmount.trim() !== '' ? Number(dealAmount) : undefined,
          contact_id: contactChoice !== 'new' ? Number(contactChoice) : undefined,
        },
      })
      onClose()
      if (result.contact && typeof result.contact.id === 'number') {
        navigate(`/contacts/${result.contact.id}`)
      }
    } catch (error) {
      setSubmitError(getErrorMessage(error))
    }
  }

  const companyLabel = lead.company_name
    ? t('leads:convertModal.companyLabel', { name: lead.company_name })
    : t('leads:convertModal.companyNone')

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('leads:convertModal.title')}
      size="md"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('leads:convertModal.cancel')}
          </Button>
          <Button
            type="button"
            loading={convertLead.isPending}
            // SYNCDESKTOP §8 (O102). The row action that opens this modal is guarded too, but a
            // modal left open across a connectivity change would otherwise still offer a submit
            // button whose verb now refuses — inert on the web build, where the guard never trips.
            disabled={convertGuard.offline}
            title={convertGuard.title}
            onClick={handleConvert}
          >
            {t('leads:convertModal.submit')}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="rounded-md bg-surface-2 p-3 text-sm text-fg">
          <p className="mb-1.5 text-xs font-medium uppercase tracking-wide text-fg-muted">
            {t('leads:convertModal.willCreateTitle')}
          </p>
          <ul className="flex flex-col gap-1">
            <li>
              {t('leads:convertModal.personLabel')}: <strong className="font-medium">{lead.full_name}</strong>
            </li>
            <li>{companyLabel}</li>
            <li>
              {t('leads:convertModal.dealLabel')}:{' '}
              {createDeal
                ? dealTitle.trim() || t('leads:convertModal.dealDefaultTitle')
                : t('leads:convertModal.dealOptional')}
            </li>
          </ul>
        </div>

        {(candidates.length > 0 || checkDuplicates.isPending) && (
          <DuplicateWarningPanel candidates={candidates} loading={checkDuplicates.isPending} />
        )}

        {strongContactMatches.length > 0 && (
          <div className="flex flex-col gap-2 rounded-md border border-border-subtle p-3">
            <p className="text-xs font-medium text-fg-muted">{t('leads:convertModal.strongMatchQuestion')}</p>
            <label className="flex items-center gap-2 text-sm text-fg">
              <input
                type="radio"
                name="contact-choice"
                checked={contactChoice === 'new'}
                onChange={() => setContactChoice('new')}
                className="size-4"
              />
              {t('leads:convertModal.createNewPerson')}
            </label>
            {strongContactMatches.map((candidate) => (
              <label key={candidate.id} className="flex items-center gap-2 text-sm text-fg">
                <input
                  type="radio"
                  name="contact-choice"
                  checked={contactChoice === String(candidate.id)}
                  onChange={() => setContactChoice(String(candidate.id))}
                  className="size-4"
                />
                {t('leads:convertModal.linkExisting', { name: candidate.name })}
              </label>
            ))}
          </div>
        )}

        <Checkbox
          label={t('leads:convertModal.createDealCheckbox')}
          checked={createDeal}
          onChange={(e) => setCreateDeal(e.target.checked)}
        />

        {createDeal && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label={t('leads:convertModal.dealTitleLabel')}
              value={dealTitle}
              onChange={(e) => setDealTitle(e.target.value)}
              placeholder={`${lead.full_name}${lead.company_name ? ' — ' + lead.company_name : ''}`}
            />
            <Input
              label={t('leads:convertModal.dealAmountLabel')}
              type="number"
              min={0}
              step="0.01"
              value={dealAmount}
              onChange={(e) => setDealAmount(e.target.value)}
            />
          </div>
        )}

        {submitError && (
          <div className="flex items-start gap-2 rounded-md bg-danger-tint p-3 text-xs text-danger">
            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>{submitError}</p>
          </div>
        )}
      </div>
    </Modal>
  )
}
