<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesAbilities;
use App\Models\Task;
use App\Support\MorphTargets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Task $resource
 */
class TaskResource extends JsonResource
{
    use ExposesAbilities;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Task $task */
        $task = $this->resource;

        $shortType = MorphTargets::shortName($task->taskable_type);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'due_at' => $task->due_at?->toIso8601String(),
            'reminder_at' => $task->reminder_at?->toIso8601String(),
            'priority' => $task->priority,
            'status' => $task->status,
            'completed_at' => $task->completed_at?->toIso8601String(),
            // Sunucu hesaplar — istemci saatine güvenilmez. `overdue`
            // yalnızca hâlâ açık (pending/in_progress) görevler için
            // anlamlıdır; tamamlanmış/iptal edilmiş bir görev asla
            // "gecikmiş" gösterilmez.
            'is_overdue' => $task->due_at !== null
                && $task->due_at->lt(now())
                && in_array($task->status, ['pending', 'in_progress'], true),
            'assignee' => $task->relationLoaded('assignee') && $task->assignee
                ? ['id' => $task->assignee->id, 'name' => $task->assignee->name]
                : null,
            'creator' => $task->relationLoaded('creator') && $task->creator
                ? ['id' => $task->creator->id, 'name' => $task->creator->name]
                : null,
            // Hedef silinmişse (soft/hard) MorphTo null döner -> null.
            // taskable_type set değilse (görev hiçbir kayda bağlı değilse)
            // de null.
            'taskable' => $task->relationLoaded('taskable') && $task->taskable !== null && $shortType !== null
                ? [
                    'type' => $shortType,
                    'id' => $task->taskable_id,
                    'label' => MorphTargets::label($shortType, $task->taskable),
                ]
                : null,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            // Bu kullanıcının bu kayıtta neyi YAPABİLDİĞİ — arayüz kuralı
            // yeniden yazmasın (gerekçe: ExposesAbilities).
            'can' => $this->abilities($request, $task, [
                'update' => 'update',
                'complete' => 'complete',
                'delete' => 'delete',
                'assign' => 'assign',
            ]),
        ];
    }
}
