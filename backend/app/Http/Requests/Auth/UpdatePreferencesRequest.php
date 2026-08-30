<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/me/preferences` — kişisel arayüz tercihleri (Faz 14 / İz D, §1.3).
 *
 * BEYAZ LİSTE ZORUNLU: `users.locale` doğrudan `App::setLocale()`'e gider (SetLocale
 * middleware). Denetimsiz bir dize orada çeviri dosyası çözümünü istemcinin etkisine
 * açardı; `Rule::in()` bunu tek noktada keser. `preferred_currency` için de aynı disiplin:
 * `Intl`/kur tablosunda karşılığı olmayan bir kod, sonradan sessiz bir biçimlendirme
 * hatasına dönüşür.
 *
 * `authorize()` YOK (varsayılan `true`): burada korunacak bir yetki yoktur — kullanıcı
 * KENDİ tercihini yazar. Rota zaten `auth:sanctum` + `active` + `password.changed`
 * arkasındadır; kimin yazdığı `$request->user()` ile sabittir, gövdeden gelmez.
 */
class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // `sometimes`: iki alan bağımsızdır. Dil seçici yalnız `locale`, para birimi
            // seçici (İz E) yalnız `preferred_currency` gönderir; biri diğerini sıfırlamaz.
            'locale' => ['sometimes', 'string', Rule::in((array) config('syncra.i18n.supported_locales'))],
            'preferred_currency' => ['sometimes', 'string', Rule::in((array) config('syncra.currency.supported'))],
        ];
    }
}
