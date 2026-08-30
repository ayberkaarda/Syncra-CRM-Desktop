<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesAbilities;
use App\Models\Ticket;
use App\Services\Tickets\SlaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =============================================================================
 * Ticket gösterimi — docs/SLA-DESIGN.md §6 API sözleşmesi
 * =============================================================================
 *
 * -----------------------------------------------------------------------------
 * İÇ NOTLAR İÇİN AYRI TABLO/UÇ AÇILMADI — `notes_count` NEDEN BÖYLE
 * -----------------------------------------------------------------------------
 * Sistem KAPALI DEVRE'dir: müşteri portalı yoktur, sisteme yalnızca şirket
 * çalışanları girer. Dolayısıyla bir ticket'a yazılan HER NOT zaten iç
 * nottur ve `is_internal` gibi bir ayrım anlamsızdır — ayrım yapacak ikinci
 * bir kitle yoktur.
 *
 * Bu yüzden ticket notları için ne yeni bir tablo (`ticket_notes`) ne de yeni
 * bir uç (`POST /api/tickets/{ticket}/notes`) açılmıştır. Notlar `activities`
 * tablosunda `type='note'` olarak, `activityable` morph ilişkisiyle ticket'a
 * bağlanır; A şeridinin `POST /api/activities` ucu bunu ZATEN destekler
 * (`activityable_type: "ticket"`, `activityable_id: <id>` — beyaz liste
 * App\Support\MorphTargets). İkinci bir not deposu açmak, zaman çizelgesini
 * (Faz 6 TimelineBuilder) iki kaynaktan birleştirmeyi zorunlu kılar ve
 * hiçbir şey kazandırmazdı.
 *
 * `notes_count` bu ilişkiden `withCount` ile (ticket başına ek sorgu YOK,
 * bkz. TicketRepository::notesCountRelation()) türetilir; arayüz "3 not"
 * rozetini tek istekle çizebilir.
 *
 * -----------------------------------------------------------------------------
 * SLA ALANLARI — SUNUCU HESABI (§6)
 * -----------------------------------------------------------------------------
 * `sla_remaining_seconds` HER ZAMAN sunucuda, yanıt üretilirken hesaplanır.
 * İstemci `Date.now()` ile `sla_due_at`'i ASLA karşılaştırmaz: kullanıcının
 * sistem saati birkaç dakika ileri/geriyse ihlal yanlış görünürdü ve iki
 * kullanıcı aynı ticket için farklı şey görürdü. Doğru istemci deseni (§6):
 * yanıt geldiği an `t0 = performance.now()` (MONOTON saat) ve
 * `r0 = sla_remaining_seconds` kaydedilir, ekrandaki sayaç
 * `r0 - (performance.now() - t0)/1000` gösterir; `sla_paused === true` ise
 * sayaç `r0`'da DONUK kalır. Sıfırın altına inince arayüz "aşıldı" der ama
 * ihlal GERÇEĞİ sunucunundur — 60 sn'lik refetch ve `private-tickets`
 * kanalından gelen olaylar `r0/t0`'ı yeniden senkronlar.
 *
 * @property-read Ticket $resource
 */
class TicketResource extends JsonResource
{
    use ExposesAbilities;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->resource;

        $sla = app(SlaService::class);

        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'category' => $ticket->category,

            // --- SLA (docs/SLA-DESIGN.md §6; isimler ve tipler bağlayıcı) ---
            // Yalnızca mutlak tarih GÖSTERİMİ için; istemci bununla aritmetik
            // YAPMAZ.
            'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
            // İlerleme çubuğunun paydası. Ticket'ın KENDİ hedefinden
            // türetilir (sla_due_at - created_at - sla_paused_seconds), ayar
            // tablosundan okunmaz — gerekçe SlaService::totalSeconds()
            // dokümanında (doğruluk + satır başına sorgu yok).
            'sla_total_seconds' => $sla->totalSeconds($ticket),
            // Akarken sla_due_at - now(); duraklamada sla_due_at -
            // sla_paused_at (DONMUŞ); çözülmüş/kapanmışta null. Negatif =
            // aşılmış (kırpılmaz — arayüz gecikmeyi yazabilsin).
            'sla_remaining_seconds' => $sla->remainingSeconds($ticket),
            'sla_paused' => $sla->isPaused($ticket),
            // Açık ticket'ta AKTİF ihlal, çözülmüşte TARİHSEL ihlal (§5.3).
            // Kalıcı bir kolon DEĞİL — her yanıtta türetilir.
            'sla_breached' => $sla->isBreached($ticket),
            'sla_paused_seconds' => (int) $ticket->sla_paused_seconds,
            // §6'nın yedi alanına EK: hedefin saat cinsinden hâli, arayüz
            // "4 saatlik SLA" etiketini `sla_total_seconds`'ı tekrar bölmek
            // zorunda kalmadan yazabilsin.
            'sla_target_hours' => $sla->targetHoursForTicket($ticket),

            'first_response_at' => $ticket->first_response_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),

            // `withCount` yüklenmediyse (ör. bir başka yoldan hidrate edilmiş
            // model) 0 döner, patlamaz.
            'notes_count' => (int) ($ticket->notes_count ?? 0),

            'contact' => $ticket->relationLoaded('contact') && $ticket->contact
                ? ['id' => $ticket->contact->id, 'full_name' => $ticket->contact->full_name]
                : null,
            'company' => $ticket->relationLoaded('company') && $ticket->company
                ? ['id' => $ticket->company->id, 'name' => $ticket->company->name]
                : null,
            'assignee' => $ticket->relationLoaded('assignee') && $ticket->assignee
                ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name]
                : null,
            'creator' => $ticket->relationLoaded('creator') && $ticket->creator
                ? ['id' => $ticket->creator->id, 'name' => $ticket->creator->name]
                : null,
            'tags' => $ticket->relationLoaded('tags')
                ? $ticket->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values()
                : [],
            'custom_fields' => $ticket->relationLoaded('customFieldValues')
                ? $ticket->customFieldValues
                    ->mapWithKeys(fn ($value) => [$value->customField->key => $value->value])
                    ->all()
                : [],

            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            // Bu kullanıcının bu kayıtta neyi YAPABİLDİĞİ — arayüz kuralı
            // yeniden yazmasın (gerekçe: ExposesAbilities).
            'can' => $this->abilities($request, $ticket, [
                'update' => 'update',
                // `PATCH /api/tickets/{ticket}/status` ayrı bir uçtur ama
                // TicketController::status() bilerek `update` yeteneğini sorar
                // (izin sözlüğünde `tickets.status` yok) — eşleme onu yansıtır.
                'status' => 'update',
                'delete' => 'delete',
                'assign' => 'assign',
            ]),
        ];
    }
}
