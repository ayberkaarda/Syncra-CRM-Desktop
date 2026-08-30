<?php

namespace App\Services\Settings;

use App\Http\Resources\PermissionMatrixResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Rol / izin matrisi.
 *
 * =============================================================================
 * SUPER ADMIN DÜZENLENEMEZ
 * =============================================================================
 * `Super Admin` rolü SIFIR izin satırı taşır; yetkisi AppServiceProvider'daki
 * `Gate::before` kancasından gelir (RolePermissionSeeder'daki not). Bu bir
 * eksiklik değil, tasarım: yeni eklenen her izin ona otomatik olarak
 * geçerlidir, migration ya da yeniden seed gerekmez.
 *
 * Matristen ona izin YAZMAK iki şekilde zarar verirdi: (1) yazılan izinler
 * hiçbir işe yaramaz — `Gate::before` zaten `true` döndüğü için okunmazlar,
 * yani arayüz kullanıcıya gerçek olmayan bir kontrol gösterir; (2) daha
 * kötüsü, ileride biri o kancayı kaldırıp "artık izinler tabloda" derse,
 * yarım doldurulmuş bu satırlar sistemin tek yöneticisini kilitler.
 *
 * =============================================================================
 * KENDİ ERİŞİMİNİ KAPATAMAZSIN
 * =============================================================================
 * `settings.manage` iznini, düzenleyen kullanıcının kendi rolünden kaldıran
 * bir kayıt reddedilir (422 `CANNOT_REVOKE_OWN_SETTINGS_ACCESS`). Aksi halde
 * tek tık, kullanıcının Ayarlar ekranından çıkışını ve o ekrana geri dönmesini
 * imkânsız kılardı — üstelik hatanın kendisi de Ayarlar ekranından
 * düzeltilemezdi. Aynı fikir UserPolicy'de zaten var: kimse kendi hesabını
 * silemez/pasifleştiremez ve son aktif Super Admin kaldırılamaz.
 *
 * Super Admin bu kontrolden ETKİLENMEZ: yetkisi rolün izin satırlarından
 * değil `Gate::before`'dan gelir, dolayısıyla hiçbir matris değişikliği onu
 * dışarıda bırakamaz.
 */
class RoleMatrixService
{
    use DeniesSettingsChange;

    /**
     * Matrisin veri kaynağı — roller (izinleriyle ve kullanıcı sayısıyla) +
     * izin sözlüğünün tamamı.
     *
     * @return array{roles: Collection<int, Role>, permissions: Collection<int, Permission>}
     */
    public function matrix(): array
    {
        /** @var Collection<int, Role> $roles */
        $roles = Role::query()->with('permissions')->orderBy('id')->get();

        $this->attachUserCounts($roles);

        return [
            'roles' => $roles,
            // Sıralama modül + eylem: matris tablosu satırlarını modüle göre
            // bloklar hâlinde çizer ve alfabetik `name` sırası zaten
            // `modul.eylem` biçimi sayesinde modülleri bir arada tutar.
            'permissions' => Permission::query()
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * `PATCH /api/settings/roles/{role}/permissions` — liste TAM DURUMDUR.
     *
     * @param  array<int, string>  $permissionNames
     */
    public function syncPermissions(Role $role, array $permissionNames, User $actor): Role
    {
        if ($role->name === PermissionMatrixResource::SUPER_ADMIN_ROLE) {
            $this->deny(
                'Super Admin rolünün izinleri düzenlenemez: yetkisi izin tablosundan değil, sistem kancasından gelir.',
                'ROLE_NOT_EDITABLE',
                ['permissions' => ['Super Admin rolünün izinleri düzenlenemez.']],
            );
        }

        $guard = (string) $role->guard_name;

        /** @var Collection<int, Permission> $permissions */
        $permissions = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $permissionNames)
            ->get();

        $unknown = array_values(array_diff(
            $permissionNames,
            $permissions->pluck('name')->map(fn ($name): string => (string) $name)->all(),
        ));

        if ($unknown !== []) {
            $this->deny(
                'Bilinmeyen izin: '.implode(', ', $unknown).'.',
                'UNKNOWN_PERMISSION',
                ['permissions' => ['Bilinmeyen izin: '.implode(', ', $unknown).'.']],
                ['unknown_permissions' => $unknown],
            );
        }

        $this->assertActorKeepsSettingsAccess($role, $permissionNames, $actor);

        // `syncPermissions` spatie'nin izin önbelleğini kendisi temizler;
        // ayrıca `forgetCachedPermissions()` çağırmak gerekmez.
        $role->syncPermissions($permissions);

        return $role->refresh()->load('permissions');
    }

    /**
     * Rol başına kullanıcı sayısı — `withCount('users')` KULLANILAMAZ.
     *
     * spatie'nin `Role::users()` ilişkisi hedef modeli
     * `getModelForGuard($this->attributes['guard_name'])` ile çözer; Eloquent
     * ise `withCount()` için ilişkiyi ATTRIBUTE'SUZ yeni bir model örneği
     * üzerinden kurar. O örnekte `guard_name` bulunmadığı için hedef sınıf
     * `null` döner ve sorgu "Class name must be a valid object or a string"
     * ile patlar. Bu yüzden sayım pivot tablosundan tek bir gruplanmış
     * sorguyla alınır (rol başına ayrı sorgu da atılmaz).
     *
     * @param  Collection<int, Role>  $roles
     */
    protected function attachUserCounts(Collection $roles): void
    {
        $counts = DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->selectRaw('role_id, COUNT(*) as aggregate')
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');

        $roles->each(function (Role $role) use ($counts): void {
            $role->setAttribute('users_count', (int) ($counts[$role->getKey()] ?? 0));
        });
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    protected function assertActorKeepsSettingsAccess(Role $role, array $permissionNames, User $actor): void
    {
        if ($actor->hasRole(PermissionMatrixResource::SUPER_ADMIN_ROLE)) {
            return;
        }

        if (in_array('settings.manage', $permissionNames, true)) {
            return;
        }

        if (! $actor->hasRole($role->name)) {
            return;
        }

        // Kullanıcının BAŞKA bir rolü de `settings.manage` taşıyorsa erişim
        // kaybolmaz; kilit yalnızca son kapıyı kapatan kayıtta devreye girer.
        //
        // `hasPermissionTo()` DEĞİL, yüklü ilişki üzerinden arama: o metot
        // izin adı sözlükte yoksa `PermissionDoesNotExist` fırlatır ve bir
        // yetki kontrolü, sistemi 500 ile düşürerek "hayır" dememelidir.
        $keepsAccess = $actor->roles
            ->reject(fn (Role $owned): bool => (int) $owned->getKey() === (int) $role->getKey())
            ->contains(fn (Role $owned): bool => $owned->permissions->contains('name', 'settings.manage'));

        if ($keepsAccess) {
            return;
        }

        // Doğrudan kullanıcıya verilmiş (role bağlı olmayan) izin de sayılır.
        if ($actor->permissions->contains('name', 'settings.manage')) {
            return;
        }

        $this->deny(
            'Bu kayıt kendi Ayarlar erişiminizi kaldırırdı; kaydedildikten sonra geri alamazdınız.',
            'CANNOT_REVOKE_OWN_SETTINGS_ACCESS',
            ['permissions' => ['settings.manage iznini kendi rolünüzden kaldıramazsınız.']],
        );
    }
}
