<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;
use App\Policies\Concerns\ChecksRecordOwnership;

class DealPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(User $user): bool
    {
        return $user->can('deals.view');
    }

    /**
     * Okuma BİLEREK düz: pipeline paylaşılan bir panodur, ekip birbirinin
     * kartını görebilmelidir (bkz. ChecksRecordOwnership dokümanı).
     */
    public function view(User $user, Deal $deal): bool
    {
        return $user->can('deals.view');
    }

    public function create(User $user): bool
    {
        return $user->can('deals.create');
    }

    /**
     * Yatay yazma izolasyonu: `deals.update` izni TEK BAŞINA yetmez —
     * kullanıcı deal'in sahibi olmalı, deal sahipsiz olmalı ya da kullanıcı
     * `deals.assign` taşımalıdır (gerekçe: ChecksRecordOwnership).
     */
    public function update(User $user, Deal $deal): bool
    {
        if (! $user->can('deals.update')) {
            return false;
        }

        return $this->ownsOrManages($user, $deal->owner_id, 'deals.assign');
    }

    /**
     * Kazanılmış (`won`) veya kaybedilmiş (`lost`) bir deal silinemez —
     * kapanmış iş kaydı analitiğin parçasıdır, soft delete olsa bile
     * raporlardan düşer.
     *
     * 403 (izin reddi ile aynı kanal) tercih edildi, 422 değil: proje
     * genelinde "durumu nedeniyle silinemez" kuralı zaten LeadPolicy::delete()
     * içinde aynı desenle uygulanıyor (dönüştürülmüş lead silinemez -> false
     * -> Gate::authorize AuthorizationException fırlatır -> 403). Yetki
     * kararını tek yerde (Policy) toplamak, "kim silebilir" ile "hangi kayıt
     * silinebilir" mantığını Controller/Service katmanına bölünmüş ayrı bir
     * 422 kuralına dağıtmaktan daha tutarlıdır.
     *
     * Silme KASITLI olarak yatay sınırın DIŞINDA: `deals.delete` bugünkü izin
     * matrisinde yalnızca Müdür/Admin/Super Admin'de; sahiplik kontrolü eklemek
     * no-op olurdu (bu roller zaten `deals.assign` taşıyor).
     */
    public function delete(User $user, Deal $deal): bool
    {
        if (! $user->can('deals.delete')) {
            return false;
        }

        return ! in_array($deal->status, ['won', 'lost'], true);
    }

    /**
     * `PATCH /api/deals/{deal}/move` — controller'ı ayrı (DealMoveController),
     * ama yetki kararı burada, tek DealPolicy'de.
     *
     * `update` ile AYNI yatay sınır: temsilci panoda herkesin kartını GÖRÜR,
     * ama yalnızca kendi (ya da sahipsiz) kartını TAŞIYABİLİR. Aksi halde bir
     * temsilci, başkasının deal'ini "kazanıldı" aşamasına sürükleyip onun
     * rakamını ve raporunu değiştirebilirdi.
     */
    public function move(User $user, Deal $deal): bool
    {
        if (! $user->can('deals.move')) {
            return false;
        }

        return $this->ownsOrManages($user, $deal->owner_id, 'deals.assign');
    }

    public function assign(User $user, Deal $deal): bool
    {
        return $user->can('deals.assign');
    }
}
