<?php

namespace App\Http\Requests\Settings;

use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/settings/email-templates/{emailTemplate}` — yetkilendirme
 * controller'da (`settings.manage`).
 *
 * `key` DEĞİŞTİRİLEMEZ: kod şablonu adıyla değil anahtarıyla bulur, dolayısıyla
 * anahtarın değişmesi o şablonu çağıran her yerin sessizce boş dönmesi
 * demektir. UpdateCustomFieldRequest ile AYNI yaklaşım: alan gövdede
 * bulunabilir (form tüm nesneyi geri gönderir) ama FARKLI bir değer taşırsa
 * 422 `EMAIL_TEMPLATE_KEY_IMMUTABLE` döner — karşılaştırma
 * EmailTemplateService::update() içinde.
 *
 * Faz 13 / H6 (§4-F5): `body_html` DOĞRULAMADAN ÖNCE sanitize edilir
 * (bkz. StoreEmailTemplateRequest::prepareForValidation gerekçesi).
 */
class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PATCH'te alan `sometimes`: GÖNDERİLMEMİŞ bir `body_html`'e dokunmak,
     * olmayan bir anahtarı boş string olarak dizeye SOKAR ve kısmi güncellemeyi
     * tam güncellemeye çevirirdi. Bu yüzden yalnız gerçekten string gelmişse
     * temizlenir.
     */
    protected function prepareForValidation(): void
    {
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
            'name' => ['sometimes', 'string', 'max:255'],
            'subject' => ['sometimes', 'string', 'max:255'],
            'body_html' => ['sometimes', 'string', 'max:65535'],
            'variables' => ['sometimes', 'nullable', 'array', 'max:100'],
            'variables.*' => ['string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],

            // Gönderilebilir, ama yalnızca mevcut değeriyle (bkz. sınıf dokümanı).
            'key' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
