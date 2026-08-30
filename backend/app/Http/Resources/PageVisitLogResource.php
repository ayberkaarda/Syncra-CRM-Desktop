<?php

namespace App\Http\Resources;

use App\Models\PageVisitLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PageVisitLog $resource
 */
class PageVisitLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PageVisitLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'route' => $log->route,
            'path' => $log->path,
            'title' => $log->title,
            'entered_at' => $log->entered_at?->toIso8601String(),
            'last_heartbeat_at' => $log->last_heartbeat_at?->toIso8601String(),
            'duration_seconds' => $log->duration_seconds,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
