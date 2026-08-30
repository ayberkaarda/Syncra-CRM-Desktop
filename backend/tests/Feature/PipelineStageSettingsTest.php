<?php

namespace Tests\Feature;

use App\Events\DealMoved;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Faz 10 — pipeline aşama editörü (`/api/settings/pipeline-stages*`).
 *
 * Bu dosyanın asıl testi mutlu yol değil, PASİFLEŞTİRMEDİR:
 * `test_deactivating_a_stage_with_open_deals_...` üçlüsü, bir sütunun
 * kapatılmasının içindeki kartları sessizce görünmez kılmadığını ve toplu
 * taşımanın Kanban'ın kendi kurallarıyla (fractional index + version +
 * DealMoved) yapıldığını sabitler.
 *
 * Ayrıca Faz 7 ucunun (`GET /api/pipeline-stages`, `deals.view`)
 * DEĞİŞMEDİĞİNİ doğrulayan bir regresyon testi taşır: iki uç aynı
 * controller metodunu paylaşır.
 */
class PipelineStageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private PipelineStage $openA;

    private PipelineStage $openB;

    private PipelineStage $wonStage;

    private PipelineStage $lostStage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->openA = PipelineStage::factory()->create(['slug' => 'yeni-firsat', 'position' => 1, 'probability' => 10]);
        $this->openB = PipelineStage::factory()->create(['slug' => 'teklif-gonderildi', 'position' => 2, 'probability' => 60]);
        $this->wonStage = PipelineStage::factory()->won()->create(['slug' => 'kazanildi', 'position' => 3]);
        $this->lostStage = PipelineStage::factory()->lost()->create(['slug' => 'kaybedildi', 'position' => 4]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function manager(): User
    {
        return $this->actorWithPermissions(['settings.manage']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDeal(PipelineStage $stage, string $position, array $attributes = []): Deal
    {
        return Deal::factory()->create(array_merge([
            'pipeline_stage_id' => $stage->getKey(),
            'position' => $position,
            'version' => 1,
            'status' => 'open',
            'probability' => null,
            'closed_at' => null,
        ], $attributes));
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/settings/pipeline-stages')->assertStatus(401);
    }

    public function test_deals_view_alone_does_not_open_the_stage_editor(): void
    {
        // Panoyu görmek sütunları yeniden tanımlamaya yetmez.
        $actor = $this->actorWithPermissions(['deals.view', 'deals.update']);

        $this->actingAs($actor)->getJson('/api/settings/pipeline-stages')->assertStatus(403);
        $this->actingAs($actor)->postJson('/api/settings/pipeline-stages', ['name' => 'X'])->assertStatus(403);
        $this->actingAs($actor)
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['name' => 'X'])
            ->assertStatus(403);
        $this->actingAs($actor)
            ->postJson('/api/settings/pipeline-stages/reorder', ['ordered_ids' => [$this->openA->id]])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Listeleme — Faz 7 ucu DEĞİŞMEDİ
    // -------------------------------------------------------------------

    public function test_the_settings_list_includes_inactive_stages_by_default(): void
    {
        $this->openA->update(['is_active' => false]);

        $response = $this->actingAs($this->manager())->getJson('/api/settings/pipeline-stages');

        $response->assertStatus(200)->assertJsonCount(4, 'data');
    }

    public function test_the_settings_list_can_be_narrowed_to_active_stages(): void
    {
        $this->openA->update(['is_active' => false]);

        $response = $this->actingAs($this->manager())->getJson('/api/settings/pipeline-stages?include_inactive=0');

        $response->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_the_board_endpoint_keeps_its_phase_seven_behaviour(): void
    {
        $this->openA->update(['is_active' => false]);
        $actor = $this->actorWithPermissions(['deals.view']);

        // Varsayılan: yalnızca aktif — pano pasif sütun çizmemeli.
        $this->actingAs($actor)->getJson('/api/pipeline-stages')
            ->assertStatus(200)->assertJsonCount(3, 'data');

        // ...ve `settings.manage` istemez.
        $this->actingAs($actor)->getJson('/api/pipeline-stages?include_inactive=1')
            ->assertStatus(200)->assertJsonCount(4, 'data');
    }

    // -------------------------------------------------------------------
    // Oluşturma
    // -------------------------------------------------------------------

    public function test_a_new_stage_is_appended_to_the_end_with_a_slug_derived_from_its_name(): void
    {
        $response = $this->actingAs($this->manager())->postJson('/api/settings/pipeline-stages', [
            'name' => 'Teklif Hazırlanıyor',
            'probability' => 45,
            'color' => 'warning',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Teklif Hazırlanıyor')
            // Str::slug Türkçe karakterleri ASCII'ye indirger.
            ->assertJsonPath('data.slug', 'teklif-hazirlaniyor')
            ->assertJsonPath('data.position', 5)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.deals_count', 0);
    }

    public function test_a_clashing_generated_slug_gets_a_numeric_suffix(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages', ['name' => 'Yeni Fırsat'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'yeni-firsat-2');
    }

    public function test_position_and_is_active_cannot_be_set_on_create(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages', ['name' => 'X', 'position' => 1])
            ->assertStatus(422);

        $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages', ['name' => 'X', 'is_active' => false])
            ->assertStatus(422);
    }

    public function test_a_second_won_stage_is_refused(): void
    {
        $response = $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages', ['name' => 'Kazanıldı 2', 'is_won' => true]);

        $response->assertStatus(422)->assertJsonPath('code', 'STAGE_FLAG_ALREADY_EXISTS');
    }

    public function test_a_stage_cannot_be_won_and_lost_at_once(): void
    {
        $this->wonStage->delete();
        $this->lostStage->delete();

        $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages', ['name' => 'İkisi', 'is_won' => true, 'is_lost' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'STAGE_FLAG_CONFLICT');
    }

    // -------------------------------------------------------------------
    // name_key — çeviri anahtarı ayrımı (Sales Funnel'ın Türkçe kalma hatası)
    // -------------------------------------------------------------------

    /**
     * Seeder, seed ettiği 7 çekirdek aşamanın `name_key`'ini slug'la doldurur — arayüz bu
     * anahtarla `enums.json`daki `pipelineStage.<slug>` çevirisini basar.
     */
    public function test_the_seeder_fills_name_key_for_the_seven_core_stages(): void
    {
        PipelineStage::query()->delete();

        $this->seed(PipelineStageSeeder::class);

        foreach (PipelineStageSeeder::STAGES as $seedStage) {
            $this->assertDatabaseHas('pipeline_stages', [
                'slug' => $seedStage['slug'],
                'name_key' => $seedStage['slug'],
            ]);
        }
    }

    /**
     * `name` GERÇEKTEN değiştiğinde `name_key` NULL'lanır: isim artık MÜŞTERİ VERİSİDİR ve
     * bir daha çeviriyle ezilmemelidir.
     */
    public function test_renaming_a_stage_nulls_its_name_key(): void
    {
        $this->openA->update(['name_key' => 'yeni-firsat']);

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['name' => 'İlk Temas'])
            ->assertStatus(200)
            ->assertJsonPath('data.name_key', null);

        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openA->id, 'name_key' => null]);
    }

    /**
     * Yalnızca `color`/`probability` güncellemesi (`name` gönderilmeden) `name_key`'i BOZMAZ.
     */
    public function test_updating_color_or_probability_alone_preserves_name_key(): void
    {
        $this->openA->update(['name_key' => 'yeni-firsat']);

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['color' => 'info', 'probability' => 20])
            ->assertStatus(200)
            ->assertJsonPath('data.name_key', 'yeni-firsat');

        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openA->id, 'name_key' => 'yeni-firsat', 'color' => 'info']);
    }

    /**
     * `name` gönderilse bile DEĞER aynıysa (gerçek bir değişiklik yoksa) `name_key` korunur.
     */
    public function test_sending_the_same_name_value_does_not_null_the_name_key(): void
    {
        $this->openA->update(['name' => 'Yeni Fırsat', 'name_key' => 'yeni-firsat']);

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['name' => 'Yeni Fırsat'])
            ->assertStatus(200)
            ->assertJsonPath('data.name_key', 'yeni-firsat');
    }

    /**
     * Admin'in oluşturduğu YENİ bir aşamanın `name_key`'i her zaman NULL'dır — bu bizim
     * taksonomimiz değil, doğrudan müşteri verisidir.
     */
    public function test_a_newly_created_stage_has_a_null_name_key(): void
    {
        $response = $this->actingAs($this->manager())->postJson('/api/settings/pipeline-stages', [
            'name' => 'Özel Aşama',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name_key', null);
        $this->assertDatabaseHas('pipeline_stages', ['slug' => 'ozel-asama', 'name_key' => null]);
    }

    /**
     * `PipelineStageResource` yanıtı `name_key`'i taşır — arayüzün çeviri kararını verebilmesi
     * için gereken tek ekstra alan budur.
     */
    public function test_the_stage_resource_exposes_name_key(): void
    {
        $this->openA->update(['name_key' => 'yeni-firsat']);

        $this->actingAs($this->manager())->getJson('/api/settings/pipeline-stages')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $this->openA->id, 'name_key' => 'yeni-firsat']);
    }

    // -------------------------------------------------------------------
    // Güncelleme — sistem bayrakları değişmez
    // -------------------------------------------------------------------

    public function test_a_stage_can_be_renamed_and_recoloured(): void
    {
        $response = $this->actingAs($this->manager())->patchJson(
            "/api/settings/pipeline-stages/{$this->openA->id}",
            ['name' => 'İlk Temas', 'color' => 'info', 'probability' => 15],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'İlk Temas')
            ->assertJsonPath('data.color', 'info')
            ->assertJsonPath('data.probability', 15);
    }

    public function test_the_won_and_lost_flags_cannot_be_flipped(): void
    {
        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['is_won' => true])
            ->assertStatus(422);

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['is_lost' => true])
            ->assertStatus(422);

        $this->assertDatabaseHas('pipeline_stages', [
            'id' => $this->openA->id, 'is_won' => false, 'is_lost' => false,
        ]);
    }

    public function test_stage_order_cannot_be_written_through_the_update_endpoint(): void
    {
        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['position' => 9])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // PASİFLEŞTİRME — bu dalganın kilit kuralı
    // -------------------------------------------------------------------

    public function test_a_system_stage_can_never_be_deactivated(): void
    {
        foreach ([$this->wonStage, $this->lostStage] as $stage) {
            $this->actingAs($this->manager())
                ->patchJson("/api/settings/pipeline-stages/{$stage->id}", ['is_active' => false])
                ->assertStatus(422)
                ->assertJsonPath('code', 'STAGE_IS_SYSTEM');

            $this->assertDatabaseHas('pipeline_stages', ['id' => $stage->id, 'is_active' => true]);
        }
    }

    public function test_an_empty_stage_deactivates_without_a_target(): void
    {
        $response = $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['is_active' => false]);

        $response->assertStatus(200)->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openA->id, 'is_active' => false]);
    }

    public function test_a_closed_deal_does_not_block_deactivation(): void
    {
        // Kapalı kartlar taşınmaz ve pasifleştirmeyi de engellemez.
        $this->makeDeal($this->openA, 'a0001', [
            'status' => 'won', 'closed_at' => now(), 'won_reason' => 'Fiyat uygundu',
        ]);

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['is_active' => false])
            ->assertStatus(200);

        $this->assertDatabaseHas('deals', [
            'id' => Deal::query()->value('id'), 'pipeline_stage_id' => $this->openA->id,
        ]);
    }

    public function test_deactivating_a_stage_with_open_deals_requires_a_target(): void
    {
        $this->makeDeal($this->openA, 'a0001');
        $this->makeDeal($this->openA, 'a0002');
        $this->makeDeal($this->openA, 'a0003');
        // Kapalı kart sayıma girmemeli.
        $this->makeDeal($this->openA, 'a0004', ['status' => 'lost', 'closed_at' => now(), 'lost_reason' => 'Bütçe']);

        $response = $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['is_active' => false]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'STAGE_HAS_OPEN_DEALS')
            ->assertJsonPath('open_deals_count', 3)
            // Ortak hata zarfı da korunur (bootstrap/app.php sözleşmesi).
            ->assertJsonPath('errors.code', 'STAGE_HAS_OPEN_DEALS');

        // Seçenekler: aktif, kaynaktan farklı, sonuç aşaması DEĞİL.
        $available = collect($response->json('available_stages'));
        $this->assertSame([$this->openB->id], $available->pluck('id')->all());
        $this->assertSame($this->openB->name, $available->first()['name']);

        // Ve aşama hâlâ aktif — hiçbir şey yarım kalmadı.
        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openA->id, 'is_active' => true]);
    }

    public function test_open_deals_are_moved_to_the_end_of_the_target_stage_in_order(): void
    {
        Event::fake([DealMoved::class]);

        $existing = $this->makeDeal($this->openB, 'a0100');
        $first = $this->makeDeal($this->openA, 'a0001');
        $second = $this->makeDeal($this->openA, 'a0002');

        $response = $this->actingAs($this->manager())->patchJson(
            "/api/settings/pipeline-stages/{$this->openA->id}",
            ['is_active' => false, 'move_to_stage_id' => $this->openB->id],
        );

        $response->assertStatus(200)->assertJsonPath('data.is_active', false);

        $first->refresh();
        $second->refresh();

        $this->assertSame($this->openB->id, $first->pipeline_stage_id);
        $this->assertSame($this->openB->id, $second->pipeline_stage_id);

        // Hedefin SONUNA, kaynak sırayı koruyarak.
        $this->assertTrue($first->position > $existing->position);
        $this->assertTrue($second->position > $first->position);

        // Yalnızca küçük harfli base36 — kolonun collation'ı büyük/küçük harf
        // duyarsız olduğu için PHP ile MySQL sıralaması ancak böyle örtüşür.
        $this->assertMatchesRegularExpression('/^[0-9a-z]+$/', $first->position);
        $this->assertMatchesRegularExpression('/^[0-9a-z]+$/', $second->position);

        // Optimistic locking: uzaktaki bayat kart 409 almalı.
        $this->assertSame(2, $first->version);
        $this->assertSame(2, $second->version);

        // Kart AÇIK kalır: taşıma bir sonuç kararı değildir.
        $this->assertSame('open', $first->status);
        $this->assertNull($first->closed_at);
    }

    public function test_each_moved_deal_broadcasts_deal_moved(): void
    {
        Event::fake([DealMoved::class]);

        $deal = $this->makeDeal($this->openA, 'a0001');
        $this->makeDeal($this->openA, 'a0002');

        $this->actingAs($actor = $this->manager())->patchJson(
            "/api/settings/pipeline-stages/{$this->openA->id}",
            ['is_active' => false, 'move_to_stage_id' => $this->openB->id],
        )->assertStatus(200);

        Event::assertDispatchedTimes(DealMoved::class, 2);

        Event::assertDispatched(DealMoved::class, function (DealMoved $event) use ($deal, $actor): bool {
            if ((int) $event->payload['deal_id'] !== (int) $deal->getKey()) {
                return false;
            }

            return (int) $event->payload['from_stage_id'] === (int) $this->openA->getKey()
                && (int) $event->payload['to_stage_id'] === (int) $this->openB->getKey()
                && $event->payload['status'] === 'open'
                && (int) $event->payload['version'] === 2
                && (int) $event->payload['moved_by_id'] === (int) $actor->getKey();
        });
    }

    public function test_a_blank_probability_is_filled_from_the_target_but_a_set_one_survives(): void
    {
        Event::fake([DealMoved::class]);

        $blank = $this->makeDeal($this->openA, 'a0001', ['probability' => null]);
        $judged = $this->makeDeal($this->openA, 'a0002', ['probability' => 20]);

        $this->actingAs($this->manager())->patchJson(
            "/api/settings/pipeline-stages/{$this->openA->id}",
            ['is_active' => false, 'move_to_stage_id' => $this->openB->id],
        )->assertStatus(200);

        // Aşamanın olasılığı bir VARSAYILAN, kartınki bir YARGI.
        $this->assertSame(60, $blank->refresh()->probability);
        $this->assertSame(20, $judged->refresh()->probability);
    }

    public function test_the_target_stage_must_be_active_different_and_not_a_result_stage(): void
    {
        $this->makeDeal($this->openA, 'a0001');
        $inactive = PipelineStage::factory()->inactive()->create(['slug' => 'arsiv', 'position' => 9]);

        $cases = [
            $this->openA->id,   // kendisi
            $inactive->id,      // pasif
            $this->wonStage->id, // sonuç
            $this->lostStage->id, // sonuç
        ];

        foreach ($cases as $targetId) {
            $this->actingAs($this->manager())
                ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", [
                    'is_active' => false, 'move_to_stage_id' => $targetId,
                ])
                ->assertStatus(422)
                ->assertJsonPath('code', 'STAGE_INVALID_MOVE_TARGET');
        }

        // Hiçbiri yarım bırakmadı.
        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openA->id, 'is_active' => true]);
        $this->assertDatabaseHas('deals', [
            'id' => Deal::query()->value('id'), 'pipeline_stage_id' => $this->openA->id, 'version' => 1,
        ]);
    }

    public function test_a_missing_target_stage_is_rejected_by_validation(): void
    {
        $this->makeDeal($this->openA, 'a0001');

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", [
                'is_active' => false, 'move_to_stage_id' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_a_deactivated_stage_can_be_reopened(): void
    {
        $this->openA->update(['is_active' => false]);

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/pipeline-stages/{$this->openA->id}", ['is_active' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true);
    }

    // -------------------------------------------------------------------
    // Yeniden sıralama — SÜTUN sırası, kart sırası DEĞİL
    // -------------------------------------------------------------------

    public function test_reorder_rewrites_stage_positions_and_leaves_card_order_untouched(): void
    {
        $deal = $this->makeDeal($this->openA, 'a0001');

        $ordered = [$this->lostStage->id, $this->openB->id, $this->openA->id, $this->wonStage->id];

        $response = $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages/reorder', ['ordered_ids' => $ordered]);

        $response->assertStatus(200);
        $this->assertSame($ordered, collect($response->json('data'))->pluck('id')->all());

        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->lostStage->id, 'position' => 1]);
        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openB->id, 'position' => 2]);
        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openA->id, 'position' => 3]);
        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->wonStage->id, 'position' => 4]);

        // `deals.position` BAŞKA bir kolondur — hiç değişmemeli.
        $this->assertSame('a0001', $deal->refresh()->position);
        $this->assertSame(1, $deal->version);
    }

    public function test_reorder_requires_every_stage(): void
    {
        $response = $this->actingAs($this->manager())->postJson('/api/settings/pipeline-stages/reorder', [
            'ordered_ids' => [$this->openB->id, $this->openA->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'STAGE_REORDER_INCOMPLETE')
            ->assertJsonPath('expected_count', 4)
            ->assertJsonPath('received_count', 2);

        // Hiçbir sıra yazılmadı.
        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openA->id, 'position' => 1]);
    }

    public function test_reorder_rejects_duplicate_and_unknown_ids(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages/reorder', [
                'ordered_ids' => [$this->openA->id, $this->openA->id, $this->openB->id, $this->wonStage->id],
            ])
            ->assertStatus(422);

        $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages/reorder', [
                'ordered_ids' => [$this->openA->id, $this->openB->id, $this->wonStage->id, 999999],
            ])
            ->assertStatus(422);
    }

    public function test_inactive_stages_take_part_in_the_order(): void
    {
        $this->openA->update(['is_active' => false]);

        $ordered = [$this->openB->id, $this->openA->id, $this->wonStage->id, $this->lostStage->id];

        $this->actingAs($this->manager())
            ->postJson('/api/settings/pipeline-stages/reorder', ['ordered_ids' => $ordered])
            ->assertStatus(200);

        $this->assertDatabaseHas('pipeline_stages', ['id' => $this->openA->id, 'position' => 2]);
    }
}
