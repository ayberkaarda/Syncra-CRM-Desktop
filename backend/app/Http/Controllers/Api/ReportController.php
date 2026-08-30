<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reports\ConversionResource;
use App\Http\Resources\Reports\SalesPerformanceResource;
use App\Http\Resources\Reports\SourceAnalysisResource;
use App\Http\Resources\Reports\UserPerformanceResource;
use App\Services\Exchange\ExchangeRateService;
use App\Services\Reports\ConversionReport;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\SalesPerformanceReport;
use App\Services\Reports\SourceAnalysisReport;
use App\Services\Reports\Support\DateRangeResolver;
use App\Services\Reports\Support\ReportCurrencyContext;
use App\Services\Reports\UserPerformanceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * İnce controller: yetkilendirme (`reports.view` / `reports.export` — Gate,
 * bkz. LogController aynı desenin kaynağı) + sorgu parametresi ayrıştırma +
 * rapor servislerine devir. İş mantığı burada DEĞİL, app/Services/Reports
 * altındadır.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly SalesPerformanceReport $salesPerformance,
        private readonly UserPerformanceReport $userPerformance,
        private readonly SourceAnalysisReport $sourceAnalysis,
        private readonly ConversionReport $conversion,
        private readonly ReportExportService $exporter,
        private readonly DateRangeResolver $dateRange,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * Görüntü para birimi bağlamı — istek başına TEK kez, isteği yapan
     * kullanıcının `users.preferred_currency`'sinden (PHASE-INTL §2.4).
     * Gerekçe DashboardController::currencyContext() ile aynıdır.
     */
    private function currencyContext(Request $request): ReportCurrencyContext
    {
        return ReportCurrencyContext::make($this->rates, $request->user()?->preferred_currency);
    }

    public function salesPerformance(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('reports.view'), Response::HTTP_FORBIDDEN);

        $range = $this->dateRange->resolve($request->query('from'), $request->query('to'));
        $groupBy = $request->query('group_by');
        $userId = $this->parseUserId($request);

        $result = $this->salesPerformance->run(
            $range,
            is_string($groupBy) ? $groupBy : 'day',
            $userId,
            $this->currencyContext($request),
        );

        return $this->respond((new SalesPerformanceResource($result))->resolve(), $result['rate_info']);
    }

    public function userPerformance(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('reports.view'), Response::HTTP_FORBIDDEN);

        $range = $this->dateRange->resolve($request->query('from'), $request->query('to'));

        $result = $this->userPerformance->run($range, $this->currencyContext($request));

        return $this->respond((new UserPerformanceResource($result))->resolve(), $result['rate_info']);
    }

    public function sourceAnalysis(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('reports.view'), Response::HTTP_FORBIDDEN);

        $range = $this->dateRange->resolve($request->query('from'), $request->query('to'));

        $result = $this->sourceAnalysis->run($range, $this->currencyContext($request));

        return $this->respond((new SourceAnalysisResource($result))->resolve(), $result['rate_info']);
    }

    public function conversion(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('reports.view'), Response::HTTP_FORBIDDEN);

        $range = $this->dateRange->resolve($request->query('from'), $request->query('to'));

        return $this->respond((new ConversionResource($this->conversion->run($range)))->resolve());
    }

    public function export(Request $request): StreamedResponse|BinaryFileResponse
    {
        abort_unless(Gate::allows('reports.export'), Response::HTTP_FORBIDDEN);

        $slug = (string) $request->query('report');

        if (! in_array($slug, ReportExportService::SLUGS, true)) {
            throw ValidationException::withMessages([
                'report' => ['Geçersiz rapor türü. Kabul edilen değerler: '.implode(', ', ReportExportService::SLUGS).'.'],
            ]);
        }

        $format = (string) $request->query('format', 'csv');

        if (! in_array($format, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages([
                'format' => ['Geçersiz dışa aktarma biçimi. Kabul edilen değerler: csv, xlsx.'],
            ]);
        }

        $range = $this->dateRange->resolve($request->query('from'), $request->query('to'));
        $userId = $this->parseUserId($request);

        return $this->exporter->export($slug, $range, $userId, $format, $this->currencyContext($request));
    }

    private function parseUserId(Request $request): ?int
    {
        $raw = $request->query('user_id');

        if (! is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Tek çıkış kapısı: `{"data": ...}` zarfı + `JSON_PRESERVE_ZERO_FRACTION`.
     *
     * İKİ AYRI GEREKÇE:
     *   1. `JsonResource::response()`'un otomatik sarma (wrap) mantığı, iç
     *      dizinin KENDİSİ zaten bir `data` anahtarı taşıyorsa (ör.
     *      SalesPerformanceResource'un `{from, to, group_by, data, totals}`
     *      şekli) çakışmayı SESSİZCE atlar ve dış `data` zarfını hiç EKLEMEZ
     *      — API sözleşmesini kırar. Zarfı burada elle kurmak bu çakışmayı
     *      ortadan kaldırır.
     *   2. PHP'nin `json_encode()`'u varsayılan olarak `JSON_PRESERVE_
     *      ZERO_FRACTION` OLMADAN tam sayıya denk gelen float'ları (ör.
     *      `50.0`) ondalıksız (`50`) yazar — istemci tarafında JS için
     *      zararsızdır ama VERİ SÖZLEŞMESİ'ndeki `delta_pct: float | null`
     *      tipini tel üzerinde belirsizleştirir. Bayrak bunu önler.
     */
    private function respond(mixed $data, ?array $rateInfo = null): JsonResponse
    {
        $payload = ['data' => $data];

        // `rate_info` (Faz 14 / İz E): `data`'nın KARDEŞİ, içinde değil —
        // gerekçe ve tam alan sözleşmesi için bkz. App\Services\Reports\
        // Support\ReportCurrencyContext::rateInfo(). Para taşımayan
        // `/reports/conversion` ucunda eklenmez (yalnız lead sayıları).
        if ($rateInfo !== null) {
            $payload['rate_info'] = $rateInfo;
        }

        return new JsonResponse($payload, Response::HTTP_OK, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
