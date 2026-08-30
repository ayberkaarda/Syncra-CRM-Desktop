// Otomasyon kuralları sekmesi — Faz 14 / İz F, Attio C4 (docs/PHASE-INTL.md §3).
//
// SABİT KATALOG: tetikleyici/eylem seçimi yalnızca sunucudan gelen (`meta.triggers`/
// `meta.actions`) dropdown'lardır — serbest metin alanı YOK (başlık/mesaj şablonu hariç,
// onlar da yalnızca sabit bir placeholder beyaz listesi kabul eder, bkz. `AutomationRuleFormModal`).
//
// `DELETE` GERÇEK bir silmedir (204) — e-posta şablonlarıyla AYNI semantik (pasifleştirme
// DEĞİL); bu yüzden bir onay modalıyla korunuyor. Aç/kapa (`is_active`) AYRI, geri alınabilir
// bir aksiyon.
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Pencil, Plus, Power, PowerOff, Trash2, Zap } from 'lucide-react'
import { Badge, Button, EmptyState, Modal, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useAutomationRules, useDeleteAutomationRule, useToggleAutomationRule } from '../hooks/useAutomationRules'
import { AutomationRuleFormModal } from './AutomationRuleFormModal'
import type { AutomationActionType, AutomationRule, AutomationTriggerType } from '../types'

/** JSON anahtarları `.` İÇEREMEZ (i18next'in varsayılan `keySeparator` ile çakışır) — katalog
 *  değerleri (`deal.stage_changed` gibi) sözlük anahtarına çevrilirken `_` ile değiştirilir. */
function toDictKey(value: string): string {
  return value.replace(/\./g, '_')
}

export function labelForTrigger(triggerType: AutomationTriggerType | string, t: TFunction): string {
  return t(`settings:automationRules.triggers.${toDictKey(triggerType)}`, { defaultValue: triggerType })
}

export function labelForAction(actionType: AutomationActionType | string, t: TFunction): string {
  return t(`settings:automationRules.actions.${toDictKey(actionType)}`, { defaultValue: actionType })
}

type FormModalState = { mode: 'create' } | { mode: 'edit'; rule: AutomationRule } | null

export function AutomationRulesTab() {
  const { t } = useTranslation(['settings', 'common'])
  const { data, isLoading, isError, refetch } = useAutomationRules()
  const toggleRule = useToggleAutomationRule()
  const deleteRule = useDeleteAutomationRule()

  const [formModal, setFormModal] = useState<FormModalState>(null)
  const [deleteTarget, setDeleteTarget] = useState<AutomationRule | null>(null)

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} variant="rect" height={56} />
        ))}
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('settings:automationRules.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  const rules = data.data

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <p className="max-w-2xl text-xs text-fg-muted">{t('settings:automationRules.description')}</p>
        <Button leftIcon={<Plus className="size-4" aria-hidden="true" />} onClick={() => setFormModal({ mode: 'create' })}>
          {t('settings:automationRules.newRule')}
        </Button>
      </div>

      {rules.length === 0 ? (
        <EmptyState
          icon={<Zap className="size-6" aria-hidden="true" />}
          title={t('settings:automationRules.empty.title')}
          description={t('settings:automationRules.empty.description')}
        />
      ) : (
        <div className="flex flex-col gap-2">
          {rules.map((rule) => (
            <div
              key={rule.id}
              className={cn(
                'flex flex-wrap items-center gap-3 rounded-lg border border-border-subtle bg-surface-1 px-3 py-2.5',
                !rule.is_active && 'opacity-60'
              )}
            >
              <div className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-sm font-medium text-fg">{rule.name}</span>
                <span className="truncate text-xs text-fg-muted">
                  {t('settings:automationRules.ruleSummary', {
                    trigger: labelForTrigger(rule.trigger_type, t),
                    action: labelForAction(rule.action_type, t),
                  })}
                </span>
              </div>

              <Badge variant={rule.is_active ? 'success' : 'neutral'} size="sm">
                {rule.is_active ? t('settings:status.active') : t('settings:status.inactive')}
              </Badge>

              <div className="flex items-center gap-1">
                <Button
                  variant="ghost"
                  size="sm"
                  leftIcon={<Pencil className="size-3.5" aria-hidden="true" />}
                  onClick={() => setFormModal({ mode: 'edit', rule })}
                >
                  {t('common:actions.edit')}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  leftIcon={
                    rule.is_active ? (
                      <PowerOff className="size-3.5" aria-hidden="true" />
                    ) : (
                      <Power className="size-3.5" aria-hidden="true" />
                    )
                  }
                  loading={toggleRule.isPending && toggleRule.variables?.id === rule.id}
                  onClick={() => toggleRule.mutate({ id: rule.id, isActive: !rule.is_active })}
                >
                  {rule.is_active ? t('settings:customFields.deactivate') : t('settings:customFields.activate')}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="text-danger hover:bg-danger-tint"
                  leftIcon={<Trash2 className="size-3.5" aria-hidden="true" />}
                  onClick={() => setDeleteTarget(rule)}
                >
                  {t('common:actions.delete')}
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <AutomationRuleFormModal
        open={!!formModal}
        onClose={() => setFormModal(null)}
        rule={formModal?.mode === 'edit' ? formModal.rule : null}
        meta={data.meta}
      />

      <Modal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title={t('settings:automationRules.delete.title')}
        description={t('settings:automationRules.delete.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteTarget(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteRule.isPending}
              onClick={async () => {
                if (!deleteTarget) return
                await deleteRule.mutateAsync(deleteTarget.id)
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
            <strong className="text-fg">{deleteTarget.name}</strong> {t('settings:automationRules.delete.confirmSuffix')}
          </p>
        )}
      </Modal>
    </div>
  )
}
