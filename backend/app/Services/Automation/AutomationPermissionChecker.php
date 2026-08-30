<?php

namespace App\Services\Automation;

use App\Models\User;

/**
 * Faz 14 / İz F — PHASE-AUDIT §5.4'ün İKİ KATMANLI kısıtının ORTAK çekirdeği: hem
 * YAZMA anında (`AutomationRulePolicy`) hem ÇALIŞMA anında (`AutomationRuleRunner`) AYNI
 * kontrol kullanılır — iki yerde iki farklı mantık yazılsaydı biri güncellenip diğeri
 * unutulduğunda tam da "yazma-anı kontrolü kalıcı bir yetki yükseltmesi bırakır" hatası
 * BAŞKA bir şekilde geri gelirdi.
 */
final class AutomationPermissionChecker
{
    /**
     * @param  array<string, mixed>  $actionConfig
     * @return list<string> eksik izinler — boş dizi = kullanıcı bu kuralı kurmaya/çalıştırmaya yetkili
     */
    public static function missingPermissions(User $user, string $triggerType, string $actionType, array $actionConfig): array
    {
        $required = array_values(array_unique(array_merge(
            [AutomationCatalog::triggerPermission($triggerType)],
            AutomationCatalog::requiredActionPermissions($actionType, $actionConfig),
        )));

        return array_values(array_filter($required, static fn (string $permission): bool => ! $user->can($permission)));
    }

    /**
     * @param  array<string, mixed>  $actionConfig
     */
    public static function userMaySetUp(User $user, string $triggerType, string $actionType, array $actionConfig): bool
    {
        return self::missingPermissions($user, $triggerType, $actionType, $actionConfig) === [];
    }
}
