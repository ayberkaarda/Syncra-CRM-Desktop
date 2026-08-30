<?php

namespace App\Services\Logging;

use App\Exports\ActivityLogsExport;
use App\Exports\PageVisitLogsExport;
use App\Exports\SessionLogsExport;
use App\Repositories\LogRepository;
use App\Support\CsvFormulaGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Log listeleme + dışa aktarma orkestrasyonu. Sorgu inşası LogRepository'ye
 * devredilir; burada yalnızca sayfalama, sıralama kolonu seçimi ve dışa
 * aktarma akışı (satır sınırı, CSV akıtma, XLSX) yer alır.
 */
class LogQueryService
{
    /**
     * Sınırsız export sunucuyu kilitler — makul bir tavan.
     */
    public const EXPORT_ROW_LIMIT = 50000;

    public const DEFAULT_PER_PAGE = 25;

    public function __construct(protected LogRepository $logs) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function sessions(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->logs->sessionsQuery($filters);
        $this->logs->applySort($query, $filters['sort'] ?? null, LogRepository::SESSION_SORTABLE, LogRepository::SESSION_DEFAULT_SORT);

        return $query->paginate($perPage ?? self::DEFAULT_PER_PAGE);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function pageVisits(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->logs->pageVisitsQuery($filters);
        $this->logs->applySort($query, $filters['sort'] ?? null, LogRepository::PAGE_VISIT_SORTABLE, LogRepository::PAGE_VISIT_DEFAULT_SORT);

        return $query->paginate($perPage ?? self::DEFAULT_PER_PAGE);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function activities(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->logs->activitiesQuery($filters);
        $this->logs->applySort($query, $filters['sort'] ?? null, LogRepository::ACTIVITY_SORTABLE, LogRepository::ACTIVITY_DEFAULT_SORT);

        return $query->paginate($perPage ?? self::DEFAULT_PER_PAGE);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function export(string $type, array $filters, string $format = 'csv'): StreamedResponse|BinaryFileResponse
    {
        $query = match ($type) {
            'sessions' => $this->logs->sessionsQuery($filters),
            'page-visits' => $this->logs->pageVisitsQuery($filters),
            'activities' => $this->logs->activitiesQuery($filters),
            default => throw ValidationException::withMessages([
                'type' => ['Geçersiz log türü.'],
            ]),
        };

        // Export akışı chunkById ile ilerler; bu yüzden sıralama daima birincil
        // anahtara göre (artan) sabitlenir — listeleme ekranındaki `sort`
        // parametresi export'a taşınmaz.
        $query->orderBy($query->getModel()->getKeyName());

        $total = (clone $query)->count();

        if ($total > self::EXPORT_ROW_LIMIT) {
            throw ValidationException::withMessages([
                'filter' => [
                    "Dışa aktarılacak kayıt sayısı ({$total}) izin verilen üst sınırı (".self::EXPORT_ROW_LIMIT.') aşıyor. Lütfen tarih aralığını daraltın.',
                ],
            ]);
        }

        $filename = $this->filename($type, $format);

        return $format === 'xlsx'
            ? $this->exportXlsx($type, $query, $filename)
            : $this->exportCsv($type, $query, $filename);
    }

    protected function filename(string $type, string $format): string
    {
        return sprintf('syncra-%s-%s.%s', $type, now()->format('Y-m-d'), $format);
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function exportCsv(string $type, Builder $query, string $filename): StreamedResponse
    {
        [$headings, $mapper] = $this->csvShape($type);

        return Response::streamDownload(function () use ($query, $headings, $mapper) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM: yoksa Excel Türkçe karakterleri (ı, ş, ğ, ç, ö, ü) bozar.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headings);

            $query->chunkById(500, function ($rows) use ($handle, $mapper) {
                foreach ($rows as $row) {
                    // Faz 13/H2 (F1): tek merkezî kapı — hangi log tipi/mapper
                    // olursa olsun, hücreler fputcsv'ye gitmeden HEMEN önce
                    // burada nötrlenir (bkz. CsvFormulaGuard docblock'u).
                    fputcsv($handle, CsvFormulaGuard::sanitizeRow($mapper($row)));
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function exportXlsx(string $type, Builder $query, string $filename): BinaryFileResponse
    {
        $export = match ($type) {
            'sessions' => new SessionLogsExport($query),
            'page-visits' => new PageVisitLogsExport($query),
            'activities' => new ActivityLogsExport($query),
        };

        return Excel::download($export, $filename);
    }

    /**
     * @return array{0: array<int, string>, 1: callable}
     */
    protected function csvShape(string $type): array
    {
        return match ($type) {
            'sessions' => [
                ['id', 'user_id', 'user_name', 'email', 'event', 'ip_address', 'device', 'browser', 'platform', 'session_id', 'logged_in_at', 'logged_out_at', 'duration_seconds', 'created_at'],
                fn ($row) => [
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
                ],
            ],
            'page-visits' => [
                ['id', 'user_id', 'user_name', 'route', 'path', 'title', 'entered_at', 'last_heartbeat_at', 'duration_seconds', 'ip_address', 'session_id', 'created_at'],
                fn ($row) => [
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
                ],
            ],
            'activities' => [
                ['id', 'log_name', 'event', 'description', 'subject_type', 'subject_id', 'causer_id', 'causer_name', 'created_at'],
                fn ($row) => [
                    $row->id,
                    $row->log_name,
                    $row->event,
                    $row->description,
                    LogRepository::shortNameForSubjectType($row->subject_type) ?? $row->subject_type,
                    $row->subject_id,
                    $row->causer_id,
                    $row->causer?->name,
                    $row->created_at?->toIso8601String(),
                ],
            ],
            default => throw ValidationException::withMessages([
                'type' => ['Geçersiz log türü.'],
            ]),
        };
    }
}
