<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Faz 12 / Dosya eki backend'i.
 */
class AttachmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_upload_is_rejected(): void
    {
        Storage::fake('local');

        $this->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
        ])->assertStatus(401);
    }

    public function test_unauthenticated_show_is_rejected(): void
    {
        $attachment = Attachment::factory()->create();

        $this->getJson("/api/attachments/{$attachment->id}")->assertStatus(401);
    }

    public function test_user_without_chat_use_permission_cannot_upload(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create();

        $this->actingAs($actor)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
        ])->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Başarılı yükleme
    // -------------------------------------------------------------------

    public function test_upload_stores_file_with_random_disk_name_and_returns_expected_shape(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $response = $this->actingAs($actor)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('teklif.pdf', 180, 'application/pdf'),
        ]);

        $response->assertStatus(201);

        $id = $response->json('data.id');

        $response->assertJsonPath('data.original_name', 'teklif.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.is_image', false)
            ->assertJsonPath('data.url', "/api/attachments/{$id}");

        $attachment = Attachment::findOrFail($id);

        // Diskteki ad KULLANICI ADI DEĞİL — path traversal / dosya adı
        // enjeksiyonuna karşı rastgele (uuid) ad kullanılır.
        $this->assertNotSame('teklif.pdf', $attachment->filename);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/',
            $attachment->filename,
        );
        $this->assertSame('teklif.pdf', $attachment->original_name);
        $this->assertSame($actor->id, $attachment->uploaded_by);
        $this->assertNull($attachment->attachable_id);

        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame('local', $attachment->disk);
    }

    public function test_uploaded_image_is_flagged_as_is_image(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $response = $this->actingAs($actor)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->image('foto.jpg'),
        ]);

        $response->assertStatus(201)->assertJsonPath('data.is_image', true);
    }

    // -------------------------------------------------------------------
    // Allowlist / MIME doğrulama
    // -------------------------------------------------------------------

    public function test_disallowed_extension_is_rejected(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $this->actingAs($actor)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ])->assertStatus(422);

        $this->assertSame(0, Attachment::count());
    }

    /**
     * Çift uzantı (`fatura.pdf.exe`): getClientOriginalExtension() SON
     * uzantıya bakar ("exe"), bu allowlist'te olmadığı için doğal olarak
     * reddedilir.
     */
    public function test_double_extension_upload_is_rejected(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $this->actingAs($actor)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('fatura.pdf.exe', 10, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertSame(0, Attachment::count());
    }

    /**
     * Uzantı allowlist'te ("pdf") ama sunucu taraflı tespit edilen MIME
     * (test ortamında `create()`'in üçüncü parametresiyle simüle edilir —
     * gerçek ortamda finfo_file() içerik tespiti karşılığıdır) bu uzantı
     * için beklenenle uyuşmuyor: reddedilir.
     */
    public function test_mime_content_mismatch_with_extension_is_rejected(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $this->actingAs($actor)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('fatura.pdf', 10, 'application/x-msdownload'),
        ])->assertStatus(422);

        $this->assertSame(0, Attachment::count());
    }

    public function test_upload_exceeding_max_size_is_rejected(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        // max_size_kb = 25 * 1024 = 25600 — bir KB üstü reddedilmeli.
        $this->actingAs($actor)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('buyuk.pdf', 25601, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertSame(0, Attachment::count());
    }

    public function test_upload_within_max_size_is_accepted(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $this->actingAs($actor)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('tam-sinir.pdf', 25600, 'application/pdf'),
        ])->assertStatus(201);
    }

    public function test_missing_file_is_rejected(): void
    {
        $actor = $this->actorWithPermissions(['chat.use']);

        $this->actingAs($actor)->postJson('/api/attachments', [])->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Görüntüleme / indirme + IDOR
    // -------------------------------------------------------------------

    public function test_uploader_can_view_own_unattached_attachment(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $attachment = Attachment::factory()->create([
            'uploaded_by' => $actor->id,
            'path' => 'attachments/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
        ]);
        Storage::disk('local')->put($attachment->path, '%PDF-1.4 fake content');

        $response = $this->actingAs($actor)->get("/api/attachments/{$attachment->id}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    /**
     * IDOR: ek hiçbir mesaja bağlı değilken yalnızca yükleyen erişebilir —
     * başka bir kimliği doğrulanmış kullanıcı 404 alır (403 DEĞİL, varlık
     * sızdırılmaz).
     */
    public function test_unattached_attachment_is_not_visible_to_other_users(): void
    {
        Storage::fake('local');
        $uploader = $this->actorWithPermissions(['chat.use']);
        $intruder = $this->actorWithPermissions(['chat.use']);

        $attachment = Attachment::factory()->create([
            'uploaded_by' => $uploader->id,
            'path' => 'attachments/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
        ]);
        Storage::disk('local')->put($attachment->path, '%PDF-1.4 fake content');

        $this->actingAs($intruder)
            ->getJson("/api/attachments/{$attachment->id}")
            ->assertStatus(404);
    }

    /**
     * IDOR: ek bir mesaja bağlıysa yalnızca o mesajın konuşmasının
     * üyeleri erişebilir. Konuşmaya hiç katılmamış bir kullanıcı 404 alır.
     */
    public function test_conversation_member_can_view_message_attachment_but_outsider_gets_404(): void
    {
        Storage::fake('local');
        $memberA = $this->actorWithPermissions(['chat.use']);
        $memberB = $this->actorWithPermissions(['chat.use']);
        $outsider = $this->actorWithPermissions(['chat.use']);

        $conversation = Conversation::factory()->dm()->create(['created_by' => $memberA->id]);
        $conversation->users()->attach([$memberA->id, $memberB->id]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $memberA->id,
        ]);

        $attachment = Attachment::factory()->attachedTo($message)->create([
            'uploaded_by' => $memberA->id,
            'path' => 'attachments/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
        ]);
        Storage::disk('local')->put($attachment->path, '%PDF-1.4 fake content');

        // Üye (mesajı yazan değil, sadece konuşmada olan) erişebilir.
        $this->actingAs($memberB)
            ->getJson("/api/attachments/{$attachment->id}")
            ->assertStatus(200);

        // Konuşmada olmayan biri 404 alır — 403 DEĞİL.
        $this->actingAs($outsider)
            ->getJson("/api/attachments/{$attachment->id}")
            ->assertStatus(404);
    }

    public function test_show_returns_404_when_file_missing_from_disk(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $attachment = Attachment::factory()->create([
            'uploaded_by' => $actor->id,
            'path' => 'attachments/'.fake()->uuid().'.pdf',
        ]);
        // Diske kasıtlı olarak yazılmadı.

        $this->actingAs($actor)
            ->getJson("/api/attachments/{$attachment->id}")
            ->assertStatus(404);
    }

    public function test_soft_deleted_attachment_is_not_found(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $attachment = Attachment::factory()->create(['uploaded_by' => $actor->id]);
        $attachment->delete();

        $this->actingAs($actor)
            ->getJson("/api/attachments/{$attachment->id}")
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------
    // inline vs attachment disposition
    // -------------------------------------------------------------------

    public function test_image_can_be_served_inline_with_query_param(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $attachment = Attachment::factory()->image()->create([
            'uploaded_by' => $actor->id,
        ]);
        Storage::disk('local')->put($attachment->path, 'fake-image-bytes');

        $response = $this->actingAs($actor)->get("/api/attachments/{$attachment->id}?inline=1");

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_non_image_ignores_inline_query_param_and_downloads(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        $attachment = Attachment::factory()->create([
            'uploaded_by' => $actor->id,
            'mime_type' => 'application/pdf',
            'path' => 'attachments/'.fake()->uuid().'.pdf',
        ]);
        Storage::disk('local')->put($attachment->path, '%PDF-1.4 fake content');

        $response = $this->actingAs($actor)->get("/api/attachments/{$attachment->id}?inline=1");

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_response_mime_type_comes_from_database_not_disk_content(): void
    {
        Storage::fake('local');
        $actor = $this->actorWithPermissions(['chat.use']);

        // path .png ile bitiyor ama DB'deki doğrulanmış mime_type pdf —
        // yanıt DB değerini kullanmalı, dosyadan yeniden tahmin etmemeli.
        $attachment = Attachment::factory()->create([
            'uploaded_by' => $actor->id,
            'mime_type' => 'application/pdf',
            'path' => 'attachments/'.fake()->uuid().'.png',
        ]);
        Storage::disk('local')->put($attachment->path, 'irrelevant-bytes');

        $response = $this->actingAs($actor)->get("/api/attachments/{$attachment->id}");

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
