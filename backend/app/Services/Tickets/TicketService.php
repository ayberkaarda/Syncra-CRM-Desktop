<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Repositories\TicketRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Ticket iş mantığı. Controller ince kalır; SLA hesabı SlaService'e, durum
 * geçişleri TicketStatusMachine'e delegedir.
 */
class TicketService
{
    /**
     * Detay/güncelleme yanıtlarında yüklenen ilişkiler — TicketResource
     * `relationLoaded()` kontrolü yaptığı için eksik yüklenen bir ilişki
     * sessizce `null` döner; bu yüzden yazma uçlarından sonra kayıt TEK
     * yerden, aynı setle tazelenir.
     *
     * @var array<int, string>
     */
    protected const DETAIL_RELATIONS = ['contact', 'company', 'assignee', 'creator', 'tags', 'customFieldValues.customField'];

    public function __construct(
        protected TicketRepository $tickets,
        protected SlaService $sla,
        protected TicketStatusMachine $statusMachine,
    ) {}

    /**
     * `GET /api/tickets`.
     *
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->tickets->paginate($filters, $perPage);
    }

    /**
     * `GET /api/tickets/stats` — filtrelerden bağımsız genel özet.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->tickets->stats();
    }

    public function find(int $id): Ticket
    {
        return $this->tickets->findOrFail($id);
    }

    /**
     * `POST /api/tickets`.
     *
     * İSTEMCİDEN KABUL EDİLMEYENLER: `ticket_number` (sunucu üretir),
     * `status` (her ticket `open` doğar), `created_by` (her zaman isteği
     * yapan kullanıcı) ve TÜM SLA alanları — hiçbiri StoreTicketRequest'te
     * tanımlı değildir, bu yüzden `validated()` içinde de yoktur.
     *
     * `sla_due_at` §5.1'e göre BURADA hesaplanıp sabitlenir; ayarlar
     * sonradan değişse bile bu ticket'ın hedefi değişmez (kabul kriteri 2).
     *
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function create(array $data, int $creatorId): Ticket
    {
        return DB::transaction(function () use ($data, $creatorId) {
            $tagIds = $data['tag_ids'] ?? [];
            $customFields = $data['custom_fields'] ?? [];
            unset($data['tag_ids'], $data['custom_fields']);

            // Tek bir "şimdi": `created_at` ve `sla_due_at` aynı ana
            // dayanmalı, yoksa invariant saniye mertebesinde kayar.
            $now = now();

            $data['priority'] = $data['priority'] ?? 'normal';
            $data['status'] = 'open';
            $data['created_by'] = $creatorId;
            $data['ticket_number'] = $this->tickets->nextTicketNumber();
            $data['created_at'] = $now;
            $data['sla_due_at'] = $this->sla->initialDueAt($now, $data['priority']);
            $data['sla_paused_at'] = null;
            $data['sla_paused_seconds'] = 0;

            $ticket = $this->tickets->create($data);

            if (! empty($tagIds)) {
                $this->tickets->syncTags($ticket, $tagIds);
            }

            if (! empty($customFields)) {
                $this->tickets->syncCustomFieldValues($ticket, $customFields);
            }

            return $this->tickets->findOrFail((int) $ticket->id);
        });
    }

    /**
     * `PATCH /api/tickets/{ticket}`.
     *
     * `status` ve tüm SLA alanları buraya HİÇ ULAŞMAZ — UpdateTicketRequest
     * onları `missing` kuralıyla 422'e çevirir (§4). Durum yalnızca
     * `PATCH /api/tickets/{ticket}/status` ucundan değişir.
     *
     * ÖNCELİK DEĞİŞİMİ tek istisnadır: `priority` bu uçtan değiştirilebilir
     * ve §5.2 gereği `sla_due_at` YENİDEN HESAPLANIR. Yükseltme ticket'ı
     * anında ihlale düşürebilir; bu istenen davranıştır.
     *
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function update(Ticket $ticket, array $data): Ticket
    {
        return DB::transaction(function () use ($ticket, $data) {
            $hasTagIds = array_key_exists('tag_ids', $data);
            $tagIds = $data['tag_ids'] ?? [];
            $hasCustomFields = array_key_exists('custom_fields', $data);
            $customFields = $data['custom_fields'] ?? [];
            unset($data['tag_ids'], $data['custom_fields']);

            $priorityChanged = array_key_exists('priority', $data)
                && $data['priority'] !== null
                && $data['priority'] !== $ticket->priority;

            if (! empty($data)) {
                // Önce yeni öncelik modele yazılır, SONRA yeniden hesap
                // yapılır: SlaService::recalculateForPriority() hedefi
                // modelin GÜNCEL `priority` değerinden okur.
                $ticket->fill($data);

                if ($priorityChanged) {
                    $this->sla->recalculateForPriority($ticket);
                }

                $ticket->save();
            }

            if ($hasTagIds) {
                $this->tickets->syncTags($ticket, $tagIds ?? []);
            }

            if ($hasCustomFields && ! empty($customFields)) {
                $this->tickets->syncCustomFieldValues($ticket, $customFields);
            }

            return $this->tickets->findOrFail((int) $ticket->id);
        });
    }

    public function delete(Ticket $ticket): void
    {
        $this->tickets->delete($ticket);
    }

    /**
     * `PATCH /api/tickets/{ticket}/assign`.
     *
     * §2: ticket devrinin SLA sayacına ETKİSİ YOKTUR — sayaç ticket'ındır,
     * kişinin değil. Müşteriye verilen taahhüt, iş el değiştirdi diye uzamaz.
     * Devir geçmişi zaten `activity_log`'a düşer (spatie, `assigned_to`
     * dirty).
     */
    public function assign(Ticket $ticket, ?int $assigneeId): Ticket
    {
        $this->tickets->update($ticket, ['assigned_to' => $assigneeId]);

        return $this->tickets->findOrFail((int) $ticket->id);
    }

    /**
     * `PATCH /api/tickets/{ticket}/status` — §4 durum makinesi.
     *
     * Geçersiz geçişte TicketStatusMachine 422 `INVALID_STATUS_TRANSITION`
     * fırlatır; buraya dönmez.
     */
    public function changeStatus(Ticket $ticket, string $status): Ticket
    {
        $updated = $this->statusMachine->transition($ticket, $status);

        return $this->tickets->findOrFail((int) $updated->id);
    }
}
