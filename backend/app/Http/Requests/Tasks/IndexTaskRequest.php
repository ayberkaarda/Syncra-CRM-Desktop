<?php

namespace App\Http\Requests\Tasks;

use App\Support\MorphTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/tasks` liste sözleşmesi — bkz. Faz 6/7 standart liste kuralları
 * (page/per_page/sort/q/filter). Yetkilendirme burada DEĞİL,
 * TaskController::index() içinde Policy ile yapılır.
 */
class IndexTaskRequest extends FormRequest
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
            'filter.status' => ['sometimes', 'nullable', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'filter.priority' => ['sometimes', 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'filter.assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.created_by' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.taskable_type' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(MorphTargets::TARGETS))],
            'filter.taskable_id' => ['sometimes', 'nullable', 'integer'],
            'filter.from' => ['sometimes', 'nullable', 'date'],
            'filter.to' => ['sometimes', 'nullable', 'date'],
            'filter.overdue' => ['sometimes', 'nullable', 'boolean'],
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
            'status' => $filter['status'] ?? null,
            'priority' => $filter['priority'] ?? null,
            'assigned_to' => $filter['assigned_to'] ?? null,
            'created_by' => $filter['created_by'] ?? null,
            'taskable_type' => $filter['taskable_type'] ?? null,
            'taskable_id' => $filter['taskable_id'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
            'overdue' => $filter['overdue'] ?? null,
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
