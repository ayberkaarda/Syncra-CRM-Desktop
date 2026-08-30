<?php

namespace App\Http\Requests\SavedView;

use App\Services\SavedViews\SavedViewModules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/saved-views` — Faz 14 / İz F / C2. Yetkilendirme burada DEĞİL,
 * `SavedViewController::index()` içinde `SavedViewPolicy::viewAny()` ile yapılır (modül
 * başına `.view` izni gerekir, bkz. o Policy'nin dokümanı).
 */
class IndexSavedViewRequest extends FormRequest
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
        ];
    }
}
