<?php

namespace App\Http\Requests\Logs;

use App\Repositories\LogRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/logs/sessions|page-visits|activities` için ortak sözleşme.
 *
 * Uca özel kurallar route adına göre (logs.sessions / logs.page-visits /
 * logs.activities) seçilir — yetkilendirme burada DEĞİL, LogController
 * içinde `Gate::allows('logs.view')` ile yapılır.
 */
class IndexLogRequest extends FormRequest
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
        $endpoint = $this->route()?->getName();

        $rules = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:64'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter' => ['sometimes', 'array'],
            'filter.user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.from' => ['sometimes', 'nullable', 'date'],
            'filter.to' => ['sometimes', 'nullable', 'date'],
        ];

        return array_merge($rules, $this->endpointRules($endpoint));
    }

    /**
     * @return array<string, mixed>
     */
    protected function endpointRules(?string $endpoint): array
    {
        return match ($endpoint) {
            'logs.sessions' => [
                'filter.event' => ['sometimes', 'nullable', Rule::in(['login', 'logout', 'failed_login', 'locked_out'])],
                'filter.ip' => ['sometimes', 'nullable', 'string', 'max:45'],
            ],
            'logs.page-visits' => [
                'filter.route' => ['sometimes', 'nullable', 'string', 'max:255'],
                'filter.path' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            'logs.activities' => [
                'filter.event' => ['sometimes', 'nullable', Rule::in(['created', 'updated', 'deleted', 'restored'])],
                'filter.subject_type' => ['sometimes', 'nullable', Rule::in(array_keys(LogRepository::SUBJECT_TYPE_MAP))],
                'filter.log_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            default => [],
        };
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

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? 25);
    }
}
