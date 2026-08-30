// E-posta Şablonları sekmesi — liste + düzenleyici (bkz. `EmailTemplateFormModal`).
//
// BU FAZDA E-POSTA GÖNDERİLMİYOR (kapalı devre sistem, `MAIL_MAILER=log`) — burada da bir
// "gönder/test et" butonu YOK (görev tanımı).
//
// 2. TUR DÜZELTME: `DELETE /api/settings/email-templates/{id}` özel alanların/aşamaların
// AKSİNE GERÇEK bir silmedir (backend 204 döner, kayıt kalıcı olarak kaldırılır) —
// pasifleştirme DEĞİL. Bu yüzden burada iki AYRI aksiyon var: "Pasifleştir/Aktifleştir"
// (`PATCH is_active`, geri alınabilir) ve "Sil" (`DELETE`, GERİ ALINAMAZ — bir onay modalıyla
// korunuyor, `price-lists` sil modalıyla aynı desen).
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Mail, Pencil, Power, PowerOff, Plus, Trash2 } from 'lucide-react'
import { Badge, Button, EmptyState, Modal, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useDeleteEmailTemplate, useEmailTemplates, useUpdateEmailTemplate } from '../hooks/useEmailTemplates'
import { EmailTemplateFormModal } from './EmailTemplateFormModal'
import type { EmailTemplate } from '../types'

type FormModalState = { mode: 'create' } | { mode: 'edit'; template: EmailTemplate } | null

export function EmailTemplatesTab() {
  const { t } = useTranslation(['settings', 'common'])
  const { data, isLoading, isError, refetch } = useEmailTemplates()
  const updateTemplate = useUpdateEmailTemplate()
  const deleteTemplate = useDeleteEmailTemplate()

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [deleteTarget, setDeleteTarget] = useState<EmailTemplate | null>(null)

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} variant="rect" height={56} />
        ))}
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('settings:emailTemplates.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  const templates = data ?? []

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-end">
        <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
          {t('settings:emailTemplates.newTemplate')}
        </Button>
      </div>

      {templates.length === 0 ? (
        <EmptyState
          icon={<Mail className="size-6" aria-hidden="true" />}
          title={t('settings:emailTemplates.empty.title')}
          description={t('settings:emailTemplates.empty.description')}
        />
      ) : (
        <div className="flex flex-col gap-2">
          {templates.map((template) => (
            <div
              key={template.id}
              className={cn(
                'flex items-center gap-3 rounded-lg border border-border-subtle bg-surface-1 px-3 py-2.5',
                !template.is_active && 'opacity-60'
              )}
            >
              <div className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-sm font-medium text-fg">{template.name}</span>
                <span className="truncate text-xs text-fg-muted">
                  <span className="font-mono">{template.key}</span> — {template.subject}
                </span>
              </div>

              {(template.variables?.length ?? 0) > 0 && (
                <Badge variant="neutral" size="sm">
                  {t('settings:emailTemplates.variablesCount', { count: template.variables?.length ?? 0 })}
                </Badge>
              )}
              <Badge variant={template.is_active ? 'success' : 'neutral'} size="sm">
                {template.is_active ? t('settings:status.active') : t('settings:status.inactive')}
              </Badge>

              <div className="flex items-center gap-1">
                <Button
                  variant="ghost"
                  size="sm"
                  leftIcon={<Pencil className="size-3.5" aria-hidden="true" />}
                  onClick={() => setFormModal({ mode: 'edit', template })}
                >
                  {t('common:actions.edit')}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  leftIcon={
                    template.is_active ? (
                      <PowerOff className="size-3.5" aria-hidden="true" />
                    ) : (
                      <Power className="size-3.5" aria-hidden="true" />
                    )
                  }
                  loading={updateTemplate.isPending && updateTemplate.variables?.id === template.id}
                  onClick={() => updateTemplate.mutate({ id: template.id, payload: { is_active: !template.is_active } })}
                >
                  {template.is_active ? t('settings:customFields.deactivate') : t('settings:customFields.activate')}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="text-danger hover:bg-danger-tint"
                  leftIcon={<Trash2 className="size-3.5" aria-hidden="true" />}
                  onClick={() => setDeleteTarget(template)}
                >
                  {t('common:actions.delete')}
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <EmailTemplateFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        template={formModal?.mode === 'edit' ? formModal.template : null}
      />

      <Modal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title={t('settings:emailTemplates.delete.title')}
        description={t('settings:emailTemplates.delete.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteTarget(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteTemplate.isPending}
              onClick={async () => {
                if (!deleteTarget) return
                await deleteTemplate.mutateAsync(deleteTarget.id)
                setDeleteTarget(null)
              }}
            >
              {t('common:actions.delete')}
            </Button>
          </div>
        }
      >
        {deleteTarget && (
          <p className="text-sm text-fg-secondary">
            <strong className="text-fg">{deleteTarget.name}</strong> {t('settings:emailTemplates.delete.confirmSuffix')}
          </p>
        )}
      </Modal>
    </div>
  )
}
