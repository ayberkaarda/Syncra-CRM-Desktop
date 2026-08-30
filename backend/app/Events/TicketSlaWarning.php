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
 * Bir ticket'ın SLA'sı ihlale YAKLAŞIYOR — kalan süre hedefin %20'sinin
 * altına indi (docs/SLA-DESIGN.md §5.5).
 *
 * `App\Console\Commands\ScanTicketSla` (`tickets:scan-sla`, 5 dakikada bir
 * zamanlanır — bkz. routes/console.php) tarafından dispatch edilir ve ticket
 * başına YALNIZCA BİR KEZ üretilir (`sla_warning_notified_at` damgası).
 *
 * ---------------------------------------------------------------------------
 * NEDEN `private-tickets` (MODÜL KANALI)
 * ---------------------------------------------------------------------------
 * Uyarı, ticket'ı AÇMIŞ OLAN kişiye değil, destek EKRANINI açık tutan herkese
 * gitmelidir: bir SLA'nın yanmak üzere olması ekibin ortak sorunudur ve
 * atanmamış ticket'ların (demo veride mevcut) kişisel bir alıcısı zaten
 * yoktur. `private-user.{id}` bu yüzden yanlış kapsamdır; `presence-record.
 * ticket.{id}` ise "şu an bu KAYDA kim bakıyor" sorusunun cevabıdır ve 50
 * ticket için 50 abonelik demektir. Tek modül kanalı, `tickets.view` izniyle
 * korunur: ticket'ları göremeyen, SLA'larını da duymaz.
 *
 * ---------------------------------------------------------------------------
 * NEDEN DÜZ SKALER PAYLOAD (MODEL DEĞİL)
 * ---------------------------------------------------------------------------
 * Faz 5/7/8'de öğrenildi: `SerializesModels` kuyruğa yalnızca sınıf + id
 * koyar ve işçi satırı YENİDEN SORGULAR — dispatch anındaki durumla işçinin
 * gördüğü durum arasında fark oluşabilir (ticket bu arada çözülmüş olabilir)
 * ve yeniden hidrasyon oturum açmış kullanıcısı olmayan bir bağlamda olur.
 * Bu yüzden yayınlanacak her şey, olay üretildiği anda hesaplanıp skaler
 * olarak taşınır (bkz. DealMoved::payload(), TaskReminderDue aynı gerekçe).
 *
 * `remaining_seconds` payload'a SUNUCU HESABI olarak konur: istemci
 * `sla_due_at`'i kendi saatiyle karşılaştırmaz (§6 istemci geri sayım
 * kuralı).
 */
class TicketSlaWarning implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  bkz. self::payload()
     */
    public function __construct(public readonly array $payload) {}

    /**
     * @return array<string, mixed>
     */
    public static function payload(Ticket $ticket, int $remainingSeconds): array
    {
        return [
            'ticket_id' => (int) $ticket->getKey(),
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'assigned_to' => $ticket->assigned_to === null ? null : (int) $ticket->assigned_to,
            'sla_due_at' => $ticket->sla_due_at?->toIso8601String(),
            'remaining_seconds' => $remainingSeconds,
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

    /**
     * Kısa, sabit olay adı — FQCN yayınlamak SPA'yı backend namespace'ine
     * bağlardı (bkz. deal.moved / task.reminder / activity.logged aynı
     * sözleşme).
     */
    public function broadcastAs(): string
    {
        return 'ticket.sla.warning';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
