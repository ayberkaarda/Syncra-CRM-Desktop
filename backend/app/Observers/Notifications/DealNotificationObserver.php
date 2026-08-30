<?php

namespace App\Observers\Notifications;

use App\Models\Deal;
use App\Notifications\DealAssignedNotification;
use App\Notifications\DealLostNotification;
use App\Notifications\DealWonNotification;
use App\Notifications\Support\NotificationDispatcher;

/**
 * Faz 10 tetikleyici sözleşmesi: "Deal: atama alanı dirty → deal.assigned;
 * `status` → won/lost → `deal.won` / `deal.lost`".
 *
 * `App\Services\Deals\DealService`/`DealRepository`/`DealMoveService`'e
 * (Faz 7 sahipliği) TEK BİR dispatch satırı bile EKLENMEDİ — bunun yerine bu
 * observer `Deal` modelinin `created`/`updated` Eloquent event'lerine
 * bağlanır (kayıt AppServiceProvider'da).
 *
 * Actor tespiti `auth()->user()` ile yapılır — tıpkı
 * `App\Observers\ActivityLogObserver`'ın kullandığı
 * `ActivityFormatter::causerName()`'in yaptığı gibi (bkz. o sınıfın
 * dokümanı): HTTP isteğinde dolu, konsol/kuyrukta `null` — `null` durumunda
 * `NotificationDispatcher`'ın "kendine bildirim gitmez" kontrolü basitçe
 * devre dışı kalır (karşılaştırılacak bir actor yok), engelleyici DEĞİL.
 */
class DealNotificationObserver
{
    public function created(Deal $deal): void
    {
        $this->notifyAssignment($deal);
    }

    public function updated(Deal $deal): void
    {
        if ($deal->wasChanged('owner_id')) {
            $this->notifyAssignment($deal);
        }

        if ($deal->wasChanged('status')) {
            $this->notifyOutcome($deal);
        }
    }

    private function notifyAssignment(Deal $deal): void
    {
        if ($deal->owner_id === null) {
            return;
        }

        $actor = auth()->user();

        NotificationDispatcher::send($deal->owner, $actor, DealAssignedNotification::make($deal, $actor));
    }

    private function notifyOutcome(Deal $deal): void
    {
        $actor = auth()->user();

        if ($deal->status === 'won') {
            NotificationDispatcher::send($deal->owner, $actor, DealWonNotification::make($deal, $actor));

            return;
        }

        if ($deal->status === 'lost') {
            NotificationDispatcher::send($deal->owner, $actor, DealLostNotification::make($deal, $actor));
        }
    }
}
