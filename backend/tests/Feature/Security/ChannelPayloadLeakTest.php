<?php

namespace Tests\Feature\Security;

use App\Broadcasting\ChannelRegistry;
use App\Models\Deal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Faz 13 / İz A / A3.2 + A3.6 — presence kanallarının `channel_data` olarak
 * DİĞER her aboneye yayınladığı payload'ın PII/iç-bayrak sızdırmadığını
 * kilitleyen regresyon testleri.
 *
 * Presence kanalları özel: private kanalların aksine, yetkilendirme
 * callback'inin dizi dönüşü sadece "kabul" anlamına gelmez — o dizi kanaldaki
 * HERKESE `channel_data` olarak yayınlanır (Pusher/Reverb presence protokolü).
 * Bu yüzden `ChannelRegistry::payload()`'ın ürettiği alan kümesi, kanal
 * yetkilendirmesinden ayrı, kendi başına bir güvenlik sınırıdır: buraya
 * sızacak her fazladan alan (ör. `must_change_password`, `is_active`,
 * `password`) o anda kanaldaki HERKESE görünür olur.
 *
 * Kanal geçişleri (record/logs/dashboard/deals/tickets/conversation) ve
 * IDOR/sınıf-enjeksiyonu senaryoları {@see ChannelAuthorizationTest}'te;
 * bu dosya SADECE dönen verinin şeklini denetler.
 *
 * ---------------------------------------------------------------------------
 * NEDEN `reverb` SÜRÜCÜSÜ ZORLANIYOR
 * ---------------------------------------------------------------------------
 * phpunit.xml BROADCAST_CONNECTION=null ayarlar ve NullBroadcaster::auth()
 * her isteğe boş/200 döner — `channel_data` üretmez, callback'i hiç çağırmaz.
 * `null` sürücüsüyle bu dosyadaki testler PAYLOAD'I HİÇ GÖRMEDEN geçer (yeşil
 * ama hiçbir şeyi doğrulamayan test — ROADMAP R14). setUp() bu yüzden
 * `Tests\Feature\BroadcastingTest`'teki desenle birebir aynı şekilde gerçek
 * `reverb` (Pusher-protokolü) broadcaster'ını devreye sokar; bu, imzayı yerel
 * HMAC ile üretir ve hiçbir soket açmaz / gerçek yayın tetiklemez.
 */
class ChannelPayloadLeakTest extends TestCase
{
    use RefreshDatabase;

    private const SOCKET = '161718.192021';

