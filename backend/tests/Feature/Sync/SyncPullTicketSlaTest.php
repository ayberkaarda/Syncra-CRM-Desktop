<?php

namespace Tests\Feature\Sync;

use App\Models\Ticket;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESKTOP-ARCHITECTURE.md EK 4, KARAR A26 — `tickets` pull row carries four
 * SERVER-COMPUTED SLA fields (`sla_remaining_seconds`, `sla_total_seconds`,
 * `sla_target_hours`, `sla_breached`). The formula itself is never exposed to
 * the client; only `SlaService`'s already-existing output is.
 *
 * Every test here proves the pull value and `TicketResource`'s value (the
 * SAME fields the web client already receives via `GET /api/tickets/{id}`)
 * are IDENTICAL for the identical ticket - K7 forbids a second, silently
 * diverging computation. `travelTo()` freezes the clock so both HTTP calls
 * (pull + show) compute `now()` at the same instant; without it a real
 * second could tick between them and the two `sla_remaining_seconds` values
 * would legitimately differ by 1.
 */
class SyncPullTicketSlaTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineStageSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function pullTicketRow(string $token, int $id): array
    {
        $rows = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['tickets' => 0],
        ])->json('tables.tickets.rows');

        $row = collect($rows)->firstWhere('id', $id);

        $this->assertNotNull($row, "Pull yanıtında ticket #{$id} bulunamadı.");

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function showTicket(string $token, int $id): array
    {
        return $this->withToken($token)->getJson("/api/tickets/{$id}")->json('data');
    }

    private function assertSlaFieldsMatch(array $pullRow, array $resourceRow): void
    {
        foreach (['sla_remaining_seconds', 'sla_total_seconds', 'sla_target_hours', 'sla_breached'] as $field) {
            $this->assertArrayHasKey($field, $pullRow, "Pull satırında `{$field}` eksik.");
            $this->assertSame(
                $resourceRow[$field],
                $pullRow[$field],
                "`{$field}` pull ile TicketResource arasında AYRIŞTI — iki hesaplama yolu farklı sonuç üretiyor."
            );
        }
    }

    public function test_an_open_ticket_with_an_active_sla_carries_the_four_computed_fields(): void
    {
        $this->travelTo(now()->startOfHour());

        [, $token] = $this->deviceUser('Admin');

        $ticket = Ticket::factory()->create([
            'priority' => 'high',
            'status' => 'open',
            'sla_due_at' => now()->addHours(20),
            'sla_paused_at' => null,
            'sla_paused_seconds' => 0,
            'resolved_at' => null,
        ]);

        $pullRow = $this->pullTicketRow($token, $ticket->id);
        $resourceRow = $this->showTicket($token, $ticket->id);

        $this->assertSlaFieldsMatch($pullRow, $resourceRow);

        // Sanity: an open ticket well inside its window is not breached and
        // has a positive remaining count.
        $this->assertFalse($pullRow['sla_breached']);
        $this->assertGreaterThan(0, $pullRow['sla_remaining_seconds']);
        $this->assertSame(20 * 3600, $pullRow['sla_total_seconds']);
    }

    public function test_a_paused_ticket_carries_a_frozen_remaining_count_matching_the_resource(): void
    {
        $this->travelTo(now()->startOfHour());

        [, $token] = $this->deviceUser('Admin');

        $ticket = Ticket::factory()->create([
            'priority' => 'normal',
            'status' => 'pending',
            'sla_due_at' => now()->addHours(10),
            'sla_paused_at' => now()->subHour(), // paused an hour ago
            'sla_paused_seconds' => 0,
            'resolved_at' => null,
        ]);

        $pullRow = $this->pullTicketRow($token, $ticket->id);
        $resourceRow = $this->showTicket($token, $ticket->id);

        $this->assertSlaFieldsMatch($pullRow, $resourceRow);

        // Frozen at sla_due_at - sla_paused_at = 11 hours, not sla_due_at - now().
        $this->assertSame(11 * 3600, $pullRow['sla_remaining_seconds']);
        $this->assertFalse($pullRow['sla_breached']);
    }

    public function test_a_resolved_ticket_carries_a_null_remaining_count_and_historical_breach(): void
    {
        $this->travelTo(now()->startOfHour());

        [, $token] = $this->deviceUser('Admin');

        // Resolved AFTER the due date -> historically breached, per SlaService::isBreached().
        $dueAt = now()->subHours(5);

        $ticket = Ticket::factory()->create([
            'priority' => 'urgent',
            'status' => 'resolved',
            'sla_due_at' => $dueAt,
            'sla_paused_at' => null,
            'sla_paused_seconds' => 0,
            'resolved_at' => now()->subHour(), // after sla_due_at
        ]);

        $pullRow = $this->pullTicketRow($token, $ticket->id);
        $resourceRow = $this->showTicket($token, $ticket->id);

        $this->assertSlaFieldsMatch($pullRow, $resourceRow);

        $this->assertNull($pullRow['sla_remaining_seconds'], 'Çözülmüş ticket için kalan süre null olmalı — sayaç bitmiştir.');
        $this->assertTrue($pullRow['sla_breached'], 'Çözüm anı sla_due_at ötesindeyse TARİHSEL ihlal true olmalı.');
    }

    public function test_a_ticket_without_an_sla_due_date_falls_back_to_the_priority_target(): void
    {
        $this->travelTo(now()->startOfHour());

        [, $token] = $this->deviceUser('Admin');

        // sla_due_at === null: only reachable outside the normal API flow
        // (StoreTicketRequest always sets one), but SlaService::totalSeconds()
        // documents an explicit fallback path for it — this proves the pull
        // row exercises that same fallback rather than crashing or diverging.
        $ticket = Ticket::factory()->create([
            'priority' => 'low',
            'status' => 'open',
            'sla_due_at' => null,
            'sla_paused_at' => null,
            'sla_paused_seconds' => 0,
            'resolved_at' => null,
        ]);

        $pullRow = $this->pullTicketRow($token, $ticket->id);
        $resourceRow = $this->showTicket($token, $ticket->id);

        $this->assertSlaFieldsMatch($pullRow, $resourceRow);

        $this->assertNull($pullRow['sla_remaining_seconds'], '`sla_due_at` yokken kalan süre null olmalı.');
        $this->assertFalse($pullRow['sla_breached'], '`sla_due_at` yokken ihlal olamaz.');
        // SlaService::FALLBACK_HOURS / seeded setting default for `low` is 72h.
        // A whole-number float (72.0) round-trips through JSON as the bare
        // integer 72 - PHP's json_encode drops the trailing ".0" - so the
        // comparison is against the DECODED wire value, not the PHP float
        // SlaService::targetHoursForTicket() returns before encoding.
        $this->assertSame(72 * 3600, $pullRow['sla_total_seconds']);
        $this->assertEquals(72.0, $pullRow['sla_target_hours']);
    }
}
