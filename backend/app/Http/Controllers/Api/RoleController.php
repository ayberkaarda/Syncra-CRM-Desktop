<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        // roles.view VEYA users.manage_roles iznine sahip olanlar erişebilir.
        abort_unless(Gate::any(['roles.view', 'users.manage_roles']), Response::HTTP_FORBIDDEN);

        $roles = Role::query()->with('permissions')->get();

        return RoleResource::collection($roles)->response();
    }
}
