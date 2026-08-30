<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Bir ticket'ın SLA'sı İHLAL EDİLDİ — `sla_due_at` geçildi ve sayaç akıyordu
 * (docs/SLA-DESIGN.md §5.5).
 *
 * `App\Console\Commands\ScanTicketSla` (`tickets:scan-sla`, 5 dakikada bir)
 * tarafından dispatch edilir ve ticket başına YALNIZCA BİR KEZ üretilir
 * (`sla_breach_notified_at` damgası).
 *
 * ---------------------------------------------------------------------------
 * OLAY KALICI DEĞİL, İHLAL TÜRETİLMİŞTİR
 * ---------------------------------------------------------------------------
 * Bu olay bir BİLDİRİM tetikleyicisidir, ihlalin kaydı DEĞİLDİR. İhlal
 * gerçeği her an `resolved_at`/`sla_paused_at`/`sla_due_at` üçlüsünden
 * türetilir (§5.3, SlaService::isBreached()); `sla_breach_notified_at`
 * yalnızca "bu bildirim üretildi mi" sorusunun cevabıdır. Bu ayrım önemlidir:
 * damga silinse bile ihlal listesi doğru kalır, yalnızca bildirim bir kez
 * daha üretilir.
 *
 * Kanal ve payload gerekçeleri TicketSlaWarning ile aynıdır (modül kanalı
 * `private-tickets`, düz skaler payload). Eventler Faz 8'de üretilir, Faz
 * 10'da kalıcı bildirime dönüşür (ROADMAP Faz 8 DoD).
 */
class TicketSlaBreached implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  bkz. self::payload()
     */
    public function __construct(public readonly array $payload) {}

    /**
     * @param  int  $overdueSeconds  Hedefin ne kadar AŞILDIĞI (pozitif saniye).
     * @return array<string, mixed>
     */
    public static function payload(Ticket $ticket, int $overdueSeconds): array
    {
        return [
            'ticket_id' => (int) $ticket->getKey(),
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'assigned_to' => $ticket->assigned_to === null ? null : (int) $ticket->assigned_to,
            'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
            'overdue_seconds' => $overdueSeconds,
            'detected_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tickets'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.sla.breached';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
