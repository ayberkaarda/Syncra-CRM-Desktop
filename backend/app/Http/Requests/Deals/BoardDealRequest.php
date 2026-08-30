<?php

namespace App\Http\Requests\Deals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/deals/board` — Kanban panosu sözleşmesi.
 *
 * `filter[q]` KASITLI olarak `filter` altında (liste ucundaki üst seviye
 * `q`'dan farklı) — pano filtrelerinin tamamı tek bir `filter[...]`
 * gövdesinde toplanır. Yetkilendirme burada DEĞİL,
 * DealController::board() içinde Policy ile yapılır.
 */
class BoardDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public const DEFAULT_PER_STAGE = 50;

    public const MAX_PER_STAGE = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_stage' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_STAGE],
            'filter' => ['sometimes', 'array'],
            'filter.owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'filter.q' => ['sometimes', 'nullable', 'string', 'max:255'],
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
        $filter = $this->validated()['filter'] ?? [];

        return [
            'q' => $filter['q'] ?? null,
            'owner_id' => $filter['owner_id'] ?? null,
            'company_id' => $filter['company_id'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
        ];
    }

    public function perStage(): int
    {
        return (int) ($this->validated()['per_stage'] ?? self::DEFAULT_PER_STAGE);
    }
}
