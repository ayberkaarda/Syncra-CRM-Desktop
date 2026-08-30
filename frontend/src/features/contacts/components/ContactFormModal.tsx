// Kişi oluşturma/düzenleme modalı. `contact` prop'u verilmezse (null/undefined) oluşturma modu.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Checkbox, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { usePermission } from '../../auth/hooks/usePermission'
import { useCreateContact, useCustomFields, useTags, useUpdateContact, useUserOptions } from '../api/contactsApi'
import { CompanyCombobox } from './CompanyCombobox'
import { TagMultiSelect } from './TagMultiSelect'
import type { CompanyOption, Contact, Tag } from '../types'

type ContactFormModalProps = {
  open: boolean
  onClose: () => void
  contact?: Contact | null
}

export function ContactFormModal({ open, onClose, contact }: ContactFormModalProps) {
  const { t } = useTranslation('contacts')
  const isEdit = !!contact
  const { can } = usePermission()
  const canViewUsers = can('users.view')

  const { data: tagOptions, isLoading: tagsLoading } = useTags()
  const { data: customFieldDefs } = useCustomFields()
  const { data: userOptions } = useUserOptions({ enabled: canViewUsers })
  const createContact = useCreateContact()
  const updateContact = useUpdateContact()

  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [mobile, setMobile] = useState('')
  const [position, setPosition] = useState('')
  const [company, setCompany] = useState<CompanyOption | null>(null)
  const [ownerId, setOwnerId] = useState('')
  const [isPrimary, setIsPrimary] = useState(false)
  const [address, setAddress] = useState('')
  const [city, setCity] = useState('')
  const [country, setCountry] = useState('')
  const [tags, setTags] = useState<Tag[]>([])
  const [notes, setNotes] = useState('')
  const [customFieldValues, setCustomFieldValues] = useState<Record<string, string>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  // Modal her açılışında (veya farklı bir kişi için açıldığında) formu sıfırla/doldur.
  // `useEffect` + setState yerine render sırasında ayarlama (React'ın önerdiği "prop değiştiğinde
  // state sıfırlama" deseni) — kapanış animasyonu sırasında alanları boşaltmamak için yalnızca
  // açılış anında tetiklenir.
  const openKey = open ? (contact ? `edit-${contact.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setFirstName(contact?.first_name ?? '')
      setLastName(contact?.last_name ?? '')
      setEmail(contact?.email ?? '')
      setPhone(contact?.phone ?? '')
      setMobile(contact?.mobile ?? '')
      setPosition(contact?.position ?? '')
      setCompany(contact?.company ?? null)
      setOwnerId(contact?.owner ? String(contact.owner.id) : '')
      setIsPrimary(contact?.is_primary ?? false)
      setAddress(contact?.address ?? '')
      setCity(contact?.city ?? '')
      setCountry(contact?.country ?? '')
      setTags(contact?.tags ?? [])
      setNotes(contact?.notes ?? '')
      setCustomFieldValues(contact?.custom_fields ?? {})
      setFieldErrors({})
    }
  }

  const isPending = createContact.isPending || updateContact.isPending

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!firstName.trim()) errors.first_name = [t('form.validation.firstNameRequired')]
    if (!lastName.trim()) errors.last_name = [t('form.validation.lastNameRequired')]
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const payload = {
      first_name: firstName,
      last_name: lastName,
      email: email || undefined,
      phone: phone || undefined,
      mobile: mobile || undefined,
      position: position || undefined,
      company_id: company?.id ?? null,
      owner_id: ownerId ? Number(ownerId) : null,
      is_primary: isPrimary,
      address: address || undefined,
      city: city || undefined,
      country: country || undefined,
      tag_ids: tags.map((t) => t.id),
      custom_fields: customFieldValues,
    }

    try {
      if (isEdit && contact) {
        await updateContact.mutateAsync({ id: contact.id, payload })
      } else {
        await createContact.mutateAsync(payload)
      }
      onClose()
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
    }
  }

  const ownerSelectOptions = [
    { value: '', label: t('form.selectOwner') },
    ...(userOptions ?? []).map((u) => ({ value: String(u.id), label: u.name })),
  ]

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
          <Button type="submit" form="contact-form" loading={isPending}>
            {isEdit ? t('form.submitEdit') : t('form.submitCreate')}
          </Button>
        </div>
      }
    >
      <form id="contact-form" onSubmit={handleSubmit} className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('form.fields.firstName')}
            value={firstName}
            onChange={(e) => setFirstName(e.target.value)}
            error={fieldError('first_name')}
            required
          />
          <Input
            label={t('form.fields.lastName')}
            value={lastName}
            onChange={(e) => setLastName(e.target.value)}
            error={fieldError('last_name')}
            required
          />
          <Input
            label={t('form.fields.email')}
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            error={fieldError('email')}
          />
          <Input
            label={t('form.fields.phone')}
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            error={fieldError('phone')}
          />
          <Input
            label={t('form.fields.mobile')}
            value={mobile}
            onChange={(e) => setMobile(e.target.value)}
            error={fieldError('mobile')}
          />
          <Input
            label={t('form.fields.position')}
            value={position}
            onChange={(e) => setPosition(e.target.value)}
            error={fieldError('position')}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <CompanyCombobox value={company} onChange={setCompany} error={fieldError('company_id')} />
          {canViewUsers && (
            <Select
              label={t('form.fields.owner')}
              value={ownerId}
              onChange={(e) => setOwnerId(e.target.value)}
              options={ownerSelectOptions}
              error={fieldError('owner_id')}
            />
          )}
        </div>

        <div className="flex flex-col gap-1.5">
          <Checkbox
            label={t('form.fields.isPrimary')}
            checked={isPrimary}
            onChange={(e) => setIsPrimary(e.target.checked)}
          />
          {isPrimary && company && (
            <p className="text-xs text-warning">
              {t('form.primaryWarning')}
            </p>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Input label={t('form.fields.address')} value={address} onChange={(e) => setAddress(e.target.value)} error={fieldError('address')} />
          <Input label={t('form.fields.city')} value={city} onChange={(e) => setCity(e.target.value)} error={fieldError('city')} />
          <Input label={t('form.fields.country')} value={country} onChange={(e) => setCountry(e.target.value)} error={fieldError('country')} />
        </div>

        <TagMultiSelect value={tags} onChange={setTags} options={tagOptions ?? []} isLoading={tagsLoading} />

        <Textarea label={t('form.fields.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} error={fieldError('notes')} />

        {(customFieldDefs ?? []).length > 0 && (
          <div className="flex flex-col gap-4 border-t border-border-subtle pt-4">
            <p className="text-xs font-medium text-fg-muted">{t('form.customFields')}</p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {(customFieldDefs ?? []).map((def) => (
                <Input
                  key={def.key}
                  label={def.label}
                  value={customFieldValues[def.key] ?? ''}
                  onChange={(e) => setCustomFieldValues((prev) => ({ ...prev, [def.key]: e.target.value }))}
                  error={fieldError(`custom_fields.${def.key}`)}
                />
              ))}
            </div>
          </div>
        )}
      </form>
    </Modal>
  )
}
