<?php

namespace App\Http\Requests\SavedView;

use App\Models\SavedView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/saved-views/{savedView}` — Faz 14 / İz F / C2. Yetkilendirme burada DEĞİL,
 * `SavedViewController::update()` içinde `SavedViewPolicy::update()` ile yapılır (yalnızca
 * sahibi — `is_shared` bunu değiştirmez, bkz. o Policy'nin dokümanı).
 *
 * `module` KASITLI OLARAK burada YOK: bir görünümün modülü sonradan değiştirilemez (bir
 * `deals` görünümünü `tickets`'e "taşımak" query_json'ın anlamını bozardı) — modül
 * değiştirmek istiyorsan yeni bir görünüm oluştur. `query_json`'ın İÇERİĞİ de burada
 * doğrulanmaz; `SavedViewQueryValidator` Controller'da mevcut `$savedView->module`'e karşı
 * çalışır (bkz. `StoreSavedViewRequest` dokümanındaki aynı gerekçe).
 */
class UpdateSavedViewRequest extends FormRequest
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
        /** @var SavedView $savedView */
        $savedView = $this->route('savedView');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('saved_views')
                    ->where(fn ($query) => $query->where('user_id', $savedView->user_id)->where('module', $savedView->module))
                    ->ignore($savedView->id),
            ],
            'query_json' => ['sometimes', 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ];
    }
}
