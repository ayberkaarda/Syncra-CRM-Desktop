<?php

namespace Database\Factories;

use App\Models\PageVisitLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageVisitLog>
 *
 * user_id is NOT NULL at the database level. It is left null in
 * definition() by design — the caller/seeder must always supply it,
 * e.g. PageVisitLog::factory()->create(['user_id' => $user->id]).
 */
class PageVisitLogFactory extends Factory
{
    protected $model = PageVisitLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$route, $path, $title] = fake()->randomElement([
            ['dashboard', '/dashboard', 'Panel'],
            ['leads.index', '/leads', 'Potansiyel Müşteriler'],
            ['deals.board', '/deals/board', 'Fırsat Panosu'],
            ['companies.index', '/companies', 'Firmalar'],
            ['contacts.index', '/contacts', 'Kişiler'],
            ['tickets.index', '/tickets', 'Destek Talepleri'],
            ['quotes.index', '/quotes', 'Teklifler'],
            ['reports.sales', '/reports/sales', 'Satış Raporları'],
        ]);

        $enteredAt = fake()->dateTimeBetween('-30 days', 'now');
        $durationSeconds = fake()->numberBetween(5, 3600);
        $lastHeartbeatAt = (clone $enteredAt)->modify("+{$durationSeconds} seconds");

        return [
            'user_id' => null,
            'route' => $route,
            'path' => $path,
            'title' => $title,
            'entered_at' => $enteredAt,
            'last_heartbeat_at' => $lastHeartbeatAt,
            'duration_seconds' => $durationSeconds,
            'ip_address' => fake()->ipv4(),
            'session_id' => fake()->uuid(),
        ];
    }
}