    /**
     * Presence payload'ının izin verilen TAM alan kümesi.
     * `ChannelRegistry::payload()` ile bire bir senkron tutulmalı.
     */
    private const ALLOWED_FIELDS = ['id', 'name', 'email', 'role', 'department'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'host' => '127.0.0.1',
                    'port' => 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
            ],
        ]);

        // BroadcastingTest.php'deki purge+require deseninin birebir aynısı:
        // reverb sürücüsü sıfır kanal sözlüğüyle boot olur, channels.php'yi
        // yeniden require etmek gerçek callback'leri onun üzerine bağlar.
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function authorize(?User $user, string $channel, array $extra = []): TestResponse
    {
        $payload = ['socket_id' => self::SOCKET, 'channel_name' => $channel] + $extra;

        $request = $user === null ? $this : $this->actingAs($user);

        return $request->postJson('/broadcasting/auth', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function channelData(TestResponse $response): array
    {
        $decoded = json_decode((string) $response->json('channel_data'), true);

        $this->assertIsArray($decoded, 'channel_data JSON olarak çözülemedi.');

        return $decoded;
    }

    /* ========================================================================
     * A3.2 — presence-online payload'ı
     * ===================================================================== */

    public function test_online_presence_payload_carries_only_the_five_whitelisted_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Ayşe Yılmaz',
            'email' => 'ayse@example.test',
            'department' => 'Satış',
        ]);
        $user->assignRole('Satış Temsilcisi');

        $response = $this->authorize($user, 'presence-online')->assertOk();
        $channelData = $this->channelData($response);

        $this->assertSame((string) $user->id, (string) $channelData['user_id']);

        $userInfo = $channelData['user_info'];

        // Tam eşitlik: eksik alan kadar FAZLA alan da testi kırmalı — ileride
        // eklenecek bir alan (ör. `phone`, `permissions`) burada yakalanmalı.
        $this->assertEqualsCanonicalizing(self::ALLOWED_FIELDS, array_keys($userInfo));

        $this->assertSame($user->id, $userInfo['id']);
        $this->assertSame('Ayşe Yılmaz', $userInfo['name']);
        $this->assertSame('ayse@example.test', $userInfo['email']);
        $this->assertSame('Satış Temsilcisi', $userInfo['role']);
        $this->assertSame('Satış', $userInfo['department']);
    }

    /**
     * Hassas/iç alanların ADI GEÇMEMELİ: hem model $fillable'ında olup
     * ChannelRegistry::payload() tarafından bilinçli dışlanan alanlar
     * (`password`, `must_change_password`, `is_active`), hem de olası gelecek
     * genişlemeler (`phone`, `permissions`, `roles` — çoğul/dizi hali,
     * `remember_token`).
     */
    public function test_online_presence_payload_never_leaks_sensitive_or_internal_fields(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole('Satış Temsilcisi');

        $response = $this->authorize($user, 'presence-online')->assertOk();
        $userInfo = $this->channelData($response)['user_info'];

        foreach (['password', 'remember_token', 'must_change_password', 'is_active', 'phone', 'permissions', 'roles', 'email_verified_at', 'last_login_at', 'created_at', 'updated_at', 'deleted_at'] as $sensitiveField) {
            $this->assertArrayNotHasKey($sensitiveField, $userInfo, "Presence payload'ı beklenmeyen alan sızdırıyor: {$sensitiveField}");
        }
    }

    /**
     * Pasif hesabın `online` kanalına katılma denemesi reddedilir VE ret
     * gövdesi, hesabın adı/e-postası gibi hiçbir profil verisi içermez —
     * yetkilendirme cevabı bir "kim olduğunu doğrulama" oracle'ına
     * dönüşmemeli.
     */
    public function test_rejected_inactive_user_leaks_no_profile_data_in_the_denial_response(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'name' => 'Gizli İsim Sızmasın',
            'email' => 'sizmasin@example.test',
        ]);

        $response = $this->authorize($user, 'presence-online')->assertStatus(403);

        $body = $response->getContent();

        $this->assertStringNotContainsString('Gizli İsim Sızmasın', (string) $body);
        $this->assertStringNotContainsString('sizmasin@example.test', (string) $body);
        $this->assertArrayNotHasKey('channel_data', $response->json() ?? []);
    }

    /* ========================================================================
     * A3.6 — presence-record.{type}.{id} payload'ı aynı kısıtlı şekli kullanır
     * ===================================================================== */

    public function test_record_presence_payload_uses_the_same_restricted_shape_as_online(): void
    {
        $deal = Deal::factory()->create();

        $user = User::factory()->create([
            'name' => 'Kayıt İzleyen Kullanıcı',
            'email' => 'izleyen@example.test',
            'department' => 'Destek',
        ]);
        $user->givePermissionTo('deals.view');

        $response = $this->authorize($user, 'presence-record.deal.'.$deal->id)->assertOk();
        $userInfo = $this->channelData($response)['user_info'];

        $this->assertEqualsCanonicalizing(self::ALLOWED_FIELDS, array_keys($userInfo));
        $this->assertSame('Kayıt İzleyen Kullanıcı', $userInfo['name']);
        $this->assertSame('izleyen@example.test', $userInfo['email']);
        $this->assertSame('Destek', $userInfo['department']);

        foreach (['password', 'must_change_password', 'is_active', 'remember_token'] as $sensitiveField) {
            $this->assertArrayNotHasKey($sensitiveField, $userInfo);
        }
    }

    /* ========================================================================
     * Doğrudan birim düzeyinde: ChannelRegistry::payload() sözleşmesi
     * ===================================================================== */

    /**
     * HTTP katmanını atlayıp üretici fonksiyonu doğrudan çağırır — hem A3.2
     * hem A3.6'nın paylaştığı TEK payload üreticisinin kendisini kilitler.
     * Anahtar kümesi tam eşitlik: gelecekte eklenecek herhangi bir alan
     * (zararsız görünse bile) bu testi kırmalı ve bilinçli bir karar
     * gerektirmeli.
     */
    public function test_channel_registry_payload_contract_is_exactly_five_fields(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'is_active' => true,
        ]);
        $user->assignRole('Admin');

        $payload = ChannelRegistry::payload($user);

        $this->assertEqualsCanonicalizing(self::ALLOWED_FIELDS, array_keys($payload));
        $this->assertArrayNotHasKey('must_change_password', $payload);
        $this->assertArrayNotHasKey('is_active', $payload);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertSame('Admin', $payload['role']);
    }

    public function test_channel_registry_payload_handles_a_user_with_no_role_gracefully(): void
    {
        // getRoleNames()->first() rolsüz kullanıcıda null döner - payload
        // yine de 5 anahtarı korumalı, patlamamalı ya da alanı düşürmemeli.
        $user = User::factory()->create();

        $payload = ChannelRegistry::payload($user);

        $this->assertEqualsCanonicalizing(self::ALLOWED_FIELDS, array_keys($payload));
        $this->assertNull($payload['role']);
    }
}
