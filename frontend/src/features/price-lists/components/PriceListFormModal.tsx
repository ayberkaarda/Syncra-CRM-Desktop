// Fiyat listesi oluşturma/düzenleme modalı. `priceList` prop'u verilmezse (null/undefined)
// oluşturma modu.
//
// Kod alanı: benzersiz olmalı — kullanıcı deneyimini kolaylaştırmak için yazarken otomatik
// BÜYÜK HARFE çevrilir ve boşluklar `_` ile değiştirilir (backend zaten `unique` doğruluyor,
// bu yalnızca istemci tarafı bir kolaylık/normalize adımı).
//
// Varsayılan checkbox'ı: İŞ KURALI (görev tanımı) — yalnızca BİR liste varsayılan olabilir,
// sunucu sessizce diğerlerini otomatik `is_default=false` yapar (contacts `is_primary`
// deseniyle aynı). Sessiz kalmaması için burada AÇIK bir uyarı gösterilir; mevcut varsayılan
// liste `usePriceLists({ is_default: true })` ile sorgulanıp adı uyarıya eklenir.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertTriangle } from 'lucide-react'
import { Button, Checkbox, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { useCreatePriceList, usePriceLists, useUpdatePriceList } from '../api/priceListsApi'
import type { PriceList } from '../types'

function normalizeCode(raw: string): string {
  return raw.toUpperCase().replace(/\s+/g, '_')
}

export type PriceListFormModalProps = {
  open: boolean
  onClose: () => void
  /** Verilirse düzenleme, yoksa oluşturma modu. */
  priceList?: PriceList | null
}

export function PriceListFormModal({ open, onClose, priceList }: PriceListFormModalProps) {
  const { t } = useTranslation()
  const CURRENCY_OPTIONS = [
    { value: 'TRY', label: t('priceLists:form.currency.try') },
    { value: 'USD', label: t('priceLists:form.currency.usd') },
    { value: 'EUR', label: t('priceLists:form.currency.eur') },
    { value: 'GBP', label: t('priceLists:form.currency.gbp') },
  ]
  const isEdit = !!priceList

  const createPriceList = useCreatePriceList()
  const updatePriceList = useUpdatePriceList()

  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [description, setDescription] = useState('')
  const [currency, setCurrency] = useState('TRY')
  const [validFrom, setValidFrom] = useState('')
  const [validUntil, setValidUntil] = useState('')
  const [isDefault, setIsDefault] = useState(false)
  const [isActive, setIsActive] = useState(true)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const openKey = open ? (priceList ? `edit-${priceList.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setName(priceList?.name ?? '')
      setCode(priceList?.code ?? '')
      setDescription(priceList?.description ?? '')
      setCurrency(priceList?.currency ?? 'TRY')
      setValidFrom(priceList?.valid_from ?? '')
      setValidUntil(priceList?.valid_until ?? '')
      setIsDefault(priceList?.is_default ?? false)
      setIsActive(priceList?.is_active ?? true)
      setFieldErrors({})
    }
  }

  // Mevcut varsayılan listeyi bul (kendisi hariç) — "varsayılan" işaretlenirken kullanıcıya
  // hangi listenin yerini alacağını isimle söyleyebilmek için. Yalnızca modal açıkken sorgular.
  const { data: currentDefaultData } = usePriceLists({ is_default: true, per_page: 5 })
  const currentDefault = (currentDefaultData?.data ?? []).find((pl) => pl.id !== priceList?.id) ?? null

  const isPending = createPriceList.isPending || updatePriceList.isPending

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!name.trim()) errors.name = [t('priceLists:form.validation.nameRequired')]
    if (!code.trim()) errors.code = [t('priceLists:form.validation.codeRequired')]
    if (validFrom && validUntil && validUntil < validFrom) {
      errors.valid_until = [t('priceLists:form.validation.validUntilBeforeFrom')]
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const payload = {
      name,
      code: normalizeCode(code),
      description: description || undefined,
      currency: currency || undefined,
      is_default: isDefault,
      is_active: isActive,
      valid_from: validFrom || null,
      valid_until: validUntil || null,
    }

    try {
      if (isEdit && priceList) {
        await updatePriceList.mutateAsync({ id: priceList.id, payload })
      } else {
        await createPriceList.mutateAsync(payload)
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
      title={isEdit ? t('priceLists:form.titleEdit') : t('priceLists:form.titleCreate')}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" form="price-list-form" loading={isPending}>
            {isEdit ? t('common:actions.save') : t('common:actions.create')}
          </Button>
        </div>
      }
    >
      <form id="price-list-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input label={t('priceLists:form.nameLabel')} value={name} onChange={(e) => setName(e.target.value)} error={fieldError('name')} required />
          <Input
            label={t('priceLists:form.codeLabel')}
            value={code}
            onChange={(e) => setCode(normalizeCode(e.target.value))}
            error={fieldError('code')}
            hint={t('priceLists:form.codeHint')}
            required
          />
        </div>

        <Textarea
          label={t('priceLists:form.descriptionLabel')}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          error={fieldError('description')}
        />

        <Select
          label={t('priceLists:form.currencyLabel')}
          value={currency}
          onChange={(e) => setCurrency(e.target.value)}
          options={CURRENCY_OPTIONS}
          error={fieldError('currency')}
        />

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('priceLists:form.validFromLabel')}
            type="date"
            value={validFrom}
            onChange={(e) => setValidFrom(e.target.value)}
            error={fieldError('valid_from')}
            max={validUntil || undefined}
          />
          <Input
            label={t('priceLists:form.validUntilLabel')}
            type="date"
            value={validUntil}
            onChange={(e) => setValidUntil(e.target.value)}
            error={fieldError('valid_until')}
            min={validFrom || undefined}
          />
        </div>

        <div className="flex flex-col gap-2">
          <Checkbox label={t('priceLists:form.defaultCheckbox')} checked={isDefault} onChange={(e) => setIsDefault(e.target.checked)} />
          {isDefault && (
            <div className="flex items-start gap-2 rounded-md bg-warning-tint px-3 py-2 text-xs text-warning">
              <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
              <span>
                {currentDefault
                  ? t('priceLists:form.defaultWarningWithCurrent', { name: currentDefault.name })
                  : t('priceLists:form.defaultWarningNoCurrent')}
              </span>
            </div>
          )}
        </div>

        <Checkbox label={t('priceLists:form.activeCheckbox')} checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
      </form>
    </Modal>
  )
}
