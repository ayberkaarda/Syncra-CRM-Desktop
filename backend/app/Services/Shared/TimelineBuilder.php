<?php

namespace App\Services\Shared;

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity as AuditLogEntry;

/**
 * Bir kişinin (Contact) veya firmanın (Company) tüm iletişim geçmişini
 * (activities, tasks, deals, tickets, attachments — ve firma için bağlı
 * kişilerin aktiviteleri) tek, zaman sıralı ve sayfalanabilir bir akışta
 * birleştirir.
 *
 * ---------------------------------------------------------------------------
 * TASARIM KARARI: neden ham SQL UNION değil, "her kaynaktan top-K" birleştirme
 * ---------------------------------------------------------------------------
 * Altı farklı tablodan (activities, tasks, deals x2 olay tipi, tickets,
 * attachments) gelen kayıtları tek bir tarihe göre sıralı sayfada birleştirmek
 * gerekiyor. Üç seçenek değerlendirildi:
 *
 *   (a) Her kaynaktan SINIRSIZ çekip PHP'de birleştirip sırala — büyük veri
 *       setinde tüm tabloyu belleğe çeker, kabul edilemez.
 *   (b) `activities` tablosunu birincil kaynak yapıp diğerlerini ayrı
 *       bölümlerde döndürmek — basit ama spesifikasyonun istediği "tek zaman
 *       sıralı akış" sözleşmesini bozar (frontend'in ayrı ayrı birleştirmesi
 *       gerekir) ve "sayfa 2" gibi bir kavram tüm kaynaklar için anlamsızlaşır.
 *   (c) SEÇİLEN YAKLAŞIM — "top-K per source" birleştirme: `page`/`per_page`
 *       verildiğinde `K = page * per_page` hesaplanır; HER kaynaktan (ayrı ayrı
 *       `occurred_at DESC, id DESC` sıralı ve `LIMIT K`) en yeni K kayıt çekilir,
 *       PHP'de tek listede birleştirilip yeniden sıralanır, son olarak
 *       `((page-1)*per_page, per_page)` aralığı dilimlenir.
 *
 * NEDEN DOĞRU: N sıralı listeden ilk K elemanı bulmak için her listeden yalnızca
 * kendi ilk K elemanını almak yeterli ve gereklidir (klasik "k-way merge" ilkesi)
 * — bir listenin K'ıncı sıradan sonraki bir öğesi, o listede ondan tarihçe daha
 * yeni K tane kayıt olduğu için global top-K'ya asla giremez. Yani bu yöntem
 * YAKLAŞIK değil, HER SAYFA için TAM DOĞRU sonuç üretir (`total` de ayrıca her
 * kaynaktan ucuz, indeksli `COUNT(*)` sorgularıyla tam olarak hesaplanır — hiçbir
 * kaynak sınırlanmadan sayılır).
 *
 * PERFORMANS SINIRI (dürüst olmak gerekirse): sorgu maliyeti `page * per_page`
 * ile DOĞRUSAL BÜYÜR — sayfa 1 ucuzken, sayfa 500 (per_page=25 ile K=12.500)
 * her kaynaktan 12.500 satır çekip PHP'de sıralamak zorunda kalır. Tipik bir
 * kişi/firma kartında bu geçmiş birkaç yüz kayıtla sınırlı olduğundan pratikte
 * sorun yaratmaz; ancak çok yoğun (binlerce etkileşimli) bir kayıtta çok derin
 * sayfalama (page > ~50) pahalılaşır. Üretimde bu asla çıkmazsa: (1) makul bir
 * `max page` sınırı koymak, ya da (2) tarih bazlı keyset/cursor sayfalamaya
 * geçmek (ör. `?before=<iso date>`) bu sınırı tamamen ortadan kaldırır — ama
 * o zaman "sayfa numarası" (current_page/last_page) sözleşmesi terk edilmesi
 * gerekir. Bu faz kapsamında sayfa numarası sözleşmesi (spec) istendiği için
 * doğruluk performansa tercih edildi.
 *
 * BAĞIMLILIK (dürüst olmak gerekirse — bkz. `dealStageChangeEntries()`): fırsat
 * "aşama değişimi" kalemi ayrı bir "deal_stage_history" tablosundan değil,
 * `LogsCrmActivity` trait'inin zaten yazdığı denetim izinden (`activity_log`,
 * `log_name=crm`) okunur. Bu, timeline'ı audit trail'in AÇIK olmasına bağımlı
 * kılar: `config('activitylog.enabled')` (env `ACTIVITY_LOGGER_ENABLED`)
 * `false` yapılırsa Deal'a yazılan `updated` olayları hiç oluşmaz ve aşama
 * değişimi kalemleri timeline'dan SESSİZCE kaybolur (hata fırlatmaz — o an
 * sadece "created" kalemi kalır). Kabul edilebilir bir bağımlılık, çünkü audit
 * trail üretimde her zaman açık kalması beklenen bir alt sistem; ama bu satır
 * olmadan biri logger'ı kapattığında timeline'ın neden eksik göründüğünü
 * anlaması zor olurdu.
 * ---------------------------------------------------------------------------
 */
