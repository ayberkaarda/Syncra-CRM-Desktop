<?php

namespace App\Notifications;

/**
 * `deal.stage_changed` — `App\Events\DealMoved` her yayınlandığında
 * `App\Listeners\Notifications\SendDealStageChangedNotification` tarafından
 * fırsatın SAHİBİNE üretilir (taşıyan kişi sahibin kendisiyse
 * `NotificationDispatcher` zaten göndermez).
 *
 * `DealMoved::payload()` yalnızca düz skaler veri taşır (bkz. o sınıfın
 * dokümanı — worker'ın modeli yeniden sorgulamaması gerekçesiyle); bu yüzden
 * burası da bir Eloquent modeli DEĞİL, listener'ın event payload'ından
 * derlediği skaler alanları alır.
 *
 * FAZ 14 / İz D — anahtar moduna dönüştürüldü. `deal_title`/`stage`
 * (pipeline aşama adı) KULLANICI VERİSİDİR (§1.5 — aşama adı çevrilmez),
 * parametre olarak taşınır; cümlenin kendisi sözlükte durur.
 */
class DealStageChangedNotification extends CrmNotification
{
    public static function make(
        int $ownerId,
        int $dealId,
        string $dealTitle,
        string $toStageName,
        ?int $actorId,
        ?string $actorName,
    ): self {
        return new self(
            recipientId: $ownerId,
            notificationType: 'deal.stage_changed',
            titleKey: 'notifications.deal_stage_changed.title',
            bodyKey: 'notifications.deal_stage_changed.body',
            params: [
                'deal_title' => $dealTitle,
                'stage' => $toStageName,
            ],
            notificationLink: '/deals/'.$dealId,
            meta: [
                'deal_id' => $dealId,
                'to_stage_name' => $toStageName,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
            ],
        );
    }
}
