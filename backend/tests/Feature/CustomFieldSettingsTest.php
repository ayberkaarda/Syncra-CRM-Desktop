<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 10 — özel alan editörü (`/api/settings/custom-fields*`).
 *
 * İki kural bu dosyanın omurgasıdır:
 *   1. DELETE = PASİFLEŞTİRME; `custom_field_values` satırlarına dokunulmaz.
 *   2. `key` ve `entity_type` oluşturulduktan sonra değişmez.
 *
 * Ayrıca Faz 6'daki form şeması ucunun (`GET /api/custom-fields`, izinsiz,
 * yalnızca aktif alanlar) DEĞİŞMEDİĞİ doğrulanır — iki uç aynı controller
 * metodunu paylaşır.
 */
class CustomFieldSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
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

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/settings/custom-fields')->assertStatus(401);
    }

    public function test_the_editor_requires_settings_manage(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        $field = CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);

        // Gövdeler GEÇERLİDİR: yetkilendirme controller'da, doğrulama ise
        // FormRequest'te yapılır ve FormRequest ÖNCE çalışır (projenin her
        // modülünde aynı sıra — bkz. PriceListApiTest). Geçersiz bir gövde
        // 403'ten önce 422 alırdı ve test yetkiyi değil doğrulamayı ölçerdi.
        $this->actingAs($actor)->getJson('/api/settings/custom-fields')->assertStatus(403);
        $this->actingAs($actor)->postJson('/api/settings/custom-fields', [
            'entity_type' => 'leads', 'name' => 'X', 'key' => 'x_alani', 'type' => 'text',
        ])->assertStatus(403);
        $this->actingAs($actor)->patchJson("/api/settings/custom-fields/{$field->id}", ['name' => 'X'])
            ->assertStatus(403);
        $this->actingAs($actor)->deleteJson("/api/settings/custom-fields/{$field->id}")->assertStatus(403);
    }

    public function test_the_phase_six_form_schema_endpoint_is_unchanged(): void
    {
        CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce', 'is_active' => true]);
        CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'eski_alan', 'is_active' => false]);
        CustomField::factory()->create(['entity_type' => 'deals', 'key' => 'rakip', 'is_active' => true]);

        // İzin gerektirmez, `entity_type` ZORUNLU, yalnızca AKTİF alanlar.
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/custom-fields')->assertStatus(422);

        $this->actingAs($actor)->getJson('/api/custom-fields?entity_type=leads')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'butce');
    }

    // -------------------------------------------------------------------
    // Listeleme
    // -------------------------------------------------------------------

    public function test_the_editor_lists_every_entity_type_including_inactive_fields(): void
    {
        CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce', 'is_active' => true]);
        CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'eski_alan', 'is_active' => false]);
        CustomField::factory()->create(['entity_type' => 'deals', 'key' => 'rakip', 'is_active' => true]);

        $response = $this->actingAs($this->manager())->getJson('/api/settings/custom-fields');

        $response->assertStatus(200)->assertJsonCount(3, 'data');
        $this->assertContains('leads', $response->json('meta.entity_types'));
        $this->assertContains('multiselect', $response->json('meta.types'));
    }

    public function test_the_editor_can_be_filtered_by_entity_type(): void
    {
        CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);
        CustomField::factory()->create(['entity_type' => 'deals', 'key' => 'rakip']);

        $this->actingAs($this->manager())->getJson('/api/settings/custom-fields?entity_type=deals')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'rakip');
    }

    public function test_an_invalid_entity_type_filter_is_rejected(): void
    {
        $this->actingAs($this->manager())->getJson('/api/settings/custom-fields?entity_type=galaxies')
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Oluşturma
    // -------------------------------------------------------------------

    public function test_a_field_is_created_at_the_end_of_its_entity_type(): void
    {
        CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce', 'position' => 7]);
        CustomField::factory()->create(['entity_type' => 'deals', 'key' => 'rakip', 'position' => 40]);

        $response = $this->actingAs($this->manager())->postJson('/api/settings/custom-fields', [
            'entity_type' => 'leads',
            'name' => 'Referans Kaynağı',
            'key' => 'referans_kaynagi',
            'type' => 'text',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.key', 'referans_kaynagi')
            ->assertJsonPath('data.entity_type', 'leads')
            ->assertJsonPath('data.is_active', true)
            // Sıra ENTITY TİPİ İÇİNDE hesaplanır; `deals`taki 40 sayılmaz.
            ->assertJsonPath('data.position', 8);
    }

    public function test_a_key_must_be_a_programmatic_identifier(): void
    {
        foreach (['Bütçe Aralığı', '1_alan', 'Alan', 'alan-adi'] as $key) {
            $this->actingAs($this->manager())->postJson('/api/settings/custom-fields', [
                'entity_type' => 'leads', 'name' => 'X', 'key' => $key, 'type' => 'text',
            ])->assertStatus(422);
        }
    }

    public function test_a_key_is_unique_per_entity_type_but_may_repeat_across_types(): void
    {
        CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);

        $this->actingAs($this->manager())->postJson('/api/settings/custom-fields', [
            'entity_type' => 'leads', 'name' => 'Bütçe', 'key' => 'butce', 'type' => 'number',
        ])->assertStatus(422);

        $this->actingAs($this->manager())->postJson('/api/settings/custom-fields', [
            'entity_type' => 'deals', 'name' => 'Bütçe', 'key' => 'butce', 'type' => 'number',
        ])->assertStatus(201);
    }

    public function test_a_select_field_requires_options_and_a_plain_field_never_stores_them(): void
    {
        $this->actingAs($this->manager())->postJson('/api/settings/custom-fields', [
            'entity_type' => 'leads', 'name' => 'Segment', 'key' => 'segment', 'type' => 'select',
        ])->assertStatus(422);

        $this->actingAs($this->manager())->postJson('/api/settings/custom-fields', [
            'entity_type' => 'leads', 'name' => 'Segment', 'key' => 'segment', 'type' => 'select',
            'options' => ['Kurumsal', 'KOBİ'],
        ])->assertStatus(201)->assertJsonPath('data.options', ['Kurumsal', 'KOBİ']);

        // `text` alanında seçenek anlamsızdır ve saklanmaz.
        $this->actingAs($this->manager())->postJson('/api/settings/custom-fields', [
            'entity_type' => 'leads', 'name' => 'Not', 'key' => 'not_alani', 'type' => 'text',
            'options' => ['a', 'b'],
        ])->assertStatus(201)->assertJsonPath('data.options', null);
    }

    public function test_is_active_cannot_be_set_on_create(): void
    {
        $this->actingAs($this->manager())->postJson('/api/settings/custom-fields', [
            'entity_type' => 'leads', 'name' => 'X', 'key' => 'x_alani', 'type' => 'text', 'is_active' => false,
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Güncelleme — key / entity_type değişmez
    // -------------------------------------------------------------------

    public function test_a_field_can_be_renamed_and_reordered(): void
    {
        $field = CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce', 'name' => 'Bütçe', 'position' => 1]);

        $this->actingAs($this->manager())->patchJson("/api/settings/custom-fields/{$field->id}", [
            'name' => 'Tahmini Bütçe', 'position' => 3, 'is_required' => true,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Tahmini Bütçe')
            ->assertJsonPath('data.position', 3)
            ->assertJsonPath('data.is_required', true);
    }

    public function test_resubmitting_the_unchanged_key_is_accepted(): void
    {
        // Ayarlar formu salt-okunur `key` alanını da geri gönderir; bu
        // düzenlemeyi kilitlememeli.
        $field = CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);

        $this->actingAs($this->manager())->patchJson("/api/settings/custom-fields/{$field->id}", [
            'key' => 'butce', 'entity_type' => 'leads', 'name' => 'Yeni Ad',
        ])->assertStatus(200)->assertJsonPath('data.name', 'Yeni Ad');
    }

    public function test_changing_the_key_is_refused(): void
    {
        $field = CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);

        $this->actingAs($this->manager())->patchJson("/api/settings/custom-fields/{$field->id}", [
            'key' => 'yeni_anahtar',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOM_FIELD_KEY_IMMUTABLE')
            ->assertJsonPath('current_key', 'butce');

        $this->assertDatabaseHas('custom_fields', ['id' => $field->id, 'key' => 'butce']);
    }

    public function test_changing_the_entity_type_is_refused(): void
    {
        $field = CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);

        $this->actingAs($this->manager())->patchJson("/api/settings/custom-fields/{$field->id}", [
            'entity_type' => 'deals',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOM_FIELD_ENTITY_TYPE_IMMUTABLE');

        $this->assertDatabaseHas('custom_fields', ['id' => $field->id, 'entity_type' => 'leads']);
    }

    public function test_switching_a_select_field_to_text_clears_its_options(): void
    {
        $field = CustomField::factory()->select()->create(['entity_type' => 'leads', 'key' => 'segment']);
        $this->assertNotNull($field->options);

        $this->actingAs($this->manager())->patchJson("/api/settings/custom-fields/{$field->id}", ['type' => 'text'])
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.options', null);
    }

    public function test_a_select_field_cannot_be_left_without_options(): void
    {
        $field = CustomField::factory()->select()->create(['entity_type' => 'leads', 'key' => 'segment']);

        $this->actingAs($this->manager())->patchJson("/api/settings/custom-fields/{$field->id}", ['options' => []])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // DELETE = pasifleştirme, veri KALIR
    // -------------------------------------------------------------------

    public function test_delete_deactivates_the_field_and_keeps_its_values(): void
    {
        $field = CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);
        $lead = Lead::factory()->create();

        $value = CustomFieldValue::factory()->create([
            'custom_field_id' => $field->id,
            'customizable_type' => $lead->getMorphClass(),
            'customizable_id' => $lead->id,
            'value' => '250000',
        ]);

        $response = $this->actingAs($this->manager())->deleteJson("/api/settings/custom-fields/{$field->id}");

        // 204 DEĞİL: uç kaydı yok etmez, DURUMUNU değiştirir ve istemcinin
        // satırı "pasif" olarak yeniden çizmesi için güncel hâli döner.
        $response->assertStatus(200)->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('custom_fields', ['id' => $field->id, 'is_active' => false]);
        $this->assertDatabaseHas('custom_field_values', ['id' => $value->id, 'value' => '250000']);
    }

    public function test_a_deactivated_field_disappears_from_the_form_schema_and_comes_back_on_reactivation(): void
    {
        $field = CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);
        $actor = User::factory()->create();

        $this->actingAs($this->manager())->deleteJson("/api/settings/custom-fields/{$field->id}")->assertStatus(200);

        $this->actingAs($actor)->getJson('/api/custom-fields?entity_type=leads')
            ->assertStatus(200)->assertJsonCount(0, 'data');

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/custom-fields/{$field->id}", ['is_active' => true])
            ->assertStatus(200);

        $this->actingAs($actor)->getJson('/api/custom-fields?entity_type=leads')
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_deleting_twice_is_harmless(): void
    {
        $field = CustomField::factory()->create(['entity_type' => 'leads', 'key' => 'butce']);

        $this->actingAs($this->manager())->deleteJson("/api/settings/custom-fields/{$field->id}")->assertStatus(200);
        $this->actingAs($this->manager())->deleteJson("/api/settings/custom-fields/{$field->id}")
            ->assertStatus(200)->assertJsonPath('data.is_active', false);
    }
}