class TimelineBuilder
{
    protected const MAX_PER_PAGE = 100;

    protected const DEFAULT_PER_PAGE = 25;

    /**
     * @param  array<string, mixed>  $filters  'page' ve 'per_page' anahtarları desteklenir.
     */
    public function build(Model $subject, array $filters = []): LengthAwarePaginator
    {
        if (! $subject instanceof Contact && ! $subject instanceof Company) {
            throw new \InvalidArgumentException('TimelineBuilder yalnızca Contact veya Company için çalışır.');
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $limit = $page * $perPage;

        $sources = [
            $this->activityEntries($subject, $limit),
            $this->taskEntries($subject, $limit),
            $this->ticketEntries($subject, $limit),
            $this->dealCreationEntries($subject, $limit),
            $this->dealStageChangeEntries($subject, $limit),
            $this->attachmentEntries($subject, $limit),
        ];

        if ($subject instanceof Company) {
            // Firma kartında tüm iletişim görünmeli: bağlı kişilerin aktiviteleri de dahil.
            $sources[] = $this->relatedContactActivityEntries($subject, $limit);
        }

        $merged = collect($sources)
            ->flatten(1)
            ->sort(function (array $a, array $b) {
                $cmp = $b['occurred_at']->getTimestamp() <=> $a['occurred_at']->getTimestamp();

                if ($cmp !== 0) {
                    return $cmp;
                }

                // Aynı anda oluşmuş kayıtlar için deterministik (kararlı) sıra.
                return strcmp($b['type'].'#'.$b['id'], $a['type'].'#'.$a['id']);
            })
            ->values();

        $items = $merged
            ->slice(($page - 1) * $perPage, $perPage)
            ->map(function (array $entry) {
                $entry['occurred_at'] = $entry['occurred_at']->toIso8601String();

                return $entry;
            })
            ->values()
            ->all();

        return new LengthAwarePaginator($items, $this->countAll($subject), $perPage, $page);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function activityEntries(Model $subject, int $limit): Collection
    {
        return Activity::query()
            ->where('activityable_type', $subject->getMorphClass())
            ->where('activityable_id', $subject->getKey())
            ->with('user')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $activity) => $this->entry(
                type: 'activity',
                id: $activity->id,
                title: $activity->subject,
                description: $activity->body,
                iconHint: $activity->type,
                occurredAt: $activity->occurred_at,
                user: $this->userRef($activity->user),
                meta: [
                    'activity_type' => $activity->type,
                    'duration_minutes' => $activity->duration_minutes,
                    'outcome' => $activity->outcome,
                ],
            ));
    }

