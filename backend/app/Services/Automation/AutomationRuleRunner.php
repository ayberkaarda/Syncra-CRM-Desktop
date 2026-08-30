<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;

/**
 * Faz 14 / İz F — aktif kuralları bir tetikleyici olayla eşleştirir, ÇALIŞMA ANI izin
 * kontrolünü (PHASE-AUDIT §5.4'ün ikinci katmanı) yeniden yapar ve eşleşen/izinli her
 * kural için `AutomationActionExecutor`'ı çağırır.
 *
 * ÇAĞIRAN TARAF (`app/Listeners/Automation/**`) `AutomationExecutionGuard::run()` İÇİNDEN
 * çağırmalıdır — bu sınıf kendi başına guard AÇMAZ, yalnızca listener'ların ortak
 * kullandığı tek giriş noktasıdır (guard'ın TEK yerde açılıp kapanması için).
 */
final class AutomationRuleRunner
{
    public static function handleDealStageChanged(Deal $deal, int $toStageId): void
    {
        $rules = AutomationRule::query()->active()->forTrigger(AutomationCatalog::TRIGGER_DEAL_STAGE_CHANGED)->get();

        if ($rules->isEmpty()) {
            return;
        }

        $stageName = PipelineStage::find($toStageId)?->name;

        foreach ($rules as $rule) {
            if ((int) ($rule->trigger_config['pipeline_stage_id'] ?? 0) !== $toStageId) {
                continue;
            }

            self::runForDeal($rule, $deal, stageName: $stageName);
        }
    }

    public static function handleDealStatusChanged(Deal $deal): void
    {
        if (! in_array($deal->status, ['won', 'lost'], true)) {
            return;
        }

        $rules = AutomationRule::query()->active()->forTrigger(AutomationCatalog::TRIGGER_DEAL_STATUS_CHANGED)->get();

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($rules as $rule) {
            if (($rule->trigger_config['status'] ?? null) !== $deal->status) {
                continue;
            }

            self::runForDeal($rule, $deal, statusLabel: $deal->status);
        }
    }

    public static function handleTicketCreated(Ticket $ticket): void
    {
        $rules = AutomationRule::query()->active()->forTrigger(AutomationCatalog::TRIGGER_TICKET_CREATED)->get();

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($rules as $rule) {
            $priorityFilter = $rule->trigger_config['priority'] ?? null;

            if ($priorityFilter !== null && $priorityFilter !== $ticket->priority) {
                continue;
            }

            self::runForTicket($rule, $ticket);
        }
    }

    private static function runForDeal(AutomationRule $rule, Deal $deal, ?string $stageName = null, ?string $statusLabel = null): void
    {
        $context = new AutomationContext(
            morphType: 'deal',
            recordId: (int) $deal->getKey(),
            recordTitle: (string) $deal->title,
            ownerId: $deal->owner_id === null ? null : (int) $deal->owner_id,
            link: '/deals/'.$deal->getKey(),
            stageName: $stageName,
            statusLabel: $statusLabel,
        );

        self::execute($rule, $context);
    }

    private static function runForTicket(AutomationRule $rule, Ticket $ticket): void
    {
        $context = new AutomationContext(
            morphType: 'ticket',
            recordId: (int) $ticket->getKey(),
            recordTitle: (string) $ticket->subject,
            ownerId: $ticket->assigned_to === null ? null : (int) $ticket->assigned_to,
            link: '/tickets/'.$ticket->getKey(),
            priorityLabel: $ticket->priority,
        );

        self::execute($rule, $context);
    }

    /**
     * PHASE-AUDIT §5.4 — ÇALIŞMA ANI YENİDEN DOĞRULAMA: kuralın YAZARININ izni kural
     * YAZILDIKTAN SONRA düşürülmüş olabilir (rol değişikliği, izin geri alma). Yazma
     * anındaki kontrol (`AutomationRulePolicy`) kalıcı değildir — burada, HER tetiklenmede,
     * `created_by` kullanıcısının GÜNCEL izniyle yeniden kontrol edilir. İzin artık yoksa
     * eylem SESSİZCE YUTULMAZ: `warning` loglanır ve çalıştırılmaz.
     */
    private static function execute(AutomationRule $rule, AutomationContext $context): void
    {
        $creator = $rule->creator;

        if ($creator === null) {
            Log::warning('automation.execute.creator_missing', ['automation_rule_id' => $rule->getKey()]);

            return;
        }

        $missing = AutomationPermissionChecker::missingPermissions(
            $creator,
            $rule->trigger_type,
            $rule->action_type,
            $rule->action_config,
        );

        if ($missing !== []) {
            Log::warning('automation.execute.permission_revoked', [
                'automation_rule_id' => $rule->getKey(),
                'automation_rule_name' => $rule->name,
                'created_by' => $creator->getKey(),
                'trigger_type' => $rule->trigger_type,
                'action_type' => $rule->action_type,
                'missing_permissions' => $missing,
            ]);

            return;
        }

        AutomationActionExecutor::execute($rule, $creator, $context);
    }
}
