<?php

namespace App\Observers\Notifications;

use App\Models\Lead;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\Support\NotificationDispatcher;

/**
 * Faz 10 tetikleyici sözleşmesi: "Lead: atama alanı dirty → lead.assigned".
 * `App\Services\Leads\*` / `App\Repositories\LeadRepository`'ye (Faz 6
 * sahipliği) dokunulmadı — bu observer `Lead` modelinin `created`/`updated`
 * event'lerine bağlanır.
 *
 * CSV toplu içe aktarma (`LeadImportService::process()`) bu event'leri satır
 * başına tetikler; bildirim yağmuru `NotificationDispatcher`'ın
 * `ActivityLogStatus::disabled()` kontrolüyle önlenir (bkz. o sınıfın
 * dokümanı) — import döngüsü zaten aynı toggle'ı audit gürültüsü için
 * kullanıyor.
 */
class LeadNotificationObserver
{
    public function created(Lead $lead): void
    {
        $this->notifyAssignment($lead);
    }

    public function updated(Lead $lead): void
    {
        if ($lead->wasChanged('owner_id')) {
            $this->notifyAssignment($lead);
        }
    }

    private function notifyAssignment(Lead $lead): void
    {
        if ($lead->owner_id === null) {
            return;
        }

        $actor = auth()->user();

        NotificationDispatcher::send($lead->owner, $actor, LeadAssignedNotification::make($lead, $actor));
    }
}
