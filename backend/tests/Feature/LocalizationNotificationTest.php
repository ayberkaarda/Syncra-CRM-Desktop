<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DealAssignedNotification;
use App\Notifications\TaskAssignedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Faz 14 / İz D — `notifications.data` anahtar+parametre sözleşmesi (PHASE-INTL §1.4).
 *
 * KİLİTLENEN İKİ ŞEY:
 *   1. Yeni satırlar metin DEĞİL anlam saklar ve metin OKUMA anında, OKUYANIN diliyle üretilir.
 *      Kullanıcı dilini değiştirdiğinde GEÇMİŞ bildirimler de yeni dilde okunur.
 *   2. Bu fazdan önce yazılmış (düz `title`/`body`) satırlar ve henüz dönüştürülmemiş bildirim
 *      tipleri aynen çalışmaya devam eder. Geriye dönük uyum bir "iyi niyet" değil,
 *      sözleşmenin parçasıdır — kırılırsa kullanıcının bildirim geçmişi boşalır.
 */
class LocalizationNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function reader(string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale]);
        $user->givePermissionTo('notifications.view');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeRow(User $user, array $data): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => DealAssignedNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => $data,
            'read_at' => null,
        ]);
    }

    // -------------------------------------------------------------------
    // Sözleşme: `data` metin değil anahtar taşır
    // -------------------------------------------------------------------

    public function test_converted_notification_stores_keys_and_params_not_rendered_text(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->create(['owner_id' => $owner->getKey()]);

        $data = DealAssignedNotification::make($deal, null)->toArray($owner);

        $this->assertSame('deal.assigned', $data['type']);
        $this->assertSame('notifications.deal_assigned.title', $data['title_key']);
        $this->assertSame('notifications.deal_assigned.body', $data['body_key']);
        $this->assertArrayHasKey('subject', $data['params']);

        // Render edilmiş metin BİLİNÇLİ olarak yazılmaz: yazılsaydı, okuma anındaki çözümün
        // yanında ölü ve yanlış dilde bir kopya dururdu ve dil donması geri gelirdi.
        $this->assertArrayNotHasKey('title', $data);
        $this->assertArrayNotHasKey('body', $data);
    }

    public function test_untouched_notification_types_still_use_plain_text_mode(): void
    {
        // 11 tipin 9'u henüz dönüştürülmedi; dönüşümün KADEMELİ olabilmesi sözleşmenin
        // parçası. Bu tipler düz metin yazmaya devam eder ve Resource onları basar.
        $actor = User::factory()->create();
        $assignee = User::factory()->create();
        $task = Task::factory()->create(['assigned_to' => $assignee->getKey(), 'due_at' => null]);

        $data = TaskAssignedNotification::make($task, $actor)->toArray($assignee);

        $this->assertSame('notifications.task_assigned.body', $data['body_key']);
        $this->assertSame($task->title, $data['params']['title']);
        $this->assertArrayNotHasKey('due_at', $data['params']);
    }

    // -------------------------------------------------------------------
    // Okuma anında çözüm — okuyanın diliyle
    // -------------------------------------------------------------------

    public function test_notification_text_is_resolved_in_the_reading_users_language(): void
    {
        $reader = $this->reader('en');
        $this->storeRow($reader, [
            'type' => 'deal.assigned',
            'title_key' => 'notifications.deal_assigned.title',
            'body_key' => 'notifications.deal_assigned.body',
            'params' => ['subject' => 'Acme Ltd.', 'amount' => '250.000,00 ₺'],
            'link' => '/deals/123',
            'meta' => ['deal_id' => 123],
        ]);

        $this->actingAs($reader)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'A deal was assigned to you')
            ->assertJsonPath('data.0.body', 'Acme Ltd. — 250.000,00 ₺');
    }

    public function test_historical_notification_follows_the_user_when_they_change_language(): void
    {
        $reader = $this->reader('tr');
        $this->storeRow($reader, [
            'type' => 'deal.assigned',
            'title_key' => 'notifications.deal_assigned.title',
            'body_key' => 'notifications.deal_assigned.body',
            'params' => ['subject' => 'Acme Ltd.', 'amount' => '250.000,00 ₺'],
            'link' => '/deals/123',
            'meta' => [],
        ]);

        $this->actingAs($reader)->getJson('/api/notifications')
            ->assertJsonPath('data.0.title', 'Size bir fırsat atandı');

        // FAZIN ASIL KAZANCI: satır DEĞİŞMEDEN, aynı bildirim yeni dilde okunur.
        $this->actingAs($reader)->patchJson('/api/me/preferences', ['locale' => 'de'])->assertOk();

        $this->actingAs($reader->fresh())->getJson('/api/notifications')
            ->assertJsonPath('data.0.title', 'Ihnen wurde eine Verkaufschance zugewiesen');
    }

    public function test_date_params_are_formatted_in_the_readers_language(): void
    {
        $reader = $this->reader('en');
        $this->storeRow($reader, [
            'type' => 'task.assigned',
            'title_key' => 'notifications.task_assigned.title',
            'body_key' => 'notifications.task_assigned.body_with_due',
            // `_at` son eki sözleşmedir: okuma anında okuyucunun diliyle biçimlendirilir.
            'params' => ['title' => 'Teklifi hazırla', 'due_at' => '2026-08-24T14:35:00+03:00'],
            'link' => '/tasks/1',
            'meta' => [],
        ]);

        $body = $this->actingAs($reader)->getJson('/api/notifications')->json('data.0.body');

        $this->assertStringContainsString('Teklifi hazırla', $body);
        $this->assertStringContainsString('Aug', $body);
        $this->assertStringNotContainsString('due_at', $body);

        $reader->update(['locale' => 'tr']);

        $trBody = $this->actingAs($reader->fresh())->getJson('/api/notifications')->json('data.0.body');
        $this->assertStringContainsString('Ağu', $trBody);
    }

    // -------------------------------------------------------------------
    // Geriye dönük uyum (ZORUNLU)
    // -------------------------------------------------------------------

    public function test_legacy_rows_without_keys_are_rendered_verbatim(): void
    {
        $reader = $this->reader('en');

        // Bu fazdan ÖNCE yazılmış satır: içinde anlam değil yalnızca cümle var, dolayısıyla
        // anahtar+parametreye geri çevrilemez ve GÖÇ EDİLMEZ — olduğu gibi basılır.
        $this->storeRow($reader, [
            'type' => 'deal.assigned',
            'title' => 'Size bir fırsat atandı',
            'body' => 'Acme Ltd. — 250.000,00 ₺',
            'link' => '/deals/123',
            'meta' => ['deal_id' => 123],
        ]);

        $this->actingAs($reader)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Size bir fırsat atandı')
            ->assertJsonPath('data.0.body', 'Acme Ltd. — 250.000,00 ₺')
            ->assertJsonPath('data.0.title_key', null);
    }

    public function test_legacy_row_rendering_does_not_depend_on_the_readers_language(): void
    {
        $reader = $this->reader('de');
        $this->storeRow($reader, [
            'type' => 'deal.assigned',
            'title' => 'Size bir fırsat atandı',
            'body' => 'Acme Ltd.',
            'link' => '/deals/1',
            'meta' => [],
        ]);

        $this->actingAs($reader)->getJson('/api/notifications')
            ->assertJsonPath('data.0.title', 'Size bir fırsat atandı');
    }

    public function test_resource_still_exposes_the_phase_10_field_contract(): void
    {
        $reader = $this->reader('tr');
        $this->storeRow($reader, [
            'type' => 'deal.assigned',
            'title_key' => 'notifications.deal_assigned.title',
            'body_key' => 'notifications.deal_assigned.body',
            'params' => ['subject' => 'Acme', 'amount' => '1,00 ₺'],
            'link' => '/deals/9',
            'meta' => ['deal_id' => 9],
        ]);

        // Alanlar ARTTI, azalmadı: mevcut istemci sözleşmesi bozulmadan karşılanır.
        $this->actingAs($reader)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'type', 'title', 'body', 'title_key', 'body_key', 'params', 'link', 'meta', 'read_at', 'created_at'],
                ],
            ]);
    }
}
