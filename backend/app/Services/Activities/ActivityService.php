<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\Ticket;
use App\Repositories\ActivityRepository;
use App\Services\Tickets\SlaService;
use App\Support\MorphTargets;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class ActivityService
{
    public function __construct(protected ActivityRepository $activities) {}

    /**
     * `GET /api/activities`.
     *
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->activities->paginate($filters, $perPage);
    }

    public function find(int $id): Activity
    {
        return $this->activities->findOrFail($id);
    }

    /**
     * `POST /api/activities`. `user_id` istemciden asla kabul edilmez —
     * StoreActivityRequest::rules() içinde bu alan hiç YOK, dolayısıyla
     * $data'da da yok; burada her zaman isteği yapan kullanıcı yazılır.
     *
     * ---------------------------------------------------------------------
     * SLA ENTEGRASYONU (Ticket şeridinin SlaService::recordFirstResponse())
     * ---------------------------------------------------------------------
     * `call`/`email`/`meeting` tipli bir aktivite bir Ticket'a bağlanırsa,
     * ticket'ın `first_response_at` metriği bu aktivite kaydıyla AYNI
     * transaction'da yazılır — aktivite kaydedilip ticket güncellenmezse
     * (ör. istek yarıda kesilirse) tutarsız bir durum kalmaması için.
     * Ayrıntılar için maybeRecordFirstResponse() dokümanına bakın.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId): Activity
    {
        $data['user_id'] = $userId;
        $data = $this->resolveActivityableType($data);

        return DB::transaction(function () use ($data) {
            $activity = $this->activities->create($data);
            $activity->load(['user', 'activityable']);

            $this->maybeRecordFirstResponse($activity);

            return $activity;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Activity $activity, array $data): Activity
    {
        $data = $this->resolveActivityableType($data);

        if (! empty($data)) {
            $this->activities->update($activity, $data);
        }

        $activity->load(['user', 'activityable']);

        return $activity;
    }

    public function delete(Activity $activity): void
    {
        $this->activities->delete($activity);
    }

    /**
     * `activityable_type` istekten KISA AD olarak gelir — bkz.
     * TaskService::resolveTaskableType() aynı gerekçe.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolveActivityableType(array $data): array
    {
        if (array_key_exists('activityable_type', $data) && $data['activityable_type'] !== null) {
            $data['activityable_type'] = MorphTargets::resolve($data['activityable_type']);
        }

        return $data;
    }

    /**
     * docs/SLA-DESIGN.md §2 — bir ticket'a ilk `call`/`email`/`meeting`
     * aktivitesi kaydedildiğinde `first_response_at` yazılır. `note`
     * SAYILMAZ (kapalı devre sistemde her not iç nottur, müşteriye yanıt
     * değildir).
     *
     * TİP KONTROLÜ: `$activity->activityable instanceof Ticket` ile yapılır
     * — `MorphTargets::shortName($activity->activityable_type) === 'ticket'`
     * GİBİ bir string karşılaştırması DEĞİL. Neden: string karşılaştırması
     * yalnızca "istemci 'ticket' dedi" bilgisini doğrular; `instanceof`
     * hem bunu hem de hedefin (relation zaten `activityable` olarak
     * yüklendiği için sorgu maliyeti olmadan) GERÇEKTEN var olan ve GERÇEKTEN
     * bir Ticket örneğine çözüldüğünü doğrular. Hedef silinmişse (soft
     * delete) veya activityable_type hiç set değilse MorphTo zaten `null`
     * döner, `null instanceof Ticket` false'tur — sessizce geçilir.
     *
     * HATA DAYANIKLILIĞI: SlaService çağrısı bu transaction'ın İÇİNDEDİR
     * (aktivite ile aynı atomik birim — bkz. create() dokümanı) ama
     * `try/catch` ile SARILIDIR: SlaService (başka bir şeridin kodu)
     * beklenmedik bir istisna fırlatırsa bile aktivite kaydı ASLA
     * başarısız OLMAMALI — aktivite birincil iş, SLA damgası yan etkidir
     * (bkz. App\Observers\ActivityLogObserver::created() aynı desen:
     * "the audit row is the product; the broadcast is a convenience").
     * `recordFirstResponse()` yalnızca bir öznitelik atar (sorgu çalıştırmaz),
     * bu yüzden `catch` bloğu transaction'ı bozmadan devam eder; asıl riskli
     * adım `$ticket->save()`'dir ve o da aynı try içindedir.
     *
     * GEREKSİZ save() YOK: SlaService zaten idempotent (`first_response_at`
     * doluysa hiçbir şey değiştirmez), ama biz de `isDirty()` ile GERÇEKTEN
     * bir değişiklik olup olmadığını kontrol edip yalnızca o zaman
     * kaydediyoruz — ikinci bir `call` aktivitesi ticket'ı gereksiz yere
     * UPDATE'lemez.
     */
    protected function maybeRecordFirstResponse(Activity $activity): void
    {
        if (! in_array($activity->type, ['call', 'email', 'meeting'], true)) {
            return;
        }

        $ticket = $activity->activityable;

        if (! $ticket instanceof Ticket) {
            return;
        }

        try {
            app(SlaService::class)->recordFirstResponse($ticket);

            if ($ticket->isDirty('first_response_at')) {
                $ticket->save();
            }
        } catch (Throwable $e) {
            // Aktivite kaydı birincil iş, SLA damgası yan etki — bkz. metot
            // dokümanı. Sessizce yutmuyoruz, raporluyoruz.
            report($e);
        }
    }
}
