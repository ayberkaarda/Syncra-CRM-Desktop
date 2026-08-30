<?php

namespace Tests\Feature\Security;

use App\Broadcasting\ChannelRegistry;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Faz 13 / İz A / A3 — `routes/channels.php` içindeki 8 broadcast kanalının
 * yetkilendirme sınırını kilitleyen regresyon testleri.
 *
 * Bu dosya `App\Models\...` sızıntısı, IDOR (enumerasyon), admin override ve
 * modül izin denetimlerine odaklanır. Presence payload'ının PII sızıntısı
 * ayrı dosyada: {@see ChannelPayloadLeakTest}.
 *
 * ---------------------------------------------------------------------------
 * NEDEN `reverb` SÜRÜCÜSÜ ZORLANIYOR
 * ---------------------------------------------------------------------------
 * phpunit.xml global olarak BROADCAST_CONNECTION=null ayarlar.
 * NullBroadcaster::auth() boş bir metottur: her zaman null döner, controller
 * 200 ile cevap verir ve `routes/channels.php` içindeki callback'ler HİÇ
 * çalıştırılmaz. Bu dosyayı `null` sürücüsüyle çalıştırmak, her isteği
 * yetkilendiren sahte-yeşil bir test paketi üretir (ROADMAP R14).
 *
 * setUp() bu yüzden gerçek Pusher-protokolü broadcaster'ı (`reverb`) devreye
 * sokar — aynen `Tests\Feature\BroadcastingTest`'in yaptığı gibi (o dosya
 * SADECE okunur, değiştirilmez). Bu, gerçek
 * Broadcaster::verifyUserCanAccessChannel yolunu ve dolayısıyla gerçek
 * `routes/channels.php` callback'lerini çalıştırır. Reverb sunucusuna soket
 * açmaz; auth cevabını imzalamak yerel bir HMAC işlemidir.
 *
 * DİKKAT: Bu paket kasıtlı olarak hiçbir event dispatch ETMEZ / broadcast
 * TETİKLEMEZ — yalnızca POST /broadcasting/auth çağrılır. Reverb sunucusu
 * 127.0.0.1:8080'de çalışıyor olsa da olmasa da bu testler onunla konuşmaz;
 * gerçek bir yayın tetiklemek (ör. event()->broadcast()) burada YOKTUR çünkü
 * sunucu kapalıyken bağlantı SYN_SENT'te asılıp paketi donduracağı gözlemlendi.
 */
