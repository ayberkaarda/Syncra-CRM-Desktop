// Özel alanlar bölümü — `/api/custom-fields?entity_type=tickets`'ten dinamik olarak üretilir.
// Aynı desen `deals/components/DealCustomFieldsSection.tsx` içinde de var; ayrı kopya olarak
// tutuluyor (her modül kendi tiplerini/dosyalarını taşır, bkz. görev tanımı).
import { useTranslation } from 'react-i18next'
import { Checkbox, Input, Select, Textarea } from '../../../components/ui'
import type { TicketCustomField } from '../types'

export type TicketCustomFieldsSectionProps = {
  fields: TicketCustomField[]
  values: Record<string, string>
  onChange: (key: string, value: string) => void
  errors?: Record<string, string>
}

function parseMultiselect(raw: string | undefined): string[] {
  if (!raw) return []
  try {
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed.map(String) : []
  } catch {
    return []
  }
}

export function TicketCustomFieldsSection({ fields, values, onChange, errors }: TicketCustomFieldsSectionProps) {
  const { t } = useTranslation('tickets')
  if (fields.length === 0) return null

  return (
    <div className="flex flex-col gap-4 border-t border-border-subtle pt-4">
      <p className="text-xs font-medium uppercase tracking-wide text-fg-muted">{t('customFields.sectionTitle')}</p>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {fields.map((field) => {
          const raw = values[field.key] ?? ''
          const error = errors?.[`custom_fields.${field.key}`] ?? errors?.[field.key]
          const options = (field.options ?? []).map((opt) => ({ value: opt, label: opt }))

          if (field.type === 'textarea') {
            return (
              <div key={field.id} className="sm:col-span-2">
                <Textarea
                  label={field.name}
                  value={raw}
                  onChange={(e) => onChange(field.key, e.target.value)}
                  error={error}
                  required={field.is_required}
                />
              </div>
            )
          }

          if (field.type === 'select') {
            return (
              <Select
                key={field.id}
                label={field.name}
                value={raw}
                onChange={(e) => onChange(field.key, e.target.value)}
                options={[{ value: '', label: t('customFields.selectPlaceholder') }, ...options]}
                error={error}
              />
            )
          }

          if (field.type === 'multiselect') {
            const selected = parseMultiselect(raw)
            return (
              <div key={field.id} className="flex flex-col gap-1.5 sm:col-span-2">
                <span className="text-xs font-medium text-fg-muted">{field.name}</span>
                <div className="flex flex-col gap-1 rounded-md border border-border-strong bg-surface-2 p-2">
                  {options.length === 0 && <p className="px-1 py-1 text-xs text-fg-muted">{t('customFields.noOptions')}</p>}
                  {options.map((opt) => (
                    <label key={opt.value} className="flex items-center gap-2 rounded-sm px-1 py-1 hover:bg-surface-3">
                      <Checkbox
                        checked={selected.includes(opt.value)}
                        onChange={() => {
                          const next = selected.includes(opt.value)
                            ? selected.filter((v) => v !== opt.value)
                            : [...selected, opt.value]
                          onChange(field.key, JSON.stringify(next))
                        }}
                      />
                      <span className="text-sm text-fg">{opt.label}</span>
                    </label>
                  ))}
                </div>
                {error && <p className="text-xs text-danger">{error}</p>}
              </div>
            )
          }

          if (field.type === 'boolean') {
            return (
              <Checkbox
                key={field.id}
                label={field.name}
                checked={raw === '1'}
                onChange={(e) => onChange(field.key, e.target.checked ? '1' : '0')}
                error={error}
              />
            )
          }

          return (
            <Input
              key={field.id}
              label={field.name}
              type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
              value={raw}
              onChange={(e) => onChange(field.key, e.target.value)}
              error={error}
              required={field.is_required}
            />
          )
        })}
      </div>
    </div>
  )
}
