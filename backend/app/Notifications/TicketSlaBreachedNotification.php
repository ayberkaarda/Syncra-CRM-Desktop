<?php

namespace App\Notifications;

use App\Events\TicketSlaBreached;

/**
 * `ticket.sla_breached` — `App\Events\TicketSlaBreached` her yayınlandığında
 * (`tickets:scan-sla`, 5 dakikada bir) `App\Listeners\Notifications\
 * SendTicketSlaBreachedNotification` tarafından ticket'ın atandığı kişiye
 * üretilir. `assigned_to` NULL ise listener hiç bildirim üretmez.
 *
 * Zamanlanmış bir tarayıcıdan geldiği için actor YOKTUR.
 *
 * FAZ 14 / İz D — anahtar moduna dönüştürüldü. `minutes` sayısal bir
 * DEĞERDİR, birim adı ("dk"/"min"/"Min.") cümlenin parçasıdır ve sözlükte
 * durur — böylece her dil kendi kısaltmasını/sözcük sırasını kullanır.
 *
 * @see TicketSlaBreached::payload() Payload alanları için.
 */
class TicketSlaBreachedNotification extends CrmNotification
{
    public static function make(int $assignedTo, string $ticketNumber, string $subject, int $ticketId, int $overdueSeconds): self
    {
        return new self(
            recipientId: $assignedTo,
            notificationType: 'ticket.sla_breached',
            titleKey: 'notifications.ticket_sla_breached.title',
            bodyKey: 'notifications.ticket_sla_breached.body',
            params: [
                'ticket_number' => $ticketNumber,
                'subject' => $subject,
                'minutes' => (string) (int) round($overdueSeconds / 60),
            ],
            notificationLink: '/tickets/'.$ticketId,
            meta: [
                'ticket_id' => $ticketId,
                'overdue_seconds' => $overdueSeconds,
            ],
        );
    }
}
