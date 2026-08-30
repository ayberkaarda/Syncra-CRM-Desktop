<?php

namespace App\Services\Automation;

use App\Services\Automation\Rules\AllowedPlaceholdersRule;
use InvalidArgumentException;

/**
 * Faz 14 / İz F — C4 SABİT katalog (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.1/§5.4).
 *
 * TEK doğruluk kaynağı: 3 tetikleyici + 3 eylem, İKİSİ DE SABİT. Keyfi kod/ifade dili/AI/
 * kullanıcı-tanımlı koşul YOK — bu sınıf bilinçli olarak "genişletilebilir bir motor" DEĞİL,
 * kapalı bir `match` tablosudur. Yeni bir tetikleyici/eylem eklemek isteyen biri BURAYI
 * (ve yalnızca burayı + ilgili çalıştırıcıyı) değiştirir; şema bir migration'da YAŞAMAZ.
 *
 * ---------------------------------------------------------------------------
 * İZİN EŞLEMESİ — PHASE-AUDIT §5.4'ün "bağlayıcı güvenlik kısıtı" karşılığı
 * ---------------------------------------------------------------------------
 * Eşleme UYDURULMADI: aşağıdaki her izin adı `database/seeders/RolePermissionSeeder.php`
 * içindeki `$permissions` sözlüğünden BİREBİR alındı (deals.view, deals.assign, tasks.create,
 * tasks.assign, tickets.view, users.view — hepsi orada var, yeni bir izin İCAT EDİLMEDİ).
 *
 * TETİKLEYİCİ İZNİ: bir kural yalnızca GÖREBİLDİĞİ bir olaya tepki verebilir — `deal.*`
 * tetikleyicileri `deals.view`, `ticket.created` `tickets.view` ister. Bu, eylemin kendi
 * izninden AYRI ve İLAVE bir kontrol (aşağıda `requiredActionPermissions()` ile birleşir).
 *
 * EYLEM İZNİ — her satırın gerekçesi:
 *   - `task.create`  → HER ZAMAN `tasks.create` + `tasks.assign` ister. Görünüşte
 *     "kaydın sahibine görev ata" seçeneği zararsız görünebilir ama hedef kullanıcı kuralın
 *     YAZARI OLMAK ZORUNDA DEĞİLDİR (tetikleyici HERHANGİ bir kayıt için ateşlenir) — yani bu,
 *     `ForcesRecordOwnerOnCreate`'in "assign izni yoksa görev SANA yazılır" güvenlik ağını
 *     dolanan, sahibi rastgele biri olan bir görev yaratma yoludur. Bu yüzden hem "sabit
 *     kullanıcı" hem "kaydın sahibi" seçeneği `tasks.assign` gerektirir — PHASE-AUDIT §5.4'ün
 *     örneğiyle (deals.assign) BİREBİR AYNI mantık, yalnız modül farklı.
 *   - `notification.send` → alıcı `kaydın sahibi` ise ek izin YOK (sahip zaten o kaydın
 *     `.view` iznine sahip HERKESE görünen alenî bir alandır — bir bildirim göndermek yeni bir
 *     bilgi SIZDIRMAZ). Alıcı SABİT bir kullanıcıysa `users.view` ister — rastgele bir
 *     kullanıcıyı HEDEF olarak seçebilmek, o kullanıcının var olduğunu/kim olduğunu
 *     görebilmeyi gerektirir (aynı gerekçe uygulamanın başka yerlerinde de: bir kullanıcıyı
 *     ata/seç ekranları `users.view`'a dayanır).
 *   - `deal.assign_owner` → PHASE-AUDIT §5.4'ün AÇIKÇA VERDİĞİ örnek: `deals.assign` şart.
 *
 * ---------------------------------------------------------------------------
 * EYLEM/TETİKLEYİCİ UYUMLULUĞU
 * ---------------------------------------------------------------------------
 * `deal.assign_owner` yalnızca `deal.*` tetikleyicileriyle anlamlıdır (bir `ticket.created`
 * kuralının "fırsat sahibini değiştirmesi" kavramsal olarak tanımsızdır — hangi fırsat?).
 * `task.create`/`notification.send` her üç tetikleyiciyle de çalışır (ikisi de "kayıt" ve
 * "sahip" kavramına geneldir).
 */
final class AutomationCatalog
{
    public const TRIGGER_DEAL_STAGE_CHANGED = 'deal.stage_changed';

    public const TRIGGER_DEAL_STATUS_CHANGED = 'deal.status_changed';

    public const TRIGGER_TICKET_CREATED = 'ticket.created';

    /** @var list<string> */
    public const TRIGGERS = [
        self::TRIGGER_DEAL_STAGE_CHANGED,
        self::TRIGGER_DEAL_STATUS_CHANGED,
        self::TRIGGER_TICKET_CREATED,
    ];

    public const ACTION_TASK_CREATE = 'task.create';

    public const ACTION_NOTIFICATION_SEND = 'notification.send';

