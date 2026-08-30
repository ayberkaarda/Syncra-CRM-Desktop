// Özel alan oluşturma/düzenleme modalı. `field` prop'u verilmezse oluşturma modu.
//
// 2. TUR DÜZELTME: Kayıt türü / alan türü seçenekleri ve "seçenek listesi gerektirir mi"
// mantığı artık sabit dizilerden DEĞİL, `GET /api/settings/custom-fields`'in `meta`
// alanından (`entity_types` / `types` / `option_types`, sunucu otorite) geliyor. Bu sorgu
// zaten `CustomFieldsTab` tarafından çekilip cache'lendiği için burada aynı `useCustomFields()`
// çağrısı ek bir ağ isteği YARATMAZ, TanStack Query cache'inden okur. `meta` her ihtimale karşı
// boşsa (modal listeden önce açılırsa gibi olağandışı bir senaryo) küçük bir istemci tarafı
// varsayılan (`FALLBACK_*`) devreye girer — kaynak yine de sunucudur, bu yalnızca bir güvenlik ağı.
//
// KRİTİK KURAL (görev tanımı): `key` alanı oluşturulduktan sonra FARKLI bir değerle
// değiştirilemez — backend 422 döner. `entity_type` de aynı kuralı taşır (ikisi birlikte
// `custom_fields` tablosunda UNIQUE bileşik anahtarı oluşturur). Düzenleme formu bu yüzden
// ikisini de payload'a hiç DAHİL ETMİYOR (bkz. `handleSubmit`), yalnızca görsel olarak devre
// dışı gösteriyor.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, X } from 'lucide-react'
import { Button, Checkbox, Input, Modal, Select } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { useCreateCustomField, useCustomFields, useUpdateCustomField } from '../hooks/useCustomFields'
import { labelForEntityType, labelForFieldType } from './CustomFieldsTab'
import type { CustomField, CustomFieldType } from '../types'

// Yalnızca `meta` beklenmedik biçimde boş gelirse kullanılan güvenlik ağı — normal akışta
// devreye girmez (bkz. dosya başı notu).
const FALLBACK_ENTITY_TYPES = ['leads', 'contacts', 'companies', 'deals', 'tickets', 'products']
const FALLBACK_TYPES: CustomFieldType[] = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'boolean']
const FALLBACK_OPTION_TYPES = ['select', 'multiselect']

function normalizeKey(raw: string): string {
  return raw
    .toLowerCase()
    .replace(/[^a-z0-9_]+/g, '_')
    .replace(/^_+|_+$/g, '')
}

export type CustomFieldFormModalProps = {
  open: boolean
  onClose: () => void
  field?: CustomField | null
  /** Oluşturma modunda hangi bölümün "+ Alan" butonundan açıldığı — select'in başlangıç değeri. */
  defaultEntityType?: string
}

