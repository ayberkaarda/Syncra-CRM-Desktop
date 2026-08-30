<?php

namespace App\Http\Requests\Settings;

use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/settings/email-templates` — yetkilendirme controller'da
 * (`settings.manage`).
 *
 * `variables` GÖNDERİLMEZSE `subject` + `body_html` içindeki
 * `{{ degisken }}` yer tutucularından TÜRETİLİR (bkz.
 * EmailTemplateService::extractVariables). Gerekçe: listeyi elle tutmak,
 * metin değiştikçe listeyi güncellemeyi unutmak demektir — önizleme ekranı
 * o zaman şablonda gerçekten geçen bir değişkeni sormaz ve çıktıda ham
 * `{{ ... }}` görünür. Elle gönderilirse olduğu gibi saklanır (metinde
 * henüz geçmeyen ama planlanan bir değişken tanımlanabilsin diye).
 *
 * Bu fazda E-POSTA GÖNDERİLMEZ: şablon yalnızca saklanır ve önizlenir.
 *
 * Faz 13 / H6 (§4-F5): `body_html` DOĞRULAMADAN ÖNCE sanitize edilir
 * (`prepareForValidation`) — gerekçe aşağıda.
 */
class StoreEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * KARAR: sanitizasyon doğrulamadan SONRA değil ÖNCE.
     *
     * Gerekçe iki yönlü:
     *  1. `required` ve `max:65535` böylece KAYDEDİLECEK değeri görür. Gövdesi
     *     tamamen `<script>…</script>` olan bir istek temizlendikten sonra boş
     *     kalır ve dürüst bir 422 `body_html.required` alır — sessizce boş bir
     *     şablon oluşturmaz. Aynı şekilde sanitizasyon uzunluğu artırırsa
     *     (varlık kodlaması) sınırı aşan değer 422 döner, DB'de sessizce
     *     kırpılmaz.
     *  2. `validated()` çıktısı zaten temiz olduğu için servise kirli değer
     *     GEÇEMEZ. Servis yine de kendi katmanında temizler (seeder/konsol gibi
     *     HTTP dışı çağıranlar için) — sanitizer idempotenttir, ikinci geçiş
     *     bedava ve zararsızdır.
     */
    protected function prepareForValidation(): void
    {
        // `is_string` şart: alan dizi/null gelirse sanitizer'a sokmak
        // TypeError olurdu; o durumu `rules()` içindeki `string` yakalar.
        if (is_string($this->input('body_html'))) {
            $this->merge([
                'body_html' => HtmlSanitizer::sanitizeEmailBody($this->input('body_html')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('email_templates', 'key'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:65535'],
            'variables' => ['sometimes', 'nullable', 'array', 'max:100'],
            'variables.*' => ['string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => __('validation.custom.settings.key_format'),
        ];
    }
}
