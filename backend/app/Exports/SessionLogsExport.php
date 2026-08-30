<?php

namespace App\Exports;

use App\Models\SessionLog;
use App\Support\CsvFormulaGuard;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * `GET /api/logs/export?type=sessions&format=xlsx`.
 *
 * @implements FromQuery<SessionLog>
 */
class SessionLogsExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping
{
    /**
     * @param  Builder<SessionLog>  $query  Filtrelenmiş, sıralanmış (LogQueryService::export) sorgu.
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
            'id', 'user_id', 'user_name', 'email', 'event', 'ip_address',
            'device', 'browser', 'platform', 'session_id',
            'logged_in_at', 'logged_out_at', 'duration_seconds', 'created_at',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        /** @var SessionLog $row */
        // Faz 13/H2 (F1): tek merkezî kapı — bkz. ActivityLogsExport::map()
        // ve CsvFormulaGuard docblock'u (XLSX'te PhpSpreadsheet kendiliğinden
        // korumaz).
        return CsvFormulaGuard::sanitizeRow([
            $row->id,
            $row->user_id,
            $row->user?->name,
            $row->email,
            $row->event,
            $row->ip_address,
            $row->device,
            $row->browser,
            $row->platform,
            $row->session_id,
            $row->logged_in_at?->toIso8601String(),
            $row->logged_out_at?->toIso8601String(),
            $row->duration_seconds,
            $row->created_at?->toIso8601String(),
        ]);
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
