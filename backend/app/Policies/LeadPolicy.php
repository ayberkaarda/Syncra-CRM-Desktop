<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Policies\Concerns\ChecksRecordOwnership;

class LeadPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(User $user): bool
    {
        return $user->can('leads.view');
    }

    /**
     * Okuma BİLEREK düz (bkz. ChecksRecordOwnership dokümanı).
     */
    public function view(User $user, Lead $lead): bool
    {
        return $user->can('leads.view');
    }

    public function create(User $user): bool
    {
        return $user->can('leads.create');
    }

    /**
     * Dönüşmüş bir lead güncellenemez — dönüşüm izi bozulmasın.
     *
     * Ayrıca yatay yazma izolasyonu: sahip / sahipsiz / `leads.assign`
     * (gerekçe: ChecksRecordOwnership). Havuza atılmış (sahipsiz) import
     * lead'leri herkesin işleyebilmesi bilinçli olarak korunuyor.
     */
    public function update(User $user, Lead $lead): bool
    {
        if (! $user->can('leads.update')) {
            return false;
        }

        if (! $this->ownsOrManages($user, $lead->owner_id, 'leads.assign')) {
            return false;
        }

        return $lead->status !== 'converted';
    }

    /**
     * Dönüşmüş bir lead silinemez — dönüşüm izi bozulmasın.
     *
     * Silmede sahiplik kontrolü YOK: `leads.delete` bugünkü izin matrisinde
     * yalnızca Müdür/Admin/Super Admin'de ve bu roller zaten `leads.assign`
     * taşıyor — kontrol no-op olurdu.
     */
    public function delete(User $user, Lead $lead): bool
    {
        if (! $user->can('leads.delete')) {
            return false;
        }

        return $lead->status !== 'converted';
    }

    /**
     * Dönüşüm, lead'i TÜKETEN tek yönlü ve geri alınamaz bir yazmadır: geriye
     * contact/company/deal üretir ve lead'i `converted` durumuna kilitler.
     * Bu yüzden `update` ile aynı yatay sınıra tabidir — başkasının lead'ini
     * dönüştürmek, o kişinin pipeline'ına kendi adına deal açmak demektir.
     */
    public function convert(User $user, Lead $lead): bool
    {
        if (! $user->can('leads.convert')) {
            return false;
        }

        return $this->ownsOrManages($user, $lead->owner_id, 'leads.assign');
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $user->can('leads.assign');
    }

    public function import(User $user): bool
    {
        return $user->can('leads.import');
    }
}
