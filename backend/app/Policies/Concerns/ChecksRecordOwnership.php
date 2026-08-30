<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * =============================================================================
 * YATAY YAZMA İZOLASYONU — "okuma düz, yazma sahiple sınırlı" (Model C)
 * =============================================================================
 *
 * KARAR: `update` / `move` / `convert` / `complete` / `status` gibi YAZMA
 * eylemleri için modül izni TEK BAŞINA yetmez; ayrıca kullanıcı ya kaydın
 * SAHİBİ olmalı, ya kayıt SAHİPSİZ olmalı, ya da modülün `*.assign` iznini
 * taşımalıdır. `view` / `viewAny` bu kuraldan MUAFTIR — okuma düz kalır.
 *
 * GEREKÇE (okumanın neden düz kaldığı): ürün gereksinimi paylaşılan realtime
 * bir Kanban panosu istiyor (PRODUCT-BRIEF.md:63) ve kayda bağlı
 * sohbet "kaydı görebilen herkes açar" diyor (a.g.e.:75); ayrıca `İzleyici`
 * rolü TÜM `.view` izinlerine sahip salt-okuma rolüdür. Yani düz okuma
 * bilinçli bir mimari zemindir, eksik olan şey rol-İÇİ yazma sınırıydı: aynı
 * rolü taşıyan iki temsilcinin birbirinin kaydını değiştirebilmesi. Bu trait
 * yalnızca o boşluğu kapatır; sorgu/repository katmanına HİÇ dokunmaz.
 *
 * GEREKÇE (`*.assign` neden "yönetici" sinyali): şemada `manager_id` ya da
 * departman kolonu yok; hiyerarşi bilgisi sistemde YALNIZCA izin matrisinde
 * duruyor. "Bir kaydı başkasına devredebilen kişi" ile "başkasının kaydını
 * düzenleyebilen kişi" pratikte aynı kümedir (Müdür/Admin), bu yüzden yeni
 * bir kolon ya da yeni bir izin uydurmak yerine mevcut `*.assign` izni
 * yönetici yetkisinin kaynağı olarak kullanılıyor.
 *
 * GEREKÇE (SAHİPSİZ kayıt neden yazılabilir): `owner_id`/`assigned_to`
 * nullable'dır ve havuza bırakılmış kayıt (atanmamış destek talebi, sahipsiz
 * import lead'i) bilinçli bir üründür — kimseye ait olmayan bir kaydı
 * kilitlemek onu yalnızca `*.assign` taşıyan kişilerin dokunabildiği ölü
 * kayda çevirirdi.
 */
trait ChecksRecordOwnership
{
    /**
     * Kullanıcı bu kayda YAZABİLİR mi? (İzin kontrolü AYRI yapılır — bu metot
     * yalnızca yatay sınırı yanıtlar.)
     *
     * @param  int|null  $ownerId  Kaydın sahiplik kolonu (`owner_id` / `assigned_to` / `user_id`).
     * @param  string  $assignPermission  Modülün yönetici sinyali (ör. `deals.assign`).
     */
    protected function ownsOrManages(User $user, ?int $ownerId, string $assignPermission): bool
    {
        if ($ownerId === null) {
            return true;
        }

        if ($ownerId === (int) $user->getKey()) {
            return true;
        }

        return $user->can($assignPermission);
    }
}
