<?php

namespace Tests\Feature\Concurrency;

use App\Models\Task;
use App\Models\Ticket;
use App\Services\Tasks\TaskService;
use App\Services\Tickets\SlaService;
use App\Services\Tickets\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Transaction dışında okunmuş kopyaya göre karar veren yazma yolları — İKİ
 * GERÇEK BAĞLANTIYLA.
 *
 * Her senaryoda servis, route model binding'in okuduğu eski kopyayla
 * çağrılır. Tam o sırada ayrı bir PHP süreci kendi bağlantısında sürüm çeken
 * yazarların kilit sırasını (önce `sync_counter`, sonra satır) uygular, satırı
 * değiştirir ve bir süre sonra commit eder. Doğru davranış: servis kilidi
 * bekler, satırı kilit altında yeniden okur ve kararını taze değere göre
 * verir.
 *
 * Diğer süreç satırı görebilmeli; veri bu yüzden COMMIT edilir: test sarmal
 * transaction kullanmaz ve oluşturduğu satırları sonunda id eşiğiyle siler.
 */
class StaleRowRaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sarmal transaction YOK: diğer süreç satırı görebilmelidir.
     *
     * @var array<int, string|null>
     */
    protected $connectionsToTransact = [];

    /** @var array<string, int> */
    private array $highWater = [];

    private const TABLES = ['tasks', 'tickets', 'activity_log', 'sync_deletions'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            $this->highWater[$table] = (int) DB::table($table)->max('id');
        }

        $this->beforeApplicationDestroyed(function () {
            foreach (array_reverse(self::TABLES) as $table) {
                DB::table($table)->where('id', '>', $this->highWater[$table])->delete();
            }
        });
    }

    /**
     * normal (48 sa) bir ticket'ı iki istek okur. Biri `urgent` (4 sa) yazıp
     * commit eder; diğeri `high` (24 sa) ister. Eski kopyadaki 48 saate göre
     * 24 saat "öne çekme" görünür ve hedef 4 saatten 24 saate GERİ alınırdı.
     */
    public function test_priority_change_compares_against_the_committed_deadline_not_a_stale_copy(): void
    {
        $sla = app(SlaService::class);
        $created = now()->startOfSecond();

        $ticket = Ticket::factory()->create([
            'priority' => 'normal',
            'status' => 'open',
            'created_at' => $created,
            'sla_due_at' => $created->copy()->addHours($sla->targetHours('normal')),
        ]);

        $stale = Ticket::query()->findOrFail($ticket->id);
        $urgentDue = $created->copy()->addHours($sla->targetHours('urgent'));

        $process = $this->runInAnotherConnection(
            'tickets',
            (int) $ticket->id,
            "UPDATE tickets SET priority = 'urgent', sla_due_at = '".$urgentDue->toDateTimeString()."' WHERE id = ".(int) $ticket->id,
            1500,
        );

        try {
            app(TicketService::class)->update($stale, ['priority' => 'high']);
        } finally {
            $exit = $this->finish($process);
        }

        $this->assertSame(0, $exit, 'İkinci bağlantı hatayla bitti.');

        $fresh = Ticket::query()->findOrFail($ticket->id);
        $this->assertSame('high', $fresh->priority);
        $this->assertSame(
            $urgentDue->toDateTimeString(),
            $fresh->sla_due_at->toDateTimeString(),
            'Öncelik değişimi kesinleşmiş hedefi ileri almamalı.',
        );
    }

    /**
     * Genel PATCH görevi `pending` iken okur; araya giren iptal commit eder.
     * Kontrol eski kopyaya bakarsa `completed` iptalin üzerine yazılır.
     */
    public function test_generic_patch_completion_racing_a_cancel_is_rejected_under_the_row_lock(): void
    {
        $task = Task::factory()->create(['status' => 'pending']);
        $stale = Task::query()->findOrFail($task->id);

        $process = $this->runInAnotherConnection(
            'tasks',
            (int) $task->id,
            "UPDATE tasks SET status = 'cancelled' WHERE id = ".(int) $task->id,
            1500,
        );

        $rejected = false;

        try {
            app(TaskService::class)->update($stale, ['status' => 'completed']);
        } catch (ValidationException $e) {
            $rejected = array_key_exists('status', $e->errors());
        } finally {
            $exit = $this->finish($process);
        }

        $this->assertSame(0, $exit, 'İkinci bağlantı hatayla bitti.');
        $this->assertTrue($rejected, 'Tamamlama kilitli satırdaki iptali görmeli ve 422 dönmeli.');
        $this->assertSame('cancelled', Task::query()->findOrFail($task->id)->status);
    }

    /**
     * Baypas: aynı yarış `/complete` ucunda — CompleteTaskRequest'in kontrolü
     * de eski kopyaya bakar.
     */
    public function test_complete_endpoint_racing_a_cancel_is_rejected_under_the_row_lock(): void
    {
        $task = Task::factory()->create(['status' => 'pending']);
        $stale = Task::query()->findOrFail($task->id);

        $process = $this->runInAnotherConnection(
            'tasks',
            (int) $task->id,
            "UPDATE tasks SET status = 'cancelled' WHERE id = ".(int) $task->id,
            1500,
        );

        $rejected = false;

        try {
            app(TaskService::class)->complete($stale, true);
        } catch (ValidationException $e) {
            $rejected = array_key_exists('completed', $e->errors());
        } finally {
            $exit = $this->finish($process);
        }

        $this->assertSame(0, $exit, 'İkinci bağlantı hatayla bitti.');
        $this->assertTrue($rejected, 'Tamamlama kilitli satırdaki iptali görmeli ve 422 dönmeli.');

        $fresh = Task::query()->findOrFail($task->id);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNull($fresh->completed_at);
    }

    /**
     * Ayrı süreçte, ayrı bağlantıda sayaç ve satır kilitlerini alır, `$sql`'i
     * çalıştırır ve `$holdMs` boyunca commit etmeden bekler. Kilitler
     * alındığında bir işaret dosyası yazar; bu metot o dosya görünene dek
     * bekler.
     *
     * @return array{0: resource, 1: string, 2: string}
     */
    private function runInAnotherConnection(string $table, int $id, string $sql, int $holdMs): array
    {
        $config = config('database.connections.'.config('database.default'));
        $base = tempnam(sys_get_temp_dir(), 'srace');
        @unlink($base);
        $marker = $base.'.lock';
        $script = $base.'.php';

        file_put_contents($script, <<<'PHP'
<?php
[, $host, $port, $database, $username, $password, $table, $id, $sql, $marker, $holdMs] = $argv;
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->beginTransaction();
$pdo->query('SELECT value FROM sync_counter WHERE id = 1 FOR UPDATE')->fetchAll();
$pdo->query('SELECT id FROM `'.$table.'` WHERE id = '.(int) $id.' FOR UPDATE')->fetchAll();
$pdo->exec($sql);
file_put_contents($marker, 'locked');
usleep((int) $holdMs * 1000);
$pdo->commit();
PHP);

        $command = array_map('strval', [
            PHP_BINARY, $script,
            $config['host'], $config['port'], $config['database'], $config['username'], $config['password'] ?? '',
            $table, $id, $sql, $marker, $holdMs,
        ]);

        $log = $script.'.log';
        $process = proc_open($command, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        $this->assertIsResource($process);

        $deadline = microtime(true) + 15;
        while (! is_file($marker)) {
            if (microtime(true) > $deadline || ! proc_get_status($process)['running']) {
                proc_terminate($process);
                $this->fail('İkinci bağlantı kilitleri alamadı: '.@file_get_contents($log));
            }
            usleep(20000);
        }

        return [$process, $marker, $script];
    }

    /**
     * @param  array{0: resource, 1: string, 2: string}  $process
     */
    private function finish(array $process): int
    {
        [$handle, $marker, $script] = $process;
        $exit = proc_close($handle);
        @unlink($marker);
        @unlink($script);
        @unlink($script.'.log');

        return $exit;
    }
}
