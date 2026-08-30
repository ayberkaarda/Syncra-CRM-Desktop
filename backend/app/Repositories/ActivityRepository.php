<?php

namespace App\Repositories;

use App\Models\Activity;
use App\Support\MorphTargets;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /api/activities` sorgu katmanı — TaskRepository ile aynı ilkeler
 * (beyaz liste sıralama, parantezli arama, ham SQL yok).
 */
class ActivityRepository
{
    /**
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'type', 'subject', 'occurred_at', 'duration_minutes', 'created_at',
    ];

    /**
     * Aktiviteler geçmiş etkileşim kayıtlarıdır — en yeni önce, log/deal
     * modülleriyle aynı `-occurred_at` konvansiyonu.
     */
    protected const DEFAULT_SORT_COLUMN = 'occurred_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        // N+1 önleme: user BelongsTo (tek sorgu), activityable MorphTo
        // (sayfadaki DİSTİNCT activityable_type sayısı kadar ek sorgu —
        // satır sayısından bağımsız, bkz. TaskRepository::paginate() aynı
        // dokümanı).
        $query = $this->baseQuery($filters)->with(['user', 'activityable']);

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);
        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
    }

    public function findOrFail(int $id): Activity
    {
        return Activity::with(['user', 'activityable'])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Activity
    {
        return Activity::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Activity $activity, array $data): Activity
    {
        $activity->fill($data);
        $activity->save();

        return $activity;
    }

    public function delete(Activity $activity): void
    {
        $activity->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters): Builder
    {
        $query = Activity::query();

        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama ŞART — bkz. TaskRepository aynı dokümanı.
            $query->where(function (Builder $query) use ($term) {
                $query->where('subject', 'like', "%{$term}%")
                    ->orWhere('body', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (array_key_exists('user_id', $filters) && $filters['user_id'] !== null) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['activityable_type'])) {
            $fqcn = MorphTargets::resolve($filters['activityable_type']);

            if ($fqcn !== null) {
                $query->where('activityable_type', $fqcn);

                if (! empty($filters['activityable_id'])) {
                    $query->where('activityable_id', $filters['activityable_id']);
                }
            } else {
                // Savunma amaçlı — bkz. TaskRepository aynı dal.
                $query->whereNull('id');
            }
        }

        if (! empty($filters['from'])) {
            $query->whereDate('occurred_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('occurred_at', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolveSort(?string $sort): array
    {
        if (empty($sort)) {
            return [self::DEFAULT_SORT_COLUMN, self::DEFAULT_SORT_DIRECTION];
        }

        $direction = 'asc';
        $column = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $column = substr($sort, 1);
        }

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return [self::DEFAULT_SORT_COLUMN, self::DEFAULT_SORT_DIRECTION];
        }

        return [$column, $direction];
    }
}
