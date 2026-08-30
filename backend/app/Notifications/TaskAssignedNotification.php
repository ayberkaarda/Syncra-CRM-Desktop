<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;

/**
 * `task.assigned` — bir görevin `assigned_to`'su yeni (ve dolu) bir
 * kullanıcıya ayarlandığında
 * `App\Observers\Notifications\TaskNotificationObserver` tarafından üretilir.
 *
 * FAZ 14 / İZ D — anahtar moduna dönüştürülen iki örnekten biri.
 */
class TaskAssignedNotification extends CrmNotification
{
    public static function make(Task $task, ?User $actor): self
    {
        $dueAt = $task->due_at?->toIso8601String();

        return new self(
            recipientId: (int) $task->assigned_to,
            notificationType: 'task.assigned',
            titleKey: 'notifications.task_assigned.title',
            /*
             * VADE VARSA AYRI ANAHTAR, koşullu birleştirme DEĞİL.
             *
             * Eski hâli `sprintf('%s — vade %s', ...)` ile cümleyi kodda kuruyordu; bu, "—"
             * ayracını ve "vade" sözcüğünün YERİNİ Türkçe cümle yapısına çiviler. Çevirmenin
             * sözcük sırasını değiştirebilmesi için tümce, cümle olarak sözlükte durmalı.
             * İki ayrı anahtar (`body` / `body_with_due`) çoğul-benzeri bir varyanttır ve
             * i18next'in `_one/_other` kalıbıyla aynı disiplini backend'de kurar.
             */
            bodyKey: $dueAt !== null
                ? 'notifications.task_assigned.body_with_due'
                : 'notifications.task_assigned.body',
            params: array_filter([
                // Görev başlığı kullanıcı verisidir — çevrilmez, parametre olarak taşınır.
                'title' => (string) $task->title,
                // `_at` son eki SÖZLEŞMEDİR: okuma anında okuyucunun diliyle biçimlendirilir
                // (bkz. NotificationText::resolveParams()). Buraya biçimlendirilmiş metin
                // yazmak, ay adını gönderim diline dondururdu.
                'due_at' => $dueAt,
            ], static fn (?string $value): bool => $value !== null),
            notificationLink: '/tasks/'.$task->getKey(),
            meta: [
                'task_id' => (int) $task->getKey(),
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
            ],
        );
    }
}
