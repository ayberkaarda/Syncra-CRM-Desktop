<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

/**
 * `ticket.assigned` — bir destek talebinin `assigned_to`'su yeni (ve dolu)
 * bir kullanıcıya ayarlandığında
 * `App\Observers\Notifications\TicketNotificationObserver` tarafından
 * üretilir.
 *
 * FAZ 14 / İz D — anahtar moduna dönüştürüldü. `ticket_number`/`subject`
 * KULLANICI VERİSİDİR (destek talebinin konusu), parametre olarak taşınır.
 */
class TicketAssignedNotification extends CrmNotification
{
    public static function make(Ticket $ticket, ?User $actor): self
    {
        return new self(
            recipientId: (int) $ticket->assigned_to,
            notificationType: 'ticket.assigned',
            titleKey: 'notifications.ticket_assigned.title',
            bodyKey: 'notifications.ticket_assigned.body',
            params: [
                'ticket_number' => (string) $ticket->ticket_number,
                'subject' => (string) $ticket->subject,
            ],
            notificationLink: '/tickets/'.$ticket->getKey(),
            meta: [
                'ticket_id' => (int) $ticket->getKey(),
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
            ],
        );
    }
}
