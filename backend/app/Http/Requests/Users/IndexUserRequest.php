<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class IndexUserRequest extends FormRequest
{
    /**
     * Yetkilendirme UserController::index() içinde Policy ile yapılır.
     */
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
            'sort' => ['sometimes', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
            'filter' => ['sometimes', 'array'],
            'filter.role' => ['nullable', 'string', 'exists:roles,name'],
            'filter.is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Repository/Service katmanının beklediği düz filtre dizisini üretir.
     *
     * @return array{q: ?string, role: ?string, is_active: mixed, sort: ?string, per_page: int}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => $validated['q'] ?? null,
            'role' => $validated['filter']['role'] ?? null,
            'is_active' => $validated['filter']['is_active'] ?? null,
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 15,
        ];
    }
}
