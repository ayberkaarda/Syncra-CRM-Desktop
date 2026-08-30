<?php

namespace App\Services\Reports;

use App\Models\Deal;
use App\Models\Lead;
use App\Services\Exchange\ExchangeRateService;
use App\Services\Reports\Support\DateRange;
use App\Services\Reports\Support\MoneyFormatter;
use App\Services\Reports\Support\ReportCurrencyContext;

/**
 * `GET /api/reports/source-analysis?from&to`
 *
 * `leads.source` başına: bu dönemde oluşturulan lead sayısı, bunlardan
 * (şu ana kadar) dönüştürülmüş olanların sayısı ve o dönüşümlerin
 * ürettiği kazanılmış deal geliri.
 *
 * Deal'ların kendi `source` kolonu YOK — kaynak bilgisi yalnızca
 * `leads.source`'ta yaşar. Gelir, `leads.converted_deal_id` üzerinden
 * `deals`'a JOIN edilerek hesaplanır (bkz. SORGU KURALLARI: join'de
 * `deals.deleted_at IS NULL` elle kontrol edilir — Eloquent'in soft-delete
 * global scope'u yalnızca sorgunun KENDİ modeline uygulanır, JOIN edilen
 * tabloya değil).
 */
class SourceAnalysisReport
{
    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * @return array{from: string, to: string, data: array<int, array<string, mixed>>, rate_info: array<string, mixed>}
     */
    public function run(DateRange $range, ?ReportCurrencyContext $currency = null): array
    {
        $currency ??= ReportCurrencyContext::make($this->rates);

        $leadCounts = Lead::query()
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('source, COUNT(*) as cnt')
            ->groupBy('source')
            ->pluck('cnt', 'source');

        $convertedCounts = Lead::query()
            ->whereBetween('created_at', [$range->from, $range->to])
            ->where('status', 'converted')
            ->selectRaw('source, COUNT(*) as cnt')
            ->groupBy('source')
            ->pluck('cnt', 'source');

        // `Lead::query()` üzerinden başlatıldığı için leads.deleted_at IS
        // NULL global scope'u otomatik uygulanır; deals tarafı JOIN edildiği
        // için elle eklenir (yukarıdaki docblock'a bkz).
        $revenueBySource = Lead::query()
            ->join('deals', 'leads.converted_deal_id', '=', 'deals.id')
            ->whereBetween('leads.created_at', [$range->from, $range->to])
            ->where('deals.status', 'won')
            ->whereNull('deals.deleted_at')
            // Kazanılmış fırsatın DONMUŞ TRY tutarı (`base_amount`) —
            // `amount` DEĞİL: kaynak analizi de tarihsel gelir raporudur ve
            // güncel kurla yeniden değerlenmemelidir (PHASE-INTL §2.4).
            ->selectRaw('leads.source as source, COALESCE(SUM(deals.base_amount), 0) as amt')
            ->groupBy('leads.source')
            ->pluck('amt', 'source');

        $currency->noteUnconvertedClosed(
            Deal::query()
                ->where('status', 'won')
                ->whereNull('base_amount')
                ->whereIn('id', Lead::query()
                    ->whereBetween('created_at', [$range->from, $range->to])
                    ->whereNotNull('converted_deal_id')
                    ->select('converted_deal_id'))
                ->count()
        );

        $sources = collect()
            ->merge($leadCounts->keys())
            ->merge($convertedCounts->keys())
            ->merge($revenueBySource->keys())
            ->unique()
            ->values();

        $data = $sources->map(function (string $source) use ($leadCounts, $convertedCounts, $revenueBySource, $currency) {
            $leadsCount = (int) ($leadCounts[$source] ?? 0);
            $convertedCount = (int) ($convertedCounts[$source] ?? 0);

            return [
                'source' => $source,
                'leads_count' => $leadsCount,
                'converted_count' => $convertedCount,
                'conversion_rate' => MoneyFormatter::ratio($convertedCount, $leadsCount),
                'revenue' => $currency->fromFrozenBase($revenueBySource[$source] ?? 0),
            ];
        })->sortByDesc('leads_count')->values()->all();

        return [
            'from' => $range->from->toDateString(),
            'to' => $range->to->toDateString(),
            'data' => $data,
            'rate_info' => $currency->rateInfo(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function exportHeadings(): array
    {
        return [
            'source' => 'Kaynak',
            'leads_count' => 'Lead Sayısı',
            'converted_count' => 'Dönüşen',
            'conversion_rate' => 'Dönüşüm %',
            'revenue' => 'Gelir',
        ];
    }
}