    /**
     * Firmaya bağlı kişilerin aktiviteleri — firma kartında tüm iletişim
     * görünmeli. Kişi id'leri tek sorguda toplu çekilir (N+1 yok), aktiviteler
     * de tek `whereIn` ile toplu çekilir.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function relatedContactActivityEntries(Company $company, int $limit): Collection
    {
        $contactIds = Contact::query()->where('company_id', $company->getKey())->pluck('id');

        if ($contactIds->isEmpty()) {
            return collect();
        }

        return Activity::query()
            ->where('activityable_type', Contact::class)
            ->whereIn('activityable_id', $contactIds)
            ->with(['user', 'activityable'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $activity) => $this->entry(
                type: 'activity',
                id: $activity->id,
                title: $activity->subject,
                description: $activity->body,
                iconHint: $activity->type,
                occurredAt: $activity->occurred_at,
                user: $this->userRef($activity->user),
                meta: [
                    'activity_type' => $activity->type,
                    'duration_minutes' => $activity->duration_minutes,
                    'outcome' => $activity->outcome,
                    'via_contact' => $activity->activityable ? [
                        'id' => $activity->activityable->id,
                        'name' => $activity->activityable->full_name,
                    ] : null,
                ],
            ));
    }

    /**
     * `occurred_at` görev için `due_at ?? created_at`. Bunu DB tarafında ham
     * SQL/orderByRaw olmadan doğru sıralamak için iki ayrı, kendi içinde doğru
     * sıralı ve K ile sınırlı akış birleştirilir (due_at dolu olanlar due_at'e
     * göre, boş olanlar created_at'e göre) — k-way merge ilkesi burada da geçerli.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function taskEntries(Model $subject, int $limit): Collection
    {
        $base = fn () => Task::query()
            ->where('taskable_type', $subject->getMorphClass())
            ->where('taskable_id', $subject->getKey())
            ->with('assignee');

        $withDue = $base()->whereNotNull('due_at')->orderByDesc('due_at')->orderByDesc('id')->limit($limit)->get();
        $withoutDue = $base()->whereNull('due_at')->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        return $withDue->concat($withoutDue)->map(fn (Task $task) => $this->entry(
            type: 'task',
            id: $task->id,
            title: $task->title,
            description: $task->description,
            iconHint: 'task',
            occurredAt: $task->due_at ?? $task->created_at,
            user: $this->userRef($task->assignee),
            meta: [
                'status' => $task->status,
                'priority' => $task->priority,
            ],
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function ticketEntries(Model $subject, int $limit): Collection
    {
        return Ticket::query()
            ->where($this->foreignKeyFor($subject), $subject->getKey())
            ->with('assignee')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Ticket $ticket) => $this->entry(
                type: 'ticket',
                id: $ticket->id,
                title: $ticket->subject,
                description: $ticket->description,
                iconHint: 'ticket',
                occurredAt: $ticket->created_at,
                user: $this->userRef($ticket->assignee),
                meta: [
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'ticket_number' => $ticket->ticket_number,
                ],
            ));
    }

    /**
     * Fırsat "oluşturma" olayı.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function dealCreationEntries(Model $subject, int $limit): Collection
    {
        return Deal::query()
            ->where($this->foreignKeyFor($subject), $subject->getKey())
            ->with(['owner', 'pipelineStage'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Deal $deal) => $this->entry(
                type: 'deal',
                id: $deal->id,
                title: $deal->title,
                description: 'Fırsat oluşturuldu',
                iconHint: 'deal',
                occurredAt: $deal->created_at,
                user: $this->userRef($deal->owner),
                meta: [
                    'event' => 'created',
                    'amount' => $deal->amount,
                    'currency' => $deal->currency,
                    'status' => $deal->status,
                    'stage' => $deal->pipelineStage?->name,
                ],
            ));
    }

    /**
     * Fırsat "aşama değişimi" olayı — audit trail (`activity_log`, log_name=crm)
     * üzerinden, `pipeline_stage_id` alanı değişen `updated` olayları okunur.
     * Bu, LogsCrmActivity trait'inin zaten tuttuğu denetim izidir; ayrı bir
     * "deal_stage_history" tablosu icat edilmedi.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function dealStageChangeEntries(Model $subject, int $limit): Collection
    {
        $dealIds = Deal::query()->where($this->foreignKeyFor($subject), $subject->getKey())->pluck('id');

        if ($dealIds->isEmpty()) {
            return collect();
        }

        $stageNames = PipelineStage::query()->pluck('name', 'id');
        $dealTitles = Deal::query()->whereIn('id', $dealIds)->pluck('title', 'id');

        return AuditLogEntry::query()
            ->where('log_name', Deal::AUDIT_LOG_NAME)
            ->where('subject_type', Deal::class)
            ->whereIn('subject_id', $dealIds)
            ->where('event', 'updated')
            ->whereNotNull('properties->attributes->pipeline_stage_id')
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (AuditLogEntry $log) use ($stageNames, $dealTitles) {
                $newStageId = ($log->properties->get('attributes') ?? [])['pipeline_stage_id'] ?? null;
                $oldStageId = ($log->properties->get('old') ?? [])['pipeline_stage_id'] ?? null;
                $dealId = (int) $log->subject_id;

                return $this->entry(
                    type: 'deal',
                    id: $dealId,
                    title: $dealTitles->get($dealId, 'Fırsat'),
                    description: sprintf(
                        'Aşama değişti: %s → %s',
                        $oldStageId !== null ? ($stageNames->get($oldStageId) ?? '—') : '—',
                        $newStageId !== null ? ($stageNames->get($newStageId) ?? '—') : '—',
                    ),
                    iconHint: 'deal',
                    occurredAt: $log->created_at,
                    user: $this->userRef($log->causer instanceof User ? $log->causer : null),
                    meta: [
                        'event' => 'stage_changed',
                        'from_stage' => $oldStageId !== null ? $stageNames->get($oldStageId) : null,
                        'to_stage' => $newStageId !== null ? $stageNames->get($newStageId) : null,
                    ],
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function attachmentEntries(Model $subject, int $limit): Collection
    {
        return Attachment::query()
            ->where('attachable_type', $subject->getMorphClass())
            ->where('attachable_id', $subject->getKey())
            ->with('uploader')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Attachment $attachment) => $this->entry(
                type: 'attachment',
                id: $attachment->id,
                title: $attachment->original_name,
                description: null,
                iconHint: 'attachment',
                occurredAt: $attachment->created_at,
                user: $this->userRef($attachment->uploader),
                meta: [
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                ],
            ));
    }

    /**
     * Sınırsız (gerçek) toplam — her kaynak için ucuz, indeksli COUNT(*).
     * Sayfalama meta'sının `total`/`last_page` alanları burada tam doğrudur;
     * yalnızca satırların KENDİSİ (yukarıdaki top-K akışlar) sınırlanır.
     */
    protected function countAll(Model $subject): int
    {
        $foreignKey = $this->foreignKeyFor($subject);

        $count = Activity::query()
            ->where('activityable_type', $subject->getMorphClass())
            ->where('activityable_id', $subject->getKey())
            ->count();

        if ($subject instanceof Company) {
            $contactIds = Contact::query()->where('company_id', $subject->getKey())->pluck('id');

            if ($contactIds->isNotEmpty()) {
                $count += Activity::query()
                    ->where('activityable_type', Contact::class)
                    ->whereIn('activityable_id', $contactIds)
                    ->count();
            }
        }

        $count += Task::query()
            ->where('taskable_type', $subject->getMorphClass())
            ->where('taskable_id', $subject->getKey())
            ->count();

        $count += Ticket::query()->where($foreignKey, $subject->getKey())->count();

        $dealIds = Deal::query()->where($foreignKey, $subject->getKey())->pluck('id');
        $count += $dealIds->count();

        if ($dealIds->isNotEmpty()) {
            $count += AuditLogEntry::query()
                ->where('log_name', Deal::AUDIT_LOG_NAME)
                ->where('subject_type', Deal::class)
                ->whereIn('subject_id', $dealIds)
                ->where('event', 'updated')
                ->whereNotNull('properties->attributes->pipeline_stage_id')
                ->count();
        }

        $count += Attachment::query()
            ->where('attachable_type', $subject->getMorphClass())
            ->where('attachable_id', $subject->getKey())
            ->count();

        return $count;
    }

    protected function foreignKeyFor(Model $subject): string
    {
        return match (true) {
            $subject instanceof Contact => 'contact_id',
            $subject instanceof Company => 'company_id',
            default => throw new \InvalidArgumentException('TimelineBuilder yalnızca Contact veya Company destekler.'),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function userRef(?Model $user): ?array
    {
        if (! $user) {
            return null;
        }

        return ['id' => $user->id, 'name' => $user->name];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function entry(
        string $type,
        int $id,
        ?string $title,
        ?string $description,
        string $iconHint,
        Carbon|\DateTimeInterface $occurredAt,
        ?array $user,
        array $meta,
    ): array {
        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'icon_hint' => $iconHint,
            'occurred_at' => $occurredAt instanceof Carbon ? $occurredAt : Carbon::instance($occurredAt),
            'user' => $user,
            'meta' => $meta,
        ];
    }
}
