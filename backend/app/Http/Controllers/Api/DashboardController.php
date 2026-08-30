<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reports\FunnelStageResource;
use App\Http\Resources\Reports\KpiCollectionResource;
use App\Http\Resources\Reports\RecentActivityResource;
use App\Http\Resources\Reports\RevenueTrendPointResource;
use App\Http\Resources\Reports\TaskSummaryResource;
use App\Services\Exchange\ExchangeRateService;
use App\Services\Reports\DashboardService;
use App\Services\Reports\Support\DateRangeResolver;
use App\Services\Reports\Support\ReportCurrencyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (`dashboard.view` — Gate, model policy yok,
 * bkz. App\Http\Controllers\Api\LogController aynı desenin kaynağı) + basit
 * sorgu parametresi ayrıştırma + DashboardService devri. İş mantığı burada
 * DEĞİL, servis katmanındadır.
 *
 * Tarih doğrulaması (`from`/`to` biçimi, `from > to`, varsayılan son 30 gün)
 * her uçta `DateRangeResolver` üzerinden aynı şekilde yapılır; geçersiz
 * girdi `ValidationException` fırlatır ve bootstrap/app.php'deki ortak hata
 * zarfına (422 VALIDATION_ERROR) düşer.
 */
class DashboardController extends Controller
{
    private const DEFAULT_RECENT_ACTIVITIES_LIMIT = 10;

    private const MAX_RECENT_ACTIVITIES_LIMIT = 50;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly DateRangeResolver $dateRange,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * Görüntü para birimi bağlamı — İSTEK BAŞINA TEK KEZ çözülür
     * (PHASE-INTL §2.4). Kaynak: isteği yapan kullanıcının
     * `users.preferred_currency`'si; yoksa/geçersizse temel para birimi
     * (TRY). Sorgu parametresiyle EZİLEMEZ: para birimi bir kişisel
     * TERCİHTİR, URL'den gelen geçici bir görünüm ayarı değil — aksi hâlde
     * paylaşılan bir dashboard bağlantısı başkasının ekranında farklı
     * rakamlar gösterirdi.
     */
    private function currencyContext(Request $request): ReportCurrencyContext
    {
        return ReportCurrencyContext::make($this->rates, $request->user()?->preferred_currency);
    }

    public function kpis(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('dashboard.view'), Response::HTTP_FORBIDDEN);

        $range = $this->dateRange->resolve($request->query('from'), $request->query('to'));

        $currency = $this->currencyContext($request);

        // Sözleşmede `user_id` yok (yalnızca /reports/sales-performance'ta
        // var) — dashboard KPI'ları her zaman şirket geneli.
        $kpis = $this->dashboard->kpis($range, null, $currency);

        return $this->respond((new KpiCollectionResource($kpis))->resolve(), $currency);
    }

    public function funnel(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('dashboard.view'), Response::HTTP_FORBIDDEN);

        $range = $this->dateRange->resolve($request->query('from'), $request->query('to'));

        $currency = $this->currencyContext($request);
        $funnel = $this->dashboard->funnel($range, $currency);

        return $this->respond(FunnelStageResource::collection($funnel)->resolve(), $currency);
    }

    public function revenueTrend(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('dashboard.view'), Response::HTTP_FORBIDDEN);

        $range = $this->dateRange->resolve($request->query('from'), $request->query('to'));
        $groupBy = $request->query('group_by');

        $currency = $this->currencyContext($request);
        $trend = $this->dashboard->revenueTrend($range, is_string($groupBy) ? $groupBy : null, $currency);

        return $this->respond(RevenueTrendPointResource::collection($trend)->resolve(), $currency);
    }

    public function recentActivities(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('dashboard.view'), Response::HTTP_FORBIDDEN);

        $limit = $this->parseLimit($request->query('limit'));

        return $this->respond(RecentActivityResource::collection($this->dashboard->recentActivities($limit))->resolve());
    }

    public function taskSummary(): JsonResponse
    {
        abort_unless(Gate::allows('dashboard.view'), Response::HTTP_FORBIDDEN);

        return $this->respond((new TaskSummaryResource($this->dashboard->taskSummary()))->resolve());
    }

    private function parseLimit(mixed $raw): int
    {
        if (! is_numeric($raw)) {
            return self::DEFAULT_RECENT_ACTIVITIES_LIMIT;
        }

        $limit = (int) $raw;

        return max(1, min($limit, self::MAX_RECENT_ACTIVITIES_LIMIT));
    }

    /**
     * Tek çıkış kapısı — bkz. ReportController::respond() aynı iki gerekçe
     * (data-anahtarı çakışması + JSON_PRESERVE_ZERO_FRACTION).
     *
     * `rate_info` (Faz 14 / İz E) `data`'nın KARDEŞİ olarak eklenir, İÇİNE
     * değil: `data` her uçta farklı bir şekle sahip (dizi/nesne/koleksiyon)
     * ve kur dipnotu o şeklin parçası DEĞİL, yanıtın meta bilgisidir.
     * Kardeş anahtar aynı zamanda tamamen additive'dir — mevcut istemciler
     * `data`'yı okumaya aynen devam eder. Para taşımayan uçlarda
     * (`recent-activities`, `task-summary`) hiç eklenmez.
     * Alan sözleşmesi: ReportCurrencyContext::rateInfo() dokümanı.
     */
    private function respond(mixed $data, ?ReportCurrencyContext $currency = null): JsonResponse
    {
        $payload = ['data' => $data];

        if ($currency !== null) {
            $payload['rate_info'] = $currency->rateInfo();
        }

        return new JsonResponse($payload, Response::HTTP_OK, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