    public const ACTION_DEAL_ASSIGN_OWNER = 'deal.assign_owner';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_TASK_CREATE,
        self::ACTION_NOTIFICATION_SEND,
        self::ACTION_DEAL_ASSIGN_OWNER,
    ];

    /**
     * Başlık/mesaj şablonlarında izin verilen TEK placeholder kümesi — serbest ifade
     * değerlendirmesi YOK, yalnızca bu adların `{ad}` biçiminde birebir metin değişimi.
     * `stage_name` yalnız `deal.stage_changed` bağlamında, `status_label` yalnız
     * `deal.status_changed`, `priority_label` yalnız `ticket.created` bağlamında DOLU
     * gelir; uyumsuz bağlamda kullanılırsa render anında boş dizeye çözülür (silinir),
     * hata VERMEZ — bkz. AutomationTemplateRenderer.
     *
     * @var list<string>
     */
    public const TITLE_PLACEHOLDERS = ['record_title', 'stage_name', 'status_label', 'priority_label'];

    /**
     * @return array<string, list<string>>
     */
    public static function triggerConfigRules(string $triggerType): array
    {
        return match ($triggerType) {
            self::TRIGGER_DEAL_STAGE_CHANGED => [
                'pipeline_stage_id' => ['required', 'integer', 'exists:pipeline_stages,id'],
            ],
            self::TRIGGER_DEAL_STATUS_CHANGED => [
                'status' => ['required', 'string', 'in:won,lost'],
            ],
            self::TRIGGER_TICKET_CREATED => [
                'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            ],
            default => throw new InvalidArgumentException("Bilinmeyen tetikleyici: {$triggerType}"),
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function actionConfigRules(string $actionType): array
    {
        return match ($actionType) {
            self::ACTION_TASK_CREATE => [
                'title_template' => ['required', 'string', 'max:255', new AllowedPlaceholdersRule(self::TITLE_PLACEHOLDERS)],
                'assignee_type' => ['required', 'string', 'in:record_owner,fixed_user'],
                // NOT: `required_if` alan yolu TAM NİTELİKLİ olmalı
                // (`action_config.assignee_type`) — bare `assignee_type` kök
                // veride yok, koşul asla eşleşmez (ölçülerek bulundu).
                'assignee_user_id' => ['required_if:action_config.assignee_type,fixed_user', 'nullable', 'integer', 'exists:users,id'],
                'due_in_days' => ['required', 'integer', 'min:0', 'max:365'],
            ],
            self::ACTION_NOTIFICATION_SEND => [
                'message_template' => ['required', 'string', 'max:500', new AllowedPlaceholdersRule(self::TITLE_PLACEHOLDERS)],
                'recipient_type' => ['required', 'string', 'in:record_owner,fixed_user'],
                'recipient_user_id' => ['required_if:action_config.recipient_type,fixed_user', 'nullable', 'integer', 'exists:users,id'],
            ],
            self::ACTION_DEAL_ASSIGN_OWNER => [
                'user_id' => ['required', 'integer', 'exists:users,id'],
            ],
            default => throw new InvalidArgumentException("Bilinmeyen eylem: {$actionType}"),
        };
    }

    public static function actionCompatibleWithTrigger(string $actionType, string $triggerType): bool
    {
        if ($actionType === self::ACTION_DEAL_ASSIGN_OWNER) {
            return in_array($triggerType, [self::TRIGGER_DEAL_STAGE_CHANGED, self::TRIGGER_DEAL_STATUS_CHANGED], true);
        }

        return true;
    }

    public static function triggerPermission(string $triggerType): string
    {
        return match ($triggerType) {
            self::TRIGGER_DEAL_STAGE_CHANGED, self::TRIGGER_DEAL_STATUS_CHANGED => 'deals.view',
            self::TRIGGER_TICKET_CREATED => 'tickets.view',
            default => throw new InvalidArgumentException("Bilinmeyen tetikleyici: {$triggerType}"),
        };
    }

    /**
     * Bir eylemin GEREKTİRDİĞİ TÜM izinler — `action_config`'e bağlı olarak değişebilir
     * (ör. `fixed_user` seçilince `users.view` eklenir). Dönen liste tetikleyici izniyle
     * BİRLEŞTİRİLMEDEN önce çağıranın (AutomationPermissionChecker) sorumluluğundadır.
     *
     * @param  array<string, mixed>  $actionConfig
     * @return list<string>
     */
    public static function requiredActionPermissions(string $actionType, array $actionConfig): array
    {
        return match ($actionType) {
            self::ACTION_TASK_CREATE => ['tasks.create', 'tasks.assign'],
            self::ACTION_NOTIFICATION_SEND => ($actionConfig['recipient_type'] ?? null) === 'fixed_user'
                ? ['users.view']
                : [],
            self::ACTION_DEAL_ASSIGN_OWNER => ['deals.assign'],
            default => throw new InvalidArgumentException("Bilinmeyen eylem: {$actionType}"),
        };
    }
}
