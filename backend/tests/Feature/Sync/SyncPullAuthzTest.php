<?php

namespace Tests\Feature\Sync;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SavedView;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pull yanıtı, web yüzeyinin gizlediği şeyi masaüstüne taşımamalı:
 *  - silinmiş mesajın gövdesi ve ek kimliği (MessageResource maskesi),
 *  - kullanıcının modülüne erişemediği paylaşılan kayıtlı görünümler
 *    (SavedViewPolicy::view).
 */
class SyncPullAuthzTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineStageSeeder::class);
    }

    /**
     * @param  array<string, int>  $cursors
     * @return array<int, array<string, mixed>>
     */
    private function pullRows(string $token, string $table): array
    {
        return $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => [$table => 0],
        ])->assertOk()->json("tables.{$table}.rows") ?? [];
    }

    public function test_deleted_message_body_and_attachment_id_are_masked(): void
    {
        [$user, $token] = $this->deviceUser('Admin');
        $conversation = Conversation::factory()->dm()->createdBy($user)->withMembers([$user])->create();

        $deleted = Message::factory()->inConversation($conversation)->fromUser($user)
            ->withAttachment(Attachment::factory()->create())
            ->create(['body' => 'GIZLI_SILINMIS_GOVDE']);
        $deleted->delete();

        $kept = Message::factory()->inConversation($conversation)->fromUser($user)
            ->create(['body' => 'GORUNUR_GOVDE']);

        $rows = collect($this->pullRows($token, 'messages'))->keyBy('id');

        $this->assertTrue($rows->has($deleted->id), 'Mezar taşı satırı düşmemeli.');
        $this->assertNotNull($rows[$deleted->id]['deleted_at']);
        $this->assertNull($rows[$deleted->id]['body']);
        $this->assertNull($rows[$deleted->id]['attachment_id']);

        $this->assertSame('GORUNUR_GOVDE', $rows[$kept->id]['body']);
    }

    public function test_shared_saved_view_of_a_forbidden_module_is_not_pulled(): void
    {
        [$rep, $token] = $this->deviceUser('Satış Temsilcisi');
        [$support] = $this->deviceUser('Destek Temsilcisi');

        $ticketView = SavedView::factory()->forModule('tickets')->shared()->create(['user_id' => $support->id]);
        $dealView = SavedView::factory()->forModule('deals')->shared()->create(['user_id' => $support->id]);
        // Kendi görünümü bile olsa izni kalmamış bir modül gelmemeli (Policy::view ile aynı).
        $ownTicketView = SavedView::factory()->forModule('tickets')->create(['user_id' => $rep->id]);

        $ids = collect($this->pullRows($token, 'saved_views'))->pluck('id')->all();

        $this->assertContains($dealView->id, $ids);
        $this->assertNotContains($ticketView->id, $ids);
        $this->assertNotContains($ownTicketView->id, $ids);

        // Web tarafı aynı kararı veriyor: bu bir sözleşme eşitliği.
        $this->assertFalse($rep->can('view', $ticketView));
        $this->assertTrue($rep->can('view', $dealView));
    }
}
