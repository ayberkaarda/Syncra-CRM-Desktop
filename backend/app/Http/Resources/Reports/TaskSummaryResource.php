<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/dashboard/task-summary` — tarih parametresi almayan anlık
 * görüntü (bkz. App\Services\Reports\DashboardService::taskSummary()).
 */
class TaskSummaryResource extends JsonResource
{
    /**
     * @return array{open_count: int, overdue_count: int, due_today_count: int, completed_today_count: int, by_priority: array<string, int>}
     */
    public function toArray(Request $request): array
    {
        return [
            'open_count' => (int) $this->resource['open_count'],
            'overdue_count' => (int) $this->resource['overdue_count'],
            'due_today_count' => (int) $this->resource['due_today_count'],
            'completed_today_count' => (int) $this->resource['completed_today_count'],
            'by_priority' => $this->resource['by_priority'],
        ];
    }
}
