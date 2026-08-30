<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exchange\StoreManualExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use App\Services\Exchange\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Ayarlar > Kur ekranı (docs/PHASE-INTL.md §2.1, §2.6) — `settings.manage`.
 *
 * TCMB'nin günlük otomatik çekmesi (`exchange:fetch-tcmb`, başka şeridin
 * işi) bu uçla İLGİLİ AMA BAĞIMSIZDIR: bu controller yalnız (a) mevcut
 * durumu OKUR (para birimi başına en güncel kur + bayatlık) ve (b) TCMB'ye
 * ulaşılamadığında yöneticinin ELLE düzeltme YAZMASINI sağlar. Otomatik
 * çekmenin kendisi bir konsol komutudur, HTTP ucu yoktur.
 *
 * Diğer Ayarlar controller'larıyla (PipelineStageController,
 * CustomFieldController) AYNI yetki deseni: Policy/Gate katmanı yok, tek
 * satır `Gate::allows('settings.manage')` kontrolü.
 */
class ExchangeRateController extends Controller
{
    public function __construct(protected ExchangeRateService $exchange) {}

    /**
     * `GET /api/settings/exchange-rates` — desteklenen HER para birimi için
     * bir satır (henüz hiç kur girilmemişse `null` "resource" olarak değil,
     * `rate: null` ile YOK olduğu açıkça belirtilir — sessizce eksik satır
     * atlamak istemcinin "üç para birimi de var" varsayımını kırardı).
     */
    public function index(): JsonResponse
    {
        $this->authorizeSettings();

        $rows = collect($this->exchange->supportedCurrencies())
            ->map(function (string $currency): array {
                $rate = $this->exchange->latest($currency);

                return [
                    'currency' => $currency,
                    'rate' => $rate === null ? null : (new ExchangeRateResource($rate))->toArray(request()),
                ];
            })
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'base_currency' => $this->exchange->baseCurrency(),
                'supported_currencies' => $this->exchange->supportedCurrencies(),
                'stale_threshold_days' => ExchangeRateService::STALE_THRESHOLD_DAYS,
            ],
        ]);
    }

    /**
     * `POST /api/settings/exchange-rates` — manuel kur girişi/düzeltme.
     *
     * Aynı gün + aynı para birimi için ikinci giriş `unique(currency,
     * rate_date)` üzerinden UPSERT'tir (`storeManualRate()` →
     * `updateOrCreate`) — kayıt çoğalmaz, mevcut satır `source='manual'`
     * ve yeni değerle güncellenir (otomatik TCMB satırının üzerine de
     * yazabilir — bilinçli: yönetici hatalı bir otomatik kuru düzeltebilmeli).
     */
    public function store(StoreManualExchangeRateRequest $request): JsonResponse
    {
        $this->authorizeSettings();

        $rate = $this->exchange->storeManualRate(
            $request->validated('currency'),
            (string) $request->validated('rate'),
            CarbonImmutable::parse($request->validated('rate_date')),
            $request->user()?->getKey(),
        );

        return (new ExchangeRateResource($rate))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * `GET /api/exchange-rates/current` — herkese açık güncel kur ucu (Faz 14 / İz E,
     * docs/PHASE-INTL.md §2 Karar B). Yalnız `auth:sanctum` (+ bu route grubunun
     * `active`/`password.changed` zorunlulukları) — EK İZİN YOK, bilinçli.
     *
     * GEREKÇE: TCMB kurları kamuya açık veridir (today.xml kimliksiz herkese açık) — gizli
     * değildir. Sıradan bir kullanıcının kendi `preferred_currency`'sinde bir tutar görebilmesi
     * bir yönetici yetkisi (`settings.manage`) olamaz; o izin yalnız kur veri kaynağını
     * DEĞİŞTİREBİLME (manuel giriş) yetkisidir, kuru OKUYABİLME değil. `index()`/`store()`
     * yukarıdaki yönetim ucu AYNEN kalır, buradan yetkisi gevşetilmez — bu tamamen ayrı,
     * ikinci bir ucun.
     *
     * Sözleşme (birebir — FE buna bağlanır):
     * ```json
     * {
     *   "base_currency": "TRY",
     *   "as_of": "2026-08-24",
     *   "is_stale": false,
     *   "days_stale": 0,
     *   "rates": [
     *     { "currency": "USD", "rate": "34.123400", "rate_date": "2026-08-24", "is_stale": false, "days_stale": 0 }
     *   ]
     * }
     * ```
     * - `rate`: 1 birim yabancı para kaç TRY, decimal STRING (asla float — QUOTE-FINANCIALS
     *   disiplini; JS tarafı yalnızca GÖSTERİM için çarpar, bu değeri asla API'ye geri yazmaz).
     * - TRY listede YOK (temel para birimi, rate=1 örtük — `supportedCurrencies()` zaten TRY
     *   içermez).
     * - Kuru hiç girilmemiş desteklenen bir para birimi SESSİZCE ATLANMAZ: satır `rate: null`,
     *   `rate_date: null` ile döner ki FE "bu para birimini çeviremiyorum" diyebilsin (§2.6
     *   "sessiz eski-kur hesabı Faz 9 KDV sınıfı hatadır" ilkesiyle aynı disiplin).
     * - `as_of`: dönen satırlar arasında kuru OLAN en eski `rate_date`; hiçbirinin kuru yoksa
     *   `null`. Üst düzey `is_stale`/`days_stale` bu en eski satırın bayatlığıdır (en kötü
     *   durum — bir para birimi bayatsa kullanıcı bunu genel bir uyarıdan da görebilmeli),
     *   `as_of === null` iken ikisi de `false`/`0`.
     */
    public function current(): JsonResponse
    {
        $rows = collect($this->exchange->supportedCurrencies())
            ->map(function (string $currency): array {
                $rate = $this->exchange->latest($currency);

                if ($rate === null) {
                    return [
                        'currency' => $currency,
                        'rate' => null,
                        'rate_date' => null,
                        'is_stale' => false,
                        'days_stale' => 0,
                    ];
                }

                return [
                    'currency' => $currency,
                    'rate' => (string) $rate->rate,
                    'rate_date' => $rate->rate_date->toDateString(),
                    'is_stale' => $this->exchange->isStale($rate),
                    'days_stale' => $this->exchange->daysStale($rate),
                ];
            })
            ->values();

        $oldest = $rows
            ->filter(fn (array $row) => $row['rate_date'] !== null)
            ->sortBy('rate_date')
            ->first();

        return response()->json([
            'base_currency' => $this->exchange->baseCurrency(),
            'as_of' => $oldest['rate_date'] ?? null,
            'is_stale' => $oldest['is_stale'] ?? false,
            'days_stale' => $oldest['days_stale'] ?? 0,
            'rates' => $rows,
        ]);
    }

    protected function authorizeSettings(): void
    {
        abort_unless(Gate::allows('settings.manage'), Response::HTTP_FORBIDDEN);
    }
}
