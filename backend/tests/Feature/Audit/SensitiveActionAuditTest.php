<?php

namespace Tests\Feature\Audit;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Auth\DeviceTokenService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Elle kur girişi ve cihaz belirteci iptali denetim izine satır bırakır.
 */
class SensitiveActionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manual_exchange_rate_entry_and_correction_are_audited_with_old_and_new_value(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('settings.manage');
        $today = CarbonImmutable::today()->toDateString();

        foreach (['34.1234', '35.5000'] as $rate) {
            $this->actingAs($manager)->postJson('/api/settings/exchange-rates', [
                'currency' => 'USD', 'rate' => $rate, 'rate_date' => $today,
            ])->assertCreated();
        }

        $row = ExchangeRate::query()->where('currency', 'USD')->firstOrFail();
        $entries = Activity::query()
            ->where('log_name', 'crm')
            ->where('description', 'exchange_rates.manual')
            ->where('subject_type', $row->getMorphClass())
            ->where('subject_id', $row->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertSame($manager->id, (int) $entries[1]->causer_id);
        $this->assertSame('created', $entries[0]->event);
        $this->assertSame('updated', $entries[1]->event);
        $this->assertSame('34.123400', $entries[1]->properties['old']['rate']);
        $this->assertSame('35.500000', $entries[1]->properties['attributes']['rate']);
    }

    public function test_device_revocation_is_audited_without_the_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('MASAÜSTÜ', [DeviceTokenService::ABILITY]);
        $laptop = $user->createToken('LAPTOP', [DeviceTokenService::ABILITY]);
        $laptopId = $laptop->accessToken->getKey();

        $this->withToken($current->plainTextToken)
            ->deleteJson("/api/me/devices/{$laptopId}")
            ->assertNoContent();

        $entry = Activity::query()->where('log_name', 'crm')->where('description', 'devices.revoked')->sole();

        $this->assertSame($user->id, (int) $entry->causer_id);
        $this->assertSame($user->id, (int) $entry->subject_id);
        $this->assertSame($laptopId, (int) $entry->properties['token_id']);
        $this->assertSame('LAPTOP', $entry->properties['name']);
        $this->assertFalse($entry->properties['self_revoked']);
        $this->assertStringNotContainsString(explode('|', $laptop->plainTextToken)[1], json_encode($entry->properties));
    }

    /**
     * Başkasının belirteci: 404, ve iz satırı yazılmaz.
     */
    public function test_foreign_device_revocation_is_404_and_writes_nothing(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('PC', [DeviceTokenService::ABILITY])->plainTextToken;
        $other = User::factory()->create();
        $otherId = $other->createToken('OTHER', [DeviceTokenService::ABILITY])->accessToken->getKey();

        $this->withToken($token)->deleteJson("/api/me/devices/{$otherId}")->assertNotFound();

        $this->assertSame(1, $other->tokens()->count());
        $this->assertFalse(Activity::query()->where('description', 'devices.revoked')->exists());
    }
}
