<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\Automation\Concerns\BuildsAutomationConfigRules;
use App\Services\Automation\AutomationCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /api/settings/automation-rules`.
 *
 * Yetkilendirme BURADA YAPILMAZ (`authorize()` her zaman `true`): PHASE-AUDIT §5.4'ün
 * "yazma anında izin kontrolü" katmanı `AutomationRulePolicy::create()` içinde, DOĞRULANMIŞ
 * (`trigger_type`/`action_type`/`action_config`) veriyle çalışır — Form Request'in
 * `authorize()`'ı henüz doğrulanmamış ham girdiyle çalışır ve bu kontrol için YETERSİZDİR.
 */
class StoreAutomationRuleRequest extends FormRequest
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
        return array_merge([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'trigger_type' => ['required', 'string', Rule::in(AutomationCatalog::TRIGGERS)],
            'trigger_config' => ['required', 'array'],
            'action_type' => ['required', 'string', Rule::in(AutomationCatalog::ACTIONS)],
            'action_config' => ['required', 'array'],
        ], $this->catalogRules());
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateCatalogShape($validator));
    }
}
