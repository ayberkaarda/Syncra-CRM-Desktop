<?php

namespace App\Observers\Notifications;

use App\Models\Task;
use App\Notifications\Support\NotificationDispatcher;
use App\Notifications\TaskAssignedNotification;

/**
 * Faz 10 tetikleyici sözleşmesi: "Task: atama alanı dirty → task.assigned".
 * `App\Services\Tasks\TaskService`'e (Faz 8 sahipliği) dokunulmadı — bu
 * observer `Task` modelinin `created`/`updated` event'lerine bağlanır.
 */
class TaskNotificationObserver
{
    public function created(Task $task): void
    {
        $this->notifyAssignment($task);
    }

    public function updated(Task $task): void
    {
        if ($task->wasChanged('assigned_to')) {
            $this->notifyAssignment($task);
        }
    }

    private function notifyAssignment(Task $task): void
    {
        if ($task->assigned_to === null) {
            return;
        }

        $actor = auth()->user();

        NotificationDispatcher::send($task->assignee, $actor, TaskAssignedNotification::make($task, $actor));
    }
}
