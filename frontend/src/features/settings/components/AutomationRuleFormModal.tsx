// Otomasyon kuralı oluşturma/düzenleme modalı — Faz 14 / İz F, Attio C4
// (docs/PHASE-INTL.md §3). `rule` verilmezse oluşturma modu.
//
// SABİT KATALOG: tetikleyici + eylem SEÇİMİ dropdown'lardır (`meta.triggers`/`meta.actions`,
// sunucu otorite) — serbest metin alanı YOK. `deal.assign_owner` eylemi yalnızca `deal.*`
// tetikleyicileriyle uyumludur (backend `AutomationCatalog::actionCompatibleWithTrigger()`
// ile AYNI kural, burada İSTEMCİ TARAFINDA da uygulanır ki kullanıcı hiç göndermeden önce
// geçersiz bir kombinasyon seçemesin — sunucu yine de kendi kopyasıyla doğrular).
//
// DÜZENLEMEDE: tetikleyici/eylem BİR BÜTÜNDÜR (backend docblock) — bu form ikisini HER ZAMAN
// birlikte gönderir, kısmi güncelleme YAPMAZ (yalnızca aç/kapa `AutomationRulesTab`'te ayrı).
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Select, Textarea } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { useAutomationUserOptions, useCreateAutomationRule, useUpdateAutomationRule } from '../hooks/useAutomationRules'
import { usePipelineStages } from '../hooks/usePipelineStages'
import { stageLabel } from '../../deals/utils/stageLabel'
import { labelForAction, labelForTrigger } from './AutomationRulesTab'
import type {
  AutomationActionType,
  AutomationAssigneeType,
  AutomationRule,
  AutomationRulesMeta,
  AutomationTriggerType,
} from '../types'

const ACTION_COMPATIBLE_TRIGGERS: Record<AutomationActionType, AutomationTriggerType[]> = {
  'task.create': ['deal.stage_changed', 'deal.status_changed', 'ticket.created'],
  'notification.send': ['deal.stage_changed', 'deal.status_changed', 'ticket.created'],
  'deal.assign_owner': ['deal.stage_changed', 'deal.status_changed'],
}

const TICKET_PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const

export type AutomationRuleFormModalProps = {
  open: boolean
  onClose: () => void
  rule?: AutomationRule | null
  meta: AutomationRulesMeta
}

