<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET|PATCH|POST|DELETE /api/notifications*` — uç sözleşmesi, izin, sayfalama
 * ve sahiplik kuralı.
 *
 * Satırlar `Notification::fake()` KULLANILMADAN doğrudan `DatabaseNotification`
 * ile üretilir: bu suite CRUD sözleşmesini test eder (tetikleyici kablolaması
 * `NotificationTriggerTest`'in işi), ve gerçek bir `App\Notifications\
 * CrmNotification` göndermek `afterCommit()` yüzünden `RefreshDatabase`
 * altında hiçbir zaman gerçekten kuyruğa girmez (bkz. o sınıfın ve
 * `NotificationTriggerTest`'in dokümanı).
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithPermissions(array $permissions = ['notifications.view']): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(string $type = 'deal.assigned'): array
    {
        return [
            'type' => $type,
            'title' => 'Size bir fırsat atandı',
            'body' => 'Acme Ltd. — 250.000,00 ₺',
            'link' => '/deals/123',
            'meta' => ['deal_id' => 123, 'actor_id' => 4, 'actor_name' => 'Elif Yıldırım'],
        ];
    }

    protected function createNotificationFor(User $user, ?string $readAt = null, string $type = 'deal.assigned'): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\DealAssignedNotification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => $this->payload($type),
            'read_at' => $readAt,
        ]);
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/notifications/unread-count')->assertStatus(401);
    }

    public function test_user_without_notifications_view_permission_cannot_list(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/notifications')->assertStatus(403);
    }

    public function test_user_without_notifications_view_permission_cannot_read_unread_count(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/notifications/unread-count')->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // GET /api/notifications
    // -------------------------------------------------------------------

    public function test_index_lists_only_the_authenticated_users_notifications(): void
    {
        $actor = $this->actorWithPermissions();
        $other = User::factory()->create();

        $this->createNotificationFor($actor);
        $this->createNotificationFor($actor);
        $this->createNotificationFor($other);

        $response = $this->actingAs($actor)->getJson('/api/notifications');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.pagination.total'));
    }

    public function test_index_returns_the_payload_contract_shape(): void
    {
        $actor = $this->actorWithPermissions();
        $this->createNotificationFor($actor);

        $response = $this->actingAs($actor)->getJson('/api/notifications');

        $response->assertStatus(200)->assertJsonStructure([
            'data' => [
                ['id', 'type', 'title', 'body', 'link', 'meta', 'read_at', 'created_at'],
            ],
            'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
        ]);
        $this->assertSame('deal.assigned', $response->json('data.0.type'));
        $this->assertSame('/deals/123', $response->json('data.0.link'));
        $this->assertSame(123, $response->json('data.0.meta.deal_id'));
    }

    public function test_index_filters_unread_only(): void
    {
        $actor = $this->actorWithPermissions();
        $unread = $this->createNotificationFor($actor, null);
        $this->createNotificationFor($actor, now()->toDateTimeString());

        $response = $this->actingAs($actor)->getJson('/api/notifications?filter[read]=unread');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($unread->id, $response->json('data.0.id'));
    }

    public function test_index_filters_read_only(): void
    {
        $actor = $this->actorWithPermissions();
        $this->createNotificationFor($actor, null);
        $read = $this->createNotificationFor($actor, now()->toDateTimeString());

        $response = $this->actingAs($actor)->getJson('/api/notifications?filter[read]=read');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($read->id, $response->json('data.0.id'));
    }

    public function test_index_rejects_invalid_read_filter(): void
    {
        $actor = $this->actorWithPermissions();

        $this->actingAs($actor)->getJson('/api/notifications?filter[read]=bogus')->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // GET /api/notifications/unread-count
    // -------------------------------------------------------------------

    public function test_unread_count_reflects_only_own_unread_notifications(): void
    {
        $actor = $this->actorWithPermissions();
        $other = User::factory()->create();

        $this->createNotificationFor($actor, null);
        $this->createNotificationFor($actor, null);
        $this->createNotificationFor($actor, now()->toDateTimeString());
        $this->createNotificationFor($other, null);

        $response = $this->actingAs($actor)->getJson('/api/notifications/unread-count');

        $response->assertStatus(200)->assertJson(['data' => ['unread_count' => 2]]);
    }

    // -------------------------------------------------------------------
    // PATCH /api/notifications/{notification}/read
    // -------------------------------------------------------------------

    public function test_mark_read_marks_the_notification_and_returns_it(): void
    {
        $actor = $this->actorWithPermissions();
        $notification = $this->createNotificationFor($actor, null);

        $response = $this->actingAs($actor)->patchJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.read_at'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_read_on_another_users_notification_returns_404_not_403(): void
    {
        $actor = $this->actorWithPermissions();
        $other = User::factory()->create();
        $notification = $this->createNotificationFor($other, null);

        $response = $this->actingAs($actor)->patchJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(404);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_read_on_unknown_id_returns_404(): void
    {
        $actor = $this->actorWithPermissions();

        $this->actingAs($actor)
            ->patchJson('/api/notifications/'.Str::uuid().'/read')
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------
    // POST /api/notifications/read-all
    // -------------------------------------------------------------------

    public function test_mark_all_read_marks_every_unread_notification_of_the_user(): void
    {
        $actor = $this->actorWithPermissions();
        $other = User::factory()->create();

        $one = $this->createNotificationFor($actor, null);
        $two = $this->createNotificationFor($actor, null);
        $othersUnread = $this->createNotificationFor($other, null);

        $response = $this->actingAs($actor)->postJson('/api/notifications/read-all');

        $response->assertStatus(200)->assertJson(['data' => ['unread_count' => 0]]);
        $this->assertNotNull($one->fresh()->read_at);
        $this->assertNotNull($two->fresh()->read_at);
        // Başkasının bildirimi ETKİLENMEDİ.
        $this->assertNull($othersUnread->fresh()->read_at);
    }

    // -------------------------------------------------------------------
    // DELETE /api/notifications/{notification}
    // -------------------------------------------------------------------

    public function test_destroy_deletes_the_users_own_notification(): void
    {
        $actor = $this->actorWithPermissions();
        $notification = $this->createNotificationFor($actor);

        $response = $this->actingAs($actor)->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_destroy_on_another_users_notification_returns_404_not_403(): void
    {
        $actor = $this->actorWithPermissions();
        $other = User::factory()->create();
        $notification = $this->createNotificationFor($other);

        $response = $this->actingAs($actor)->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }
}
