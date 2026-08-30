<?php

namespace App\Policies;

use App\Models\AutomationRule;
use App\Models\User;
use App\Services\Automation\AutomationPermissionChecker;

/**
 * Faz 14 / İz F — C4 otomasyon kuralları (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.4).
 *
 * PHASE-AUDIT §5.4'ün İKİ KATMANLI kısıtının BİRİNCİ katmanı (YAZMA anı) buradadır: kural
 * listesi/ekranı `settings.manage` arkasındadır (diğer Ayarlar sekmeleriyle AYNI desen —
 * `CustomFieldController`/`ExchangeRateController` yorumuna bkz.), AMA `settings.manage`
 * TEK BAŞINA YETERLİ DEĞİLDİR: `create`/`update` AYRICA seçilen tetikleyici+eylemin
 * gerektirdiği izinleri aktörde arar (`AutomationPermissionChecker` — çalışma anı
 * yeniden-doğrulamasıyla AYNI kod yolu, bkz. o sınıfın dokümanı). Böylece `settings.manage`
 * sahibi ama ör. `deals.assign` TAŞIMAYAN bir Admin (varsayımsal — bugünkü seed'de Admin
 * zaten `deals.assign` taşıyor, ama rol matrisi Ayarlar'dan SONRADAN değiştirilebilir)
 * "aşamaya gelince sahibi değiştir" kuralı YAZAMAZ.
 */
class AutomationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function view(User $user, AutomationRule $rule): bool
    {
        return $user->can('settings.manage');
    }

    /**
     * @param  array<string, mixed>  $triggerConfig
     * @param  array<string, mixed>  $actionConfig
     */
    public function create(User $user, string $triggerType, array $triggerConfig, string $actionType, array $actionConfig): bool
    {
        if (! $user->can('settings.manage')) {
            return false;
        }

        return AutomationPermissionChecker::userMaySetUp($user, $triggerType, $actionType, $actionConfig);
    }

    /**
     * @param  array<string, mixed>  $actionConfig
     */
    public function update(User $user, AutomationRule $rule, string $triggerType, string $actionType, array $actionConfig): bool
    {
        if (! $user->can('settings.manage')) {
            return false;
        }

        return AutomationPermissionChecker::userMaySetUp($user, $triggerType, $actionType, $actionConfig);
    }

    /**
     * Aç/kapa (`is_active`) — kuralın gövdesini DEĞİŞTİRMEZ, bu yüzden `update()`'in
     * eylem-izni kontrolünü TEKRARLAMAZ: kuralı zaten yetkiliyken yazan biri var; onu
     * geçici olarak durdurmak/başlatmak yeni bir yetki gerektirmez.
     */
    public function toggle(User $user, AutomationRule $rule): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, AutomationRule $rule): bool
    {
        return $user->can('settings.manage');
    }
}
