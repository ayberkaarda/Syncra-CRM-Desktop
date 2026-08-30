<?php

namespace App\Http\Requests\Chat;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/conversations` — Yetkilendirme ConversationController::index()
 * içinde Policy ile yapılır.
 */
class IndexConversationRequest extends FormRequest
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
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter' => ['sometimes', 'array'],
            'filter.type' => ['sometimes', 'nullable', Rule::in(Conversation::TYPES)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filter = $validated['filter'] ?? [];

        return [
            'type' => $filter['type'] ?? null,
            'q' => $validated['q'] ?? null,
            'per_page' => $validated['per_page'] ?? 30,
        ];
    }
}
