<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\User;

/**
 * `lead.assigned` — bir adayın `owner_id`'si yeni (ve dolu) bir kullanıcıya
 * ayarlandığında `App\Observers\Notifications\LeadNotificationObserver`
 * tarafından üretilir.
 *
 * CSV toplu içe aktarma sırasında (`LeadImportService`) bildirim yağmuru
 * `NotificationDispatcher`'daki `ActivityLogStatus` kontrolüyle önlenir —
 * bkz. o sınıfın dokümanı.
 *
 * FAZ 14 / İz D — anahtar moduna dönüştürüldü. Şirket adı varsa/yoksa ayrı
 * `body`/`body_with_company` anahtarı (TaskAssignedNotification'daki
 * `body`/`body_with_due` ayrımıyla aynı disiplin) — koşullu birleştirme
 * cümleyi kodda kurmaz, çevirmen sözcük sırasını değiştirebilir.
 */
class LeadAssignedNotification extends CrmNotification
{
    public static function make(Lead $lead, ?User $actor): self
    {
        $person = trim($lead->first_name.' '.$lead->last_name);
        $hasCompany = $lead->company_name !== null && $lead->company_name !== '';

        return new self(
            recipientId: (int) $lead->owner_id,
            notificationType: 'lead.assigned',
            titleKey: 'notifications.lead_assigned.title',
            bodyKey: $hasCompany
                ? 'notifications.lead_assigned.body_with_company'
                : 'notifications.lead_assigned.body',
            params: array_filter([
                'person' => $person,
                'company' => $hasCompany ? (string) $lead->company_name : null,
            ], static fn (?string $value): bool => $value !== null),
            notificationLink: '/leads/'.$lead->getKey(),
            meta: [
                'lead_id' => (int) $lead->getKey(),
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
            ],
        );
    }
}
