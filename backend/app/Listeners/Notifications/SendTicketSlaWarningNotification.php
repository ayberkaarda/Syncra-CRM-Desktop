<?php

namespace App\Listeners\Notifications;

use App\Events\TicketSlaWarning;
use App\Models\User;
use App\Notifications\Support\NotificationDispatcher;
use App\Notifications\TicketSlaWarningNotification;

/**
 * Faz 10 tetikleyici sözleşmesi: "TicketSlaWarning → ticket.sla_warning".
 *
 * `tickets:scan-sla` (Faz 8 sahipliği) zamanlanmış bir tarayıcıdır — actor
 * YOKTUR. `assigned_to` NULL ise (atanmamış ticket) gönderilecek kimse
 * yoktur, hiçbir sorgu atmadan çıkılır.
 */
class SendTicketSlaWarningNotification
{
    public function handle(TicketSlaWarning $event): void
    {
        $assignedTo = $event->payload['assigned_to'] ?? null;

        if ($assignedTo === null) {
            return;
        }

        $recipient = User::find((int) $assignedTo);

        NotificationDispatcher::send(
            $recipient,
            null,
            TicketSlaWarningNotification::make(
                assignedTo: (int) $assignedTo,
                ticketNumber: (string) $event->payload['ticket_number'],
                subject: (string) $event->payload['subject'],
                ticketId: (int) $event->payload['ticket_id'],
                remainingSeconds: (int) $event->payload['remaining_seconds'],
            ),
        );
    }
}
