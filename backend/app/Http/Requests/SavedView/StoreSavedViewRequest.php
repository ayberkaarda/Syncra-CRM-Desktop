<?php

namespace App\Http\Requests\SavedView;

use App\Services\SavedViews\SavedViewModules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/saved-views` — Faz 14 / İz F / C2. Yetkilendirme burada DEĞİL,
 * `SavedViewController::store()` içinde `SavedViewPolicy::create()` ile yapılır (modül
 * başına `.view` izni gerekir — bir modülü göremeyen biri o modül için görünüm de
 * kaydedemez).
 *
 * `query_json`'ın İÇERİĞİ burada doğrulanmaz (`array` dışında kural yok): asıl modül
 * başına BEYAZ LİSTE doğrulaması `App\Services\SavedViews\SavedViewQueryValidator`'dadır
 * (docs/PHASE-AUDIT.md §5.4) — burada iki ayrı doğrulama katmanı İCAT ETMEK yerine
 * Controller o servisi çağırır (bkz. `SavedViewController::store()`).
 */
class StoreSavedViewRequest extends FormRequest
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
            'module' => ['required', 'string', Rule::in(SavedViewModules::names())],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('saved_views')->where(function ($query) {
                    return $query
                        ->where('user_id', $this->user()->id)
                        ->where('module', $this->input('module'));
                }),
            ],
            // `present` (DEĞİL `required`): boş bir dizi ({}) geçerli bir görünümdür —
            // "hiç filtre/sıra yok, hepsini varsayılan sırayla göster" anlamına gelir.
            // Laravel'in `required` kuralı BOŞ bir diziyi eksik SAYAR (`empty([])===true`),
            // bu yüzden hiçbir filtre uygulamamış bir kullanıcı "geçerli bir görünüm
            // kaydedemez" gibi anlamsız bir 422 alırdı.
            'query_json' => ['present', 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ];
    }
}
