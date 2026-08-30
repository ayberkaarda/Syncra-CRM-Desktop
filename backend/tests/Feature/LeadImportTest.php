<?php

namespace Tests\Feature;

use App\Jobs\ImportLeadsJob;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeadImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // 'local' diski gerçek (temp) bir dizine yönlendirilir — fgetcsv gibi
        // ham dosya sistemi işlemleri Storage::fake() ile de çalışır, çünkü
        // 'local' sürücüsü gerçek diski kullanır, yalnızca kökü değişir.
        Storage::fake('local');
    }

    protected function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<int, string>>  $rows
     */
    protected function csvContent(array $header, array $rows): string
    {
        $lines = [implode(',', $header)];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    protected function defaultHeader(): array
    {
        return ['first_name', 'last_name', 'email', 'phone', 'company_name', 'position', 'source', 'status', 'score', 'notes'];
    }

    protected function csvFile(string $content, string $name = 'leads.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content)->mimeType('text/csv');
    }

    // -------------------------------------------------------------------
    // Yetkilendirme
    // -------------------------------------------------------------------

    public function test_user_without_import_permission_cannot_upload(): void
    {
        $actor = User::factory()->create();
        $content = $this->csvContent($this->defaultHeader(), [
            ['Ayşe', 'Yılmaz', 'ayse@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
        ]);

        $response->assertStatus(403);
    }

    public function test_user_without_import_permission_cannot_download_template(): void
    {
        $actor = User::factory()->create();

        $response = $this->actingAs($actor)->get('/api/leads/import/template');

        $response->assertStatus(403);
    }

    public function test_user_without_import_permission_cannot_read_batch_status(): void
    {
        $actor = User::factory()->create();

        $response = $this->actingAs($actor)->getJson('/api/leads/import/'.Str::uuid());

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Yükleme — mutlu yol
    // -------------------------------------------------------------------

    public function test_valid_csv_creates_leads_and_reports_correct_counts(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Ayşe', 'Yılmaz', 'ayse.yilmaz@example.com', '+90 532 111 22 33', 'Yılmaz Holding', 'Satın Alma Müdürü', 'website', 'new', '65', 'not'],
            ['Mehmet', 'Demir', 'mehmet.demir@example.com', '0532 222 33 44', 'Demir İnşaat', 'Genel Müdür', 'referral', 'contacted', '40', 'not'],
            ['Elif', 'Kaya', 'elif.kaya@example.com', '0532 333 44 55', 'Kaya A.Ş.', 'Uzman', 'event', 'new', '20', 'not'],
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_rows', 3)
            ->assertJsonPath('data.processed', 3)
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.failed', 0);

        $this->assertDatabaseHas('leads', ['email' => 'ayse.yilmaz@example.com', 'owner_id' => $actor->id]);
        $this->assertDatabaseHas('leads', ['email' => 'mehmet.demir@example.com']);
        $this->assertDatabaseHas('leads', ['email' => 'elif.kaya@example.com']);
    }

    public function test_missing_required_column_is_rejected_with_422(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        // last_name sütunu yok.
        $header = ['first_name', 'email', 'phone', 'company_name', 'position', 'source', 'status', 'score', 'notes'];
        $content = $this->csvContent($header, [
            ['Ayşe', 'ayse@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('last_name', (string) $response->json('errors.message'));
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_row_with_invalid_email_fails_but_others_are_processed(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Ayşe', 'Yılmaz', 'ayse.yilmaz@example.com', '', '', '', 'website', 'new', '50', ''], // row 2 - geçerli
            ['Bozuk', 'Satır', 'gecersiz-eposta', '', '', '', 'website', 'new', '50', ''], // row 3 - geçersiz email
            ['Elif', 'Kaya', 'elif.kaya@example.com', '', '', '', 'website', 'new', '50', ''], // row 4 - geçerli
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.failed', 1);

        $errors = $response->json('data.errors');
        $this->assertNotEmpty($errors);
        $failedRow = collect($errors)->firstWhere('row', 3);
        $this->assertNotNull($failedRow, 'Satır 3 için hata raporlanmalı.');
        $this->assertSame('error', $failedRow['level']);

        $this->assertDatabaseHas('leads', ['email' => 'ayse.yilmaz@example.com']);
        $this->assertDatabaseHas('leads', ['email' => 'elif.kaya@example.com']);
        $this->assertDatabaseMissing('leads', ['first_name' => 'Bozuk']);
    }

    // -------------------------------------------------------------------
    // Duplicate stratejileri
    // -------------------------------------------------------------------

    public function test_duplicate_mode_skip_skips_strong_duplicate(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);
        Lead::factory()->create(['email' => 'mevcut@example.com', 'first_name' => 'Eski']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Yeni', 'Ad', 'mevcut@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
            'duplicate_mode' => 'skip',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.created', 0);

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('leads', ['email' => 'mevcut@example.com', 'first_name' => 'Eski']);
    }

    public function test_duplicate_mode_create_creates_despite_duplicate(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);
        Lead::factory()->create(['email' => 'mevcut@example.com']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Yeni', 'Ad', 'mevcut@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
            'duplicate_mode' => 'create',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseCount('leads', 2);
    }

    public function test_duplicate_mode_update_updates_existing_lead(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);
        $existing = Lead::factory()->create(['email' => 'mevcut@example.com', 'first_name' => 'Eski']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Yeni', 'Ad', 'mevcut@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
            'duplicate_mode' => 'update',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.created', 0);

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('leads', ['id' => $existing->id, 'first_name' => 'Yeni']);
    }

    public function test_duplicate_mode_update_skips_when_duplicate_is_a_contact(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);
        $contact = Contact::factory()->create(['email' => 'musteri@example.com', 'first_name' => 'Değişmez']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Yeni', 'Ad', 'musteri@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
            'duplicate_mode' => 'update',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0);

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'first_name' => 'Değişmez']);
    }

    // -------------------------------------------------------------------
    // Dosya güvenliği
    // -------------------------------------------------------------------

    public function test_oversized_file_is_rejected(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        $file = UploadedFile::fake()->create('buyuk.csv', 5121, 'text/csv');

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_wrong_extension_and_mime_is_rejected(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        $file = UploadedFile::fake()->create('kotu.php', 10, 'application/x-httpd-php');

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_uploaded_file_is_deleted_after_processing(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Ayşe', 'Yılmaz', 'ayse.yilmaz@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
        ]);

        $response->assertStatus(200);

        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    // -------------------------------------------------------------------
    // Şablon
    // -------------------------------------------------------------------

    public function test_template_download_starts_with_bom_and_has_correct_headers(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        $response = $this->actingAs($actor)->get('/api/leads/import/template');

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $withoutBom = substr($content, 3);
        $firstLine = strtok($withoutBom, "\r\n");
        $this->assertSame(
            'first_name,last_name,email,phone,company_name,position,source,status,score,notes',
            $firstLine,
        );
    }

    // -------------------------------------------------------------------
    // Batch durumu
    // -------------------------------------------------------------------

    public function test_batch_status_is_forbidden_for_other_users(): void
    {
        $owner = $this->actorWithPermissions(['leads.import']);
        $stranger = $this->actorWithPermissions(['leads.import']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Ayşe', 'Yılmaz', 'ayse.yilmaz@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $importResponse = $this->actingAs($owner)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
        ]);

        $batchId = $importResponse->json('data.id');
        $this->assertNotNull($batchId);

        $response = $this->actingAs($stranger)->getJson("/api/leads/import/{$batchId}");

        $response->assertStatus(403);
    }

    public function test_batch_status_is_visible_to_owner(): void
    {
        $owner = $this->actorWithPermissions(['leads.import']);

        $content = $this->csvContent($this->defaultHeader(), [
            ['Ayşe', 'Yılmaz', 'ayse.yilmaz@example.com', '', '', '', 'website', 'new', '50', ''],
        ]);

        $importResponse = $this->actingAs($owner)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
        ]);

        $batchId = $importResponse->json('data.id');

        $response = $this->actingAs($owner)->getJson("/api/leads/import/{$batchId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.created', 1);
    }

    public function test_unknown_batch_returns_404(): void
    {
        $actor = $this->actorWithPermissions(['leads.import']);

        $response = $this->actingAs($actor)->getJson('/api/leads/import/'.Str::uuid());

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------
    // Büyük dosya -> kuyruk
    // -------------------------------------------------------------------

    public function test_large_file_is_queued_and_returns_202(): void
    {
        Queue::fake();

        $actor = $this->actorWithPermissions(['leads.import']);

        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = ["Ad{$i}", "Soyad{$i}", "kisi{$i}@example.com", '', '', '', 'website', 'new', '50', ''];
        }
        $content = $this->csvContent($this->defaultHeader(), $rows);

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => $this->csvFile($content),
        ]);

        $response->assertStatus(202);
        $this->assertNotNull($response->json('data.batch_id'));

        Queue::assertPushed(ImportLeadsJob::class);

        // Kuyruğa devredilen dosya İŞLENMEDEN silinmemeli — job henüz
        // çalışmadı (Queue::fake gerçek çalıştırmayı engelliyor).
        $this->assertNotEmpty(Storage::disk('local')->allFiles('imports'));
    }
}
