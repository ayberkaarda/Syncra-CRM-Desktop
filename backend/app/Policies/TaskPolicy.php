<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksRecordOwnership;

class TaskPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(User $user): bool
    {
        return $user->can('tasks.view');
    }

    /**
     * Okuma BİLEREK düz (bkz. ChecksRecordOwnership dokümanı).
     */
    public function view(User $user, Task $task): bool
    {
        return $user->can('tasks.view');
    }

    public function create(User $user): bool
    {
        return $user->can('tasks.create');
    }

    /**
     * Yatay yazma izolasyonu; görevde sahiplik kolonu `assigned_to`'dur
     * (`created_by` DEĞİL: görevi kimin yazdığı değil, kimin YÜRÜTTÜĞÜ
     * sorumluluğu belirler). Atanmamış görev havuzdadır, herkes alabilir.
     */
    public function update(User $user, Task $task): bool
    {
        if (! $user->can('tasks.update')) {
            return false;
        }

        return $this->ownsOrManages($user, $task->assigned_to, 'tasks.assign');
    }

    /**
     * Silmede sahiplik kontrolü YOK: `tasks.delete` yalnızca Müdür/Admin/
     * Super Admin'de ve bu roller zaten `tasks.assign` taşıyor — no-op olurdu.
     */
    public function delete(User $user, Task $task): bool
    {
        return $user->can('tasks.delete');
    }

    public function assign(User $user, Task $task): bool
    {
        return $user->can('tasks.assign');
    }

    /**
     * KARAR (Faz 13'te REVİZE EDİLDİ): bir görevi ancak ATANAN kişi, görev
     * atanmamışsa herkes, ya da `tasks.assign` taşıyan bir yönetici
     * tamamlayabilir — yalnız `tasks.update` artık yetmez.
     *
     * ÖNCEKİ KARAR ve NEDEN DEĞİŞTİ: burada eskiden "tamamlama `update`'in bir
     * alt kümesidir, o yüzden `tasks.update` yeterlidir" yazıyordu. Gerekçenin
     * dayandığı senaryo (yöneticinin ekip üyesinin görevini kapatabilmesi)
     * AYNEN korunuyor — Müdür ve Admin `tasks.assign` taşıdığı için fiilî
     * davranışları değişmiyor. Değişen tek şey, aynı rolü paylaşan iki
     * TEMSİLCİNİN birbirinin görevini kapatabilmesiydi; bu bir yetki değil bir
     * izolasyon boşluğuydu ve kapatıldı. "Tamamlama update'in alt kümesidir"
     * önermesi hâlâ geçerli, çünkü `update` de artık aynı yatay sınıra tabi.
     */
    public function complete(User $user, Task $task): bool
    {
        if (! $user->can('tasks.update')) {
            return false;
        }

        return $this->ownsOrManages($user, $task->assigned_to, 'tasks.assign');
    }
}
