// Özel Alanlar sekmesi — `entity_type`'a göre gruplu liste, ekle/düzenle/pasifleştir.
//
// 2. TUR DÜZELTME: Bölüm listesi artık sabit bir dizi DEĞİL — `GET /api/settings/custom-fields`
// yanıtının `meta.entity_types` alanından türetiliyor (sunucu otorite). `meta` beklenmedik
// şekilde boş gelirse (olağandışı bir durum) alanların kendi `entity_type` değerlerinden
// benzersiz bir liste çıkarılır — böylece sayfa hiçbir zaman boş kalmaz.
//
// SİLME YOK: `DELETE /api/settings/custom-fields/{id}` backend'de silme değil pasifleştirmedir
// (bkz. `hooks/useCustomFields.ts` → `useDeactivateCustomField`). Pasif bir alan burada
// yeniden aktifleştirilebilir (`PATCH { is_active: true }`).
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Pencil, Plus, Power, PowerOff, SlidersHorizontal } from 'lucide-react'
import { Badge, Button, EmptyState, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useCustomFields, useDeactivateCustomField, useUpdateCustomField } from '../hooks/useCustomFields'
import { CustomFieldFormModal } from './CustomFieldFormModal'
import type { CustomField } from '../types'

// `entity_type`/`type` sunucu otorite değerleridir (bkz. dosya başı notu) — etiketler yalnızca
// BİLİNEN değerler için `settings` sözlüğünden çözülür, bilinmeyeni titleize edilmiş haliyle basılır.
export function labelForEntityType(entityType: string, t: TFunction): string {
  return t(`settings:customFields.entityTypes.${entityType}`, {
    defaultValue: entityType.replace(/\b\w/g, (c) => c.toUpperCase()),
  })
}

export function labelForFieldType(type: string, t: TFunction): string {
  return t(`settings:customFields.fieldTypes.${type}`, {
    defaultValue: type.replace(/\b\w/g, (c) => c.toUpperCase()),
  })
}

type FormModalState = { mode: 'create'; entityType: string } | { mode: 'edit'; field: CustomField } | null

export function CustomFieldsTab() {
  const { t } = useTranslation(['settings', 'common'])
  const { data, isLoading, isError, refetch } = useCustomFields()
  const updateField = useUpdateCustomField()
  const deactivateField = useDeactivateCustomField()

  const [formModal, setFormModal] = useState<FormModalState>(null)

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} variant="rect" height={48} />
        ))}
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('settings:customFields.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  const fields = data?.data ?? []
  const entityTypes = data?.meta.entity_types?.length
    ? data.meta.entity_types
    : Array.from(new Set(fields.map((field) => field.entity_type)))

  return (
    <div className="flex flex-col gap-6">
      {entityTypes.map((entityType) => {
        const sectionFields = fields
          .filter((field) => field.entity_type === entityType)
          .sort((a, b) => a.position - b.position)

        return (
          <div key={entityType} className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold text-fg">{labelForEntityType(entityType, t)}</h3>
              <Button
                variant="secondary"
                size="sm"
                leftIcon={<Plus className="size-3.5" aria-hidden="true" />}
                onClick={() => setFormModal({ mode: 'create', entityType })}
              >
                {t('settings:customFields.addField')}
              </Button>
            </div>

            {sectionFields.length === 0 ? (
              <EmptyState
                icon={<SlidersHorizontal className="size-5" aria-hidden="true" />}
                title={t('settings:customFields.empty.title')}
                description={t('settings:customFields.empty.description')}
              />
            ) : (
              <div className="flex flex-col gap-2">
                {sectionFields.map((field) => (
                  <div
                    key={field.id}
                    className={cn(
                      'flex items-center gap-3 rounded-lg border border-border-subtle bg-surface-1 px-3 py-2.5',
                      !field.is_active && 'opacity-60'
                    )}
                  >
                    <div className="flex min-w-0 flex-1 flex-col">
                      <span className="truncate text-sm font-medium text-fg">{field.name}</span>
                      <span className="font-mono text-xs text-fg-muted">{field.key}</span>
                    </div>

                    <Badge variant="neutral" size="sm">
                      {labelForFieldType(field.type, t)}
                    </Badge>
                    {field.is_required && (
                      <Badge variant="warning" size="sm">
                        {t('settings:customFields.required')}
                      </Badge>
                    )}
                    <Badge variant={field.is_active ? 'success' : 'neutral'} size="sm">
                      {field.is_active ? t('settings:status.active') : t('settings:status.inactive')}
                    </Badge>

                    <div className="flex items-center gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        leftIcon={<Pencil className="size-3.5" aria-hidden="true" />}
                        onClick={() => setFormModal({ mode: 'edit', field })}
                      >
                        {t('common:actions.edit')}
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        leftIcon={
                          field.is_active ? (
                            <PowerOff className="size-3.5" aria-hidden="true" />
                          ) : (
                            <Power className="size-3.5" aria-hidden="true" />
                          )
                        }
                        loading={
                          (field.is_active && deactivateField.isPending && deactivateField.variables === field.id) ||
                          (!field.is_active && updateField.isPending && updateField.variables?.id === field.id)
                        }
                        onClick={() => {
                          if (field.is_active) deactivateField.mutate(field.id)
                          else updateField.mutate({ id: field.id, payload: { is_active: true } })
                        }}
                      >
                        {field.is_active ? t('settings:customFields.deactivate') : t('settings:customFields.activate')}
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )
      })}

      <CustomFieldFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        field={formModal?.mode === 'edit' ? formModal.field : null}
        defaultEntityType={formModal?.mode === 'create' ? formModal.entityType : undefined}
      />
    </div>
  )
}
