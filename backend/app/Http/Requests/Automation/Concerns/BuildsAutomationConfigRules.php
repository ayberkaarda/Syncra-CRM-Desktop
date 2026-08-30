<?php

namespace App\Http\Requests\Automation\Concerns;

use App\Services\Automation\AutomationCatalog;
use Illuminate\Validation\Validator;

/**
 * Faz 14 / İz F — `StoreAutomationRuleRequest` ve `UpdateAutomationRuleRequest`'in ORTAK
 * doğrulama mantığı: `trigger_config`/`action_config` ŞEMAYA KARŞI doğrulanır (görev tanımı:
 * "ham JSON olduğu gibi güvenilmez — C2'nin `query_json` kısıtının aynı ruhu").
 *
 * Şema `AutomationCatalog`'tan (TEK doğruluk kaynağı) okunur; bu trait yalnızca onu bir
 * Form Request'in `rules()`/`withValidator()` çift-noktasına BAĞLAR.
 */
trait BuildsAutomationConfigRules
{
    /**
     * @return array<string, mixed>
     */
    protected function catalogRules(): array
    {
        $rules = [];

        $triggerType = $this->input('trigger_type');
        if (is_string($triggerType) && in_array($triggerType, AutomationCatalog::TRIGGERS, true)) {
            foreach (AutomationCatalog::triggerConfigRules($triggerType) as $field => $fieldRules) {
                $rules["trigger_config.{$field}"] = $fieldRules;
            }
        }

        $actionType = $this->input('action_type');
        if (is_string($actionType) && in_array($actionType, AutomationCatalog::ACTIONS, true)) {
            foreach (AutomationCatalog::actionConfigRules($actionType) as $field => $fieldRules) {
                $rules["action_config.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * Beyaz listede OLMAYAN bir `trigger_config`/`action_config` anahtarı sessizce YOK
     * SAYILMAZ — 422 ile reddedilir. Eylem/tetikleyici uyumsuzluğu (ör. `ticket.created` +
     * `deal.assign_owner`) da burada yakalanır (PHASE-INTL §3 tablosu: `deal.assign_owner`
     * yalnızca `deal.*` tetikleyicileriyle anlamlıdır).
     */
    protected function validateCatalogShape(Validator $validator): void
    {
        $triggerType = $this->input('trigger_type');
        $actionType = $this->input('action_type');

        if (! is_string($triggerType) || ! in_array($triggerType, AutomationCatalog::TRIGGERS, true)) {
            return;
        }
        if (! is_string($actionType) || ! in_array($actionType, AutomationCatalog::ACTIONS, true)) {
            return;
        }

        $allowedTriggerKeys = array_keys(AutomationCatalog::triggerConfigRules($triggerType));
        $triggerConfig = $this->input('trigger_config');
        if (is_array($triggerConfig)) {
            $unknown = array_diff(array_keys($triggerConfig), $allowedTriggerKeys);
            foreach ($unknown as $key) {
                $validator->errors()->add(
                    "trigger_config.{$key}",
                    __('validation.custom.automation.unknown_config_key', ['key' => $key]),
                );
            }
        }

        $allowedActionKeys = array_keys(AutomationCatalog::actionConfigRules($actionType));
        $actionConfig = $this->input('action_config');
        if (is_array($actionConfig)) {
            $unknown = array_diff(array_keys($actionConfig), $allowedActionKeys);
            foreach ($unknown as $key) {
                $validator->errors()->add(
                    "action_config.{$key}",
                    __('validation.custom.automation.unknown_config_key', ['key' => $key]),
                );
            }
        }

        if (! AutomationCatalog::actionCompatibleWithTrigger($actionType, $triggerType)) {
            $validator->errors()->add('action_type', __('validation.custom.automation.incompatible_action'));
        }
    }
}
