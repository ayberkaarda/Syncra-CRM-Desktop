<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Deal;
use App\Models\User;
use App\Notifications\Support\NotificationDispatcher;
use App\Services\Automation\Notifications\AutomationRuleNotification;
use App\Services\Deals\DealService;
use App\Services\Tasks\TaskService;
use Illuminate\Support\Facades\Log;

/**
 * Faz 14 / İz F — sabit 3 eylemin GERÇEK yürütücüsü. `AutomationRuleRunner` çalışma-anı
 * izin kontrolünü ZATEN yaptıktan SONRA burayı çağırır — bu sınıf izin kontrolü YAPMAZ,
 * yalnızca yürütür (tek sorumluluk).
 *
 * Mevcut servisler (`TaskService::create()`, `DealService::assign()`) ÜZERİNE oturur —
 * bu servisler değiştirilmedi, yalnızca çağrıldı (görev tanımı: "Faz 10 zaten
 * observer/listener tabanlı bildirim tetikliyor — o altyapının ÜSTÜNE otur").
 */
final class AutomationActionExecutor
{
    public static function execute(AutomationRule $rule, User $creator, AutomationContext $context): void
    {
        match ($rule->action_type) {
            AutomationCatalog::ACTION_TASK_CREATE => self::createTask($rule, $creator, $context),
            AutomationCatalog::ACTION_NOTIFICATION_SEND => self::sendNotification($rule, $creator, $context),
            AutomationCatalog::ACTION_DEAL_ASSIGN_OWNER => self::assignDealOwner($rule, $context),
            default => Log::warning('automation.unknown_action', [
                'automation_rule_id' => $rule->getKey(),
                'action_type' => $rule->action_type,
            ]),
        };
    }

    private static function createTask(AutomationRule $rule, User $creator, AutomationContext $context): void
    {
        $config = $rule->action_config;

        $assigneeId = ($config['assignee_type'] ?? null) === 'fixed_user'
            ? (int) ($config['assignee_user_id'] ?? 0)
            : $context->ownerId;

        // "Kaydın sahibi" seçiliyken kaydın sahibi yoksa atanacak kimse yok — sessizce
        // atlanır (görev sahipsiz açılırsa herkesin yazabildiği bir kayıt olurdu, bkz.
        // ForcesRecordOwnerOnCreate'in aynı gerekçesi); bu bir hata değil, beklenen bir sınır.
        if ($assigneeId === null || $assigneeId <= 0) {
            Log::info('automation.task_create.skipped_no_assignee', ['automation_rule_id' => $rule->getKey()]);

            return;
        }

        $title = AutomationTemplateRenderer::render((string) $config['title_template'], $context->placeholderValues());
        $dueInDays = (int) ($config['due_in_days'] ?? 0);

        app(TaskService::class)->create([
            'title' => $title,
            'due_at' => now()->addDays($dueInDays),
            'assigned_to' => $assigneeId,
            'taskable_type' => $context->morphType,
            'taskable_id' => $context->recordId,
        ], $creator->getKey());
    }

    private static function sendNotification(AutomationRule $rule, User $creator, AutomationContext $context): void
    {
        $config = $rule->action_config;

        $recipientId = ($config['recipient_type'] ?? null) === 'fixed_user'
            ? (int) ($config['recipient_user_id'] ?? 0)
            : $context->ownerId;

        if ($recipientId === null || $recipientId <= 0) {
            Log::info('automation.notification_send.skipped_no_recipient', ['automation_rule_id' => $rule->getKey()]);

            return;
        }

        $recipient = User::find($recipientId);

        if ($recipient === null) {
            return;
        }

        $message = AutomationTemplateRenderer::render((string) $config['message_template'], $context->placeholderValues());

        NotificationDispatcher::send(
            $recipient,
            $creator,
            AutomationRuleNotification::make($rule, (int) $recipient->getKey(), $message, $context->link),
        );
    }

    private static function assignDealOwner(AutomationRule $rule, AutomationContext $context): void
    {
        if ($context->morphType !== 'deal') {
            // AutomationCatalog::actionCompatibleWithTrigger() yazma anında bunu zaten
            // engeller — buraya asla ulaşmamalı; ulaşırsa sessizce yutmak yerine loglanır.
            Log::warning('automation.assign_owner.incompatible_context', [
                'automation_rule_id' => $rule->getKey(),
                'morph_type' => $context->morphType,
            ]);

            return;
        }

        $deal = Deal::find($context->recordId);

        if ($deal === null) {
            return;
        }

        app(DealService::class)->assign($deal, (int) $rule->action_config['user_id']);
    }
}