export function AutomationRuleFormModal({ open, onClose, rule, meta }: AutomationRuleFormModalProps) {
  const { t } = useTranslation(['settings', 'common'])
  const isEdit = !!rule

  const createRule = useCreateAutomationRule()
  const updateRule = useUpdateAutomationRule()
  const { data: stages } = usePipelineStages()
  const { data: userOptions, isForbidden: usersForbidden } = useAutomationUserOptions()

  const [name, setName] = useState('')
  const [triggerType, setTriggerType] = useState<AutomationTriggerType>(meta.triggers[0] ?? 'deal.stage_changed')
  const [pipelineStageId, setPipelineStageId] = useState('')
  const [status, setStatus] = useState<'won' | 'lost'>('won')
  const [priority, setPriority] = useState('')

  const [actionType, setActionType] = useState<AutomationActionType>(meta.actions[0] ?? 'task.create')
  const [titleTemplate, setTitleTemplate] = useState('')
  const [assigneeType, setAssigneeType] = useState<AutomationAssigneeType>('record_owner')
  const [assigneeUserId, setAssigneeUserId] = useState('')
  const [dueInDays, setDueInDays] = useState('3')
  const [messageTemplate, setMessageTemplate] = useState('')
  const [recipientType, setRecipientType] = useState<AutomationAssigneeType>('record_owner')
  const [recipientUserId, setRecipientUserId] = useState('')
  const [assignOwnerUserId, setAssignOwnerUserId] = useState('')

  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const openKey = open ? (rule ? `edit-${rule.id}` : 'create') : null
  const [lastOpenKey, setLastOpenKey] = useState<string | null>(null)
  if (openKey !== lastOpenKey) {
    setLastOpenKey(openKey)
    if (openKey) {
      setFieldErrors({})
      setName(rule?.name ?? '')

      const nextTrigger = rule?.trigger_type ?? meta.triggers[0] ?? 'deal.stage_changed'
      setTriggerType(nextTrigger)
      const tc = (rule?.trigger_config ?? {}) as Record<string, unknown>
      setPipelineStageId(tc.pipeline_stage_id != null ? String(tc.pipeline_stage_id) : '')
      setStatus((tc.status as 'won' | 'lost') ?? 'won')
      setPriority(typeof tc.priority === 'string' ? tc.priority : '')

      const nextAction = rule?.action_type ?? meta.actions[0] ?? 'task.create'
      setActionType(nextAction)
      const ac = (rule?.action_config ?? {}) as Record<string, unknown>
      setTitleTemplate(typeof ac.title_template === 'string' ? ac.title_template : '')
      setAssigneeType((ac.assignee_type as AutomationAssigneeType) ?? 'record_owner')
      setAssigneeUserId(ac.assignee_user_id != null ? String(ac.assignee_user_id) : '')
      setDueInDays(ac.due_in_days != null ? String(ac.due_in_days) : '3')
      setMessageTemplate(typeof ac.message_template === 'string' ? ac.message_template : '')
      setRecipientType((ac.recipient_type as AutomationAssigneeType) ?? 'record_owner')
      setRecipientUserId(ac.recipient_user_id != null ? String(ac.recipient_user_id) : '')
      setAssignOwnerUserId(ac.user_id != null ? String(ac.user_id) : '')
    }
  }

  const isPending = createRule.isPending || updateRule.isPending

  function fieldError(f: string): string | undefined {
    return fieldErrors[f]?.[0]
  }

  const triggerOptions = meta.triggers.map((type) => ({ value: type, label: labelForTrigger(type, t) }))
  const actionOptions = meta.actions
    .filter((type) => ACTION_COMPATIBLE_TRIGGERS[type]?.includes(triggerType) ?? true)
    .map((type) => ({ value: type, label: labelForAction(type, t) }))

  function handleTriggerChange(value: string) {
    const next = value as AutomationTriggerType
    setTriggerType(next)
    // Seçili eylem yeni tetikleyiciyle UYUMSUZ hâle geldiyse ilk uyumlu eyleme düş —
    // sunucuya asla geçersiz bir kombinasyon gönderilmez (backend zaten reddeder, ama
    // formun kendisi bu durumu HİÇ oluşturmamalı).
    const compatible = meta.actions.filter((type) => ACTION_COMPATIBLE_TRIGGERS[type]?.includes(next) ?? true)
    if (!compatible.includes(actionType) && compatible[0]) {
      setActionType(compatible[0])
    }
  }

  const stageOptions = (stages ?? []).map((stage) => ({ value: String(stage.id), label: stageLabel(t, stage) }))
  const userSelectOptions = (userOptions ?? []).map((user) => ({ value: String(user.id), label: user.name }))
  const placeholderHint = t('settings:automationRules.form.placeholderHint', {
    list: meta.title_placeholders.map((p) => `{${p}}`).join(', '),
  })

  function buildTriggerConfig(): Record<string, unknown> {
    switch (triggerType) {
      case 'deal.stage_changed':
        return { pipeline_stage_id: pipelineStageId ? Number(pipelineStageId) : null }
      case 'deal.status_changed':
        return { status }
      case 'ticket.created':
        return { priority: priority || null }
      default:
        return {}
    }
  }

  function buildActionConfig(): Record<string, unknown> {
    switch (actionType) {
      case 'task.create':
        return {
          title_template: titleTemplate,
          assignee_type: assigneeType,
          assignee_user_id: assigneeType === 'fixed_user' && assigneeUserId ? Number(assigneeUserId) : null,
          due_in_days: dueInDays ? Number(dueInDays) : null,
        }
      case 'notification.send':
        return {
          message_template: messageTemplate,
          recipient_type: recipientType,
          recipient_user_id: recipientType === 'fixed_user' && recipientUserId ? Number(recipientUserId) : null,
        }
      case 'deal.assign_owner':
        return { user_id: assignOwnerUserId ? Number(assignOwnerUserId) : null }
      default:
        return {}
    }
  }

  function validate(): boolean {
    const errors: Record<string, string[]> = {}
    if (!name.trim()) errors.name = [t('settings:automationRules.form.errors.nameRequired')]

    if (triggerType === 'deal.stage_changed' && !pipelineStageId) {
      errors['trigger_config.pipeline_stage_id'] = [t('settings:automationRules.form.errors.stageRequired')]
    }

    if (actionType === 'task.create') {
      if (!titleTemplate.trim()) errors['action_config.title_template'] = [t('settings:automationRules.form.errors.titleTemplateRequired')]
      if (assigneeType === 'fixed_user' && !assigneeUserId) {
        errors['action_config.assignee_user_id'] = [t('settings:automationRules.form.errors.assigneeRequired')]
      }
      if (!dueInDays.trim()) errors['action_config.due_in_days'] = [t('settings:automationRules.form.errors.dueInDaysRequired')]
    }

    if (actionType === 'notification.send') {
      if (!messageTemplate.trim()) errors['action_config.message_template'] = [t('settings:automationRules.form.errors.messageTemplateRequired')]
      if (recipientType === 'fixed_user' && !recipientUserId) {
        errors['action_config.recipient_user_id'] = [t('settings:automationRules.form.errors.recipientRequired')]
      }
    }

    if (actionType === 'deal.assign_owner' && !assignOwnerUserId) {
      errors['action_config.user_id'] = [t('settings:automationRules.form.errors.newOwnerRequired')]
    }

    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return

    const payload = {
      name,
      trigger_type: triggerType,
      trigger_config: buildTriggerConfig(),
      action_type: actionType,
      action_config: buildActionConfig(),
    }

    try {
      if (isEdit && rule) {
        await updateRule.mutateAsync({ id: rule.id, payload })
      } else {
        await createRule.mutateAsync(payload)
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
      title={isEdit ? t('settings:automationRules.form.titleEdit') : t('settings:automationRules.form.titleCreate')}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" form="automation-rule-form" loading={isPending}>
            {isEdit ? t('common:actions.save') : t('common:actions.create')}
          </Button>
        </div>
      }
    >
      <form id="automation-rule-form" onSubmit={handleSubmit} className="flex flex-col gap-5">
        <Input
          label={t('settings:automationRules.form.nameLabel')}
          value={name}
          onChange={(e) => setName(e.target.value)}
          error={fieldError('name')}
          required
        />

        <div className="flex flex-col gap-3 rounded-lg border border-border-subtle p-3">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-fg-muted">
            {t('settings:automationRules.form.triggerSection')}
          </h3>

          <Select
            label={t('settings:automationRules.form.triggerTypeLabel')}
            value={triggerType}
            onChange={(e) => handleTriggerChange(e.target.value)}
            options={triggerOptions}
          />

          {triggerType === 'deal.stage_changed' && (
            <Select
              label={t('settings:automationRules.form.pipelineStageLabel')}
              value={pipelineStageId}
              onChange={(e) => setPipelineStageId(e.target.value)}
              placeholder={t('settings:automationRules.form.pipelineStagePlaceholder')}
              options={stageOptions}
              error={fieldError('trigger_config.pipeline_stage_id')}
            />
          )}

          {triggerType === 'deal.status_changed' && (
            <Select
              label={t('settings:automationRules.form.dealStatusLabel')}
              value={status}
              onChange={(e) => setStatus(e.target.value as 'won' | 'lost')}
              options={[
                { value: 'won', label: t('settings:automationRules.form.dealStatusWon') },
                { value: 'lost', label: t('settings:automationRules.form.dealStatusLost') },
              ]}
            />
          )}

          {triggerType === 'ticket.created' && (
            <Select
              label={t('settings:automationRules.form.ticketPriorityFilterLabel')}
              value={priority}
              onChange={(e) => setPriority(e.target.value)}
              placeholder={t('settings:automationRules.form.ticketPriorityAny')}
              options={TICKET_PRIORITIES.map((p) => ({ value: p, label: t(`settings:automationRules.form.priorities.${p}`) }))}
              hint={t('settings:automationRules.form.ticketPriorityHint')}
            />
          )}
        </div>

        <div className="flex flex-col gap-3 rounded-lg border border-border-subtle p-3">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-fg-muted">
            {t('settings:automationRules.form.actionSection')}
          </h3>

          <Select
            label={t('settings:automationRules.form.actionTypeLabel')}
            value={actionType}
            onChange={(e) => setActionType(e.target.value as AutomationActionType)}
            options={actionOptions}
          />

          {actionType === 'task.create' && (
            <>
              <Input
                label={t('settings:automationRules.form.titleTemplateLabel')}
                value={titleTemplate}
                onChange={(e) => setTitleTemplate(e.target.value)}
                error={fieldError('action_config.title_template')}
                hint={placeholderHint}
                required
              />
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Select
                  label={t('settings:automationRules.form.assigneeTypeLabel')}
                  value={assigneeType}
                  onChange={(e) => setAssigneeType(e.target.value as AutomationAssigneeType)}
                  options={[
                    { value: 'record_owner', label: t('settings:automationRules.form.assigneeRecordOwner') },
                    {
                      value: 'fixed_user',
                      label: t('settings:automationRules.form.assigneeFixedUser'),
                      disabled: usersForbidden,
                    },
                  ]}
                />
                <Input
                  type="number"
                  min={0}
                  max={365}
                  label={t('settings:automationRules.form.dueInDaysLabel')}
                  value={dueInDays}
                  onChange={(e) => setDueInDays(e.target.value)}
                  error={fieldError('action_config.due_in_days')}
                />
              </div>
              {assigneeType === 'fixed_user' && (
                <Select
                  label={t('settings:automationRules.form.assigneeUserLabel')}
                  value={assigneeUserId}
                  onChange={(e) => setAssigneeUserId(e.target.value)}
                  placeholder={t('settings:automationRules.form.userPlaceholder')}
                  options={userSelectOptions}
                  error={fieldError('action_config.assignee_user_id')}
                  disabled={usersForbidden}
                  hint={usersForbidden ? t('settings:automationRules.form.usersForbidden') : undefined}
                />
              )}
            </>
          )}

          {actionType === 'notification.send' && (
            <>
              <Textarea
                label={t('settings:automationRules.form.messageTemplateLabel')}
                value={messageTemplate}
                onChange={(e) => setMessageTemplate(e.target.value)}
                error={fieldError('action_config.message_template')}
                hint={placeholderHint}
                rows={3}
              />
              <Select
                label={t('settings:automationRules.form.recipientTypeLabel')}
                value={recipientType}
                onChange={(e) => setRecipientType(e.target.value as AutomationAssigneeType)}
                options={[
                  { value: 'record_owner', label: t('settings:automationRules.form.recipientRecordOwner') },
                  {
                    value: 'fixed_user',
                    label: t('settings:automationRules.form.recipientFixedUser'),
                    disabled: usersForbidden,
                  },
                ]}
              />
              {recipientType === 'fixed_user' && (
                <Select
                  label={t('settings:automationRules.form.recipientUserLabel')}
                  value={recipientUserId}
                  onChange={(e) => setRecipientUserId(e.target.value)}
                  placeholder={t('settings:automationRules.form.userPlaceholder')}
                  options={userSelectOptions}
                  error={fieldError('action_config.recipient_user_id')}
                  disabled={usersForbidden}
                  hint={usersForbidden ? t('settings:automationRules.form.usersForbidden') : undefined}
                />
              )}
            </>
          )}

          {actionType === 'deal.assign_owner' && (
            <Select
              label={t('settings:automationRules.form.newOwnerLabel')}
              value={assignOwnerUserId}
              onChange={(e) => setAssignOwnerUserId(e.target.value)}
              placeholder={t('settings:automationRules.form.userPlaceholder')}
              options={userSelectOptions}
              error={fieldError('action_config.user_id')}
              disabled={usersForbidden}
              hint={usersForbidden ? t('settings:automationRules.form.usersForbidden') : undefined}
            />
          )}
        </div>
      </form>
    </Modal>
  )
}
