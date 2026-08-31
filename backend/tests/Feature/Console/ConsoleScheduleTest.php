<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * D4: locks the `attachments:prune-orphans` scheduler registration in
 * routes/console.php. The command itself (App\Console\Commands\
 * PruneOrphanAttachments) was written in Faz 12 but never scheduled, so the
 * orphan-attachment cleanup silently never ran — see that command's own
 * docblock. If this registration is ever removed or reworded, this test
 * fails instead of the regression going unnoticed again.
 */
class ConsoleScheduleTest extends TestCase
{
    public function test_prune_orphans_is_scheduled_daily_with_default_threshold(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'attachments:prune-orphans'));

        $this->assertCount(
            1,
            $events,
            '`attachments:prune-orphans` routes/console.php içinde zamanlanmış bulunamadı — D4 kaydı düşmüş.'
        );

        $event = $events->first();

        // The command's OWN default retention threshold (config('chat.attachments
        // .orphan_retention_hours')) must not be overridden here with --hours.
        $this->assertStringNotContainsString(
            '--hours',
            (string) $event->command,
            'Zamanlayıcı --hours ile komutun kendi varsayılan eşiğini eziyor — bu D4 kapsamı dışı bir karar değişikliği.'
        );

        // Scheduled (non-interactive) runs cannot prompt for confirmation.
        $this->assertStringContainsString(
            '--force',
            (string) $event->command,
            'Zamanlanmış çalışma --force içermiyor — non-interactive silme onay bekleyip askıda kalır.'
        );

        $this->assertSame(
            '47 3 * * *',
            $event->getExpression(),
            'Zamanlama beklenen günlük 03:47 cron ifadesinden sapmış.'
        );
    }
}
