<?php

namespace App\Http\Requests\Deals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/deals/{deal}/move` gövdesi.
 *
 * ---------------------------------------------------------------------------
 * `position` KABUL EDİLMEZ — `prohibited`
 * ---------------------------------------------------------------------------
 * Sıralama anahtarı daima sunucuda, satır kilidi altında üretilir. İstemciden
 * gelen bir `position` sessizce YOK SAYILSAYDI, bunu deneyen bir istemci
 * geliştiricisi "çalışıyor" sanıp üzerine mantık kurardı. `prohibited` kuralı
 * denemeyi 422 ile açıkça reddeder ve sözleşmeyi kodda görünür kılar.
 *
 * ---------------------------------------------------------------------------
 * `version` ZORUNLU
 * ---------------------------------------------------------------------------
 * `sometimes` ya da varsayılan bir değer, iyimser kilidi tamamen devre dışı
 * bırakırdı: alanı göndermeyen her istemci çakışma kontrolünden muaf olurdu.
 * Kilidin işe yaraması, istemcinin "ben kartın ŞU hâlini gördüm" demesine
 * bağlıdır.
 *
 * Komşu id'lerinde `exists` kuralı KULLANILMAZ; komşuların gerçekten hedef
 * aşamada ve silinmemiş olduğu doğrulaması DealMoveService'te, satır kilidi
 * ALTINDA yapılır. Burada yapılan bir `exists` kontrolü kilitten önce
 * olacağından yalnızca yanıltıcı bir güvence olurdu.
 */
class MoveDealRequest extends FormRequest
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
            'to_stage_id' => ['required', 'integer', 'min:1'],
            'before_deal_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'after_deal_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'different:before_deal_id'],
            'version' => ['required', 'integer', 'min:1'],
            'lost_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'won_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'position' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => __('validation.custom.deals.version_required'),
            'after_deal_id.different' => __('validation.custom.deals.neighbor_conflict'),
            'position.prohibited' => __('validation.custom.deals.position_prohibited'),
        ];
    }

    /**
     * DealMoveService::move()'un beklediği normalize edilmiş dizi.
     *
     * @return array{
     *     to_stage_id: int,
     *     before_deal_id: ?int,
     *     after_deal_id: ?int,
     *     version: int,
     *     lost_reason: ?string,
     *     won_reason: ?string
     * }
     */
    public function movePayload(): array
    {
        return [
            'to_stage_id' => (int) $this->validated('to_stage_id'),
            'before_deal_id' => $this->nullableId('before_deal_id'),
            'after_deal_id' => $this->nullableId('after_deal_id'),
            'version' => (int) $this->validated('version'),
            'lost_reason' => $this->validated('lost_reason'),
            'won_reason' => $this->validated('won_reason'),
        ];
    }

    private function nullableId(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null ? null : (int) $value;
    }
}
