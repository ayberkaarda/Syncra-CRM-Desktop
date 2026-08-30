<?php

namespace App\Services\Automation\Notifications;

use App\Models\AutomationRule;
use App\Notifications\CrmNotification;

/**
 * Faz 14 / İz F — `notification.send` eylemi bunu üretir.
 *
 * DÜZ METİN MODUNDA (anahtar+parametre DEĞİL) BİLİNÇLİ OLARAK: kuralın adı VE mesaj şablonu
 * yöneticinin (kural OLUŞTURUCUSUNUN) kendi yazdığı serbest metindir — tıpkı
 * `email_templates.body_html` (docs/PHASE-INTL.md §1.5: "Admin yazar, çevrilmez — kullanıcı
 * verisi") gibi. Anahtar moduna zorlamak (ör. sabit bir `notifications.automation.body`
 * cümlesi + parametre) BURADA YANLIŞ olurdu: cümlenin DİLİ değil, TAMAMI kullanıcı içeriğidir.
 *
 * Bu sınıf bilerek `App\Notifications\` DIŞINDA yaşıyor (bu şeridin dosya sahipliği
 * `app/Services/Automation/**`'e sınırlı; `app/Notifications/**` "mevcutları kullan,
 * değiştirme" kapsamındadır) — yalnızca soyut `CrmNotification`'ı miras alır, onu değiştirmez.
 */
class AutomationRuleNotification extends CrmNotification
{
    public static function make(AutomationRule $rule, int $recipientId, string $message, string $link): self
    {
        return new self(
            recipientId: $recipientId,
            notificationType: 'automation.rule_triggered',
            notificationLink: $link,
            meta: [
                'automation_rule_id' => (int) $rule->getKey(),
                'automation_rule_name' => $rule->name,
            ],
            notificationTitle: $rule->name,
            notificationBody: $message,
        );
    }
}
