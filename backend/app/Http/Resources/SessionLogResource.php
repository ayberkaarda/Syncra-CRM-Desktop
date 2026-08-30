<?php

namespace App\Http\Resources;

use App\Models\SessionLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `user_agent` ham stringi kasıtlı olarak dönülmez (gürültü) — parse edilmiş
 * device/browser/platform yeterlidir.
 *
 * @property-read SessionLog $resource
 */
class SessionLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SessionLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
            'email' => $log->email,
            'event' => $log->event,
            'ip_address' => $log->ip_address,
            'device' => $log->device,
            'browser' => $log->browser,
            'platform' => $log->platform,
            'logged_in_at' => $log->logged_in_at?->toIso8601String(),
            'logged_out_at' => $log->logged_out_at?->toIso8601String(),
            'duration_seconds' => $log->duration_seconds,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
