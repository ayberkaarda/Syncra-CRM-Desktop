<?php

namespace App\Listeners\Automation;

use App\Models\Ticket;
use App\Services\Automation\AutomationExecutionGuard;
use App\Services\Automation\AutomationRuleRunner;

/**
 * Faz 14 / İz F — `ticket.created` tetikleyicisi. `RunAutomationRulesOnDealUpdated`
 * dokümanındaki AYNI teknik: `Ticket::observe(TicketNotificationObserver::class)`
 * (Faz 10, BAŞKA bir şeridin alanı) ile aynı temel Eloquent olayına
 * (`"eloquent.created: ".Ticket::class`) BAĞIMSIZ, ikinci bir dinleyici olarak bağlanılır.
 * `TicketNotificationObserver`'a dokunulmadı.
 */
class RunAutomationRulesOnTicketCreated
{
    public function handle(Ticket $ticket): void
    {
        if (AutomationExecutionGuard::isRunning()) {
            return;
        }

        AutomationExecutionGuard::run(static function () use ($ticket): void {
            AutomationRuleRunner::handleTicketCreated($ticket);
        });
    }
}
