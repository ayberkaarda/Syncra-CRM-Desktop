<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateRolePermissionsRequest;
use App\Http\Resources\PermissionMatrixResource;
use App\Http\Resources\RoleResource;
use App\Services\Settings\RoleMatrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Rol / izin matrisi — `settings.manage`.
 *
 * -----------------------------------------------------------------------------
 * NEDEN `RoleController`'A EKLENMEDİ
 * -----------------------------------------------------------------------------
 * `GET /api/roles` (Faz 2) bir LOOKUP ucudur: kullanıcı formundaki rol
 * açılır listesini besler ve `roles.view` VEYA `users.manage_roles` ile
 * korunur. Matris ise bir YÖNETİM ekranıdır ve `settings.manage` ister —
 * rol atayabilen bir yönetici, rollerin ne anlama geldiğini yeniden
 * tanımlayabilmek zorunda değildir. İki farklı yetki alanı, iki controller.
 *
 * -----------------------------------------------------------------------------
 * OKUMA DA `settings.manage` İSTER
 * -----------------------------------------------------------------------------
 * Matris, sistemin tam yetki haritasıdır: hangi rolün neyi yapabildiğini tek
 * ekranda gösterir. Bu, saldırgan için "en zayıf halka hangisi" sorusunun
 * hazır cevabıdır; okuma iznini gevşetmenin karşılığı yok (sözleşme gereği
 * de `settings.manage`).
 */
class RoleMatrixController extends Controller
{
    public function __construct(protected RoleMatrixService $matrix) {}

    /**
     * `GET /api/settings/permission-matrix`.
     */
    public function index(): JsonResponse
    {
        $this->authorizeSettings();

        return (new PermissionMatrixResource($this->matrix->matrix()))->response();
    }

    /**
     * `PATCH /api/settings/roles/{role}/permissions` — gövdedeki liste TAM
     * DURUMDUR (sync): gönderilmeyen her izin kaldırılır.
     *
     * Super Admin 422 `ROLE_NOT_EDITABLE` alır (gerekçe RoleMatrixService'te).
     */
    public function update(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->authorizeSettings();

        $role = $this->matrix->syncPermissions($role, $request->permissionNames(), $request->user());

        return (new RoleResource($role))->response();
    }

    protected function authorizeSettings(): void
    {
        abort_unless(Gate::allows('settings.manage'), Response::HTTP_FORBIDDEN);
    }
}
