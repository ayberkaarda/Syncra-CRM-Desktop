<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Rol × izin matrisi — Ayarlar ekranındaki tek tabloyu tek istekte besler.
 *
 * -----------------------------------------------------------------------------
 * NEDEN MODEL DEĞİL, DİZİ SARMALAR
 * -----------------------------------------------------------------------------
 * Matrisin kendisi bir tablo satırı değildir: iki koleksiyonun (roller,
 * izinler) çapraz görünümüdür. `RoleResource::collection()` + ayrı bir izin
 * listesi döndürmek de mümkündü, ama o zaman "hangi izin hangi modüle ait"
 * ayrıştırması istemcide, isim üzerinden string parçalayarak yapılırdı —
 * izin sözlüğünün biçimi (`modul.eylem`) sunucunun sözleşmesidir, istemcinin
 * tahmini değil.
 *
 * -----------------------------------------------------------------------------
 * `is_editable` NEDEN YANITTA
 * -----------------------------------------------------------------------------
 * Super Admin rolü SIFIR izin satırı taşır; yetkisi AppServiceProvider'daki
 * `Gate::before`'dan gelir (bkz. RolePermissionSeeder). Matriste bu rol boş
 * bir sütun gibi görünür — düzenlenebilir sanılırsa kullanıcı kutuları
 * işaretler, sunucu 422 döner ve arayüz "neden" diyemez. Bayrak yanıtta
 * olduğu için istemci sütunu baştan salt-okunur çizer; sunucu tarafındaki
 * 422 (`ROLE_NOT_EDITABLE`) ise yalnızca son savunma hattıdır.
 *
 * @property-read array{roles: Collection<int, Role>, permissions: Collection<int, Permission>}  $resource
 */
class PermissionMatrixResource extends JsonResource
{
    /**
     * `Gate::before` ile her yetkiye sahip olan, bu yüzden izin satırı
     * tutmayan ve düzenlenemeyen rol.
     */
    public const SUPER_ADMIN_ROLE = UserResource::SUPER_ADMIN_ROLE;

    /**
     * @param  array{roles: Collection<int, Role>, permissions: Collection<int, Permission>}  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Role> $roles */
        $roles = $this->resource['roles'];
        /** @var Collection<int, Permission> $permissions */
        $permissions = $this->resource['permissions'];

        return [
            'permissions' => $permissions
                ->map(fn (Permission $permission): array => [
                    'name' => $permission->name,
                    'module' => self::moduleOf((string) $permission->name),
                    'action' => self::actionOf((string) $permission->name),
                ])
                ->values()
                ->all(),

            // Modül grupları — matris tablosunun satır blokları. Etiket
            // TAŞIMAZ: Türkçe metin bir sunum meselesidir ve arayüzde
            // eşlenir (bkz. LogsCrmActivity'deki aynı karar).
            'modules' => $permissions
                ->groupBy(fn (Permission $permission): string => self::moduleOf((string) $permission->name))
                ->map(fn (Collection $group, string $module): array => [
                    'key' => $module,
                    'permissions' => $group->pluck('name')->values()->all(),
                ])
                ->values()
                ->all(),

            'roles' => $roles
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'is_super_admin' => $role->name === self::SUPER_ADMIN_ROLE,
                    'is_editable' => $role->name !== self::SUPER_ADMIN_ROLE,
                    'users_count' => (int) ($role->users_count ?? 0),
                    'permissions' => $role->permissions->pluck('name')->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public static function moduleOf(string $permission): string
    {
        return str_contains($permission, '.')
            ? (string) strstr($permission, '.', true)
            : $permission;
    }

    public static function actionOf(string $permission): string
    {
        return str_contains($permission, '.')
            ? (string) substr(strstr($permission, '.'), 1)
            : '';
    }
}
