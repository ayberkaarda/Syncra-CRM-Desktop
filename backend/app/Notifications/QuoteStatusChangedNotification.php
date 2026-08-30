<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Models\User;

/**
 * `quote.status_changed` — bir teklifin `status`'ü değiştiğinde
 * `App\Observers\Notifications\QuoteNotificationObserver` tarafından
 * teklifin OLUŞTURANINA (`created_by`) üretilir.
 *
 * Quote'ta ayrı bir "sahip/atanan" alanı yok — yalnızca `created_by` (bkz.
 * migration). Bu yüzden alıcı, DealController/TicketController'daki gibi bir
 * "owner"/"assignee" değil, teklifi oluşturan kullanıcıdır: durum
 * `sent → accepted/rejected/expired` değiştiğinde asıl ilgilenen kişi
 * teklifi hazırlayan kişidir.
 *
 * FAZ 14 / İz D — anahtar moduna dönüştürüldü. Durum ETİKETİ (Taslak/
 * Gönderildi/...) KULLANICI VERİSİ DEĞİL, UI metnidir — bu yüzden eskiden
 * olduğu gibi gönderim anında Türkçe render edilip parametreye KONULMAZ (o
 * dil donması olurdu); her durum için AYRI bir `body_*` anahtarı seçilir ve
 * cümle okuma anında okuyanın diliyle çözülür.
 */
class QuoteStatusChangedNotification extends CrmNotification
{
    public static function make(Quote $quote, string $fromStatus, ?User $actor): self
    {
        return new self(
            recipientId: (int) $quote->created_by,
            notificationType: 'quote.status_changed',
            titleKey: 'notifications.quote_status_changed.title',
            bodyKey: self::bodyKeyForStatus((string) $quote->status),
            params: [
                'quote_number' => (string) $quote->quote_number,
                // Yalnız `body_default` bunu kullanır (bilinmeyen durum, teoride
                // ulaşılmaz — QuoteStatusMachine::TRANSITIONS beş sabit durum
                // tanımlar); diğer anahtarlar için zararsız fazla parametredir.
                'status' => (string) $quote->status,
            ],
            notificationLink: '/quotes/'.$quote->getKey(),
            meta: [
                'quote_id' => (int) $quote->getKey(),
                'from_status' => $fromStatus,
                'to_status' => $quote->status,
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
            ],
        );
    }

    private static function bodyKeyForStatus(string $status): string
    {
        return match ($status) {
            'draft' => 'notifications.quote_status_changed.body_draft',
            'sent' => 'notifications.quote_status_changed.body_sent',
            'accepted' => 'notifications.quote_status_changed.body_accepted',
            'rejected' => 'notifications.quote_status_changed.body_rejected',
            'expired' => 'notifications.quote_status_changed.body_expired',
            default => 'notifications.quote_status_changed.body_default',
        };
    }
}
