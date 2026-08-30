<?php

namespace App\Http\Requests\Quotes;

use App\Services\Quotes\QuoteStatusMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/quotes` liste sözleşmesi — Faz 6/7/8 standart liste kuralları
 * (page/per_page/sort/q/filter). Yetkilendirme burada DEĞİL,
 * QuoteController::index() içinde Policy ile yapılır.
 */
class IndexQuoteRequest extends FormRequest
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
            'filter.status' => ['sometimes', 'nullable', Rule::in(QuoteStatusMachine::statuses())],
            'filter.deal_id' => ['sometimes', 'nullable', 'integer', 'exists:deals,id'],
            'filter.company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'filter.contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'filter.from' => ['sometimes', 'nullable', 'date'],
            'filter.to' => ['sometimes', 'nullable', 'date'],
            // `boolean` kuralı "1"/"0"/"true"/"false" kabul eder — query
            // string'den her zaman string geldiği için bu şart.
            'filter.expired' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * Repository/Service katmanının beklediği düz filtre dizisini üretir.
     *
     * `expired` ÜÇ DURUMLUDUR: `null` (filtre yok), `true` (yalnızca süresi
     * dolmuşlar), `false` (yalnızca süresi dolmamışlar). Diğer boolean
     * filtrelerdeki gibi `false`'a düşürülemez — o zaman filtreyi hiç
     * göndermemek ile `expired=0` göndermek aynı şeye gelirdi.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filter = $validated['filter'] ?? [];

        $expired = null;

        if (array_key_exists('expired', $filter) && $filter['expired'] !== null) {
            $expired = filter_var($filter['expired'], FILTER_VALIDATE_BOOLEAN);
        }

        return [
            'q' => $validated['q'] ?? null,
            'status' => $filter['status'] ?? null,
            'deal_id' => $filter['deal_id'] ?? null,
            'company_id' => $filter['company_id'] ?? null,
            'contact_id' => $filter['contact_id'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
            'expired' => $expired,
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
