<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\User;
use App\Notifications\Support\Money;

/**
 * `deal.assigned` — bir fırsatın `owner_id`'si yeni (ve dolu) bir kullanıcıya
 * ayarlandığında `App\Observers\Notifications\DealNotificationObserver`
 * tarafından üretilir.
 *
 * FAZ 14 / İZ D — ANAHTAR MODUNA DÖNÜŞTÜRÜLEN İKİ ÖRNEKTEN BİRİ (diğeri
 * `TaskAssignedNotification`). Kalan dokuz tip düz metin modunda kalır ve
 * `NotificationText`in geriye dönük uyum yolundan geçer; dönüşüm kademelidir.
 */
class DealAssignedNotification extends CrmNotification
{
    public static function make(Deal $deal, ?User $actor): self
    {
        return new self(
            recipientId: (int) $deal->owner_id,
            notificationType: 'deal.assigned',
            titleKey: 'notifications.deal_assigned.title',
            bodyKey: 'notifications.deal_assigned.body',
            params: [
                // `subject`: firma adı, yoksa fırsat başlığı. İkisi de KULLANICI VERİSİDİR ve
                // çevrilmez (PHASE-INTL §1.5 sınırı) — parametre olarak taşınması tam da bu
                // yüzden doğru: cümle çevrilir, içindeki isim olduğu gibi kalır.
                'subject' => (string) ($deal->company?->name ?? $deal->title),
                // BİLİNÇLİ SINIR: tutar gönderim anında biçimlendirilir. Ayraç dile bağlıdır,
                // ama para birimi ekseninin (simge/kod/görüntüleme dönüşümü) sahibi İz E'dir
                // (PHASE-INTL §2). Cümlenin DİLİ okuma anında doğru olur; yalnız sayının
                // ayracı gönderim anındaki biçimde kalır — bkz. NotificationText::resolveParams().
                'amount' => Money::format((string) $deal->amount, (string) $deal->currency),
            ],
            notificationLink: '/deals/'.$deal->getKey(),
            meta: [
                'deal_id' => (int) $deal->getKey(),
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
            ],
        );
    }
}
