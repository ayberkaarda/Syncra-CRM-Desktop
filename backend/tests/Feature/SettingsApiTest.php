<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET|PATCH /api/settings` — Faz 10.
 *
 * Ağırlık merkezi mutlu yol değil, DEĞER TİPLERİ ve YAZILAMAYAN KOLONLARDIR:
 * `settings` tablosu tek bir `text` kolonunda dört farklı tip taşır ve
 * `is_public` bir yapılandırmayı herkese açabilecek bir bayraktır.
 */
class SettingsApiTest extends TestCase
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
    protected function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function manager(): User
    {
        return $this->actorWithPermissions(['settings.manage']);
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/settings')->assertStatus(401);
    }

    public function test_user_without_settings_manage_cannot_read_settings(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/settings')->assertStatus(403);
    }

    public function test_user_without_settings_manage_cannot_update_settings(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        Setting::factory()->create(['key' => 'company.name', 'type' => 'string', 'group' => 'company']);

        $this->actingAs($actor)->patchJson('/api/settings', ['company.name' => 'X'])->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Okuma
    // -------------------------------------------------------------------

    public function test_index_returns_every_setting_cast_by_its_type(): void
    {
        Setting::factory()->create([
            'key' => 'company.name', 'value' => 'Syncra A.Ş.', 'type' => 'string', 'group' => 'company', 'is_public' => true,
        ]);
        Setting::factory()->create([
            'key' => 'quote.validity_days', 'value' => '30', 'type' => 'integer', 'group' => 'quote',
        ]);
        Setting::factory()->create([
            'key' => 'general.dark_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'general',
        ]);
        Setting::factory()->create([
            'key' => 'general.locales', 'value' => '["tr","en"]', 'type' => 'json', 'group' => 'general',
        ]);

        $response = $this->actingAs($this->manager())->getJson('/api/settings');

        $response->assertStatus(200)->assertJsonCount(4, 'data');

        $byKey = collect($response->json('data'))->keyBy('key');

        // Ham string DEĞİL, `type`'a göre cast edilmiş değer.
        $this->assertSame('Syncra A.Ş.', $byKey['company.name']['value']);
        $this->assertSame(30, $byKey['quote.validity_days']['value']);
        $this->assertTrue($byKey['general.dark_mode']['value']);
        $this->assertSame(['tr', 'en'], $byKey['general.locales']['value']);

        $this->assertTrue($byKey['company.name']['is_public']);
    }

    public function test_index_exposes_the_group_list_for_the_settings_tabs(): void
    {
        Setting::factory()->create(['key' => 'a.one', 'group' => 'company']);
        Setting::factory()->create(['key' => 'b.two', 'group' => 'general']);
        Setting::factory()->create(['key' => 'c.three', 'group' => 'general']);

        $response = $this->actingAs($this->manager())->getJson('/api/settings');

        $response->assertStatus(200);
        $this->assertSame(['company', 'general'], $response->json('meta.groups'));
    }

    public function test_the_seeded_dictionary_is_readable_end_to_end(): void
    {
        $this->seed(SettingSeeder::class);

        $response = $this->actingAs($this->manager())->getJson('/api/settings');

        $response->assertStatus(200);

        $byKey = collect($response->json('data'))->keyBy('key');

        $this->assertArrayHasKey('company.name', $byKey);
        // Seeder'da `'30'` string olarak yazılı; uç onu tam sayı döndürmeli.
        $this->assertSame(30, $byKey['quote.validity_days']['value']);
        $this->assertSame(20, $byKey['quote.default_tax_rate']['value']);
    }

    // -------------------------------------------------------------------
    // Yazma
    // -------------------------------------------------------------------

    public function test_a_flat_dotted_key_map_updates_the_matching_rows(): void
    {
        Setting::factory()->create(['key' => 'company.name', 'value' => 'Eski', 'type' => 'string', 'group' => 'company']);
        Setting::factory()->create(['key' => 'company.email', 'value' => 'eski@x.local', 'type' => 'string', 'group' => 'company']);

        $response = $this->actingAs($this->manager())->patchJson('/api/settings', [
            'company.name' => 'Syncra Teknoloji A.Ş.',
            'company.email' => 'info@syncra.local',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('settings', ['key' => 'company.name', 'value' => 'Syncra Teknoloji A.Ş.']);
        $this->assertDatabaseHas('settings', ['key' => 'company.email', 'value' => 'info@syncra.local']);

        // Yanıt, kaydedilen hâlin TAMAMINI döner: istemci ikinci bir GET
        // atmadan formu tazeleyebilmeli.
        $byKey = collect($response->json('data'))->keyBy('key');
        $this->assertSame('Syncra Teknoloji A.Ş.', $byKey['company.name']['value']);
    }

    public function test_settings_not_present_in_the_body_are_left_alone(): void
    {
        Setting::factory()->create(['key' => 'company.name', 'value' => 'Eski', 'type' => 'string', 'group' => 'company']);
        Setting::factory()->create(['key' => 'company.phone', 'value' => '+90', 'type' => 'string', 'group' => 'company']);

        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['company.name' => 'Yeni'])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', ['key' => 'company.phone', 'value' => '+90']);
    }

    public function test_an_unknown_key_is_rejected(): void
    {
        Setting::factory()->create(['key' => 'company.name', 'type' => 'string', 'group' => 'company']);

        $response = $this->actingAs($this->manager())->patchJson('/api/settings', [
            'company.name' => 'Yeni',
            'company.secret_backdoor' => 'x',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.code', 'VALIDATION_ERROR');

        // NOT: `assertJsonPath()` KULLANILAMAZ — `data_get()` nokta ile yol
        // ayırır ve ayar anahtarlarının kendisi nokta içerir
        // ("company.secret_backdoor"), yani yol iki seviye derine iner ve
        // hiçbir zaman eşleşmez. Alan haritası düz dizi olarak okunur.
        $fields = $response->json('errors.fields');
        $this->assertArrayHasKey('company.secret_backdoor', $fields);
        $this->assertSame(
            'Bilinmeyen ayar anahtarı: company.secret_backdoor.',
            $fields['company.secret_backdoor'][0]
        );

        // Aynı istekteki GEÇERLİ anahtar da yazılmamalı: doğrulama toplu,
        // yazma tek transaction.
        $this->assertDatabaseMissing('settings', ['key' => 'company.name', 'value' => 'Yeni']);
    }

    public function test_column_names_are_not_settable_keys(): void
    {
        Setting::factory()->create(['key' => 'company.name', 'type' => 'string', 'group' => 'company', 'is_public' => false]);

        // `is_public` / `type` / `group` birer AYAR ANAHTARI değildir; gövdede
        // görünürlerse "bilinmeyen anahtar" olarak reddedilirler ve hiçbir
        // kolona yazılmazlar.
        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['is_public' => true, 'type' => 'json'])
            ->assertStatus(422);

        $this->assertDatabaseHas('settings', ['key' => 'company.name', 'is_public' => false, 'type' => 'string']);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $this->actingAs($this->manager())
            ->patchJson('/api/settings', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    // -------------------------------------------------------------------
    // Tip doğrulaması — `type` kolonuna göre
    // -------------------------------------------------------------------

    public function test_an_integer_setting_rejects_a_non_numeric_value(): void
    {
        Setting::factory()->create(['key' => 'quote.validity_days', 'value' => '30', 'type' => 'integer', 'group' => 'quote']);

        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['quote.validity_days' => 'otuz'])
            ->assertStatus(422)
            ->assertJsonFragment(['Bu ayar bir tam sayı bekliyor.']);

        $this->assertDatabaseHas('settings', ['key' => 'quote.validity_days', 'value' => '30']);
    }

    public function test_an_integer_setting_accepts_a_numeric_string(): void
    {
        Setting::factory()->create(['key' => 'quote.validity_days', 'value' => '30', 'type' => 'integer', 'group' => 'quote']);

        $response = $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['quote.validity_days' => '45']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('settings', ['key' => 'quote.validity_days', 'value' => '45']);

        $byKey = collect($response->json('data'))->keyBy('key');
        $this->assertSame(45, $byKey['quote.validity_days']['value']);
    }

    public function test_a_boolean_setting_stores_one_or_zero_and_rejects_garbage(): void
    {
        Setting::factory()->create(['key' => 'general.dark_mode', 'value' => '0', 'type' => 'boolean', 'group' => 'general']);

        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['general.dark_mode' => true])
            ->assertStatus(200);
        $this->assertDatabaseHas('settings', ['key' => 'general.dark_mode', 'value' => '1']);

        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['general.dark_mode' => false])
            ->assertStatus(200);
        $this->assertDatabaseHas('settings', ['key' => 'general.dark_mode', 'value' => '0']);

        // `filter_var(..., FILTER_VALIDATE_BOOLEAN)` bayraksız çağrılsaydı
        // "belki" sessizce `false` olarak KAYDEDİLİRDİ.
        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['general.dark_mode' => 'belki'])
            ->assertStatus(422);
        $this->assertDatabaseHas('settings', ['key' => 'general.dark_mode', 'value' => '0']);
    }

    public function test_a_json_setting_requires_an_array(): void
    {
        Setting::factory()->create(['key' => 'general.locales', 'value' => '["tr"]', 'type' => 'json', 'group' => 'general']);

        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['general.locales' => ['tr', 'en']])
            ->assertStatus(200);

        $this->assertSame(['tr', 'en'], Setting::get('general.locales'));

        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['general.locales' => 'tr,en'])
            ->assertStatus(422);
    }

    public function test_a_string_setting_rejects_an_array(): void
    {
        Setting::factory()->create(['key' => 'company.name', 'value' => 'X', 'type' => 'string', 'group' => 'company']);

        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['company.name' => ['a', 'b']])
            ->assertStatus(422);

        $this->assertDatabaseHas('settings', ['key' => 'company.name', 'value' => 'X']);
    }

    public function test_a_null_value_clears_the_setting(): void
    {
        Setting::factory()->create(['key' => 'company.address', 'value' => 'Eski adres', 'type' => 'string', 'group' => 'company']);

        $this->actingAs($this->manager())
            ->patchJson('/api/settings', ['company.address' => null])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', ['key' => 'company.address', 'value' => null]);
    }
}
