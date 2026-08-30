<?php

namespace App\Listeners\Automation;

use App\Models\Deal;
use App\Services\Automation\AutomationExecutionGuard;
use App\Services\Automation\AutomationRuleRunner;

/**
 * Faz 14 / İz F — `deal.status_changed` tetikleyicisi.
 *
 * `App\Observers\Notifications\DealNotificationObserver` (Faz 10, BAŞKA bir şeridin
 * alanı) `Deal::observe()` ile modelin `updated` Eloquent olayına bağlanıyor — bu KENDİ
 * `updated()` metodunu değiştirmek yerine, Eloquent'in HER `created`/`updated`'de zaten
 * fırlattığı HAM string event'e (`"eloquent.updated: ".Deal::class`,
 * `Illuminate\Database\Eloquent\Concerns\HasEvents::fireModelEvent()` — vendor kaynağı
 * okunarak doğrulandı) İKİNCİ, BAĞIMSIZ bir dinleyici olarak bağlanılır. Bu, `Deal::observe()`
 * çağrısının ALTINDA yatan AYNI mekanizmadır (registerObserver da aynı string event'e
 * `ClassName@updated` ekler) — DealNotificationObserver'a tek satır bile dokunulmadı,
 * ikinci bir paralel event sistemi de kurulmadı.
 *
 * `wasChanged('status')` burada güvenlidir: bu olay `performUpdate()` içinde `save()`
 * TAMAMLANDIKTAN hemen sonra, `$halt=false` (`dispatch`, `until` DEĞİL) ile fırlatılır —
 * DealNotificationObserver::updated() da AYNI olayda AYNI kontrolü yapıyor (kanıtlanmış
 * çalışan desen).
 */
class RunAutomationRulesOnDealUpdated
{
    public function handle(Deal $deal): void
    {
        if (AutomationExecutionGuard::isRunning()) {
            return;
        }

        if (! $deal->wasChanged('status')) {
            return;
        }

        AutomationExecutionGuard::run(static function () use ($deal): void {
            AutomationRuleRunner::handleDealStatusChanged($deal);
        });
    }
}
