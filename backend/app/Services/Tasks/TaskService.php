<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Repositories\TaskRepository;
use App\Support\MorphTargets;
use App\Sync\SyncCounter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TaskService
{
    public function __construct(protected TaskRepository $tasks) {}

    /**
     * `GET /api/tasks`.
     *
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->tasks->paginate($filters, $perPage);
    }

    /**
     * `GET /api/tasks/calendar`.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Task>
     */
    public function calendar(array $filters): Collection
    {
        return $this->tasks->calendar($filters);
    }

    public function find(int $id): Task
    {
        return $this->tasks->findOrFail($id);
    }

    /**
     * `POST /api/tasks`. `created_by` istemciden KABUL EDİLMEZ — her zaman
     * isteği yapan kullanıcı (StoreTaskRequest::rules() içinde yok, bu yüzden
     * validated() içinde de yok; burada açıkça ekleniyor).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $creatorId): Task
    {
        $data['created_by'] = $creatorId;
        $data['priority'] = $data['priority'] ?? 'normal';
        $data['status'] = $data['status'] ?? 'pending';
        $data = $this->resolveTaskableType($data);

        $task = $this->tasks->create($data);
        $task->load(['assignee', 'creator', 'taskable']);

        return $task;
    }

    /**
     * `PATCH /api/tasks/{task}`. `completed_at` buraya HİÇ ulaşmaz —
     * UpdateTaskRequest bunu `missing` kuralıyla 422'e çevirir (yönetimi
     * yalnızca `/complete` ucunda, bkz. self::complete()).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Task $task, array $data): Task
    {
        // İptal edilmiş görev bu uçtan da TAMAMLANAMAZ: CompleteTaskRequest
        // `/complete`'te reddettiği geçişi, `status` alanını kabul eden genel
        // PATCH sessizce yapabiliyordu. Kural burada — FormRequest'te değil —
        // çünkü senkron uygulayıcı UpdateTaskRequest'i route'a bağlı görev
        // olmadan çalıştırır; tek ortak nokta bu servistir. Yeniden açmak
        // (`cancelled` → `pending`/`in_progress`) açık bir geçiştir ve serbesttir.
        //
        // Durum KİLİTLİ SATIRDAN okunur: `$task` transaction dışında okunmuş
        // kopyadır; arada başka bir istek görevi iptal edip commit ederse eski
        // kopyadaki `pending` kontrolü geçer ve `completed` iptalin üzerine
        // yazılırdı. Sıra her sürüm çeken yazarla aynı: önce sayaç, sonra satır.
        return DB::transaction(function () use ($task, $data) {
            $task = $this->lockTask($task);

            if (($data['status'] ?? null) === 'completed' && $task->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => 'İptal edilmiş bir görev tamamlanamaz.',
                ]);
            }

            $data = $this->resolveTaskableType($data);

            if (! empty($data)) {
                $this->tasks->update($task, $data);
            }

            $task->load(['assignee', 'creator', 'taskable']);

            return $task;
        });
    }

    /**
     * Satırı transaction içinde sayaç kilidinden SONRA kilitleyip taze haliyle
     * döner. Çağıran bir DB::transaction içinde olmalıdır.
     */
    protected function lockTask(Task $task): Task
    {
        SyncCounter::lock();

        /** @var Task|null $locked */
        $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw new NotFoundHttpException;
        }

        return $locked;
    }

    public function delete(Task $task): void
    {
        $this->tasks->delete($task);
    }

    public function assign(Task $task, int $assignedTo): Task
    {
        $this->tasks->update($task, ['assigned_to' => $assignedTo]);
        $task->load(['assignee', 'creator', 'taskable']);

        return $task;
    }

    /**
     * `PATCH /api/tasks/{task}/complete` — idempotent.
     *
     * `completed=true`  -> status='completed', completed_at=now() (yalnızca
     *                      henüz 'completed' DEĞİLSE — zaten tamamlanmış bir
     *                      görevde tekrar çağrı `completed_at`'i YENİDEN
     *                      şimdiki zamana ÇEKMEZ, aksi halde her tekrar çağrı
     *                      farklı bir sonuç üretir ki bu idempotent OLMAZ).
     * `completed=false` -> status='pending', completed_at=null (yalnızca
     *                      zaten bu durumda DEĞİLSE).
     *
     * `cancelled` bir görevin tamamlanamayacağı kuralı BURADA DEĞİL,
     * CompleteTaskRequest::withValidator() içinde 422 olarak uygulanır —
     * bu, "geçersiz durum geçişi" bir veri doğrulama sorunudur (422), bir
     * yetki reddi (403) değildir; proje genelinde bu ayrım DealPolicy'nin
     * (403, yetki) `move`/`delete` kısıtlarından BİLİNÇLİ olarak farklı
     * tutuluyor çünkü buradaki kısıtlama "kim" değil "hangi durumda" sorusu.
     * Aynı kural, eşzamanlı bir iptale karşı kilitli satır üzerinde burada da
     * (aynı 422 biçimiyle) yeniden doğrulanır.
     */
    public function complete(Task $task, bool $completed): Task
    {
        return DB::transaction(function () use ($task, $completed) {
            // CompleteTaskRequest'in kontrolü route binding'in eski kopyasına
            // bakar; arada gelen bir iptal ancak kilitli satırda görülür.
            $task = $this->lockTask($task);

            if ($completed) {
                if ($task->status === 'cancelled') {
                    throw ValidationException::withMessages([
                        'completed' => 'İptal edilmiş bir görev tamamlanamaz.',
                    ]);
                }

                if ($task->status !== 'completed') {
                    $this->tasks->update($task, [
                        'status' => 'completed',
                        'completed_at' => now(),
                    ]);
                }
            } else {
                if ($task->status !== 'pending' || $task->completed_at !== null) {
                    $this->tasks->update($task, [
                        'status' => 'pending',
                        'completed_at' => null,
                    ]);
                }
            }

            $task->load(['assignee', 'creator', 'taskable']);

            return $task;
        });
    }

    /**
     * `taskable_type` istekten KISA AD olarak gelir (ör. 'deal') —
     * StoreTaskRequest/UpdateTaskRequest bunu yalnızca MorphTargets
     * beyaz listesine karşı doğrular, FQCN'e ÇEVİRMEZ (bu, bir Form
     * Request'in işi değil). Veritabanına yazmadan HEMEN önce burada
     * çözülür: `taskable_type` sütununda KISA AD değil GERÇEK sınıf adı
     * (App\Models\Deal) durmalı, aksi halde Task::taskable() MorphTo
     * ilişkisi "deal" diye bir sınıf arayıp patlar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolveTaskableType(array $data): array
    {
        if (array_key_exists('taskable_type', $data) && $data['taskable_type'] !== null) {
            $data['taskable_type'] = MorphTargets::resolve($data['taskable_type']);
        }

        return $data;
    }
}
