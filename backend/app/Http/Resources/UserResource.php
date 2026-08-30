<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Canonical user payload. The frontend `can()` helper and the whole
 * permission-driven navigation are built on the `permissions` array,
 * so its shape must not drift.
 *
 * @property-read User $resource
 */
class UserResource extends JsonResource
{
    /**
     * The role name that is granted everything through `Gate::before`.
     */
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $role = $user->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department' => $user->department,
            // Kişisel arayüz tercihleri (Faz 14). SPA açılışta `/api/me`'den okur ve
            // i18n'i buna göre ayarlar — `users.locale` istemcideki localStorage'ın
            // OTORİTESİDİR (docs/PHASE-INTL.md §1.3).
            'locale' => (string) $user->locale,
            'preferred_currency' => (string) $user->preferred_currency,
            'is_active' => (bool) $user->is_active,
            'must_change_password' => (bool) $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
            ] : null,
            'permissions' => static::effectivePermissions($user),
        ];
    }

    /**
     * Resolve the permissions the frontend should treat as granted.
     *
     * The `Super Admin` role deliberately holds ZERO permission rows in the
     * database - its authority comes from the `Gate::before` hook registered in
     * AppServiceProvider. If we returned `getAllPermissions()` verbatim for a
     * Super Admin the SPA would receive an empty array, every `can()` check
     * would fail and the user would see no navigation at all. So for that role
     * we expand to the full permission dictionary instead.
     *
     * @return array<int, string>
     */
    public static function effectivePermissions(User $user): array
    {
        if ($user->hasRole(static::SUPER_ADMIN_ROLE)) {
            return static::allPermissionNames();
        }

        return $user->getAllPermissions()
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Every permission name registered for the application guard.
     *
     * Read through the spatie permission registrar so this hits the cached
     * permission collection rather than issuing a query on every response.
     *
     * @return array<int, string>
     */
    protected static function allPermissionNames(): array
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        /** @var Collection<int, Permission> $permissions */
        $permissions = app(PermissionRegistrar::class)->getPermissions();

        return $permissions
            ->filter(fn ($permission): bool => $permission->guard_name === $guard)
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
