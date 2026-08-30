<?php

namespace App\Listeners\Notifications;

use App\Events\DealMoved;
use App\Models\PipelineStage;
use App\Models\User;
use App\Notifications\DealStageChangedNotification;
use App\Notifications\Support\NotificationDispatcher;

/**
 * Faz 10 tetikleyici sözleşmesi: "DealMoved → deal.stage_changed (fırsatın
 * sahibine; taşıyan kişi sahibin kendisiyse gönderme)".
 *
 * `App\Services\Deals\DealMoveService`'e (Faz 7 sahipliği) dokunulmadı — bu
 * listener, o servisin zaten yaydığı `App\Events\DealMoved`'a bağlanır
 * (kayıt AppServiceProvider'da). `DealMoved::payload()` düz skaler veri
 * taşıdığı için (worker'ın modeli yeniden sorgulamaması gerekçesiyle — bkz.
 * o sınıfın dokümanı) burada da modele DEĞİL, event payload'ına güvenilir;
 * yalnızca alıcı/actor'ün `is_active` durumu ve hedef aşamanın adı için iki
 * küçük sorgu atılır.
 */
class SendDealStageChangedNotification
{
    public function handle(DealMoved $event): void
    {
        $ownerId = $event->payload['owner_id'] ?? null;

        if ($ownerId === null) {
            return;
        }

        $movedById = (int) $event->payload['moved_by_id'];

        // Taşıyan kişi sahibin kendisiyse gönderme — erken çıkış, gereksiz
        // sorgudan kurtarır. NotificationDispatcher aynı kontrolü zaten
        // uygular; burası yalnızca bir performans kısayoludur.
        if ((int) $ownerId === $movedById) {
            return;
        }

        $owner = User::find((int) $ownerId);
        $actor = User::find($movedById);
        $stageName = PipelineStage::find((int) $event->payload['to_stage_id'])?->name ?? '—';

        NotificationDispatcher::send(
            $owner,
            $actor,
            DealStageChangedNotification::make(
                ownerId: (int) $ownerId,
                dealId: (int) $event->payload['deal_id'],
                dealTitle: (string) $event->payload['title'],
                toStageName: $stageName,
                actorId: $movedById,
                actorName: $event->payload['moved_by_name'] ?? null,
            ),
        );
    }
}
