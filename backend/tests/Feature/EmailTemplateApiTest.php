<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Faz 10 — e-posta şablonları (`/api/settings/email-templates*`).
 *
 * BU FAZDA E-POSTA GÖNDERİLMİYOR: sistem kapalı devre, `MAIL_MAILER=log`.
 * Testlerden biri bunu açıkça sabitler — bir sonraki fazda gönderim eklenirse
 * o testin BİLEREK güncellenmesi gerekir, kazara değil.
 */
class EmailTemplateApiTest extends TestCase
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
        $this->getJson('/api/settings/email-templates')->assertStatus(401);
    }

    public function test_every_endpoint_requires_settings_manage(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view', 'quotes.send']);
        $template = EmailTemplate::factory()->create();

        // Gövdeler GEÇERLİDİR — FormRequest doğrulaması controller'daki
        // yetki kontrolünden ÖNCE çalışır (projenin genel sırası); geçersiz
        // gövde 403 yerine 422 üretir ve test yetkiyi ölçmez olurdu.
        $this->actingAs($actor)->getJson('/api/settings/email-templates')->assertStatus(403);
        $this->actingAs($actor)->postJson('/api/settings/email-templates', [
            'key' => 'x_sablon', 'name' => 'X', 'subject' => 'S', 'body_html' => '<p>B</p>',
        ])->assertStatus(403);
        $this->actingAs($actor)->patchJson("/api/settings/email-templates/{$template->id}", ['name' => 'X'])
            ->assertStatus(403);
        $this->actingAs($actor)->deleteJson("/api/settings/email-templates/{$template->id}")->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Listeleme
    // -------------------------------------------------------------------

    public function test_the_list_includes_inactive_templates_by_default(): void
    {
        EmailTemplate::factory()->create(['key' => 'teklif_gonderildi', 'name' => 'A']);
        EmailTemplate::factory()->inactive()->create(['key' => 'eski_sablon', 'name' => 'B']);

        $this->actingAs($this->manager())->getJson('/api/settings/email-templates')
            ->assertStatus(200)->assertJsonCount(2, 'data');

        $this->actingAs($this->manager())->getJson('/api/settings/email-templates?include_inactive=0')
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_the_resource_always_exposes_a_variable_array(): void
    {
        EmailTemplate::factory()->create(['key' => 'bos', 'variables' => null]);

        $this->actingAs($this->manager())->getJson('/api/settings/email-templates')
            ->assertStatus(200)
            ->assertJsonPath('data.0.variables', []);
    }

    // -------------------------------------------------------------------
    // Oluşturma
    // -------------------------------------------------------------------

    public function test_a_template_is_created_and_its_variables_are_derived_from_the_text(): void
    {
        $response = $this->actingAs($this->manager())->postJson('/api/settings/email-templates', [
            'key' => 'teklif_gonderildi',
            'name' => 'Teklif Gönderimi',
            'subject' => 'Sayın {{ contact.name }}, teklifiniz hazır',
            'body_html' => '<p>{{ quote.title }} numaralı teklif ektedir. — {{ company.name }}</p>',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.key', 'teklif_gonderildi')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.variables', ['contact.name', 'quote.title', 'company.name']);
    }

    public function test_an_explicit_variable_list_is_kept_as_sent(): void
    {
        $response = $this->actingAs($this->manager())->postJson('/api/settings/email-templates', [
            'key' => 'hos_geldiniz',
            'name' => 'Hoş Geldiniz',
            'subject' => 'Merhaba',
            'body_html' => '<p>Merhaba</p>',
            // Metinde henüz geçmeyen ama planlanan değişken.
            'variables' => ['user.name'],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.variables', ['user.name']);
    }

    public function test_the_key_is_unique_and_must_be_a_programmatic_identifier(): void
    {
        EmailTemplate::factory()->create(['key' => 'teklif_gonderildi']);

        $this->actingAs($this->manager())->postJson('/api/settings/email-templates', [
            'key' => 'teklif_gonderildi', 'name' => 'X', 'subject' => 'S', 'body_html' => 'B',
        ])->assertStatus(422);

        $this->actingAs($this->manager())->postJson('/api/settings/email-templates', [
            'key' => 'Teklif Gönderildi', 'name' => 'X', 'subject' => 'S', 'body_html' => 'B',
        ])->assertStatus(422);
    }

    public function test_subject_and_body_are_required(): void
    {
        $this->actingAs($this->manager())->postJson('/api/settings/email-templates', [
            'key' => 'x_sablon', 'name' => 'X',
        ])->assertStatus(422)->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    // -------------------------------------------------------------------
    // Güncelleme
    // -------------------------------------------------------------------

    public function test_editing_the_body_refreshes_the_derived_variables(): void
    {
        $template = EmailTemplate::factory()->create([
            'key' => 'teklif_gonderildi',
            'subject' => 'Merhaba {{ contact.name }}',
            'body_html' => '<p>{{ quote.title }}</p>',
            'variables' => ['contact.name', 'quote.title'],
        ]);

        $this->actingAs($this->manager())->patchJson("/api/settings/email-templates/{$template->id}", [
            'body_html' => '<p>{{ deal.title }} için teklif</p>',
        ])
            ->assertStatus(200)
            // Konu değişmedi, gövde değişti: liste ikisinden yeniden türetilir.
            ->assertJsonPath('data.variables', ['contact.name', 'deal.title']);
    }

    public function test_resubmitting_the_unchanged_key_is_accepted_but_changing_it_is_not(): void
    {
        $template = EmailTemplate::factory()->create(['key' => 'teklif_gonderildi']);

        $this->actingAs($this->manager())->patchJson("/api/settings/email-templates/{$template->id}", [
            'key' => 'teklif_gonderildi', 'name' => 'Yeni Ad',
        ])->assertStatus(200)->assertJsonPath('data.name', 'Yeni Ad');

        $this->actingAs($this->manager())->patchJson("/api/settings/email-templates/{$template->id}", [
            'key' => 'baska_anahtar',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_TEMPLATE_KEY_IMMUTABLE')
            ->assertJsonPath('current_key', 'teklif_gonderildi');

        $this->assertDatabaseHas('email_templates', ['id' => $template->id, 'key' => 'teklif_gonderildi']);
    }

    public function test_a_template_can_be_switched_off_without_being_deleted(): void
    {
        $template = EmailTemplate::factory()->create(['key' => 'teklif_gonderildi']);

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/email-templates/{$template->id}", ['is_active' => false])
            ->assertStatus(200)->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('email_templates', ['id' => $template->id, 'is_active' => false]);
    }

    // -------------------------------------------------------------------
    // Silme — CustomField'dan KASITLI fark: gerçek silme
    // -------------------------------------------------------------------

    public function test_delete_removes_the_row(): void
    {
        $template = EmailTemplate::factory()->create(['key' => 'teklif_gonderildi']);

        $this->actingAs($this->manager())
            ->deleteJson("/api/settings/email-templates/{$template->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('email_templates', ['id' => $template->id]);
    }

    public function test_a_missing_template_returns_404(): void
    {
        $this->actingAs($this->manager())
            ->deleteJson('/api/settings/email-templates/999999')
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------
    // Kapalı devre
    // -------------------------------------------------------------------

    public function test_no_endpoint_in_this_phase_sends_mail(): void
    {
        Mail::fake();

        $this->actingAs($this->manager())->postJson('/api/settings/email-templates', [
            'key' => 'teklif_gonderildi',
            'name' => 'Teklif Gönderimi',
            'subject' => 'Sayın {{ contact.name }}',
            'body_html' => '<p>Merhaba</p>',
        ])->assertStatus(201);

        $this->actingAs($this->manager())->getJson('/api/settings/email-templates')->assertStatus(200);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }
}
