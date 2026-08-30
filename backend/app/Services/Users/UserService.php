<?php

namespace App\Services\Users;

use App\Events\UserDeactivated;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserService
{
    /**
     * Yetkisi veritabanındaki izinlerden DEĞİL, `Gate::before`'dan gelen rol
     * (bkz. AppServiceProvider::registerSuperAdminGate). İzin kümesi BOŞ
     * olduğu için aşağıdaki "alt küme" testi bu rolde her zaman geçerdi —
     * bu yüzden ayrıca ve açıkça yasaklanıyor.
     */
    protected const SUPER_ADMIN_ROLE = 'Super Admin';

    public function __construct(protected UserRepository $users) {}

    /**
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        unset($filters['per_page']);

        return $this->users->paginate($filters, $perPage);
    }

    /**
     * `POST /api/users`.
     *
     * =========================================================================
     * DİKEY YETKİ YÜKSELTMESİ KAPATILDI (Faz 13 / F7)
     * =========================================================================
     * `users.create` iznine sahip ama `users.manage_roles` OLMAYAN bir aktör
     * (izin matrisinde tam olarak `Admin` rolü) gövdeye `role: "Super Admin"`
     * koyup kendi seçtiği şifreyle bir hesap açabiliyor, ardından o hesaba
     * girip Super Admin olabiliyordu. Rol kontrolü YALNIZCA update() yolunda
     * vardı; create yolunda rol doğrudan `syncRoles()`'a gidiyordu.
     *
     * Bkz. assertActorMayGrantRole() — kararın gerekçesi orada.
     *
     * @param  array<string, mixed>  $data  'role' anahtarı içerebilir.
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $role = $data['role'] ?? null;
            unset($data['role']);

            // Kapalı devre erişim modeli: geçici şifreyle giriş yapıp değiştirmek zorunda.
            $data['must_change_password'] = true;

            if ($role) {
                // Kullanıcı satırı YAZILMADAN önce: reddedilen istek geride
                // yarım kayıt bırakmasın (transaction geri alsa bile
                // auto-increment ve olay dinleyicileri boşuna tetiklenir).
                $this->assertActorMayGrantRole((string) $role);
            }

            $user = $this->users->create($data);

            if ($role) {
                $user->syncRoles([$role]);
            }

            return $user->load('roles');
        });
    }

    /**
     * @param  array<string, mixed>  $data  'role' anahtarı içerebilir.
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $role = array_key_exists('role', $data) ? $data['role'] : null;
            unset($data['role']);

            if (! empty($data)) {
                $this->users->update($user, $data);
            }

            if ($role !== null) {
                // Rol değişimi yalnızca users.manage_roles iznine sahip aktörler içindir.
                Gate::authorize('manageRoles', $user);

                $user->syncRoles([$role]);
            }

            return $user->load('roles');
        });
    }

    /**
     * =========================================================================
     * KARAR: kimse KENDİNDEN FAZLASINI VEREMEZ ("rol tavanı")
     * =========================================================================
     * `users.manage_roles` taşıyan aktör her rolü atayabilir (değişmedi).
     * Taşımayan aktör YALNIZCA izin kümesi KENDİ izin kümesinin ALT KÜMESİ
     * olan bir rol atayabilir; `Super Admin` ise her hâlükârda yasaktır.
     * Aksi hâlde `AuthorizationException` -> HTTP 403.
     *
     * GEREKÇE (neden 403, 422 değil): istek biçimsel olarak geçerlidir — rol
     * gerçekten vardır (`exists:roles,name`). Reddin nedeni girdi değil YETKİ.
     * update() yolundaki rol reddi de zaten `Gate::authorize('manageRoles')`
     * üzerinden 403 üretiyor; iki yolun aynı hatayı aynı kanaldan vermesi
     * istemci için tek bir kural demektir.
     *
     * GEREKÇE (neden "rolü sessizce yok say / varsayılan rol ata" DEĞİL):
     * `role` StoreUserRequest'te `required`. Sessiz düşürme, 201 ve "kullanıcı
     * oluşturuldu" yanıtı dönerken ortada TALEP EDİLENDEN BAŞKA (izinsiz,
     * hayalet) bir hesap bırakırdı; yönetici hesabın çalıştığını sanar, hata
     * ancak kullanıcı giremeyince ortaya çıkardı. Sessiz sapma bir güvenlik
     * düzeltmesinin verebileceği en kötü yanıttır.
     *
     * GEREKÇE (neden "rol gönderen herkes 403" DEĞİL): `role` zorunlu alan
     * olduğu için düz bir "manage_roles yoksa 403", `Admin` rolünün
     * `users.create` iznini TAMAMEN kullanılamaz hâle getirirdi — var olan bir
     * yeteneği yok etmek, kapatılmak istenen açıkla ilgisi olmayan bir
     * gerileme olurdu. Açık, "kendinden YÜKSEK yetki üretebilmek"tir; tavan
     * kuralı tam olarak onu keser: Admin bugünkü beş rolün beşini de atamaya
     * devam eder (hepsinin izinleri Admin'in izinlerinin alt kümesidir),
     * yalnızca `Super Admin` üretemez.
     *
     * NOT (bilinçli asimetri): update() rol DEĞİŞİMİ için hâlâ tam
     * `users.manage_roles` ister. Orası bu düzeltmeden önce de sıkı çalışıyordu
     * ve bir güvenlik düzeltmesi kapsamında GEVŞETİLMEDİ.
     */
    protected function assertActorMayGrantRole(string $roleName): void
    {
        $actor = Auth::user();

        // Konsol/seed bağlamı: ortada bir aktör yoksa uygulanacak tavan da yok.
        if (! $actor instanceof User) {
            return;
        }

        if ($actor->can('users.manage_roles')) {
            return;
        }

        if ($roleName === self::SUPER_ADMIN_ROLE) {
            throw new AuthorizationException(
                'Super Admin rolü atamak için users.manage_roles izni gerekir.'
            );
        }

        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null) {
            return; // `exists:roles,name` zaten doğruladı; savunmacı dal.
        }

        $excess = array_diff(
            $role->permissions->pluck('name')->all(),
            $actor->getAllPermissions()->pluck('name')->all()
        );

        if ($excess !== []) {
            throw new AuthorizationException(
                'Kendi sahip olmadığınız izinleri içeren bir rol atayamazsınız.'
            );
        }
    }

    public function toggleActive(User $user, bool $isActive): User
    {
        return DB::transaction(function () use ($user, $isActive) {
            $user->is_active = $isActive;
            $user->save();

            if (! $isActive) {
                // "Beni hatırla" çerezini geçersiz kıl.
                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new UserDeactivated($user->id));
            }

            return $user->load('roles');
        });
    }

    public function resetPassword(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password) {
            // Düz metin ata; model cast'i ('password' => 'hashed') otomatik hash'ler.
            $user->password = $password;
            $user->must_change_password = true;
            $user->setRememberToken(Str::random(60));
            $user->save();

            return $user->load('roles');
        });
    }

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->setRememberToken(Str::random(60));
            $user->save();

            $this->users->delete($user);
        });
    }
}
