<?php

namespace App\Exports;

use App\Models\PageVisitLog;
use App\Support\CsvFormulaGuard;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * `GET /api/logs/export?type=page-visits&format=xlsx`.
 *
 * @implements FromQuery<PageVisitLog>
 */
class PageVisitLogsExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping
{
    /**
     * @param  Builder<PageVisitLog>  $query  Filtrelenmiş, sıralanmış (LogQueryService::export) sorgu.
     */
    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'id', 'user_id', 'user_name', 'route', 'path', 'title',
            'entered_at', 'last_heartbeat_at', 'duration_seconds',
            'ip_address', 'session_id', 'created_at',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        /** @var PageVisitLog $row */
        // Faz 13/H2 (F1): tek merkezî kapı — bkz. ActivityLogsExport::map()
        // ve CsvFormulaGuard docblock'u (XLSX'te PhpSpreadsheet kendiliğinden
        // korumaz).
        return CsvFormulaGuard::sanitizeRow([
            $row->id,
            $row->user_id,
            $row->user?->name,
            $row->route,
            $row->path,
            $row->title,
            $row->entered_at?->toIso8601String(),
            $row->last_heartbeat_at?->toIso8601String(),
            $row->duration_seconds,
            $row->ip_address,
            $row->session_id,
            $row->created_at?->toIso8601String(),
        ]);
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
