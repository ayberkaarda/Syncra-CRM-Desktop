<?php

namespace App\Services\Users;

use App\Events\UserDeactivated;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\Settings\DeniesSettingsChange;
use App\Sync\SyncCounter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserService
{
    use DeniesSettingsChange;

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
            $role = isset($data['role']) ? $this->resolveRole($data['role']) : null;
            unset($data['role']);

            // Kapalı devre erişim modeli: geçici şifreyle giriş yapıp değiştirmek zorunda.
            $data['must_change_password'] = true;

            if ($role) {
                // Kullanıcı satırı YAZILMADAN önce: reddedilen istek geride
                // yarım kayıt bırakmasın (transaction geri alsa bile
                // auto-increment ve olay dinleyicileri boşuna tetiklenir).
                $this->assertActorMayGrantRole($role);
            }

            $user = $this->users->create($data);

            if ($role) {
                $user->syncRoles([$role]);
                $this->auditRoleChange($user, [], $role);
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
            $this->lockCounterFirst();

            $role = isset($data['role']) ? $this->resolveRole($data['role']) : null;
            unset($data['role']);

            $roleChanged = $role !== null
                && ! ($user->roles()->count() === 1 && $user->roles()->whereKey($role->getKey())->exists());

            if ($roleChanged) {
                Gate::authorize('manageRoles', $user);
                $this->assertActorMayGrantRole($role);

                // Aynı kilit kümesi eşzamanlı son yönetici düşürmelerini de sıralar.
                $superAdmins = User::role(self::SUPER_ADMIN_ROLE)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($role->name !== self::SUPER_ADMIN_ROLE
                    && $superAdmins->contains('id', $user->getKey())
                    && $superAdmins->count() <= 1) {
                    $this->deny(
                        'Son aktif Super Admin rolü kaldırılamaz.',
                        'LAST_SUPER_ADMIN',
                        ['role' => ['En az bir aktif Super Admin kalmalıdır.']],
                    );
                }
            }

            if (! empty($data)) {
                $this->users->update($user, $data);
            }

            if ($roleChanged) {
                $previous = $user->roles()->pluck('name')->map(fn ($name): string => (string) $name)->all();
                $user->syncRoles([$role]);
                $this->auditRoleChange($user, $previous, $role);
            }

            return $user->load('roles');
        });
    }

    /**
     * Kullanıcı yazımlarının ortak kilit sırası: önce senkron sayacı, sonra
     * `users` ve `personal_access_tokens` satırları.
     *
     * `users` senkron tablosudur; her kayıt `saving` anında sayaçtan sürüm
     * çeker (SyncVersionObserver). Belirteçleri silip ya da Super Admin
     * satırlarını kilitleyip sonra kaydeden bir işlem, sayacı tutup aynı
     * satırları bekleyen başka bir kullanıcı yazımıyla 1213'e düşerdi
     * (parola sıfırlama/silme ile pasifleştirme, rol değişikliği ile başka bir
     * Super Admin'in pasifleştirilmesi). Bkz. SyncCounter.
     */
    protected function lockCounterFirst(): void
    {
        SyncCounter::lock();
    }

    /**
     * Rol ataması pivot tablosuna (`model_has_roles`) yazılır; kullanıcının
     * öznitelikleri değişmediği için otomatik denetim kaydı oluşmaz. Kimin
     * rolünün kim tarafından neyden neye çevrildiği açıkça yazılır.
     *
     * @param  array<int, string>  $from
     */
    protected function auditRoleChange(User $user, array $from, Role $to): void
    {
        activity('crm')
            ->performedOn($user)
            ->withProperties(['from' => $from, 'to' => [(string) $to->name]])
            ->log('users.role_changed');
    }

    protected function resolveRole(string $name): Role
    {
        $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();

        if ($role === null) {
            throw ValidationException::withMessages(['role' => ['Seçilen rol geçersiz.']]);
        }

        return $role;
    }

    /**
     * =========================================================================
     * KARAR: kimse KENDİNDEN FAZLASINI VEREMEZ ("rol tavanı")
     * =========================================================================
     * Super Admin her rolü atayabilir. Diğer aktörler, `users.manage_roles`
     * taşısalar da yalnızca kendi yetkileriyle karşılanan izinleri verebilir;
     * `Super Admin` rolünü yalnızca başka bir Super Admin atayabilir.
     * Denetim ve atama, önce veritabanından çözümlenen aynı rolü kullanır.
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
    protected function assertActorMayGrantRole(Role $role): void
    {
        $actor = Auth::user();

        // Konsol/seed bağlamı: ortada bir aktör yoksa uygulanacak tavan da yok.
        if (! $actor instanceof User) {
            return;
        }

        if ($actor->hasRole(self::SUPER_ADMIN_ROLE)) {
            return;
        }

        if ($role->name === self::SUPER_ADMIN_ROLE) {
            throw new AuthorizationException(
                'Super Admin rolünü yalnızca Super Admin atayabilir.'
            );
        }

        $excess = $role->permissions->reject(fn ($permission): bool => $actor->can($permission->name));

        if ($excess->isNotEmpty()) {
            throw new AuthorizationException(
                'Kendi sahip olmadığınız izinleri içeren bir rol atayamazsınız.'
            );
        }
    }

    public function toggleActive(User $user, bool $isActive): User
    {
        return DB::transaction(function () use ($user, $isActive) {
            $this->lockCounterFirst();

            $user->is_active = $isActive;
            $user->save();

            if (! $isActive) {
                // "Beni hatırla" çerezini geçersiz kıl.
                $user->setRememberToken(Str::random(60));
                $user->save();

                /*
                 * Faz F1 — cihaz belirteçleri de anında iptal edilir
                 * (SYNCDESKTOP §4.3, protokol §3.6).
                 *
                 * The existing instant-revoke story covers the SPA only:
                 * UserDeactivated tears down sockets and EnsureUserIsActive
                 * rejects the next stateful request. A bearer token passes
                 * neither gate the same way - `active` would still catch it on
                 * the next call, but the credential itself would survive
                 * indefinitely on a machine the company just cut off. Deleting
                 * the rows is the only revocation a stateless token has.
                 *
                 * Inside the same transaction as the flag: an account can never
                 * be marked inactive while its device credentials still exist.
                 */
                $user->tokens()->delete();

                event(new UserDeactivated($user->id));
            }

            return $user->load('roles');
        });
    }

    /**
     * Faz F1 — yönetici şifre sıfırlaması TÜM cihaz belirteçlerini siler
     * (protokol §3.6/D7 — SYNCDESKTOP §4.3'te atlanmış bir noktaydı).
     *
     * An administrator resetting somebody's password is, in practice, the
     * "this account may be compromised" procedure. Rotating the password and
     * the remember-me cookie while leaving long-lived device tokens alive would
     * leave every already-installed desktop client fully authenticated - the
     * one credential the reset was meant to invalidate.
     */
    public function resetPassword(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password) {
            $this->lockCounterFirst();

            // Düz metin ata; model cast'i ('password' => 'hashed') otomatik hash'ler.
            $user->password = $password;
            $user->must_change_password = true;
            $user->setRememberToken(Str::random(60));

            $revoked = $user->tokens()->delete();

            $user->save();

            // Parola, hash'i ve remember_token denetim kaydına HİÇ girmez
            // (LogsCrmActivity::GLOBALLY_EXCLUDED_ATTRIBUTES); bu yüzden model
            // kaydı boş kalır ve sıfırlama izsiz kalırdı. Yalnızca olayın
            // kendisi ve iptal edilen cihaz sayısı yazılır.
            activity('crm')
                ->performedOn($user)
                ->withProperties(['tokens_revoked' => (int) $revoked])
                ->log('users.password_reset');

            return $user->load('roles');
        });
    }

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lockCounterFirst();

            $user->setRememberToken(Str::random(60));

            /*
             * Faz F1 (protokol §3.6). Soft delete alone already fails a bearer
             * request closed - Sanctum's Guard resolves `tokenable` through the
             * SoftDeletes global scope and gets null, which is a 401 - but
             * leaving the rows behind means restoring the user would silently
             * reactivate every device credential they ever had. Deletion is
             * explicit.
             */
            $user->tokens()->delete();

            $user->save();

            $this->users->delete($user);
        });
    }
}
