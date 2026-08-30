<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/dashboard/recent-activities` — tek bir aktivite satırı.
 */
class RecentActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource['id'],
            'type' => $this->resource['type'],
            'subject' => $this->resource['subject'],
            'occurred_at' => $this->resource['occurred_at'],
            'user' => $this->resource['user'],
            'related' => $this->resource['related'],
        ];
    }
}
