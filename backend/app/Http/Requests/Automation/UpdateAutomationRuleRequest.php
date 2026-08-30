<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\Automation\Concerns\BuildsAutomationConfigRules;
use App\Services\Automation\AutomationCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PATCH /api/settings/automation-rules/{automationRule}`.
 *
 * Tetikleyici/eylem BİR BÜTÜNDÜR: `trigger_type`/`trigger_config`/`action_type`/
 * `action_config`'ten BİRİ gönderilirse HEPSİ gönderilmelidir (görev tanımı bu dört alanı
 * mantıksal olarak ayrılamaz bir küme olarak tarif eder — yalnızca `trigger_config`'i
 * `trigger_type` olmadan doğrulamak anlamsızdır). `name`/`is_active` bağımsız
 * güncellenebilir (yalnızca aç/kapa veya yeniden adlandırma).
 */
class UpdateAutomationRuleRequest extends FormRequest
{
    use BuildsAutomationConfigRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $configFields = ['trigger_type', 'trigger_config', 'action_type', 'action_config'];
        $touchesConfig = $this->requestTouchesConfigFields($configFields);
        $requiredIfTouched = $touchesConfig ? 'required' : 'sometimes';

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'trigger_type' => [$requiredIfTouched, 'string', Rule::in(AutomationCatalog::TRIGGERS)],
            'trigger_config' => [$requiredIfTouched, 'array'],
            'action_type' => [$requiredIfTouched, 'string', Rule::in(AutomationCatalog::ACTIONS)],
            'action_config' => [$requiredIfTouched, 'array'],
        ];

        return array_merge($rules, $this->catalogRules());
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $configFields = ['trigger_type', 'trigger_config', 'action_type', 'action_config'];
            $touchesConfig = $this->requestTouchesConfigFields($configFields);

            if ($touchesConfig) {
                $this->validateCatalogShape($validator);
            }
        });
    }

    /**
     * @param  list<string>  $fields
     */
    private function requestTouchesConfigFields(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->has($field)) {
                return true;
            }
        }

        return false;
    }
}
