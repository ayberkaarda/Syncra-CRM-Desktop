<?php

namespace App\Listeners\Notifications;

use App\Events\TicketSlaBreached;
use App\Models\User;
use App\Notifications\Support\NotificationDispatcher;
use App\Notifications\TicketSlaBreachedNotification;

/**
 * Faz 10 tetikleyici sözleşmesi: "TicketSlaBreached → ticket.sla_breached".
 *
 * `tickets:scan-sla` (Faz 8 sahipliği) zamanlanmış bir tarayıcıdır — actor
 * YOKTUR. `assigned_to` NULL ise (atanmamış ticket) gönderilecek kimse
 * yoktur, hiçbir sorgu atmadan çıkılır.
 */
class SendTicketSlaBreachedNotification
{
    public function handle(TicketSlaBreached $event): void
    {
        $assignedTo = $event->payload['assigned_to'] ?? null;

        if ($assignedTo === null) {
            return;
        }

        $recipient = User::find((int) $assignedTo);

        NotificationDispatcher::send(
            $recipient,
            null,
            TicketSlaBreachedNotification::make(
                assignedTo: (int) $assignedTo,
                ticketNumber: (string) $event->payload['ticket_number'],
                subject: (string) $event->payload['subject'],
                ticketId: (int) $event->payload['ticket_id'],
                overdueSeconds: (int) $event->payload['overdue_seconds'],
            ),
        );
    }
}
