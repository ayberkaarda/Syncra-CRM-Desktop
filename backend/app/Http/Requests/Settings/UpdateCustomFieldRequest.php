<?php

namespace App\Http\Requests\Settings;

use App\Http\Controllers\Api\CustomFieldController;
use App\Models\CustomField;
use App\Services\Settings\CustomFieldService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/settings/custom-fields/{customField}` — yetkilendirme
 * controller'da (`settings.manage`).
 *
 * =============================================================================
 * `key` VE `entity_type` DEĞİŞTİRİLEMEZ — AMA `missing` DEĞİL, "AYNI OLMALI"
 * =============================================================================
 * `custom_field_values` satırları `custom_field_id` üzerinden bağlıdır; anahtar
 * değişse veriler kaybolmazdı ama alanı anahtarıyla okuyan HER ŞEY (form
 * şeması, CSV içe/dışa aktarma başlıkları, ileride raporlar) sessizce boş
 * dönerdi — ve `entity_type` değişimi alanı, değerlerinin ait olduğu kayıt
 * tipinden koparırdı ("leads" alanına yazılmış 300 değer bir anda "deals"
 * alanının değerleri olurdu).
 *
 * Diğer modüllerdeki `missing` kuralı yerine EŞİTLİK kontrolü seçildi:
 * ayarlar ekranı formu, kullanıcı yalnızca etiketi değiştirse bile alanın
 * TAMAMINI geri gönderir (salt-okunur gösterilen `key` dahil). `missing`
 * olsaydı hiçbir düzenleme kaydedilemezdi. Değer FARKLIYSA 422 döner
 * (`CUSTOM_FIELD_KEY_IMMUTABLE` / `CUSTOM_FIELD_ENTITY_TYPE_IMMUTABLE`),
 * karşılaştırma CustomFieldService::update() içinde yapılır.
 */
class UpdateCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(CustomFieldService::TYPES)],
            'options' => ['sometimes', 'nullable', 'array', 'max:100'],
            'options.*' => ['string', 'max:255'],
            'is_required' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            // Gönderilebilirler, ama yalnızca MEVCUT değerleriyle
            // (bkz. sınıf dokümanı); farkı servis yakalar.
            'key' => ['sometimes', 'string', 'max:255'],
            'entity_type' => ['sometimes', Rule::in(CustomFieldController::ENTITY_TYPES)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->has('type') && ! $this->has('options')) {
                    return;
                }

                /** @var CustomField $field */
                $field = $this->route('customField');

                $type = (string) $this->input('type', $field->type);

                if (! in_array($type, CustomFieldService::OPTION_TYPES, true)) {
                    return;
                }

                $options = $this->has('options') ? $this->input('options') : $field->options;

                if ($options === null || $options === []) {
                    $validator->errors()->add(
                        'options',
                        'Seçim tipindeki bir alan en az bir seçenek içermelidir.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => __('validation.custom.settings.custom_field_type_invalid', [
                'values' => implode('|', CustomFieldService::TYPES),
            ]),
            'entity_type.in' => __('validation.custom.settings.custom_field_entity_type_invalid', [
                'values' => implode('|', CustomFieldController::ENTITY_TYPES),
            ]),
        ];
    }
}
