<?php

namespace App\Http\Requests\Exchange;

use App\Services\Exchange\ExchangeRateService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/settings/exchange-rates` — manuel kur girişi
 * (docs/PHASE-INTL.md §2.1 "Kaynak politikası (Karar A)": TCMB'ye
 * ulaşılamadığında yönetici elle kur girebilir).
 *
 * Yetkilendirme controller'da (`settings.manage`, diğer Ayarlar uçlarıyla
 * aynı desen). Kural seti BURADA TEKRAR TANIMLANMAZ —
 * `ExchangeRateService::manualEntryRules()`'tan alınır: hem bu form hem
 * servisin kendi savunma-amaçlı ikinci kontrolü (`storeManualRate()`
 * içindeki `assertReasonableRate()`) AYNI eşiği kullanır, drift riski
 * olmaz. `rate_date` bugünden ileri bir tarih OLAMAZ — henüz gerçekleşmemiş
 * bir günün kurunu girmek anlamsızdır ve `resolveForFreeze()`'in "o gün
 * veya öncesi" aramasını bozar.
 */
class StoreManualExchangeRateRequest extends FormRequest
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
        $rules = app(ExchangeRateService::class)->manualEntryRules();
        $rules['rate_date'][] = 'before_or_equal:today';

        return $rules;
    }
}
