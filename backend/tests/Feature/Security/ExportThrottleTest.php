<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H4/F3 — pahalı uçlarda (`/leads/import`, `/logs/export`, `/reports/export`)
 * sıklık sınırı regresyon kilidi.
 *
 * Her test METODU Laravel'in test ortamında TAZE bir uygulama örneği alır
 * (`TestCase::setUp()` -> `refreshApplication()`), bu da `CACHE_STORE=array`
 * (bkz. phpunit.xml) olduğu için rate limiter sayaçlarının test METODLARI
 * ARASINDA SIZMADIĞI anlamına gelir — ayrıca bir `RateLimiter::clear()`
 * çağrısına gerek yok. Tek bir test metodu içinde birden fazla istek atmak
 * KASITLI: bütçenin dolduğunu doğrulamanın tek yolu bu.
 *
 * Anahtar kimliğe (KULLANICIYA) göredir, IP'ye değil: kimliği doğrulanmış
 * her istekte Laravel'in varsayılan `throttle` middleware'i
 * `$request->user()->getAuthIdentifier()`'ı anahtar olarak kullanır (bkz.
 * Illuminate\Routing\Middleware\ThrottleRequests::resolveRequestSignature) —
 * routes/api.php'deki yorumda belgelenen tasarım kararı burada doğrulanır.
 */
class ExportThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function csvFile(): UploadedFile
    {
        $content = implode("\r\n", [
            'first_name,last_name,email,phone,company_name,position,source,status,score,notes',
            'Ayşe,Yılmaz,ayse.throttle@example.com,,,,website,new,50,',
        ])."\r\n";

        return UploadedFile::fake()->createWithContent('leads.csv', $content)->mimeType('text/csv');
    }

    // -------------------------------------------------------------------
    // POST /leads/import — throttle:5,1,leads-import
    // -------------------------------------------------------------------

    public function test_leads_import_allows_five_requests_per_minute_then_throttles(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($actor)
                ->postJson('/api/leads/import', ['file' => $this->csvFile()])
                ->assertStatus(200);
        }

        $this->actingAs($actor)
            ->postJson('/api/leads/import', ['file' => $this->csvFile()])
            ->assertStatus(429);
    }

    // -------------------------------------------------------------------
    // GET /logs/export ve GET /reports/export —
    // throttle:10,1,heavy-export (PAYLAŞILAN bütçe — bkz. routes/api.php)
    // -------------------------------------------------------------------

    public function test_logs_export_allows_ten_requests_per_minute_then_throttles(): void
    {
        $actor = $this->actorWithPermissions(['logs.export', 'logs.view']);

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($actor)
                ->getJson('/api/logs/export?type=sessions&format=csv')
                ->assertStatus(200);
        }

        $this->actingAs($actor)
            ->getJson('/api/logs/export?type=sessions&format=csv')
            ->assertStatus(429);
    }

    public function test_reports_export_and_logs_export_share_the_heavy_export_budget(): void
    {
        $actor = $this->actorWithPermissions(['reports.export', 'reports.view', 'logs.export', 'logs.view']);

        // İlk 5 istek logs/export'a, sonraki 5 istek reports/export'a —
        // ikisi AYNI `heavy-export` önekini paylaştığı için toplam 10'da
        // bütçe dolmuş olmalı.
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($actor)
                ->getJson('/api/logs/export?type=sessions&format=csv')
                ->assertStatus(200);
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($actor)
                ->getJson('/api/reports/export?report=conversion&format=csv&from=2026-06-01&to=2026-06-30')
                ->assertStatus(200);
        }

        // 11. istek — hangi uca gitse fark etmez, paylaşılan bütçe dolu.
        $this->actingAs($actor)
            ->getJson('/api/reports/export?report=conversion&format=csv&from=2026-06-01&to=2026-06-30')
            ->assertStatus(429);
    }

    // -------------------------------------------------------------------
    // Limit altındaki istekler farklı kullanıcıları KİLİTLEMEMELİ (kullanıcı
    // bazlı anahtar doğrulaması — NAT arkasında IP paylaşımı sorunu YOK).
    // -------------------------------------------------------------------

    public function test_throttle_budget_is_per_user_not_shared_across_users(): void
    {
        $first = $this->actorWithPermissions(['leads.import']);
        $second = $this->actorWithPermissions(['leads.import']);

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($first)
                ->postJson('/api/leads/import', ['file' => $this->csvFile()])
                ->assertStatus(200);
        }

        // İlk kullanıcının bütçesi dolu olsa da ikinci kullanıcı etkilenmez.
        $this->actingAs($second)
            ->postJson('/api/leads/import', ['file' => $this->csvFile()])
            ->assertStatus(200);
    }
}
