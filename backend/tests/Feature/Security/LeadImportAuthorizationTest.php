<?php

namespace Tests\Feature\Security;

use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadImportService;
use App\Support\ImportBatch;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lead CSV içe aktarma:
 *  - `update` modu web'deki PATCH ile aynı LeadPolicy::update() kapısından
 *    geçer; geçemeyen satır yazılmaz, raporda atlandı olarak görünür;
 *    güncelleme sahibi değiştirmez ve alan düzeyinde denetim izi bırakır;
 *  - `converted` durumu CSV'den de yazılamaz;
 *  - sütun uzunluğu ve geçersiz UTF-8 satır hatası olarak raporlanır, tüm
 *    içe aktarmayı düşürmez ve listeyi bozmaz.
 */
class LeadImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'first_name,last_name,email,phone,company_name,position,source,status,score,notes';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        // ImportBatch durumu Redis'te tutar; bu dosya içe aktarma KARARLARINI
        // sınar, Redis'i değil. Bellek içi bir sözlük, phpredis eklentisi
        // olmayan ortamda da testlerin gerçek yolu koşmasını sağlar.
        $store = [];
        Redis::shouldReceive('setex')->andReturnUsing(function (string $key, int $ttl, $value) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });
        Redis::shouldReceive('get')->andReturnUsing(fn (string $key) => $store[$key] ?? null);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actor(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function import(User $actor, array $lines, string $mode = 'update'): TestResponse
    {
        $content = self::HEADER."\r\n".implode("\r\n", $lines)."\r\n";

        return $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => UploadedFile::fake()->createWithContent('leads.csv', $content)->mimeType('text/csv'),
            'duplicate_mode' => $mode,
        ]);
    }

    // ------------------------------------------------------------ O2

    public function test_update_mode_skips_a_lead_owned_by_someone_else(): void
    {
        $owner = User::factory()->create();
        $actor = $this->actor(['leads.import', 'leads.update']);
        $lead = Lead::factory()->create(['email' => 'yabanci@example.com', 'first_name' => 'Eski', 'owner_id' => $owner->id]);

        $this->import($actor, ['Yeni,Ad,yabanci@example.com,,,,website,new,50,EZILDI'])
            ->assertOk()
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => 'Eski', 'owner_id' => $owner->id]);
    }

    public function test_update_mode_skips_a_converted_lead(): void
    {
        $actor = $this->actor(['leads.import', 'leads.update', 'leads.assign']);
        $lead = Lead::factory()->converted()->create(['email' => 'donusmus@example.com', 'first_name' => 'Eski']);

        $this->import($actor, ['Yeni,Ad,donusmus@example.com,,,,website,new,50,EZILDI'])
            ->assertOk()
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => 'Eski', 'status' => 'converted']);
    }

    public function test_update_mode_requires_leads_update(): void
    {
        $actor = $this->actor(['leads.import']);
        $lead = Lead::factory()->create(['email' => 'havuz@example.com', 'first_name' => 'Eski', 'owner_id' => null]);

        $this->import($actor, ['Yeni,Ad,havuz@example.com,,,,website,new,50,'])
            ->assertOk()
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => 'Eski']);
    }

    public function test_update_mode_updates_own_lead_without_taking_or_changing_ownership(): void
    {
        $actor = $this->actor(['leads.import', 'leads.update']);
        $mine = Lead::factory()->create(['email' => 'benim@example.com', 'first_name' => 'Eski', 'owner_id' => $actor->id]);
        $pool = Lead::factory()->create(['email' => 'havuz@example.com', 'first_name' => 'Eski', 'owner_id' => null]);

        $this->import($actor, [
            'Yeni,Ad,benim@example.com,,,,website,contacted,50,',
            'Yeni,Ad,havuz@example.com,,,,website,contacted,50,',
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertDatabaseHas('leads', ['id' => $mine->id, 'first_name' => 'Yeni', 'owner_id' => $actor->id]);
        // Havuzdaki lead güncellenir ama import eden kişiye geçmez.
        $this->assertDatabaseHas('leads', ['id' => $pool->id, 'first_name' => 'Yeni', 'owner_id' => null]);
    }

    public function test_update_mode_leaves_a_field_level_audit_row(): void
    {
        $actor = $this->actor(['leads.import', 'leads.update']);
        $lead = Lead::factory()->create(['email' => 'iz@example.com', 'notes' => 'ESKI_NOT', 'owner_id' => $actor->id]);

        $this->import($actor, ['Yeni,Ad,iz@example.com,,,,website,new,50,YENI_NOT'])->assertOk();

        $row = DB::table('activity_log')
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($row, 'Güncellenen lead için alan düzeyinde denetim satırı yazılmalı.');
        $this->assertStringContainsString('ESKI_NOT', (string) $row->properties);
    }

    /**
     * Karar kilitli satıra göre verilir ve yazım aynı transaction'da olur:
     * eşleşen lead `FOR UPDATE` ile okunmadan önce hiçbir `update leads`
     * çalışmaz. Kilitsiz anlık görüntüde araya giren bir dönüşüm `converted`
     * durumunu `new` ile ezdirebilir, bir devretme başkasının lead'ini
     * yazdırabilirdi. (Tek bağlantılı testte gerçek eşzamanlılık kurulamaz;
     * bu test kilit sözleşmesini sabitler.)
     */
    public function test_update_mode_decides_and_writes_under_a_row_lock(): void
    {
        $actor = $this->actor(['leads.import', 'leads.update']);
        $lead = Lead::factory()->create(['email' => 'kilit@example.com', 'first_name' => 'Eski', 'owner_id' => $actor->id]);

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, '`leads`')) {
                $log[] = ['sql' => $sql, 'level' => DB::transactionLevel()];
            }
        });

        $this->import($actor, ['Yeni,Ad,kilit@example.com,,,,website,new,50,'])->assertOk()->assertJsonPath('data.updated', 1);

        $lockAt = collect($log)->search(fn (array $q): bool => str_starts_with($q['sql'], 'select') && str_contains($q['sql'], 'for update'));
        $updateAt = collect($log)->search(fn (array $q): bool => str_starts_with($q['sql'], 'update `leads`'));

        $this->assertNotFalse($lockAt, 'Eşleşen lead FOR UPDATE ile okunmalı.');
        $this->assertNotFalse($updateAt);
        $this->assertLessThan($updateAt, $lockAt);
        // RefreshDatabase zaten bir seviye açar; kilit ondan derinde olmalı.
        $this->assertGreaterThan(1, $log[$lockAt]['level']);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => 'Yeni']);
    }

    /**
     * Kuyruktaki import'ta oturum yoktur; satır başına güncelleme denetimi
     * yine de import'u başlatan kullanıcıyı causer olarak taşımalı.
     */
    public function test_queued_update_audit_rows_carry_the_actor_as_causer(): void
    {
        $actor = $this->actor(['leads.import', 'leads.update']);
        $lead = Lead::factory()->create(['email' => 'kuyruk@example.com', 'notes' => 'ESKI', 'owner_id' => $actor->id]);

        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, self::HEADER."\r\nYeni,Ad,kuyruk@example.com,,,,website,new,50,YENI\r\n");
        $handle = fopen($path, 'rb');

        $this->assertNull(auth()->user(), 'Kuyruk bağlamı: oturum yok.');

        try {
            $service = app(LeadImportService::class);
            $service->readHeader($handle);
            $service->process(ImportBatch::start('kuyruk-test', $actor->id, 1), $handle, 'update', null, $actor);
        } finally {
            fclose($handle);
            @unlink($path);
        }

        $row = DB::table('activity_log')
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($actor->id, (int) $row->causer_id);
        $this->assertSame(User::class, $row->causer_type);
    }

    // ------------------------------------------------------------ status

    public function test_csv_cannot_create_a_converted_lead(): void
    {
        $actor = $this->actor(['leads.import']);

        $this->import($actor, ['Ayşe,Yılmaz,donusum@example.com,,,,website,converted,50,'], 'create')
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertDatabaseHas('leads', ['email' => 'donusum@example.com', 'status' => 'new']);
        $this->assertDatabaseMissing('leads', ['status' => 'converted']);
    }

    // ------------------------------------------------------------ length / encoding

    public function test_overlong_column_is_a_row_error_not_an_aborted_import(): void
    {
        $actor = $this->actor(['leads.import']);
        $long = str_repeat('a', 256);

        $response = $this->import($actor, [
            "{$long},Uzun,uzun@example.com,,,,website,new,50,",
            'Elif,Kaya,elif@example.com,'.str_repeat('5', 51).',,,website,new,50,',
            'Ayşe,Yılmaz,gecerli@example.com,,,,website,new,50,',
        ], 'create');

        $response->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 2);

        $this->assertSame([2, 3], collect($response->json('data.errors'))->where('level', 'error')->pluck('row')->sort()->values()->all());
        $this->assertDatabaseHas('leads', ['email' => 'gecerli@example.com']);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_invalid_utf8_is_a_row_error_and_the_list_stays_readable(): void
    {
        $actor = $this->actor(['leads.import', 'leads.view']);

        // "Ayşe" Windows-1254 ile: ş = 0xFE, geçerli bir UTF-8 dizisi değil.
        $response = $this->import($actor, [
            "Ay\xFEe,Y\xFDlmaz,cp1254@example.com,,,,website,new,50,",
            'Elif,Kaya,elif@example.com,,,,website,new,50,',
        ], 'create');

        $response->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 1);

        $this->assertDatabaseMissing('leads', ['email' => 'cp1254@example.com']);

        $this->actingAs($actor)->getJson('/api/leads')->assertOk()->assertJsonPath('meta.pagination.total', 1);
    }
}
