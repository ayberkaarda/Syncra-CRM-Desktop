<?php

namespace App\Http\Requests\Logs;

use App\Repositories\LogRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/logs/export?type=&format=` — listeleme ile AYNI filtreleri kabul
 * eder (yetkilendirme LogController içinde `Gate::allows('logs.export')` ile
 * yapılır). Uca özel kurallar `type` girdisine göre seçilir.
 */
class ExportLogRequest extends FormRequest
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
        $type = $this->input('type');

        $rules = [
            'type' => ['required', Rule::in(['sessions', 'page-visits', 'activities'])],
            'format' => ['sometimes', 'nullable', Rule::in(['csv', 'xlsx'])],
            'sort' => ['sometimes', 'nullable', 'string', 'max:64'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter' => ['sometimes', 'array'],
            'filter.user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.from' => ['sometimes', 'nullable', 'date'],
            'filter.to' => ['sometimes', 'nullable', 'date'],
        ];

        return array_merge($rules, $this->typeRules($type));
    }

    /**
     * @return array<string, mixed>
     */
    protected function typeRules(?string $type): array
    {
        return match ($type) {
            'sessions' => [
                'filter.event' => ['sometimes', 'nullable', Rule::in(['login', 'logout', 'failed_login', 'locked_out'])],
                'filter.ip' => ['sometimes', 'nullable', 'string', 'max:45'],
            ],
            'page-visits' => [
                'filter.route' => ['sometimes', 'nullable', 'string', 'max:255'],
                'filter.path' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            'activities' => [
                'filter.event' => ['sometimes', 'nullable', Rule::in(['created', 'updated', 'deleted', 'restored'])],
                'filter.subject_type' => ['sometimes', 'nullable', Rule::in(array_keys(LogRepository::SUBJECT_TYPE_MAP))],
                'filter.log_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filter = $validated['filter'] ?? [];

        return [
            'q' => $validated['q'] ?? null,
            'user_id' => $filter['user_id'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
            'event' => $filter['event'] ?? null,
            'ip' => $filter['ip'] ?? null,
            'route' => $filter['route'] ?? null,
            'path' => $filter['path'] ?? null,
            'subject_type' => $filter['subject_type'] ?? null,
            'log_name' => $filter['log_name'] ?? null,
            'sort' => $validated['sort'] ?? null,
        ];
    }

    public function type(): string
    {
        return $this->validated()['type'];
    }

    public function exportFormat(): string
    {
        return $this->validated()['format'] ?? 'csv';
    }
}
