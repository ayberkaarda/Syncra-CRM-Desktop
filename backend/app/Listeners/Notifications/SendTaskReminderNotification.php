<?php

namespace App\Listeners\Notifications;

use App\Events\TaskReminderDue;
use App\Models\User;
use App\Notifications\Support\NotificationDispatcher;
use App\Notifications\TaskReminderNotification;

/**
 * Faz 10 tetikleyici sözleşmesi: "TaskReminderDue → task.reminder".
 *
 * `tasks:dispatch-reminders` (Faz 8 sahipliği) zamanlanmış bir komuttur —
 * actor YOKTUR, bu yüzden `NotificationDispatcher::send()`'e `null` actor
 * geçilir (kendine-bildirim-gitmez kontrolü basitçe uygulanmaz).
 */
class SendTaskReminderNotification
{
    public function handle(TaskReminderDue $event): void
    {
        $recipient = User::find($event->assignedTo);

        NotificationDispatcher::send(
            $recipient,
            null,
            TaskReminderNotification::make($event->assignedTo, $event->payload),
        );
    }
}