export function CustomFieldFormModal({ open, onClose, field, defaultEntityType }: CustomFieldFormModalProps) {
  const { t } = useTranslation(['settings', 'common'])
  const isEdit = !!field

  const { data } = useCustomFields()
  const createField = useCreateCustomField()
  const updateField = useUpdateCustomField()

  const entityTypeOptionValues = data?.meta.entity_types?.length ? data.meta.entity_types : FALLBACK_ENTITY_TYPES
  const fieldTypeOptionValues = data?.meta.types?.length ? data.meta.types : FALLBACK_TYPES
  const optionTypes = data?.meta.option_types?.length ? data.meta.option_types : FALLBACK_OPTION_TYPES

  const entityTypeOptions = entityTypeOptionValues.map((type) => ({ value: type, label: labelForEntityType(type, t) }))
  const fieldTypeOptions = fieldTypeOptionValues.map((type) => ({ value: type, label: labelForFieldType(type, t) }))

  const [entityType, setEntityType] = useState(defaultEntityType ?? entityTypeOptionValues[0] ?? '')
  const [name, setName] = useState('')
  const [key, setKey] = useState('')
  const [type, setType] = useState<string>('text')
  const [options, setOptions] = useState<string[]>([])
  const [isRequired, setIsRequired] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const openKey = open ? (field ? `edit-${field.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setEntityType(field?.entity_type ?? defaultEntityType ?? entityTypeOptionValues[0] ?? '')
      setName(field?.name ?? '')
      setKey(field?.key ?? '')
      setType(field?.type ?? 'text')
      setOptions(field?.options ?? [])
      setIsRequired(field?.is_required ?? false)
      setFieldErrors({})
    }
  }

  const isPending = createField.isPending || updateField.isPending
  const needsOptions = optionTypes.includes(type)

  function fieldError(f: string): string | undefined {
    return fieldErrors[f]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!name.trim()) errors.name = [t('settings:customFieldForm.errors.nameRequired')]
    if (!isEdit && !key.trim()) errors.key = [t('settings:customFieldForm.errors.keyRequired')]
    if (needsOptions && options.filter((o) => o.trim()).length === 0) {
      errors.options = [t('settings:customFieldForm.errors.optionsRequired')]
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const cleanOptions = needsOptions ? options.map((o) => o.trim()).filter(Boolean) : null

    try {
      if (isEdit && field) {
        // `entity_type`/`key` KASITLI OLARAK gönderilmiyor — bkz. dosya başı notu.
        await updateField.mutateAsync({
          id: field.id,
          payload: { name, type: type as CustomFieldType, options: cleanOptions, is_required: isRequired },
        })
      } else {
        await createField.mutateAsync({
          entity_type: entityType,
          name,
          key: normalizeKey(key),
          type: type as CustomFieldType,
          options: cleanOptions,
          is_required: isRequired,
        })
      }
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  function updateOption(index: number, value: string) {
    setOptions((prev) => prev.map((o, i) => (i === index ? value : o)))
  }

  function removeOption(index: number) {
    setOptions((prev) => prev.filter((_, i) => i !== index))
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? t('settings:customFieldForm.titleEdit') : t('settings:customFieldForm.titleCreate')}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" form="custom-field-form" loading={isPending}>
            {isEdit ? t('common:actions.save') : t('common:actions.create')}
          </Button>
        </div>
      }
    >
      <form id="custom-field-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select
            label={t('settings:customFieldForm.entityTypeLabel')}
            value={entityType}
            onChange={(e) => setEntityType(e.target.value)}
            options={entityTypeOptions}
            disabled={isEdit}
            hint={isEdit ? t('settings:customFieldForm.entityTypeHintLocked') : undefined}
          />
          <Select
            label={t('settings:customFieldForm.fieldTypeLabel')}
            value={type}
            onChange={(e) => setType(e.target.value)}
            options={fieldTypeOptions}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('settings:customFieldForm.nameLabel')}
            value={name}
            onChange={(e) => setName(e.target.value)}
            error={fieldError('name')}
            required
          />
          <Input
            label={t('settings:customFieldForm.keyLabel')}
            value={key}
            onChange={(e) => setKey(normalizeKey(e.target.value))}
            error={fieldError('key')}
            disabled={isEdit}
            hint={isEdit ? t('settings:customFieldForm.keyHintLocked') : t('settings:customFieldForm.keyHintPattern')}
            required={!isEdit}
          />
        </div>

        {needsOptions && (
          <div className="flex flex-col gap-2">
            <span className="text-xs font-medium text-fg-muted">{t('settings:customFieldForm.optionsLabel')}</span>
            {options.map((option, index) => (
              <div key={index} className="flex items-center gap-2">
                <div className="flex-1">
                  <Input
                    value={option}
                    onChange={(e) => updateOption(index, e.target.value)}
                    placeholder={t('settings:customFieldForm.optionPlaceholder', { index: index + 1 })}
                  />
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => removeOption(index)}
                  aria-label={t('settings:customFieldForm.removeOption')}
                >
                  <X className="size-4" aria-hidden="true" />
                </Button>
              </div>
            ))}
            <Button
              type="button"
              variant="secondary"
              size="sm"
              leftIcon={<Plus className="size-3.5" aria-hidden="true" />}
              onClick={() => setOptions((prev) => [...prev, ''])}
            >
              {t('settings:customFieldForm.addOption')}
            </Button>
            {fieldError('options') && <p className="text-xs text-danger">{fieldError('options')}</p>}
          </div>
        )}

        <Checkbox
          label={t('settings:customFieldForm.requiredLabel')}
          checked={isRequired}
          onChange={(e) => setIsRequired(e.target.checked)}
        />
      </form>
    </Modal>
  )
}
