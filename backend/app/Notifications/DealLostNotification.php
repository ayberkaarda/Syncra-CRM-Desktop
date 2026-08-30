<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\User;
use App\Notifications\Support\Money;

/**
 * `deal.lost` — bir fırsatın `status`'ü `lost`'a döndüğünde
 * `App\Observers\Notifications\DealNotificationObserver` tarafından fırsatın
 * SAHİBİNE üretilir.
 *
 * FAZ 14 / İz D — anahtar moduna dönüştürüldü (bkz. DealAssignedNotification
 * aynı desen: `subject`/`amount` kullanıcı verisi/gönderim-anı biçimi, cümle
 * sözlükte).
 */
class DealLostNotification extends CrmNotification
{
    public static function make(Deal $deal, ?User $actor): self
    {
        return new self(
            recipientId: (int) $deal->owner_id,
            notificationType: 'deal.lost',
            titleKey: 'notifications.deal_lost.title',
            bodyKey: 'notifications.deal_lost.body',
            params: [
                'subject' => (string) ($deal->company?->name ?? $deal->title),
                'amount' => Money::format((string) $deal->amount, (string) $deal->currency),
            ],
            notificationLink: '/deals/'.$deal->getKey(),
            meta: [
                'deal_id' => (int) $deal->getKey(),
                'lost_reason' => $deal->lost_reason,
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
            ],
        );
    }
}
