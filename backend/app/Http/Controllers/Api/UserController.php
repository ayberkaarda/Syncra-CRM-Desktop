<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\IndexUserRequest;
use App\Http\Requests\Users\ResetPasswordRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\ToggleActiveRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması + UserService devri.
 * İş mantığı burada değil, UserService içinde yer alır.
 */
class UserController extends Controller
{
    public function __construct(protected UserService $users) {}

    public function index(IndexUserRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $paginator = $this->users->list($request->filters());

        return response()->json([
            'data' => UserResource::collection($paginator->items()),
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

    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $user = $this->users->create($request->validated());

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return (new UserResource($user->loadMissing('roles')))->response();
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $user = $this->users->update($user, $request->validated());

        return (new UserResource($user))->response();
    }

    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $this->users->delete($user);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function toggleActive(ToggleActiveRequest $request, User $user): JsonResponse
    {
        Gate::authorize('toggleActive', $user);

        $user = $this->users->toggleActive($user, $request->boolean('is_active'));

        return (new UserResource($user))->response();
    }

    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        Gate::authorize('resetPassword', $user);

        $this->users->resetPassword($user, $request->validated()['password']);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
