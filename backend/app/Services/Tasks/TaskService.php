<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Repositories\TaskRepository;
use App\Support\MorphTargets;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

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
        $data = $this->resolveTaskableType($data);

        if (! empty($data)) {
            $this->tasks->update($task, $data);
        }

        $task->load(['assignee', 'creator', 'taskable']);

        return $task;
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
     */
    public function complete(Task $task, bool $completed): Task
    {
        if ($completed) {
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
