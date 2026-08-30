<?php

namespace App\Listeners\Automation;

use App\Events\DealMoved;
use App\Models\Deal;
use App\Services\Automation\AutomationExecutionGuard;
use App\Services\Automation\AutomationRuleRunner;

/**
 * Faz 14 / İz F — `deal.stage_changed` tetikleyicisi. Faz 10'un `deal.stage_changed`
 * bildirimiyle (`SendDealStageChangedNotification`) AYNI event'e (`App\Events\DealMoved`)
 * bağlanır — `App\Services\Deals\DealMoveService`'e (Faz 7 sahipliği) TEK satır bile
 * dokunulmadı, ikinci bir paralel tetikleme mekanizması KURULMADI (görev tanımı).
 *
 * `DealMoved::payload()` düz skaler taşır (o sınıfın dokümanı) — burada da modele değil
 * payload'a güvenilir, yalnızca hedef deal'ı (görev/bildirim oluşturmak için) yeniden
 * sorgular.
 */
class RunAutomationRulesOnDealMoved
{
    public function handle(DealMoved $event): void
    {
        if (AutomationExecutionGuard::isRunning()) {
            return;
        }

        $deal = Deal::find((int) $event->payload['deal_id']);

        if ($deal === null) {
            return;
        }

        $toStageId = (int) $event->payload['to_stage_id'];

        AutomationExecutionGuard::run(static function () use ($deal, $toStageId): void {
            AutomationRuleRunner::handleDealStageChanged($deal, $toStageId);
        });
    }
}
