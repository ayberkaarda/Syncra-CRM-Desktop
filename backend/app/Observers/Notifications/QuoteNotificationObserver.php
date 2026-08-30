<?php

namespace App\Observers\Notifications;

use App\Models\Quote;
use App\Notifications\QuoteStatusChangedNotification;
use App\Notifications\Support\NotificationDispatcher;

/**
 * Faz 10 tetikleyici sözleşmesi: "Quote: `status` dirty → quote.status_changed".
 * `App\Services\Quotes\*` / `App\Repositories\QuoteRepository`'ye (Faz 9
 * sahipliği) dokunulmadı — bu observer `Quote` modelinin `updated` event'ine
 * bağlanır. `created`'ta tetiklenmez: bir teklif her zaman `draft` olarak
 * doğar (`status` sütununun varsayılanı), yani oluşturma anında "değişen"
 * bir durum yoktur — ilk anlamlı geçiş `draft → sent` (QuoteService::send())
 * her zaman bir `updated` event'idir.
 */
class QuoteNotificationObserver
{
    public function updated(Quote $quote): void
    {
        if (! $quote->wasChanged('status')) {
            return;
        }

        if ($quote->created_by === null) {
            return;
        }

        $actor = auth()->user();
        $fromStatus = (string) $quote->getOriginal('status');

        NotificationDispatcher::send(
            $quote->creator,
            $actor,
            QuoteStatusChangedNotification::make($quote, $fromStatus, $actor),
        );
    }
}
