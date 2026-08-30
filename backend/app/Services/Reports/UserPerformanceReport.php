<?php

namespace App\Services\Reports;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use App\Services\Exchange\ExchangeRateService;
use App\Services\Reports\Support\DateRange;
use App\Services\Reports\Support\MoneyFormatter;
use App\Services\Reports\Support\ReportCurrencyContext;

/**
 * `GET /api/reports/user-performance?from&to`
 *
 * Satış temsilcisi (deal owner) başına performans satırı. Bir kullanıcı bu
 * dönemde ne bir deal kapatmış ne açık bir deal başlatmış ne de bir
 * aktivite kaydetmişse satırda hiç görünmez — sıfırlarla dolu boş satırlar
 * raporu şişirmez.
 */
class UserPerformanceReport
{
    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * @return array{from: string, to: string, data: array<int, array<string, mixed>>, rate_info: array<string, mixed>}
     */
    public function run(DateRange $range, ?ReportCurrencyContext $currency = null): array
    {
        $currency ??= ReportCurrencyContext::make($this->rates);

        // KAPANMIŞ: donmuş TRY tutarı (`base_amount`) — dönüşüm YOK, kararlı.
        $closedByOwner = Deal::query()
            ->whereIn('status', ['won', 'lost'])
            ->whereBetween('closed_at', [$range->from, $range->to])
            ->whereNotNull('owner_id')
            ->selectRaw('owner_id, status, COUNT(*) as cnt, COALESCE(SUM(base_amount), 0) as amt')
            ->groupBy('owner_id', 'status')
            ->get();

        // AÇIK: sahip × para birimi kovası, TEK sorgu. Satır başına dönüşüm
        // YOK — kova sayısı sahip sayısı × (en fazla 4) para birimiyle
        // sınırlıdır ve dönüşüm PHP'de, kur önbelleğinden yapılır (N+1 yok).
        $openByOwner = Deal::query()
            ->where('status', 'open')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->whereNotNull('owner_id')
            ->selectRaw('owner_id, currency, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as amt')
            ->groupBy('owner_id', 'currency')
            ->get();

        $currency->noteUnconvertedClosed(
            Deal::query()
                ->where('status', 'won')
                ->whereBetween('closed_at', [$range->from, $range->to])
                ->whereNotNull('owner_id')
                ->whereNull('base_amount')
                ->count()
        );

        $activitiesByOwner = Activity::query()
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as cnt')
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id');

        $metrics = [];
        $ensure = function (int $ownerId) use (&$metrics): void {
            $metrics[$ownerId] ??= ['won_count' => 0, 'lost_count' => 0, 'revenue' => '0.00', 'open_deals_count' => 0, 'open_deals_value' => '0.00', 'activities_count' => 0];
        };

        foreach ($closedByOwner as $row) {
            $ownerId = (int) $row->owner_id;
            $ensure($ownerId);

            if ($row->status === 'won') {
                $metrics[$ownerId]['won_count'] = (int) $row->cnt;
                $metrics[$ownerId]['revenue'] = $currency->fromFrozenBase($row->amt);
            } else {
                $metrics[$ownerId]['lost_count'] = (int) $row->cnt;
            }
        }

        foreach ($openByOwner as $row) {
            $ownerId = (int) $row->owner_id;
            $ensure($ownerId);
            $metrics[$ownerId]['open_deals_count'] += (int) $row->cnt;
            $metrics[$ownerId]['open_deals_value'] = bcadd(
                $metrics[$ownerId]['open_deals_value'],
                $currency->convertOpen($row->amt, $row->currency),
                2
            );
        }

        foreach ($activitiesByOwner as $ownerId => $cnt) {
            $ownerId = (int) $ownerId;
            $ensure($ownerId);
            $metrics[$ownerId]['activities_count'] = (int) $cnt;
        }

        if ($metrics === []) {
            return [
                'from' => $range->from->toDateString(),
                'to' => $range->to->toDateString(),
                'data' => [],
                'rate_info' => $currency->rateInfo(),
            ];
        }

        $names = User::query()->whereIn('id', array_keys($metrics))->pluck('name', 'id');

        $data = [];
        foreach ($metrics as $ownerId => $m) {
            $data[] = [
                'user_id' => $ownerId,
                'user_name' => $names[$ownerId] ?? null,
                'revenue' => $m['revenue'],
                'won_count' => $m['won_count'],
                'lost_count' => $m['lost_count'],
                'conversion_rate' => MoneyFormatter::ratio($m['won_count'], $m['won_count'] + $m['lost_count']),
                'avg_deal_size' => MoneyFormatter::average($m['revenue'], $m['won_count']),
                'open_deals_count' => $m['open_deals_count'],
                'open_deals_value' => $m['open_deals_value'],
                'activities_count' => $m['activities_count'],
            ];
        }

        // En yüksek gelirden düşüğe — sıralamayı belirsiz bırakmak yerine
        // "kim en çok kazandırdı" sorusuna doğrudan cevap veren bir sıra.
        usort($data, fn ($a, $b) => bccomp($b['revenue'], $a['revenue'], 2));

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
            'user_id' => 'Kullanıcı ID',
            'user_name' => 'Kullanıcı',
            'revenue' => 'Gelir',
            'won_count' => 'Kazanılan',
            'lost_count' => 'Kaybedilen',
            'conversion_rate' => 'Dönüşüm %',
            'avg_deal_size' => 'Ort. Anlaşma Tutarı',
            'open_deals_count' => 'Açık Fırsat',
            'open_deals_value' => 'Açık Fırsat Tutarı',
            'activities_count' => 'Aktivite',
        ];
    }
}
