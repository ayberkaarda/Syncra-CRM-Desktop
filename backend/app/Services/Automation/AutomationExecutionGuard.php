<?php

namespace App\Services\Automation;

use Closure;

/**
 * Faz 14 / İz F — sonsuz döngü koruması (görev tanımı: "`deal.assign_owner` eylemi yeni
 * bir deal event'i doğurabilir → aynı istek içinde otomasyon zincirinin yeniden
 * tetiklenmesini engelle; basit bir 'otomasyon içinde çalışıyorum' bayrağı yeter").
 *
 * BİLİNÇLİ OLARAK BASİT: istek/işlem ömürlü bir statik bayrak (worker/kuyruk süreci PHP
 * her isteği ayrı bir process'te çalıştırır — Octane/uzun-yaşayan worker bu projede
 * KULLANILMIYOR, bkz. `NotificationDispatcher`'ın aynı varsayımı). Sabit katalogdaki 3
 * eylemden yalnızca `deal.assign_owner` bir Eloquent güncellemesi (`owner_id`) üretir ve
 * bu alan hiçbir tetikleyicinin (`stage_changed`/`status_changed`/`ticket.created`) koşulu
 * DEĞİLDİR — yani bugünkü sabit katalogla gerçek bir sonsuz döngü YOLU yoktur. Guard yine
 * de savunma amaçlı (katalog ileride genişlerse, ya da bir gözden kaçan zincir varsa)
 * VE testle kilitlenir (bkz. `RunAutomationRulesOnDealMoved`/Deal listener'larının hepsi
 * bunu tek giriş noktası olarak kullanır).
 */
final class AutomationExecutionGuard
{
    private static bool $running = false;

    public static function isRunning(): bool
    {
        return self::$running;
    }

    /**
     * `$callback` yalnızca ZATEN çalışan bir otomasyon YOKSA çalıştırılır; iç içe (re-entrant)
     * bir çağrı sessizce YUTULUR (no-op) — bir istisna fırlatmak, otomasyonun tetiklediği asıl
     * kullanıcı isteğini (ör. `PATCH /api/deals/{id}/move`) da başarısız kılardı.
     */
    public static function run(Closure $callback): void
    {
        if (self::$running) {
            return;
        }

        self::$running = true;

        try {
            $callback();
        } finally {
            self::$running = false;
        }
    }
}
