<?php

namespace App\Repositories;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PageVisitLog;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SessionLog;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as ActivityLog;

/**
 * Üç log kaynağı (session_logs, page_visit_logs, activity_log) için ortak
 * filtre/arama/sıralama sözleşmesini uygular.
 *
 * Sıralama YALNIZCA beyaz listedeki sütunlarla yapılır — kullanıcı girdisi
 * asla doğrudan orderBy'a verilmez. Arama (q) her zaman parantezli bir where
 * grubunda çalışır, aksi halde OR diğer filtreleri delip geçer.
 *
 * `filter[subject_type]` için de aynı prensip geçerli: kullanıcı girdisi
 * (kısa ad, ör. "deal") asla doğrudan sınıf adına çevrilmez — yalnızca bu
 * beyaz listede (SUBJECT_TYPE_MAP) tanımlı bir eşleme üzerinden çözülür.
 * IndexLogRequest/ExportLogRequest zaten Rule::in(array_keys(...)) ile bunu
 * doğrular; burada tekrar array_key_exists kontrolü savunma amaçlı tutulur.
 */
class LogRepository
{
    /**
     * Kısa ad -> tam sınıf adı beyaz listesi. `filter[subject_type]` ve
     * ActivityLogResource'un `subject_type` alanı bu eşlemeyi (iki yönlü)
     * kullanır.
     *
     * @var array<string, class-string>
     */
    public const SUBJECT_TYPE_MAP = [
        'lead' => Lead::class,
        'contact' => Contact::class,
        'company' => Company::class,
        'deal' => Deal::class,
        'task' => Task::class,
        'activity' => Activity::class,
        'ticket' => Ticket::class,
        'quote' => Quote::class,
        'product' => Product::class,
        'user' => User::class,
    ];

    /**
     * @var array<int, string>
     */
    public const SESSION_SORTABLE = [
        'event', 'ip_address', 'logged_in_at', 'logged_out_at', 'duration_seconds', 'created_at',
    ];

    public const SESSION_DEFAULT_SORT = 'created_at';

    /**
     * @var array<int, string>
     */
    public const PAGE_VISIT_SORTABLE = [
        'route', 'path', 'entered_at', 'last_heartbeat_at', 'duration_seconds', 'created_at',
    ];

    public const PAGE_VISIT_DEFAULT_SORT = 'created_at';

    /**
     * @var array<int, string>
     */
    public const ACTIVITY_SORTABLE = [
        'log_name', 'event', 'subject_type', 'subject_id', 'created_at',
    ];

    public const ACTIVITY_DEFAULT_SORT = 'created_at';

    /**
     * Kısa ad -> tam sınıf adı. Beyaz listede yoksa null döner.
     */
    public static function resolveSubjectType(?string $shortName): ?string
    {
        if (empty($shortName)) {
            return null;
        }

        return self::SUBJECT_TYPE_MAP[$shortName] ?? null;
    }

    /**
     * Tam sınıf adı -> kısa ad. Beyaz listede yoksa null döner (ham sınıf adı
     * asla dışarı sızdırılmaz).
     */
    public static function shortNameForSubjectType(?string $fqcn): ?string
    {
        if (empty($fqcn)) {
            return null;
        }

        $flipped = array_flip(self::SUBJECT_TYPE_MAP);

        return $flipped[$fqcn] ?? null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<SessionLog>
     */
    public function sessionsQuery(array $filters): Builder
    {
        $query = SessionLog::query()->with('user');

        $this->applySearch($query, $filters['q'] ?? null, ['email', 'ip_address', 'device', 'browser', 'platform']);
        $this->applyDateRange($query, $filters);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['ip'])) {
            $query->where('ip_address', $filters['ip']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PageVisitLog>
     */
    public function pageVisitsQuery(array $filters): Builder
    {
        $query = PageVisitLog::query()->with('user');

        $this->applySearch($query, $filters['q'] ?? null, ['route', 'path', 'title']);
        $this->applyDateRange($query, $filters);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['route'])) {
            $query->where('route', $filters['route']);
        }

        if (! empty($filters['path'])) {
            $query->where('path', $filters['path']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ActivityLog>
     */
    public function activitiesQuery(array $filters): Builder
    {
        $query = ActivityLog::query()->with(['causer', 'subject']);

        $this->applySearch($query, $filters['q'] ?? null, ['description', 'log_name']);
        $this->applyDateRange($query, $filters);

        if (! empty($filters['user_id'])) {
            // Log kaydını tetikleyen (causer) kullanıcı. Diğer causer tipleri
            // (ör. konsol komutları) bu filtreyle kasıtlı olarak elenir.
            $query->where('causer_id', $filters['user_id'])
                ->where('causer_type', User::class);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['log_name'])) {
            $query->where('log_name', $filters['log_name']);
        }

        if (! empty($filters['subject_type'])) {
            $fqcn = self::resolveSubjectType($filters['subject_type']);

            if ($fqcn !== null) {
                $query->where('subject_type', $fqcn);
            } else {
                // Beyaz listede olmayan bir değer buraya asla ulaşmamalı
                // (Form Request zaten reddeder); yine de güvenli tarafta
                // kalmak için sonucu boşalt. Ham SQL yasak olduğu için
                // imkansız bir Eloquent koşulu kullanılır (id hiçbir zaman
                // negatif olamaz).
                $query->where('id', '<', 0);
            }
        }

        return $query;
    }

    /**
     * Beyaz liste üzerinden sıralama uygular. Listede olmayan bir sütun
     * gelirse hata vermez, varsayılana düşer.
     *
     * @param  array<int, string>  $whitelist
     */
    public function applySort(Builder $query, ?string $sort, array $whitelist, string $default): Builder
    {
        [$column, $direction] = $this->resolveSort($sort, $whitelist, $default);

        return $query->orderBy($column, $direction);
    }

    /**
     * @param  array<int, string>  $whitelist
     * @return array{0: string, 1: string}
     */
    protected function resolveSort(?string $sort, array $whitelist, string $default): array
    {
        if (empty($sort)) {
            return [$default, 'desc'];
        }

        $direction = 'asc';
        $column = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $column = substr($sort, 1);
        }

        if (! in_array($column, $whitelist, true)) {
            return [$default, 'desc'];
        }

        return [$column, $direction];
    }

    /**
     * Serbest metin araması — daima parantezli bir where grubunda, aksi
     * halde OR diğer filtreleri delip geçer ve yetkisiz veri sızdırabilir.
     *
     * @param  array<int, string>  $columns
     */
    protected function applySearch(Builder $query, ?string $term, array $columns): void
    {
        if (empty($term)) {
            return;
        }

        $query->where(function (Builder $query) use ($term, $columns) {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $query->where($column, 'like', "%{$term}%");
                } else {
                    $query->orWhere($column, 'like', "%{$term}%");
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyDateRange(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }
    }
}