class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const SOCKET = '246810.1213141';

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

        // BroadcastManager::__call, boot sırasında kaydedilen callback'leri
        // NullBroadcaster üzerinde biriktirir. Yeni `reverb` sürücüsü sıfır
        // kanal sözlüğüyle başlar (her şey 403 döner ve negatif testler yanlış
        // sebepten geçer) — purge edip channels.php'yi yeniden `require`
        // ederek gerçek sözlüğü tazeden çalıştırıyoruz. channels.php dosya
        // kapsamında const/function bildirmediği için (bkz. ChannelRegistry
        // docblock'u) yeniden require etmek güvenli.
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

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /* ========================================================================
     * A3.1 — private-user.{id}: katı kimlik, admin override YOK
     * ===================================================================== */

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function overrideCandidateRoles(): array
    {
        return [
            'sıradan kullanıcı' => [null],
            'Admin' => ['Admin'],
            'Super Admin' => ['Super Admin'],
        ];
    }

    /**
     * En değerli test: `user.{id}` kişisel kanaldır (oturum iptali, kişisel
     * bildirimler). "Kullanıcıları yönetebilir" yetkisi "postasını okuyabilir"
     * anlamına gelmez — hiçbir rol, başka birinin kişisel kanalına abone
     * OLAMAZ. ChannelRegistry::user() eşdeğeri yok; `channels.php:64-66`
     * `$user->id === (int) $id` dışında hiçbir dal içermiyor — bu testin
     * amacı gelecekte eklenecek bir "is_admin => true" kısayolunu kırmak.
     */
    #[DataProvider('overrideCandidateRoles')]
    public function test_no_role_overrides_the_strict_identity_check_on_the_personal_channel(?string $role): void
    {
        $subscriber = User::factory()->create();

        if ($role !== null) {
            $subscriber->assignRole($role);
        }

        $victim = User::factory()->create();

        $this->authorize($subscriber, 'private-user.'.$victim->id)->assertStatus(403);

        // Pozitif kontrol: aynı kullanıcı KENDİ kanalını hâlâ açabiliyor mu?
        // Bu olmadan yukarıdaki 403 "her şeyi reddeden bozuk bir route"
        // yüzünden de geçebilir.
        $this->authorize($subscriber, 'private-user.'.$subscriber->id)->assertOk();
    }

    /* ========================================================================
     * A3.3(a) — presence-record.{type}.{id}: sınıf enjeksiyonu / whitelist
     * ===================================================================== */

    /**
     * @return array<string, array{0: string}>
     */
    public static function classInjectionPayloads(): array
    {
        return [
            'tam nitelikli sınıf adı' => ['App\Models\User'],
            'çift ters bölü kaçışlı sınıf adı' => ['App\\\\Models\\\\User'],
            'framework dahili sınıfı' => ['Illuminate\Support\Facades\DB'],
            'küçük harf model adı (kayıt YOK sanki)' => ['user'],
            'büyük harf varyasyonu' => ['Deal'],
            'çoğul form' => ['deals'],
            'path traversal' => ['../../../../etc/passwd'],
            'boş string' => [''],
            'sql enjeksiyon denemesi' => ["deal' OR '1'='1"],
            'php stream wrapper' => ['php://filter/resource=deal'],
            'baştaki/sondaki boşluk' => [' deal '],
            'null benzeri string' => ['null'],
        ];
    }

    /**
     * (a) sınıf enjeksiyonu: whitelist dışı `{type}` DEĞERLERİ, izin var olsa
     * bile reddedilmeli — VE reddediliş whitelist adımından gelmeli, izin
     * kontrolünden ya da varlık sorgusundan DEĞİL. Bunu kanıtlamak için:
     * kullanıcıya ilgili TÜM modül izinlerini (fazlasıyla) veriyoruz, böylece
     * 403 "zaten yetkisizdi" diye geçmiyor; ayrıca DB::listen ile callback
     * sırasında çalışan SQL'i yakalayıp record tablolarına (deals/tickets/
     * contacts/companies/leads) HİÇ dokunulmadığını doğruluyoruz — whitelist
     * `ChannelRegistry::record()` sorgu çalışmadan `null` döndürüyor
     * (channels.php:96-100), yani varlık kontrolüne (`whereKey()->exists()`)
     * hiç ulaşılmıyor.
     */
    #[DataProvider('classInjectionPayloads')]
    public function test_record_channel_rejects_unwhitelisted_types_before_any_database_query(string $type): void
    {
        $deal = Deal::factory()->create();

        $user = $this->userWithPermissions(
            'deals.view', 'tickets.view', 'contacts.view',
            'companies.view', 'leads.view', 'users.view',
        );

        $touchedProtectedTable = false;
        $queries = [];

        DB::listen(function ($query) use (&$touchedProtectedTable, &$queries): void {
            $queries[] = $query->sql;

            foreach (['deals', 'tickets', 'contacts', 'companies', 'leads'] as $table) {
                if (str_contains($query->sql, "`{$table}`")) {
                    $touchedProtectedTable = true;
                }
            }
        });

        $this->authorize($user, 'presence-record.'.$type.'.'.$deal->id)->assertStatus(403);

        $this->assertFalse(
            $touchedProtectedTable,
            "Whitelist dışı type=[{$type}] için record tablolarına sorgu atıldı (whitelist önce gelmeli). Çalışan sorgular: ".implode(' | ', $queries),
        );
    }

    /* ========================================================================
     * A3.3(b) — presence-record.{type}.{id}: modül izni yok
     * ===================================================================== */

    public function test_record_channel_rejects_a_user_whose_permission_belongs_to_a_different_module(): void
    {
        $deal = Deal::factory()->create();

        // Destek Temsilcisi: tickets.view VAR, deals.view YOK — gerçekçi bir
        // "yanlış modülün izni" senaryosu (ad-hoc izin karışımı değil).
        $user = User::factory()->create();
        $user->assignRole('Destek Temsilcisi');

        $this->authorize($user, 'presence-record.deal.'.$deal->id)->assertStatus(403);
    }

    public function test_record_channel_accepts_the_matching_module_permission(): void
    {
        $ticket = Ticket::factory()->create();

        $user = User::factory()->create();
        $user->assignRole('Destek Temsilcisi');

        $this->authorize($user, 'presence-record.ticket.'.$ticket->id)->assertOk();
    }

    /* ========================================================================
     * A3.3(c) — presence-record.{type}.{id}: enumerasyon (IDOR) sızıntısız
     * ===================================================================== */

    /**
     * Var olmayan bir id ile "izin var ama kayıt yok" ile var olan bir kayıtla
     * "kayıt var ama izin yok" durumları, saldırgana kayıt kimlik uzayı
     * hakkında bilgi vermeyecek şekilde AYNI cevabı üretmeli. channels.php
     * her iki dalda da `null` döndürüyor (satır 98-100 whitelist/permission,
     * 106-109 varlık) — ikisi de aynı AccessDeniedHttpException'a düşer ve
     * bootstrap/app.php'deki generic 'FORBIDDEN' gövdesine sarılır.
     */
    public function test_permission_denied_and_nonexistent_record_are_indistinguishable(): void
    {
        $deal = Deal::factory()->create();

        $noPermission = $this->userWithPermissions('tickets.view');
        $withPermission = $this->userWithPermissions('deals.view');

        $existingButUnauthorized = $this->authorize($noPermission, 'presence-record.deal.'.$deal->id)
            ->assertStatus(403);

        $missingButAuthorized = $this->authorize($withPermission, 'presence-record.deal.999999')
            ->assertStatus(403);

        $this->assertSame(
            $existingButUnauthorized->json(),
            $missingButAuthorized->json(),
            'İzin reddi ile var-olmayan-kayıt reddi farklı gövde döndürüyor — bu, kayıt kimliklerinin enumerasyonuna izin verebilir.',
        );
    }

    public function test_the_record_whitelist_still_covers_exactly_the_five_documented_types(): void
    {
        // ChannelRegistry sözlüğünün kendisini bekçileyen bir sözleşme testi:
        // buradaki saldırı senaryoları RECORDS haritasının bu beş anahtarla
        // sınırlı kaldığı varsayımına dayanıyor.
        $this->assertSame(
            ['deal', 'ticket', 'contact', 'company', 'lead'],
            array_keys(ChannelRegistry::RECORDS),
        );
    }

    /* ========================================================================
     * A3.4 — private-conversation.{id}: pivot üyeliği + chat.use, ikisi de
     * ===================================================================== */

    public function test_non_member_cannot_authorize_a_conversation_channel_even_with_chat_permission(): void
    {
        $member = $this->userWithPermissions('chat.use');
        $outsider = $this->userWithPermissions('chat.use');

        $conversation = Conversation::factory()->create(['created_by' => $member->id]);
        $conversation->users()->attach($member->id);

        $this->authorize($outsider, 'private-conversation.'.$conversation->id)->assertStatus(403);
    }

    public function test_member_without_chat_use_permission_cannot_authorize_a_conversation_channel(): void
    {
        // İzleyici: her ".view" izni var, chat.use YOK (seeder: "no chat").
        $viewer = User::factory()->create();
        $viewer->assignRole('İzleyici');

        $conversation = Conversation::factory()->create(['created_by' => $viewer->id]);
        $conversation->users()->attach($viewer->id);

        $this->authorize($viewer, 'private-conversation.'.$conversation->id)->assertStatus(403);
    }

    public function test_member_with_chat_use_permission_can_authorize_a_conversation_channel(): void
    {
        $member = $this->userWithPermissions('chat.use');

        $conversation = Conversation::factory()->create(['created_by' => $member->id]);
        $conversation->users()->attach($member->id);

        $this->authorize($member, 'private-conversation.'.$conversation->id)->assertOk();
    }

    public function test_removing_a_member_revokes_the_conversation_channel_on_the_next_authorization(): void
    {
        // Pivot her seferinde yeniden kontrol ediliyor (channels.php:126-129
        // docblock'u): üyelikten çıkarılan kullanıcı bir sonraki abonelikte
        // kanalı kaybetmeli.
        $member = $this->userWithPermissions('chat.use');
        $conversation = Conversation::factory()->create(['created_by' => $member->id]);
        $conversation->users()->attach($member->id);

        $this->authorize($member, 'private-conversation.'.$conversation->id)->assertOk();

        $conversation->users()->detach($member->id);

        $this->authorize($member, 'private-conversation.'.$conversation->id)->assertStatus(403);
    }

    /* ========================================================================
     * A3.5 — private-logs / private-dashboard / private-deals / private-tickets
     * ===================================================================== */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function moduleChannels(): array
    {
        return [
            'logs' => ['private-logs', 'logs.view'],
            'dashboard' => ['private-dashboard', 'dashboard.view'],
            'deals' => ['private-deals', 'deals.view'],
            'tickets' => ['private-tickets', 'tickets.view'],
        ];
    }

    #[DataProvider('moduleChannels')]
    public function test_module_channel_requires_its_own_view_permission(string $channel, string $permission): void
    {
        $this->authorize($this->userWithPermissions($permission), $channel)->assertOk();
        $this->authorize($this->userWithPermissions(), $channel)->assertStatus(403);
    }

    /**
     * Her callback `$user->is_active` kontrol ediyor (channels.php:136,143,
     * 158,176) — websocket bağlantısı isteği açan HTTP çağrısından daha uzun
     * yaşadığı için, izinli ama devre dışı bırakılan bir hesap yeni bir
     * abonelik AÇAMAMALI.
     */
    #[DataProvider('moduleChannels')]
    public function test_module_channel_rejects_an_inactive_user_even_with_the_permission(string $channel, string $permission): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->givePermissionTo($permission);

        $this->authorize($user, $channel)->assertStatus(403);
    }

    /* ========================================================================
     * A3.2 (destek) — presence-online: pasif hesap reddi burada da kilitlenir;
     * payload alan denetimi ChannelPayloadLeakTest'te.
     * ===================================================================== */

    public function test_inactive_user_cannot_join_the_online_presence_channel(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->authorize($user, 'presence-online')->assertStatus(403);
    }

    public function test_deactivated_user_cannot_watch_a_record_presence_channel(): void
    {
        $deal = Deal::factory()->create();

        $user = User::factory()->create(['is_active' => false]);
        $user->givePermissionTo('deals.view');

        $this->authorize($user, 'presence-record.deal.'.$deal->id)->assertStatus(403);
    }

    /* ========================================================================
     * Regresyon sabitleri: model + izin eşlemesi ChannelRegistry ile bire bir
     * ===================================================================== */

    public function test_every_record_type_still_maps_to_a_real_model_and_permission(): void
    {
        $records = [
            'deal' => Deal::factory()->create(),
            'ticket' => Ticket::factory()->create(),
            'contact' => Contact::factory()->create(),
            'company' => Company::factory()->create(),
            'lead' => Lead::factory()->create(),
        ];

        foreach ($records as $type => $record) {
            $permission = ChannelRegistry::RECORDS[$type]['permission'];
            $channel = 'presence-record.'.$type.'.'.$record->id;

            $this->authorize($this->userWithPermissions($permission), $channel)->assertOk();
        }
    }
}
