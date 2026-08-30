<?php

namespace Database\Factories;

use App\Models\AutomationRule;
use App\Models\User;
use App\Services\Automation\AutomationCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    protected $model = AutomationRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake('tr_TR')->sentence(3),
            'is_active' => true,
            'trigger_type' => AutomationCatalog::TRIGGER_TICKET_CREATED,
            'trigger_config' => ['priority' => null],
            'action_type' => AutomationCatalog::ACTION_NOTIFICATION_SEND,
            'action_config' => [
                'message_template' => 'Yeni kayıt: {record_title}',
                'recipient_type' => 'record_owner',
                'recipient_user_id' => null,
            ],
            'created_by' => User::factory(),
        ];
    }

    public function dealStageChanged(int $pipelineStageId): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => AutomationCatalog::TRIGGER_DEAL_STAGE_CHANGED,
            'trigger_config' => ['pipeline_stage_id' => $pipelineStageId],
        ]);
    }

    public function dealStatusChanged(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => AutomationCatalog::TRIGGER_DEAL_STATUS_CHANGED,
            'trigger_config' => ['status' => $status],
        ]);
    }

    public function taskCreateAction(string $assigneeType = 'record_owner', ?int $assigneeUserId = null, int $dueInDays = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => AutomationCatalog::ACTION_TASK_CREATE,
            'action_config' => [
                'title_template' => 'Takip: {record_title}',
                'assignee_type' => $assigneeType,
                'assignee_user_id' => $assigneeUserId,
                'due_in_days' => $dueInDays,
            ],
        ]);
    }

    public function assignOwnerAction(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'action_type' => AutomationCatalog::ACTION_DEAL_ASSIGN_OWNER,
            'action_config' => ['user_id' => $userId],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
