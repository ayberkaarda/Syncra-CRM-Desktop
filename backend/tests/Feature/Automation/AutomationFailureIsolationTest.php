<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationRule;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Bir otomasyon eyleminin hatası tetikleyen isteği geri almamalı: eylem
 * tetikleyenin transaction'ı içinde koşar, hata yukarı taşınsaydı kullanıcı
 * 500 alır ve kaydı (ör. ticket) hiç oluşmazdı. Eylemin yarım yazımı ise
 * geri alınmalı.
 */
class AutomationFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_failing_action_does_not_roll_back_the_triggering_ticket(): void
    {
        $creator = User::factory()->create();
        $creator->givePermissionTo(['settings.manage', 'tickets.view', 'tasks.create', 'tasks.assign']);

        AutomationRule::factory()->for($creator, 'creator')
            ->taskCreateAction(dueInDays: 1)
            ->create([
                'trigger_type' => 'ticket.created',
                'trigger_config' => ['priority' => null],
            ]);

        // Eylemin yazdığı görev kaydı patlar.
        Task::creating(function (): void {
            throw new RuntimeException('eylem arızası');
        });

        $actor = User::factory()->create();
        $actor->givePermissionTo(['tickets.create', 'tickets.view']);

        $this->actingAs($actor)->postJson('/api/tickets', [
            'subject' => 'Yazıcı bağlanmıyor',
            'description' => 'Ağ yazıcısı listede görünmüyor.',
        ])->assertCreated();

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(0, Task::query()->count());
    }
}
