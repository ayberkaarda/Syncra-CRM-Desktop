<?php

namespace App\Notifications\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Spatie\Activitylog\ActivityLogStatus;

/**
 * Tek çıkış kapısı: her observer/listener bildirim göndermek için BUNU
 * çağırır, `$recipient->notify(...)`'ı doğrudan hiçbir yerden çağırmaz.
 * Böylece "kendine bildirim gitmez / pasif kullanıcıya gitmez / toplu
 * içe aktarmada susturulur" kuralları TEK yerde uygulanır.
 *
 * ---------------------------------------------------------------------------
 * TOPLU İŞLEM SUSTURMASI — MEVCUT MEKANİZMANIN YENİDEN KULLANIMI
 * ---------------------------------------------------------------------------
 * `App\Services\Leads\LeadImportService::process()` (Faz 6/B, bu şeridin
 * SAHİPLİĞİ DIŞINDA) bir CSV import'u boyunca
 * `app(ActivityLogStatus::class)->disable()` çağırıp `finally` bloğunda
 * `enable()` ile geri açıyor — gerekçe: yüzlerce satırlık bir import'un
 * `activity_log`'u tek tek doldurmaması, tek bir özet satırla temsil
 * edilmesi (bkz. LeadImportService dokümanı "AUDIT GÜRÜLTÜSÜ").
 *
 * Bu, Faz 10 görev tanımının istediği "toplu bağlamda bildirim yağmuru
 * olmasın" sinyaliyle BİREBİR AYNI sinyaldir: import döngüsü Eloquent
 * `Lead::create()`/`update()` kullanıyor (ham `insert()` değil), yani
 * `LeadNotificationObserver` her satırda normal şekilde tetiklenir. Bu
 * paket-genelinde, `scoped()` (istek/job ömürlü) bir bayrak olduğu için
 * `LeadImportService`'e TEK BİR SATIR bile dokunmadan, üçüncü bir global
 * değişken icat etmeden, aynı toggle'ı burada OKUYARAK 500 bildirimlik bir
 * import'un aynı anda 500 bildirim üretmesini engelliyoruz.
 *
 * `DemoDataSeeder` bu kontrole hiç ihtiyaç duymaz: orada `DB::table(...)
 * ->insert()` ham sorgu kullanılıyor, Eloquent event'leri hiç fırlamıyor,
 * dolayısıyla hiçbir observer/listener zaten çalışmıyor.
 */
final class NotificationDispatcher
{
    public static function send(?User $recipient, ?User $actor, Notification $notification): void
    {
        if ($recipient === null || ! $recipient->is_active) {
            return;
        }

        // Eylemi yapan kişi = alıcı ise bildirim üretilmez.
        if ($actor !== null && (int) $actor->getKey() === (int) $recipient->getKey()) {
            return;
        }

        if (app(ActivityLogStatus::class)->disabled()) {
            return;
        }

        $recipient->notify($notification);
    }
}
