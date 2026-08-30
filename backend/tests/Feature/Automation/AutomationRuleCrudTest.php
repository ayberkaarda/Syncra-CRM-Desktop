<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationRule;
use App\Models\PipelineStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 14 / İz F — C4 otomasyon kuralları: CRUD + şema doğrulaması
 * (docs/PHASE-INTL.md §3). İzin-eşleme testleri (PHASE-AUDIT §5.4'ün İKİ
 * KATMANLI kısıtı) `tests/Feature/Security/AutomationRulePermissionTest.php`'te
 * AYRIDIR — bu dosya yalnızca `settings.manage` gating + katalog şema
 * doğrulamasını (whitelist, placeholder, uyumluluk) kapsar.
 */
class AutomationRuleCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    /**
     * Bu uygulamanın hata zarfı Laravel'in varsayılan `{"message":...,"errors":{field:[...]}}`
     * şekli DEĞİL — `{"errors":{"message":...,"code":...,"fields":{field:[...]}}}` (bkz.
     * `bootstrap/app.php` `$apiError`). `assertJsonValidationErrors()` bu özel zarfı TANIMAZ
     * (`errors` anahtarının DOĞRUDAN field=>mesaj haritası olmasını bekler), bu yüzden
     * doğrudan gövdeyi okuyup `errors.fields.<field>` anahtarının VARLIĞINI kontrol ediyoruz.
     * `field` noktalı bir isim OLABİLİR (ör. `trigger_config.pipeline_stage_id`) — bu, iç
     * içe bir JSON yolu DEĞİL, Laravel'in hata torbasının düz (flat) anahtarıdır.
     */
    private function assertHasFieldError(\Illuminate\Testing\TestResponse $response, string $field): void
    {
        $fields = $response->json('errors.fields') ?? [];
        $this->assertArrayHasKey($field, $fields, "'{$field}' için bir doğrulama hatası bekleniyordu. Gelen alanlar: ".implode(', ', array_keys($fields)));
    }

    private function validDealStagePayload(int $stageId): array
    {
        return [
            'name' => 'Aşamaya geçince görev aç',
            'trigger_type' => 'deal.stage_changed',
            'trigger_config' => ['pipeline_stage_id' => $stageId],
            'action_type' => 'task.create',
            'action_config' => [
                'title_template' => 'Takip et: {record_title} ({stage_name})',
                'assignee_type' => 'record_owner',
                'assignee_user_id' => null,
                'due_in_days' => 3,
            ],
        ];
    }

    // -------------------------------------------------------------------
    // settings.manage gating
    // -------------------------------------------------------------------

    public function test_index_requires_settings_manage(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/settings/automation-rules')->assertStatus(403);
    }

    public function test_store_requires_settings_manage(): void
    {
        $user = User::factory()->create();
        $stage = PipelineStage::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/settings/automation-rules', $this->validDealStagePayload($stage->id))
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    public function test_admin_can_create_list_update_and_delete_a_rule(): void
    {
        $admin = $this->adminUser();
        $stage = PipelineStage::factory()->create();

        $created = $this->actingAs($admin)
            ->postJson('/api/settings/automation-rules', $this->validDealStagePayload($stage->id))
            ->assertStatus(201)
            ->json('data');

        $this->assertDatabaseHas('automation_rules', [
            'id' => $created['id'],
            'trigger_type' => 'deal.stage_changed',
            'action_type' => 'task.create',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->getJson('/api/settings/automation-rules')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $created['id'])
            ->assertJsonStructure(['meta' => ['triggers', 'actions', 'title_placeholders']]);

        // Yalnızca aç/kapa — Policy::toggle() yoluna girer, eylem izni tekrar sorulmaz.
        $this->actingAs($admin)
            ->patchJson("/api/settings/automation-rules/{$created['id']}", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($admin)
            ->deleteJson("/api/settings/automation-rules/{$created['id']}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('automation_rules', ['id' => $created['id']]);
    }

    // -------------------------------------------------------------------
    // Şema doğrulaması — "ham JSON olduğu gibi güvenilmez"
    // -------------------------------------------------------------------

    public function test_unknown_trigger_type_is_rejected(): void
    {
        $admin = $this->adminUser();

        $payload = $this->validDealStagePayload(1);
        $payload['trigger_type'] = 'deal.exploded';

        $response = $this->actingAs($admin)->postJson('/api/settings/automation-rules', $payload)
            ->assertStatus(422);
        $this->assertHasFieldError($response, 'trigger_type');
    }

    public function test_unknown_config_key_is_rejected(): void
    {
        $admin = $this->adminUser();
        $stage = PipelineStage::factory()->create();

        $payload = $this->validDealStagePayload($stage->id);
        $payload['trigger_config']['unexpected_field'] = 'x';

        $response = $this->actingAs($admin)->postJson('/api/settings/automation-rules', $payload)
            ->assertStatus(422);
        $this->assertHasFieldError($response, 'trigger_config.unexpected_field');
    }

    public function test_nonexistent_pipeline_stage_is_rejected(): void
    {
        $admin = $this->adminUser();

        $payload = $this->validDealStagePayload(999999);

        $response = $this->actingAs($admin)->postJson('/api/settings/automation-rules', $payload)
            ->assertStatus(422);
        $this->assertHasFieldError($response, 'trigger_config.pipeline_stage_id');
    }

    public function test_title_template_rejects_placeholders_outside_the_whitelist(): void
    {
        $admin = $this->adminUser();
        $stage = PipelineStage::factory()->create();

        $payload = $this->validDealStagePayload($stage->id);
        $payload['action_config']['title_template'] = 'Tutar: {amount}';

        $response = $this->actingAs($admin)->postJson('/api/settings/automation-rules', $payload)
            ->assertStatus(422);
        $this->assertHasFieldError($response, 'action_config.title_template');
    }

    public function test_title_template_rejects_expression_like_placeholders(): void
    {
        // Serbest ifade değerlendirmesi YOK — parantez içi TAM OLARAK bir beyaz liste
        // adı olmalı, `{deal.amount}` gibi bir "erişim ifadesi" de reddedilir.
        $admin = $this->adminUser();
        $stage = PipelineStage::factory()->create();

        $payload = $this->validDealStagePayload($stage->id);
        $payload['action_config']['title_template'] = '{deal.amount}';

        $response = $this->actingAs($admin)->postJson('/api/settings/automation-rules', $payload)
            ->assertStatus(422);
        $this->assertHasFieldError($response, 'action_config.title_template');
    }

    public function test_deal_assign_owner_action_is_incompatible_with_ticket_created_trigger(): void
    {
        $admin = $this->adminUser();
        $targetUser = User::factory()->create();

        $payload = [
            'name' => 'Geçersiz kombinasyon',
            'trigger_type' => 'ticket.created',
            'trigger_config' => ['priority' => null],
            'action_type' => 'deal.assign_owner',
            'action_config' => ['user_id' => $targetUser->id],
        ];

        $response = $this->actingAs($admin)->postJson('/api/settings/automation-rules', $payload)
            ->assertStatus(422);
        $this->assertHasFieldError($response, 'action_type');
    }

    public function test_fixed_user_assignee_requires_assignee_user_id(): void
    {
        $admin = $this->adminUser();
        $stage = PipelineStage::factory()->create();

        $payload = $this->validDealStagePayload($stage->id);
        $payload['action_config']['assignee_type'] = 'fixed_user';
        $payload['action_config']['assignee_user_id'] = null;

        $response = $this->actingAs($admin)->postJson('/api/settings/automation-rules', $payload)
            ->assertStatus(422);
        $this->assertHasFieldError($response, 'action_config.assignee_user_id');
    }

    public function test_update_requires_full_config_bundle_when_any_config_field_is_touched(): void
    {
        $admin = $this->adminUser();
        $stage = PipelineStage::factory()->create();

        $rule = AutomationRule::factory()->for($admin, 'creator')->dealStageChanged($stage->id)->taskCreateAction()->create();

        $response = $this->actingAs($admin)
            ->patchJson("/api/settings/automation-rules/{$rule->id}", ['trigger_type' => 'ticket.created'])
            ->assertStatus(422);
        $this->assertHasFieldError($response, 'action_type');
        $this->assertHasFieldError($response, 'action_config');
    }
}
