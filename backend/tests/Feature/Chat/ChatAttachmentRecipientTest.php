<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gerçek akış: dosya yüklenir, mesaja `attachment_id` ile iliştirilir.
 * MessageService `attachable_*`'a yazmadığı için alıcı daha önce 404 alıyordu.
 */
class ChatAttachmentRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function chatter(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('chat.use');

        return $user;
    }

    public function test_recipient_can_download_a_file_sent_in_their_conversation(): void
    {
        $sender = $this->chatter();
        $recipient = $this->chatter();
        $outsider = $this->chatter();

        $conversation = Conversation::factory()->dm()->createdBy($sender)->withMembers([$sender, $recipient])->create();

        $attachmentId = $this->actingAs($sender)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('teklif.pdf', 50, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $messageId = $this->actingAs($sender)->postJson("/api/conversations/{$conversation->id}/messages", [
            'attachment_id' => $attachmentId,
        ])->assertCreated()->json('data.id');

        $this->actingAs($recipient)->get("/api/attachments/{$attachmentId}")->assertOk();
        $this->actingAs($sender)->get("/api/attachments/{$attachmentId}")->assertOk();
        $this->actingAs($outsider)->getJson("/api/attachments/{$attachmentId}")->assertNotFound();

        // Mesaj silinince (mezar taşı) dosya yalnız yükleyende kalır.
        Message::query()->findOrFail($messageId)->delete();

        $this->actingAs($recipient)->getJson("/api/attachments/{$attachmentId}")->assertNotFound();
        $this->actingAs($sender)->get("/api/attachments/{$attachmentId}")->assertOk();
    }

    /**
     * Baypas denemesi: başkasının yüklediği dosyayı kendi sohbetine iliştirip
     * erişim kazanmak mümkün değil.
     */
    public function test_attaching_someone_elses_upload_grants_nothing(): void
    {
        $owner = $this->chatter();
        $thief = $this->chatter();
        $accomplice = $this->chatter();

        $attachmentId = $this->actingAs($owner)->postJson('/api/attachments', [
            'file' => UploadedFile::fake()->create('gizli.pdf', 50, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $conversation = Conversation::factory()->dm()->createdBy($thief)->withMembers([$thief, $accomplice])->create();

        $this->actingAs($thief)->postJson("/api/conversations/{$conversation->id}/messages", [
            'attachment_id' => $attachmentId,
        ])->assertStatus(422);

        $this->actingAs($thief)->getJson("/api/attachments/{$attachmentId}")->assertNotFound();
        $this->actingAs($accomplice)->getJson("/api/attachments/{$attachmentId}")->assertNotFound();
    }
}
