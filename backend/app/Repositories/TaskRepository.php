<?php

namespace App\Repositories;

use App\Models\Task;
use App\Support\MorphTargets;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * `GET /api/tasks` + `GET /api/tasks/calendar` sorgu katmanı. Filtre/arama/
 * sıralama sözleşmesi Faz 6/7 (LeadRepository/DealRepository) ile aynı
 * ilkeleri izler: sıralama YALNIZCA beyaz listedeki sütunlarla, arama (`q`)
 * her zaman parantezli bir `where` grubunda, ham SQL YOK.
 */
class TaskRepository
{
    /**
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'title', 'due_at', 'priority', 'status', 'completed_at', 'created_at',
    ];

    /**
     * Görevlerde varsayılan sıralama alanı `created_at` DEĞİL `due_at`'tir:
     * bir görev listesinde "ne zaman oluşturuldu" değil "ne zaman vadesi
     * doluyor" anlamlı olan bilgidir. Yön ('-') proje genelindeki diğer
     * modüllerle (deals: -created_at, activities: -occurred_at) aynı
     * "en-önce-en-alakalı" kuralını izler — beyaz liste dışı/boş bir `sort`
     * sessizce buraya düşer.
     */
    protected const DEFAULT_SORT_COLUMN = 'due_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        // N+1 önleme: assignee/creator birer BelongsTo, tek sorguda toplu
        // yüklenir. taskable ise MorphTo — Laravel bunu ilişkideki kayıtların
        // taskable_type DEĞERİ BAŞINA (satır başına DEĞİL) tek bir sorguda
        // toplu yükler: sayfada 3 farklı hedef tipi (deal/contact/company)
        // varsa taskable için 3 ek sorgu çalışır, taskable_id sayısı kaç
        // olursa olsun bu 3 sabit kalır. Hedef tiplerinin KENDİ ilişkilerini
        // de (ör. deal->company) yüklemek gerekseydi morphWith() kullanılırdı;
        // burada taskable.label yalnızca hedefin kendi sütunlarını (title/
        // name/subject/...) okuduğu için buna gerek yok.
        $query = $this->baseQuery($filters)->with(['assignee', 'creator', 'taskable']);

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);
        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
    }

    /**
     * `GET /api/tasks/calendar` — sayfalama YOK, tüm `[from, to]` aralığı
     * (max 90 gün, CalendarTaskRequest'te doğrulanır) tek seferde döner.
     * `due_at` NULL olan görevler takvimde gösterilemeyeceği için hariç
     * tutulur.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Task>
     */
    public function calendar(array $filters): Collection
    {
        $query = Task::query()
            ->whereNotNull('due_at')
            ->whereDate('due_at', '>=', $filters['from'])
            ->whereDate('due_at', '<=', $filters['to'])
            ->with(['assignee', 'creator', 'taskable']);

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        return $query->orderBy('due_at')->get();
    }

    public function findOrFail(int $id): Task
    {
        return Task::with(['assignee', 'creator', 'taskable'])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Task
    {
        return Task::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Task $task, array $data): Task
    {
        $task->fill($data);
        $task->save();

        return $task;
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters): Builder
    {
        $query = Task::query();

        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama ŞART: yoksa OR diğer filtreleri deler ve
            // yetkisiz veri sızdırır (bkz. Faz 6/7 aynı desen).
            $query->where(function (Builder $query) use ($term) {
                $query->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (array_key_exists('assigned_to', $filters) && $filters['assigned_to'] !== null) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (array_key_exists('created_by', $filters) && $filters['created_by'] !== null) {
            $query->where('created_by', $filters['created_by']);
        }

        if (! empty($filters['taskable_type'])) {
            $fqcn = MorphTargets::resolve($filters['taskable_type']);

            if ($fqcn !== null) {
                $query->where('taskable_type', $fqcn);

                if (! empty($filters['taskable_id'])) {
                    $query->where('taskable_id', $filters['taskable_id']);
                }
            } else {
                // IndexTaskRequest zaten Rule::in ile beyaz liste dışı bir
                // değeri 422'e çevirir, buraya asla ulaşmamalı — savunma
                // amaçlı: hiçbir satırla eşleşmeyen, ham SQL gerektirmeyen
                // bir koşul (id birincil anahtar, hiçbir zaman null olamaz).
                $query->whereNull('id');
            }
        }

        if (! empty($filters['from'])) {
            $query->whereDate('due_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('due_at', '<=', $filters['to']);
        }

        if (! empty($filters['overdue'])) {
            // Task::scopeOverdue() ile TAM olarak aynı kural: due_at < now()
            // VE status completed/cancelled değil. Burada tekrar yazmak
            // yerine modeldeki scope'u kullanmak, iki yerde aynı iş kuralının
            // birbirinden sapmasını (ör. biri güncellenip diğeri unutulursa)
            // engeller.
            $query->overdue();
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
