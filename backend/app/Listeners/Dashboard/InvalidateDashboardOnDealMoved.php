<?php

namespace App\Listeners\Dashboard;

use App\Events\DashboardInvalidated;
use App\Events\DealMoved;
use Illuminate\Support\Facades\Cache;

/**
 * `DealMoved` her kart taşımada fırlar — ama bir kullanıcı Kanban panosunu
 * "temizlerken" (ör. bir gün içinde toplanmış 12 kartı art arda sürükleyip
 * kapatırken) 12 ayrı `DashboardInvalidated` yayını gereksiz bir olay
 * yağmuru olurdu: her biri aynı üç widget'ı ("kpis", "funnel",
 * "revenue-trend") zaten bayat işaretler, sonuncusundan öncekiler tamamen
 * anlamsızdır.
 *
 * KAYIT: Manuel `Event::listen(...)` ÇAĞRISI YOK. Metodun adı `handle` ve
 * ilk parametresi `DealMoved` olarak tip belirtildiği için Laravel'in
 * otomatik olay keşfi (varsayılan açık — bkz. App\Listeners\
 * LogSuccessfulLogin'in docblock'u, aynı mekanizmayı KASITLI OLARAK
 * devre dışı bırakan tek örnek budur) bu sınıfı DealMoved'a otomatik
 * bağlar. `app/Providers/AppServiceProvider.php`'ye (başka bir şeridin
 * sahipliğinde) dokunulmadı; yeni bir EventServiceProvider da açılmadı —
 * Laravel 12'de keşif için gerekmiyor.
 *
 * THROTTLE: `Cache::add()` atomik "yoksa yaz" ile leading-edge debounce.
 * Bir DealMoved olayı geldiğinde anahtar YOKSA hemen yayınlanır VE anahtar
 * kısa bir TTL ile yazılır; TTL süresince gelen sonraki DealMoved'lar
 * `Cache::add()`'den `false` alır ve sessizce atlanır. TTL dolunca bir
 * sonraki hareket yeniden yayınlar. Bu, "12 taşımaya 1 yayın" davranışını
 * kalıcı bir job/kuyruk kurmadan, tek bir cache anahtarıyla verir.
 */
class InvalidateDashboardOnDealMoved
{
    /**
     * Aynı burst içindeki taşımaları tek bir yayında toplamak için yeterince
     * uzun (kullanıcı art arda birkaç kartı hızlıca sürüklerken hepsini
     * yutar), ama dashboard'u canlı tutmak için yeterince kısa (bkz.
     * CANLILIK gereksinimi — bayatlık birkaç saniyeden uzun sürmemeli).
     */
    private const THROTTLE_SECONDS = 3;

    private const THROTTLE_CACHE_KEY = 'dashboard:invalidate:throttle';

    /**
     * @var array<int, string>
     */
    private const INVALIDATED_KEYS = ['kpis', 'funnel', 'revenue-trend'];

    public function handle(DealMoved $event): void
    {
        if (Cache::add(self::THROTTLE_CACHE_KEY, 1, now()->addSeconds(self::THROTTLE_SECONDS))) {
            broadcast(new DashboardInvalidated(self::INVALIDATED_KEYS));
        }
    }
}
