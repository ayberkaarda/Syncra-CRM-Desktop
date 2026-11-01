<?php

namespace Tests\Feature\Quotes;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\Quotes\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Teklif güncellemesi ile gönderim arasındaki yarış — İKİ GERÇEK BAĞLANTIYLA.
 *
 * Senaryo: `PATCH /api/quotes/{id}` teklifi `draft` iken okur (route model
 * binding). Tam o sırada başka bir bağlantı gönderimi yürütür:
 * QuoteStatusMachine::transition() ile aynı kilitleri (önce `sync_counter`,
 * sonra teklif satırı) alır, durumu `sent` yapar ve bir süre sonra commit
 * eder. Güncelleme isteği bu arada yazmaya girer.
 *
 * Kilit kararı transaction dışındaki eski kopyaya bakarsa güncelleme
 * gönderimin commit'ini bekler ve SONRA kalemleri yeniden yazar: müşteriye
 * gönderilmiş teklifin tutarı değişir. Doğru davranış: güncelleme kilidi
 * bekler, satırı kilit altında yeniden okur, `sent` görür ve QUOTE_LOCKED
 * döner.
 *
 * İkinci bağlantı ayrı bir PHP sürecidir (aynı süreç kendi kilidini
 * bekleyemez). Veri bu yüzden COMMIT edilmelidir: test kendi transaction
 * sarmalını kapatır ve oluşturduğu satırları sonunda kendisi siler.
 */
class QuoteUpdateSendRaceTest extends TestCase
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

    private const TABLES = ['quotes', 'quote_items', 'activity_log', 'sync_deletions'];

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

    public function test_update_racing_a_send_rechecks_the_lock_under_the_row_lock(): void
    {
        $quote = Quote::factory()->create(['status' => 'draft']);
        QuoteItem::factory()->create([
            'quote_id' => $quote->id,
            'name' => 'Lisans',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_percent' => 0,
            'tax_rate' => 20,
            'line_total' => 100,
            'position' => 1,
        ]);

        // Route model binding'in transaction dışında yaptığı okuma.
        $stale = Quote::query()->findOrFail($quote->id);
        $this->assertSame('draft', $stale->status);

        [$process, $marker, $script] = $this->startSendInAnotherConnection((int) $quote->id, 1500);

        $code = null;

        try {
            app(QuoteService::class)->update($stale, ['items' => [
                ['name' => 'Lisans', 'quantity' => 50, 'unit_price' => 1000, 'discount_percent' => 0, 'tax_rate' => 20],
            ]]);
        } catch (HttpResponseException $e) {
            $code = $e->getResponse()->getData(true)['errors']['code'] ?? null;
        } finally {
            $exit = proc_close($process);
            @unlink($marker);
            @unlink($script);
            @unlink($script.'.log');
        }

        $this->assertSame(0, $exit, 'Gönderimi yürüten ikinci bağlantı hatayla bitti.');

        $fresh = Quote::query()->findOrFail($quote->id);
        $items = QuoteItem::query()->where('quote_id', $quote->id)->get();

        $this->assertSame('sent', $fresh->status);
        $this->assertSame('QUOTE_LOCKED', $code, 'Güncelleme gönderimden sonra kilit altında yeniden doğrulanmalı.');
        $this->assertCount(1, $items);
        $this->assertSame(1.0, (float) $items->first()->quantity, 'Gönderilmiş teklifin kalemi değişmemeli.');
        $this->assertSame(100.0, (float) $items->first()->unit_price);
    }

    /**
     * Ayrı süreçte, ayrı bağlantıda gönderimin kilit sırasını uygular ve
     * `$holdMs` boyunca commit etmeden bekler. Kilitler alındığında bir
     * işaret dosyası yazar; bu metot o dosya görünene dek bekler.
     *
     * @return array{0: resource, 1: string, 2: string}
     */
    private function startSendInAnotherConnection(int $quoteId, int $holdMs): array
    {
        $config = config('database.connections.'.config('database.default'));
        $base = tempnam(sys_get_temp_dir(), 'qrace');
        @unlink($base);
        $marker = $base.'.lock';
        $script = $base.'.php';

        file_put_contents($script, <<<'PHP'
<?php
[, $host, $port, $database, $username, $password, $quoteId, $marker, $holdMs] = $argv;
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->beginTransaction();
$pdo->query('SELECT value FROM sync_counter WHERE id = 1 FOR UPDATE')->fetchAll();
$pdo->query('SELECT id FROM quotes WHERE id = '.(int) $quoteId.' FOR UPDATE')->fetchAll();
$pdo->exec("UPDATE quotes SET status = 'sent', sent_at = NOW() WHERE id = ".(int) $quoteId);
file_put_contents($marker, 'locked');
usleep((int) $holdMs * 1000);
$pdo->commit();
PHP);

        $command = array_map('strval', [
            PHP_BINARY, $script,
            $config['host'], $config['port'], $config['database'], $config['username'], $config['password'] ?? '',
            $quoteId, $marker, $holdMs,
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
}
