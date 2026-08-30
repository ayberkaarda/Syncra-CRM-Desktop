<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activities\IndexActivityRequest;
use App\Http\Requests\Activities\StoreActivityRequest;
use App\Http\Requests\Activities\UpdateActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Services\Activities\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * ActivityService devri. İş mantığı burada değil, ActivityService/
 * ActivityRepository içinde yer alır.
 */
class ActivityController extends Controller
{
    public function __construct(protected ActivityService $activities) {}

    public function index(IndexActivityRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Activity::class);

        $paginator = $this->activities->list($request->filters());

        return response()->json([
            'data' => ActivityResource::collection($paginator->items()),
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

    public function store(StoreActivityRequest $request): JsonResponse
    {
        Gate::authorize('create', Activity::class);

        $activity = $this->activities->create($request->validated(), $request->user()->id);

        return (new ActivityResource($activity))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Activity $activity): JsonResponse
    {
        Gate::authorize('view', $activity);

        $activity = $this->activities->find($activity->id);

        return (new ActivityResource($activity))->response();
    }

    public function update(UpdateActivityRequest $request, Activity $activity): JsonResponse
    {
        Gate::authorize('update', $activity);

        $activity = $this->activities->update($activity, $request->validated());

        return (new ActivityResource($activity))->response();
    }

    public function destroy(Activity $activity): JsonResponse
    {
        Gate::authorize('delete', $activity);

        $this->activities->delete($activity);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
