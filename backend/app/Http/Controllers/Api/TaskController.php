<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\AssignTaskRequest;
use App\Http\Requests\Tasks\CalendarTaskRequest;
use App\Http\Requests\Tasks\CompleteTaskRequest;
use App\Http\Requests\Tasks\IndexTaskRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * TaskService devri. İş mantığı burada değil, TaskService/TaskRepository
 * içinde yer alır.
 */
class TaskController extends Controller
{
    public function __construct(protected TaskService $tasks) {}

    public function index(IndexTaskRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Task::class);

        $paginator = $this->tasks->list($request->filters());

        return response()->json([
            'data' => TaskResource::collection($paginator->items()),
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

    /**
     * `GET /api/tasks/calendar` — route sırası KASITLIDIR: bu sabit segment
     * routes/api.php'de `{task}` route-model-binding parametresinden ÖNCE
     * tanımlanmalı, yoksa Laravel `calendar`'ı bir görev id'si sanıp 404
     * üretir (Faz 6'daki `check-duplicates`, Faz 7'deki `board` ile aynı
     * tuzak — bkz. TaskApiTest route sırası testi).
     */
    public function calendar(CalendarTaskRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $request->filters();
        $tasks = $this->tasks->calendar($filters);

        return response()->json([
            'data' => TaskResource::collection($tasks),
            'meta' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'count' => $tasks->count(),
            ],
        ]);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        Gate::authorize('create', Task::class);

        $task = $this->tasks->create($request->validated(), $request->user()->id);

        return (new TaskResource($task))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        $task = $this->tasks->find($task->id);

        return (new TaskResource($task))->response();
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('update', $task);

        $task = $this->tasks->update($task, $request->validated());

        return (new TaskResource($task))->response();
    }

    public function destroy(Task $task): JsonResponse
    {
        Gate::authorize('delete', $task);

        $this->tasks->delete($task);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function complete(CompleteTaskRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('complete', $task);

        $task = $this->tasks->complete($task, (bool) $request->validated()['completed']);

        return (new TaskResource($task))->response();
    }

    public function assign(AssignTaskRequest $request, Task $task): JsonResponse
    {
        Gate::authorize('assign', $task);

        $task = $this->tasks->assign($task, (int) $request->validated()['assigned_to']);

        return (new TaskResource($task))->response();
    }
}
