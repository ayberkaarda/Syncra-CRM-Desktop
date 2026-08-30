<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesAbilities;
use App\Models\Activity;
use App\Support\MorphTargets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Activity $resource
 */
class ActivityResource extends JsonResource
{
    use ExposesAbilities;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Activity $activity */
        $activity = $this->resource;

        $shortType = MorphTargets::shortName($activity->activityable_type);

        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'subject' => $activity->subject,
            'body' => $activity->body,
            'occurred_at' => $activity->occurred_at?->toIso8601String(),
            'duration_minutes' => $activity->duration_minutes,
            'outcome' => $activity->outcome,
            'user' => $activity->relationLoaded('user') && $activity->user
                ? ['id' => $activity->user->id, 'name' => $activity->user->name]
                : null,
            // Hedef silinmişse (soft/hard) MorphTo null döner -> null.
            'activityable' => $activity->relationLoaded('activityable') && $activity->activityable !== null && $shortType !== null
                ? [
                    'type' => $shortType,
                    'id' => $activity->activityable_id,
                    'label' => MorphTargets::label($shortType, $activity->activityable),
                ]
                : null,
            'created_at' => $activity->created_at?->toIso8601String(),
            'updated_at' => $activity->updated_at?->toIso8601String(),
            // Bu kullanıcının bu kayıtta neyi YAPABİLDİĞİ — arayüz kuralı
            // yeniden yazmasın (gerekçe: ExposesAbilities).
            'can' => $this->abilities($request, $activity, [
                'update' => 'update',
                'delete' => 'delete',
            ]),
        ];
    }
}
