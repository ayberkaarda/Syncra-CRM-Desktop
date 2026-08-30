<?php

namespace Tests\Feature\Security;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Faz 13 / İz B — 6 ROLÜN UÇTAN UCA YETKİ KABUL TURU.
 *
 * =============================================================================
 * AMAÇ VE KAPSAM
 * =============================================================================
 * `docs/PHASE-AUDIT.md` §3'ün BAĞLAYICI güncellemesi (görev talimatındaki
 * "YENİ YETKİLENDİRME MODELİ" bölümü) şunu tarif eder: `view`/`viewAny`/
 * `index` DÜZ'dür — ilgili modülün `.view` iznine sahip HERKES o modüldeki
 * HER kaydı görür ("Satış Temsilcisi yalnız kendi kaydını görür" YANLIŞ).
 * Bu dosya o iddiayı, GERÇEK seeder çıktısına karşı, HERKESİN girdiği ORTAK
 * bir uç kümesi üzerinden (`index` + bir `show`) rol bazında doğrular.
 *
 * `AuthzIdorTest`/`OwnershipIsolationTest` ile ÇAKIŞMA YOK: onlar tek tek uç/
 * senaryo bazında "çapraz sahip okuma/yazma" ve "İzleyici'nin yazma tarafı"
 * sorularını cevaplıyor. Bu dosyanın sorusu farklı: "bu rolle giriş
 * yaptığımda sistemin TAMAMI bana ne açıyor" — yani ROL bazlı bir matris.
 * `PrivilegeEscalationTest` zaten Gate::before/rol matrisi editörünü
 * kilitliyor; burada yalnızca "kim rol matrisini OKUYABİLİR" (settings.manage
 * kapısı) test ediliyor, matrisin İÇERİĞİ/DÜZENLENMESİ değil.
 *
 * =============================================================================
 * MATRİS NASIL KURULDU — "EZBERDEN YAZMA" KURALINA UYUM
 * =============================================================================
 * Beklenen rol × modül erişimi elle transkript edilip HARDCODE EDİLMEDİ —
 * `expectedViewPermissions()` her rol için GERÇEKTEN seed edilmiş
 * `Role::hasPermissionTo()` sonucunu okur (Super Admin hariç: o zaten
 * `Gate::before` ile kısa devre yapıyor, DB'deki rol izinlerinden bağımsız).
 * Böylece test, `RolePermissionSeeder`'ın METNİNİ değil ÇALIŞMA ANI
 * ÇIKTISINI doğrular — seeder'da bir izin kayarsa test kendiliğinden buna
 * göre davranır DEĞİL, aksine test seeder'ın GERÇEKTEN ne ürettiğini
 * yansıtır (seeder'ın kendisi hatalıysa bu test o hatayı YAKALAMAZ; onu
 * yakalayan zaten seeder'ın kendi testleridir). Modül × izin haritası
 * (`MODULE_PERMISSIONS`) ise `RolePermissionSeeder::$permissions` sözlüğünden
 * DOĞRUDAN OKUNARAK (dosya `docs/PHASE-AUDIT.md` §3 görevi öncesi elle
 * incelendi) yazıldı; bu sabit değişmez çünkü hangi uç hangi izne bağlı
 * olduğu route/controller/policy tarafında sabit bir sözleşmedir.
 *
 * `chat.use` KASITLI OLARAK matrisin DIŞINDA: `.view` isimlendirme
 * kuralına uymuyor ve yetkilendirmesi modül-düz değil KAYIT (konuşma
 * üyeliği) bazlı — `ChannelAuthorizationTest`/`ChannelPayloadLeakTest` onu
 * zaten kapsıyor. Aynı şekilde `settings`/`logs.export`/`reports.export`/
 * `users.*` yazma eylemleri `.view` sözleşmesine uymadığı için matrisin
 * DIŞINDA, ayrı odaklı testlerde ele alınıyor (aşağıda).
 */
class RoleAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Modül adı => o modülün `index`/`show` uçlarını koruyan `.view` izni.
     * `RolePermissionSeeder::$permissions` sözlüğündeki 15 `.view` izninin
     * TAMAMI burada — İzleyici rolünün tam olarak bu kümeye sahip olması
     * (`$viewPermissions` filtresi) bilinçli bir sözleşme, aşağıdaki
     * `test_izleyici_role_has_exactly_the_fifteen_view_permissions_and_no_others`
     * bunu ayrıca kilitliyor.
     *
     * @var array<string, string>
     */
    private const MODULE_PERMISSIONS = [
        'dashboard' => 'dashboard.view',
        'users' => 'users.view',
        'roles' => 'roles.view',
        'leads' => 'leads.view',
        'contacts' => 'contacts.view',
        'companies' => 'companies.view',
        'deals' => 'deals.view',
        'tasks' => 'tasks.view',
        'activities' => 'activities.view',
        'tickets' => 'tickets.view',
        'products' => 'products.view',
        'quotes' => 'quotes.view',
        'reports' => 'reports.view',
        'logs' => 'logs.view',
        'notifications' => 'notifications.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actorWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Bu turda test edilecek 6 rol. Data provider DB'ye erişemez (setUp'tan
     * ÖNCE çalışır) — yalnızca rol adlarını döner, gerçek izin sorgusu test
     * gövdesinde (seed'den SONRA) yapılır.
     *
     * @return array<string, array{0: string}>
     */
    public static function roleProvider(): array
    {
        return [
            'Super Admin' => ['Super Admin'],
            'Admin' => ['Admin'],
            'Satış Müdürü' => ['Satış Müdürü'],
            'Satış Temsilcisi' => ['Satış Temsilcisi'],
            'Destek Temsilcisi' => ['Destek Temsilcisi'],
            'İzleyici' => ['İzleyici'],
        ];
    }

    /**
     * Verilen rolün GERÇEKTEN erişebileceği modül listesi — DB'deki seed
     * edilmiş izinlerden, ezberden değil.
     *
     * @return array<int, string>
     */
    private function expectedAccessibleModules(string $roleName): array
    {
        if ($roleName === 'Super Admin') {
            // Gate::before kısa devre: rolün DB'deki (boş) izin listesinden
            // bağımsız olarak HER şeye erişir (bkz. PrivilegeEscalationTest).
            return array_keys(self::MODULE_PERMISSIONS);
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->firstOrFail();

        return collect(self::MODULE_PERMISSIONS)
            ->filter(fn (string $permission): bool => $role->hasPermissionTo($permission))
            ->keys()
            ->all();
    }

    /**
     * Her modül için bir kayıt üretir ve GET ile sınanacak uçları döner.
     * `roles`/`dashboard`/`reports`/`logs`/`notifications` için ayrı bir
     * kayıt gerekmiyor (uçlar id almıyor ya da oturum sahibi kullanıcıya
     * göre çalışıyor) — yalnızca izin kontrolü ölçülüyor.
     *
     * @return array<string, array<int, string>>
     */
    private function moduleEndpoints(): array
    {
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $targetUser = User::factory()->create();
        $lead = Lead::factory()->create();
        $contact = Contact::factory()->create();
        $company = Company::factory()->create();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);
        $task = Task::factory()->create();
        $activity = Activity::factory()->create([
            'activityable_type' => Lead::class,
            'activityable_id' => $lead->id,
        ]);
        $ticket = Ticket::factory()->create();
        $product = Product::factory()->create();
        $quote = Quote::factory()->create();

        return [
            'dashboard' => ['/api/dashboard/kpis'],
            'users' => ['/api/users', "/api/users/{$targetUser->id}"],
            'roles' => ['/api/roles'],
            'leads' => ['/api/leads', "/api/leads/{$lead->id}"],
            'contacts' => ['/api/contacts', "/api/contacts/{$contact->id}"],
            'companies' => ['/api/companies', "/api/companies/{$company->id}"],
            'deals' => ['/api/deals', "/api/deals/{$deal->id}"],
            'tasks' => ['/api/tasks', "/api/tasks/{$task->id}"],
            'activities' => ['/api/activities', "/api/activities/{$activity->id}"],
            'tickets' => ['/api/tickets', "/api/tickets/{$ticket->id}"],
            'products' => ['/api/products', "/api/products/{$product->id}"],
            'quotes' => ['/api/quotes', "/api/quotes/{$quote->id}"],
            'reports' => ['/api/reports/sales-performance'],
            'logs' => ['/api/logs/sessions'],
            'notifications' => ['/api/notifications'],
        ];
    }

    // =========================================================================
    // ANA MATRİS — rol × 15 modül: görmesi gereken 200, görmemesi gereken 403
    // =========================================================================

    /**
     * Her rol için: `.view` izni OLAN her modülün `index`+`show` ucu 200,
     * `.view` izni OLMAYAN her modülün aynı uçları 403 dönmeli. Bu TEK test
     * hem görevin 1. maddesini ("erişebildiği modüller") hem 2. maddesini
     * ("erişemediği modüller") hem de İzleyici özel şartını ("okuma tarafı
     * tam açık" — İzleyici için allowedModules = 15 modülün TAMAMI) kilitler.
     */
    #[DataProvider('roleProvider')]
    public function test_role_sees_exactly_the_modules_its_seeded_view_permissions_grant_and_nothing_else(string $roleName): void
    {
        $actor = $this->actorWithRole($roleName);
        $endpoints = $this->moduleEndpoints();
        $allowedModules = $this->expectedAccessibleModules($roleName);

        // Sözleşme kontrolü: matris boş dönerse test sessizce "her şey 403"
        // diye geçer ve hiçbir şey doğrulamamış olur — bu asla olmamalı,
        // Super Admin/Admin en az bir modüle her zaman sahip.
        $this->assertNotEmpty($endpoints);

        foreach ($endpoints as $module => $uris) {
            $shouldSee = in_array($module, $allowedModules, true);

            foreach ($uris as $uri) {
                $response = $this->actingAs($actor)->getJson($uri);

                if ($shouldSee) {
                    $this->assertSame(
                        200,
                        $response->getStatusCode(),
                        sprintf(
                            '[%s] "%s" modülüne (izin: %s) erişebilmeliydi ama %s -> %d döndü.',
                            $roleName,
                            $module,
                            self::MODULE_PERMISSIONS[$module],
                            $uri,
                            $response->getStatusCode(),
                        ),
                    );
                } else {
                    $this->assertSame(
                        403,
                        $response->getStatusCode(),
                        sprintf(
                            '[%s] "%s" modülüne (izin: %s YOK) erişEMEmeliydi ama %s -> %d döndü.',
                            $roleName,
                            $module,
                            self::MODULE_PERMISSIONS[$module],
                            $uri,
                            $response->getStatusCode(),
                        ),
                    );
                }
            }
        }
    }

    /**
     * Sözleşme sabitleyici: İzleyici'nin 15 izni TAM OLARAK yukarıdaki
     * `MODULE_PERMISSIONS` haritasındaki 15 `.view` izniyle birebir eşleşir
     * — ne eksik ne fazla. Bu, ana matris testinin "İzleyici = tüm modüller
     * erişilebilir" varsayımının GERÇEKTEN doğru temele oturduğunu kilitler.
     */
    public function test_izleyici_role_has_exactly_the_fifteen_view_permissions_and_no_others(): void
    {
        $izleyici = Role::where('name', 'İzleyici')->where('guard_name', 'web')->firstOrFail();

        $actual = $izleyici->permissions->pluck('name')->sort()->values()->all();
        $expected = collect(self::MODULE_PERMISSIONS)->values()->sort()->values()->all();

        $this->assertSame($expected, $actual);
        $this->assertCount(15, $actual);
    }

    // =========================================================================
    // AYARLAR + ROL MATRİSİ — yalnız settings.manage (görev madde 3)
    // =========================================================================

    /**
     * `settings.manage` `.view` sözleşmesine UYMAZ (Model C'nin "yazma
     * sahiple sınırlı" tarafına da girmez — tamamen kendi başına idari bir
     * kapı) — bu yüzden ana matrisin DIŞINDA, ayrı test ediliyor. 6 rolün
     * SADECE Admin'in (ve Gate::before ile Super Admin'in) bu izne sahip
     * olduğu `RolePermissionSeeder`'dan doğrulandı: Satış Müdürü/Satış
     * Temsilcisi/Destek Temsilcisi/İzleyici hiçbirinde `settings.manage`
     * YOK — İzleyici tüm `.view` izinlerine sahip olsa bile `settings.manage`
     * bir `.view` izni OLMADIĞI için bu kapsam dışında kalır.
     */
    public function test_only_settings_manage_holders_can_read_settings_and_the_permission_matrix(): void
    {
        $admin = $this->actorWithRole('Admin');
        $salesManager = $this->actorWithRole('Satış Müdürü');
        $salesRep = $this->actorWithRole('Satış Temsilcisi');
        $supportRep = $this->actorWithRole('Destek Temsilcisi');
        $viewer = $this->actorWithRole('İzleyici');

        foreach (['/api/settings', '/api/settings/permission-matrix'] as $uri) {
            $this->actingAs($admin)->getJson($uri)->assertStatus(200);

            foreach ([$salesManager, $salesRep, $supportRep, $viewer] as $actor) {
                $this->actingAs($actor)->getJson($uri)->assertStatus(403);
            }
        }
    }

    // =========================================================================
    // LOGLAR — logs.view listeler, logs.export AYRI izin (görev madde 4)
    // =========================================================================

    /**
     * Seeder'dan doğrulandı: `logs.view` yalnızca Admin'de VE (bir `.view`
     * izni olarak) İzleyici'de var. `logs.export` ise HİÇBİR seed edilmiş
     * rolde YOK — yalnızca Super Admin (Gate::before) export alabiliyor.
     * Bu, denetim raporunda AYRICA bildirilecek bir gözlem: bugünkü matriste
     * normal bir kullanıcı log dışa aktaramaz, bu KISITLAYICI ama güvenli
     * bir varsayılan (üretim koduna DOKUNULMADI, yalnızca doğrulandı).
     */
    public function test_logs_view_grants_listing_but_logs_export_requires_the_separate_export_permission(): void
    {
        $admin = $this->actorWithRole('Admin'); // logs.view VAR, logs.export YOK
        $viewer = $this->actorWithRole('İzleyici'); // logs.view VAR (bir .view izni), logs.export YOK
        $salesManager = $this->actorWithRole('Satış Müdürü'); // logs.view YOK

        foreach ([$admin, $viewer] as $actor) {
            $this->actingAs($actor)->getJson('/api/logs/sessions')->assertStatus(200);
            // `type` ExportLogRequest'te zorunlu — 403 değil 422 almamak için geçerli değer.
            $this->actingAs($actor)->getJson('/api/logs/export?type=sessions')->assertStatus(403);
        }

        $this->actingAs($salesManager)->getJson('/api/logs/sessions')->assertStatus(403);
        $this->actingAs($salesManager)->getJson('/api/logs/export?type=sessions')->assertStatus(403);
    }

    // =========================================================================
    // RAPORLAR — reports.view listeler, reports.export AYRI izin (görev madde 5)
    // =========================================================================

    /**
     * Seeder'dan doğrulandı: `reports.view`+`reports.export` BİRLİKTE
     * Admin'de ve Satış Müdürü'nde var (ikisi de aynı anda veriliyor, ayrı
     * ayrı test edilmesi gereken bir "view var export yok" rolü YOK — bu
     * yüzden pozitif taraf için AYRICA `actorWithPermissions`-tarzı elle
     * izin verilmiş bir aktör kullanılıyor, `OwnershipIsolationTest`'teki
     * desenle aynı gerekçeyle: gerçek bir rol kompozisyonu yokken kuralı
     * izole test etmenin tek yolu budur). İzleyici `reports.view`'e sahip
     * (bir `.view` izni) ama `reports.export`'a DEĞİL — negatif tarafın
     * gerçek-rol örneği.
     */
    public function test_reports_view_grants_listing_but_reports_export_requires_the_separate_export_permission(): void
    {
        $viewOnlyActor = User::factory()->create();
        $viewOnlyActor->givePermissionTo('reports.view'); // reports.export YOK

        $this->actingAs($viewOnlyActor)->getJson('/api/reports/sales-performance')->assertStatus(200);
        $this->actingAs($viewOnlyActor)->getJson('/api/reports/export')->assertStatus(403);

        $viewer = $this->actorWithRole('İzleyici'); // reports.view VAR (.view izni), reports.export YOK
        $this->actingAs($viewer)->getJson('/api/reports/sales-performance')->assertStatus(200);
        $this->actingAs($viewer)->getJson('/api/reports/export')->assertStatus(403);

        $admin = $this->actorWithRole('Admin'); // reports.view + reports.export İKİSİ DE VAR
        $this->actingAs($admin)->getJson('/api/reports/sales-performance')->assertStatus(200);
        // `report` ExportReportRequest'te zorunlu (geçerli değer: sales-performance
        // vb.) — 403 değil 422 almamak için geçerli bir değer verilmeli.
        $this->actingAs($admin)->getJson('/api/reports/export?report=sales-performance')->assertStatus(200);

        $supportRep = $this->actorWithRole('Destek Temsilcisi'); // reports.view YOK
        $this->actingAs($supportRep)->getJson('/api/reports/sales-performance')->assertStatus(403);
        $this->actingAs($supportRep)->getJson('/api/reports/export')->assertStatus(403);
    }

    // =========================================================================
    // KULLANICI YÖNETİMİ — yalnız ilgili users.* izinleri (görev madde 6)
    // =========================================================================

    /**
     * `users.view` OKUMA (index/show) sağlar — bu zaten ana matriste
     * kilitlendi (İzleyici için 200). Burada YAZMA tarafı: İzleyici
     * `users.view` taşısa da `users.create/update/delete/toggle_active/
     * reset_password`'un HİÇBİRİNİ taşımıyor (RolePermissionSeeder'da
     * İzleyici yalnızca `.view` izinlerine sahip) — her yazma ucu AYRI
     * `users.*` iznini sorar, `users.view` yeterli DEĞİLDİR. `AuthzIdorTest`
     * §A2.4 İzleyici sweep'i `/api/users/*` uçlarını İÇERMİYOR (kasıtlı
     * boşluk — bu test onu dolduruyor), bu yüzden ÇAKIŞMA yok.
     */
    public function test_user_management_write_actions_require_their_specific_users_permission_not_just_users_view(): void
    {
        $viewer = $this->actorWithRole('İzleyici'); // users.view VAR, başka users.* YOK
        $target = $this->actorWithRole('Satış Temsilcisi');

        $this->actingAs($viewer)->postJson('/api/users', [
            'name' => 'İzleyici Denemesi',
            'email' => 'izleyici-users-probe@example.com',
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Satış Temsilcisi',
        ])->assertStatus(403);

        $this->actingAs($viewer)->patchJson("/api/users/{$target->id}", [
            'department' => 'Yeni Departman',
        ])->assertStatus(403);

        $this->actingAs($viewer)->deleteJson("/api/users/{$target->id}")->assertStatus(403);

        $this->actingAs($viewer)->patchJson("/api/users/{$target->id}/active", [
            'is_active' => false,
        ])->assertStatus(403);

        $this->actingAs($viewer)->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'An0ther!Str0ngPass9',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'izleyici-users-probe@example.com']);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null, 'is_active' => true]);

        // KONTRAST: Admin (users.create/update/delete/toggle_active/reset_password
        // TAŞIR) aynı uçlardan geçebiliyor — negatifin izinsizlik yüzünden
        // olduğunu, uç/gövde hatası olmadığını doğrular.
        $admin = $this->actorWithRole('Admin');

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Admin Denemesi',
            'email' => 'admin-users-probe@example.com',
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Satış Temsilcisi',
        ])->assertStatus(201);

        $this->actingAs($admin)->patchJson("/api/users/{$target->id}", [
            'department' => 'Admin Departmanı',
        ])->assertStatus(200);

        $this->actingAs($admin)->patchJson("/api/users/{$target->id}/active", [
            'is_active' => false,
        ])->assertStatus(200);

        $this->actingAs($admin)->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'An0ther!Str0ngPass9',
        ])->assertStatus(204);
    }
}
