<?php

namespace App\Observers\Notifications;

use App\Models\Ticket;
use App\Notifications\Support\NotificationDispatcher;
use App\Notifications\TicketAssignedNotification;

/**
 * Faz 10 tetikleyici sözleşmesi: "Ticket: atama alanı dirty → ticket.assigned".
 * `App\Services\Tickets\TicketService`'e (Faz 8 sahipliği) dokunulmadı — bu
 * observer `Ticket` modelinin `created`/`updated` event'lerine bağlanır.
 */
class TicketNotificationObserver
{
    public function created(Ticket $ticket): void
    {
        $this->notifyAssignment($ticket);
    }

    public function updated(Ticket $ticket): void
    {
        if ($ticket->wasChanged('assigned_to')) {
            $this->notifyAssignment($ticket);
        }
    }

    private function notifyAssignment(Ticket $ticket): void
    {
        if ($ticket->assigned_to === null) {
            return;
        }

        $actor = auth()->user();

        NotificationDispatcher::send($ticket->assignee, $actor, TicketAssignedNotification::make($ticket, $actor));
    }
}
