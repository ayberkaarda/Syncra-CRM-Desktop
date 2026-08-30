<?php

namespace App\Http\Requests\Activities;

use App\Support\MorphTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/activities` liste sözleşmesi. Yetkilendirme burada DEĞİL,
 * ActivityController::index() içinde Policy ile yapılır.
 */
class IndexActivityRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:64'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter' => ['sometimes', 'array'],
            'filter.type' => ['sometimes', 'nullable', Rule::in(['call', 'meeting', 'email', 'note'])],
            'filter.user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.activityable_type' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(MorphTargets::TARGETS))],
            'filter.activityable_id' => ['sometimes', 'nullable', 'integer'],
            'filter.from' => ['sometimes', 'nullable', 'date'],
            'filter.to' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * Repository/Service katmanının beklediği düz filtre dizisini üretir.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filter = $validated['filter'] ?? [];

        return [
            'q' => $validated['q'] ?? null,
            'type' => $filter['type'] ?? null,
            'user_id' => $filter['user_id'] ?? null,
            'activityable_type' => $filter['activityable_type'] ?? null,
            'activityable_id' => $filter['activityable_id'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
