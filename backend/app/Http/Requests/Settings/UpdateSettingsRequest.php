<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/settings` — gövde DÜZ BİR HARİTADIR:
 *
 *     { "company.name": "Syncra A.Ş.", "quote.validity_days": 45 }
 *
 * =============================================================================
 * NEDEN `rules()` BOŞ
 * =============================================================================
 * Doğrulanacak anahtarlar SABİT DEĞİLDİR: hangi ayarların var olduğu
 * `settings` TABLOSUNDAN okunur ve her ayarın kabul ettiği değer kendi `type`
 * kolonuna bağlıdır. Bunu `rules()` içinde ifade etmek her istekte tablonun
 * tamamını okuyup kural dizisi üretmek demektir — ve bir tuzağı vardır:
 * Laravel doğrulayıcısında kural anahtarındaki NOKTA iç içe erişim demektir,
 * yani `company.name` kuralı `['company' => ['name' => ...]]` arar, gövdedeki
 * düz `"company.name"` anahtarını GÖRMEZ (kaçış için `company\.name` yazmak
 * gerekirdi). İki tarafı da yanlış anlamaya açık bırakmamak için tip/anahtar
 * doğrulaması SettingsService::update() içinde, ayarın kendi `type`'ına göre
 * yapılır ve oradan `ValidationException` fırlatılır.
 *
 * Burada yalnızca GÖVDENİN ŞEKLİ doğrulanır: boş olmamalı ve iç içe olmamalı
 * (JSON tipi ayarlar hariç — onların değeri zaten bir dizidir).
 */
class UpdateSettingsRequest extends FormRequest
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
        return [];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->settings() === []) {
                    $validator->errors()->add(
                        'settings',
                        'Güncellenecek en az bir ayar gönderilmelidir.'
                    );
                }
            },
        ];
    }

    /**
     * Gövdedeki düz harita. `$this->input('company.name')` KULLANILAMAZ:
     * nokta iç içe erişim olarak yorumlanır ve düz anahtarı bulamaz.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $payload = $this->all();

        return is_array($payload) ? $payload : [];
    }
}
