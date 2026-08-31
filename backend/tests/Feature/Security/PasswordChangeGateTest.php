<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Faz 13 / İz A — A1.3 + A1.7 güvenlik regresyon testleri.
 *
 * =============================================================================
 * NEDEN BU DOSYA VAR (A1.3)
 * =============================================================================
 * `EnsurePasswordIsChanged` (`password.changed`) BEYAZ LİSTE ile uygulanıyor:
 * `routes/api.php`'de `auth:sanctum`+`active` grubunun içinde, ama
 * `password.changed` alt-grubunun DIŞINDA yalnızca `logout`, `me` ve
 * `password/change` var; `/broadcasting/auth` da (bootstrap/app.php'de ayrı
 * kayıtlı) bilinçli olarak dışarıda. PHASE-AUDIT §2/A1.3'ün uyardığı risk:
 * bir sonraki fazda biri `auth:sanctum` grubuna yeni bir VERİ ucu eklerse ve
 * onu `password.changed` alt-grubuna koymayı unutursa, o uç SESSİZCE zorunlu
 * şifre değişimini atlar (R11 — kritik). Bu test, `Route::getRoutes()`'u
 * TARAYARAK `auth:sanctum` taşıyan ama `password.changed` taşımayan uçların
 * kümesinin TAM OLARAK bu 4 uç olduğunu doğrular — yeni bir uç bu kümeye
 * (istemeden) eklenirse test KIRILIR, kod okunmadan fark edilir.
 *
 * =============================================================================
 * NEDEN BU DOSYA VAR (A1.7)
 * =============================================================================
 * Şifre değiştiğinde diğer oturumların (çalınmış çerez dahil) bir sonraki
 * istekte düşmesi gerekiyor. Mekanizma AuthService::changePassword() dokümanına
 * göre Sanctum'un `AuthenticateSession` middleware'i (config/sanctum.php ->
 * middleware.authenticate_session): her stateful istekte oturumun
 * `password_hash_web` değerini GÜNCEL parola hash'iyle karşılaştırır, uyuşmazsa
 * 401 döner. Bu, kodu okuyarak DOĞRULANDI ama önceden hiçbir testte
 * KİLİTLENMEMİŞTİ (AuthTest.php'de böyle bir senaryo yok) — bu test onu kapatır.
 */
class PasswordChangeGateTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct!Horse2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * A1.3 — beyaz listenin TAM OLARAK 4 uç olduğunu kilitler.
     *
     * Yöntem: kayıtlı her route'un middleware yığınında `auth:sanctum` VAR ama
     * `password.changed` YOK olanları topla. Rota adı olmayan (`/broadcasting/
     * auth`, Sanctum'un kendi paketi tarafından kaydediliyor) uçlar için
     * "METOD(LAR) URI" imzası kullanılır — isimsiz bir rotayı da kümeye
     * sokabilmek için.
     */
    public function test_password_changed_middleware_whitelist_is_exactly_the_five_expected_endpoints(): void
    {
        $exempt = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            if (in_array('password.changed', $middleware, true)) {
                continue;
            }

            $methods = implode('|', array_diff($route->methods(), ['HEAD']));
            $exempt[] = $methods.' '.$route->uri();
        }

        sort($exempt);

        $this->assertSame(
            [
                /*
                 * Faz F1 — BEŞİNCİ ELEMAN, BİLİNÇLİ EKLENDİ (protokol §3.7/§7.1).
                 *
                 * `api/broadcasting/auth` is the desktop client's channel
                 * authorisation route, registered from routes/api.php with
                 * ['auth:sanctum','active'] so it can be reached with a bearer
                 * token. It is exempt from `password.changed` for EXACTLY the
                 * reason the cookie route below it is (see the DELIBERATELY
                 * ABSENT paragraph in bootstrap/app.php): a user under a forced
                 * password change still needs a live socket - that is the very
                 * session in which UserDeactivated has to reach them - and no
                 * channel callback grants data beyond identity plus permissions
                 * the user already holds.
                 *
                 * It is registered at `api/broadcasting/auth` rather than
                 * through a second withBroadcasting() call because that helper
                 * hard-codes the URI: the second registration would collide
                 * with the first and, having no route name, would SILENTLY
                 * never run (protokol §3.7/D9).
                 *
                 * `/api/me/devices*` is NOT in this list and must not be: those
                 * two routes are declared INSIDE the `password.changed` group,
                 * because a user who still owes a password change should not be
                 * enrolling long-lived device tokens with a temporary password.
                 */
                'GET api/me',
                'GET|POST api/broadcasting/auth',
                'GET|POST broadcasting/auth',
                'POST api/logout',
                'POST api/password/change',
            ],
            $exempt,
            'auth:sanctum taşıyan ama password.changed taşımayan uç kümesi beklenen 5 beyaz liste '.
            'ucundan SAPTI — yeni eklenen bir veri ucu zorunlu şifre değişimini atlıyor olabilir (R11).'
        );
    }

    /**
     * A1.3 tamamlayıcısı: gerçek bir veri ucunun (ör. /api/leads), zorunlu
     * şifre değişimi bayrağı açık bir kullanıcı için fiilen 403
     * `PASSWORD_CHANGE_REQUIRED` döndürdüğünü — yani middleware'in yalnız
     * KAYITLI değil, ÇALIŞIYOR olduğunu — doğrular.
     */
    public function test_a_data_endpoint_rejects_a_user_with_forced_password_change_pending(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'must_change_password' => true,
        ]);
        $user->assignRole('Admin');

        $response = $this->actingAs($user)->getJson('/api/leads');

        $response->assertStatus(403);
        $response->assertJsonPath('errors.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    /**
     * A1.3 tamamlayıcısı: beyaz listedeki üç uç (logout/me/password-change),
     * zorunlu şifre değişimi bayrağı açıkken dahi ERİŞİLEBİLİR kalmalı —
     * aksi halde kullanıcı ekrandan çıkamaz.
     */
    public function test_whitelisted_endpoints_remain_reachable_with_forced_password_change_pending(): void
    {
        // `password/change` çağırır `session()->regenerate()` (AuthService),
        // bu yüzden isteğin StartSession middleware'inden geçmesi gerekir —
        // EnsureFrontendRequestsAreStateful yalnızca Origin/Referer
        // `sanctum.stateful` ile eşleşince o yığını devreye sokar.
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'must_change_password' => true,
        ]);
        $user->assignRole('Satış Temsilcisi');

        $this->actingAs($user)->getJson('/api/me')->assertStatus(200);

        $this->actingAs($user)->postJson('/api/password/change', [
            'current_password' => self::PASSWORD,
            'password' => 'BrandNew!2026Pass',
            'password_confirmation' => 'BrandNew!2026Pass',
        ])->assertStatus(200);
    }

    /**
     * A1.7 — şifre değişimi, ÇALINMIŞ/eski bir oturum çerezini taşıyan başka
     * bir istemciyi bir sonraki istekte düşürmeli (Sanctum AuthenticateSession
     * `password_hash_web` uyuşmazlığı -> 401).
     *
     * Gerçek çerez tabanlı akış kurulur (AuthTest.php'deki desenle aynı):
     * login -> session çerezini SAKLA (eski oturum) -> AYNI kullanıcı YENİ bir
     * döngüde şifresini değiştir -> eski çerezle bir istek at -> 401 bekle.
     */
    public function test_changing_password_invalidates_other_sessions_cookie_on_next_request(): void
    {
        $this->withCredentials();
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ]);
        $user->assignRole('Satış Temsilcisi');

        // 1) "Çalınan" (eski) oturum: normal giriş.
        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);
        $loginResponse->assertStatus(200);

        $staleCookies = $loginResponse->headers->getCookies();

        // 2) O oturumun hâlâ geçerli olduğunu doğrula (karşılaştırma temeli).
        $this->replayCookies($staleCookies);
        $this->getJson('/api/me')->assertStatus(200);

        // 3) AYNI kullanıcı şifresini değiştirir (kendi güncel oturumundan).
        $this->postJson('/api/password/change', [
            'current_password' => self::PASSWORD,
            'password' => 'BrandNew!2026Pass',
            'password_confirmation' => 'BrandNew!2026Pass',
        ])->assertStatus(200);

        // 4) ESKİ (çalınmış) çerezle bir sonraki istek: guard yeniden kurulur
        // (yeni bir HTTP döngüsü simülasyonu), password_hash_web artık DB'deki
        // hash ile uyuşmuyor -> 401 beklenir.
        $this->replayCookies($staleCookies);
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    /**
     * Response'un çerezlerini bir sonraki isteğe taşır ve guard/session
     * durumunu sıfırlayarak "yeni bir HTTP süreci" simüle eder — AuthTest.php
     * `keepSessionFrom()`/`startNewRequestCycle()` ile AYNI desen.
     */
    private function replayCookies(iterable $cookies): void
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getValue() === null || $cookie->getValue() === '') {
                continue;
            }

            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        Auth::clearResolvedInstances();
        $this->app->forgetInstance('auth');
        $this->app->forgetInstance('auth.driver');

        if ($this->app->resolved('session')) {
            $this->app['session']->driver()->flush();
        }
    }
}
