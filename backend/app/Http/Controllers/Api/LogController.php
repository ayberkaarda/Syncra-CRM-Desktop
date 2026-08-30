<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\ExportLogRequest;
use App\Http\Requests\Logs\IndexLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\PageVisitLogResource;
use App\Http\Resources\SessionLogResource;
use App\Services\Logging\LogQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * İnce controller: yetkilendirme (Gate — model policy yok, `logs.view` /
 * `logs.export` doğrudan izin adları) + Form Request doğrulaması +
 * LogQueryService devri. İş mantığı burada değil, servis/repository
 * katmanında yer alır.
 */
class LogController extends Controller
{
    public function __construct(protected LogQueryService $logs) {}

    public function sessions(IndexLogRequest $request): JsonResponse
    {
        abort_unless(Gate::allows('logs.view'), Response::HTTP_FORBIDDEN);

        $paginator = $this->logs->sessions($request->filters(), $request->perPage());

        return $this->paginatedResponse($paginator, SessionLogResource::class);
    }

    public function pageVisits(IndexLogRequest $request): JsonResponse
    {
        abort_unless(Gate::allows('logs.view'), Response::HTTP_FORBIDDEN);

        $paginator = $this->logs->pageVisits($request->filters(), $request->perPage());

        return $this->paginatedResponse($paginator, PageVisitLogResource::class);
    }

    public function activities(IndexLogRequest $request): JsonResponse
    {
        abort_unless(Gate::allows('logs.view'), Response::HTTP_FORBIDDEN);

        $paginator = $this->logs->activities($request->filters(), $request->perPage());

        return $this->paginatedResponse($paginator, ActivityLogResource::class);
    }

    public function export(ExportLogRequest $request): StreamedResponse|BinaryFileResponse
    {
        abort_unless(Gate::allows('logs.export'), Response::HTTP_FORBIDDEN);

        return $this->logs->export($request->type(), $request->filters(), $request->exportFormat());
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        return response()->json([
            'data' => $resourceClass::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }
}
