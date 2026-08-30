<?php

namespace App\Services\Reports;

use App\Models\Lead;
use App\Services\Reports\Support\DateRange;
use App\Services\Reports\Support\MoneyFormatter;

/**
 * `GET /api/reports/conversion?from&to`
 *
 * Bu dönemde OLUŞTURULMUŞ lead'lerin (kohort) durum dağılımı ve dönüşüm
 * oranı. `converted_count`, dönemde oluşturulup dönüşümü PERİYODUN
 * DIŞINDA gerçekleşmiş olsa bile şu anki durumu `converted` olan
 * lead'leri sayar — kohort tanımı tutarlı kalsın diye dönüşüm ne zaman
 * olduğuna değil, lead ne zaman oluşturulduğuna bakılır.
 */
class ConversionReport
{
    /**
     * Kararlı sütun sırası için "bilinen" durumlar — ama bkz. aşağıdaki
     * döngü: bu liste bir BEYAZ LİSTE (whitelist/filtre) DEĞİL, yalnızca
     * hangi anahtarların ÖNCE ve dolgusuz (0) göründüğünü belirler.
     * `leads.status` üzerinde SQL tarafında ASLA filtre uygulanmaz — bir
     * lead burada YOKSA `total_leads`'ten sessizce düşer, ki bu tam olarak
     * kaçınılması gereken hatadır (bkz. gerçek demo veride keşfedilen
     * `status='lost'` — Lead modelinin `StoreLeadRequest::STATUSES`
     * doğrulama listesinde YOKTU ama `DemoDataSeeder`/başka bir akış
     * üzerinden veritabanında gerçekten var; ilk sürüm bu yüzden
     * total_leads'i 40'tan 35'e düşürüyordu).
     *
     * @var array<int, string>
     */
    private const KNOWN_STATUSES = ['new', 'contacted', 'qualified', 'unqualified', 'lost', 'converted'];

    /**
     * @return array{from: string, to: string, total_leads: int, converted_count: int, conversion_rate: float, avg_days_to_convert: float|null, by_status: array<string, int>}
     */
    public function run(DateRange $range): array
    {
        $statusRows = Lead::query()
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        // Önce bilinen durumlar (sabit sütun sırası + dolgusuz 0), SONRA
        // veritabanında bulunan ama listede olmayan HERHANGİ bir durum —
        // hiçbir satır sessizce atlanmaz.
        $byStatus = [];
        foreach (self::KNOWN_STATUSES as $status) {
            $byStatus[$status] = (int) ($statusRows[$status] ?? 0);
        }
        foreach ($statusRows as $status => $count) {
            if (! array_key_exists($status, $byStatus)) {
                $byStatus[$status] = (int) $count;
            }
        }

        $totalLeads = array_sum($byStatus);
        $convertedCount = $byStatus['converted'];

        $avgDaysRow = Lead::query()
            ->whereBetween('created_at', [$range->from, $range->to])
            ->where('status', 'converted')
            ->whereNotNull('converted_at')
            ->selectRaw('AVG(DATEDIFF(converted_at, created_at)) as avg_days')
            ->first();

        return [
            'from' => $range->from->toDateString(),
            'to' => $range->to->toDateString(),
            'total_leads' => $totalLeads,
            'converted_count' => $convertedCount,
            'conversion_rate' => MoneyFormatter::ratio($convertedCount, $totalLeads),
            'avg_days_to_convert' => $avgDaysRow?->avg_days !== null ? round((float) $avgDaysRow->avg_days, 1) : null,
            'by_status' => $byStatus,
        ];
    }

    /**
     * Export için tek satırlık düz gösterim — `by_status` sütunlara açılır.
     *
     * @param  array{total_leads: int, converted_count: int, conversion_rate: float, avg_days_to_convert: float|null, by_status: array<string, int>}  $result
     * @return array<string, mixed>
     */
    public static function flattenForExport(array $result): array
    {
        $row = [
            'total_leads' => $result['total_leads'],
            'converted_count' => $result['converted_count'],
            'conversion_rate' => $result['conversion_rate'],
            'avg_days_to_convert' => $result['avg_days_to_convert'],
        ];

        foreach ($result['by_status'] as $status => $count) {
            $row['status_'.$status] = $count;
        }

        return $row;
    }

    /**
     * @return array<string, string>
     */
    public static function exportHeadings(): array
    {
        $headings = [
            'total_leads' => 'Toplam Lead',
            'converted_count' => 'Dönüşen',
            'conversion_rate' => 'Dönüşüm %',
            'avg_days_to_convert' => 'Ort. Dönüşüm Süresi (gün)',
        ];

        foreach (self::KNOWN_STATUSES as $status) {
            $headings['status_'.$status] = 'Durum: '.$status;
        }

        return $headings;
    }
}
