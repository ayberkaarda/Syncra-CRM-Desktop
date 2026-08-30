<?php

namespace App\Console\Commands;

use App\Models\PageVisitLog;
use App\Models\SessionLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * `logs:prune` — ROADMAP R5.
 *
 * Log tabloları (page_visit_logs, session_logs, activity_log) süresiz
 * büyümeye bırakılırsa hem disk hem de log ekranlarının performansı zamanla
 * kullanılamaz hale gelir. Bu komut, tablo başına yapılandırılabilir bir
 * saklama süresinin ötesindeki satırları CHUNK'LI şekilde siler — tek bir
 * DELETE ile milyonlarca satırı silmek tabloyu uzun süre kilitler.
 */
class PruneLogs extends Command
{
    /**
     * @var string
     */
    protected $signature = 'logs:prune
        {--days= : Tüm tablolar için tek bir saklama süresi (gün) — config değerlerini ezer}
        {--table= : Yalnızca bu tabloyu buda: page_visits|sessions|activities}
        {--dry-run : Hiçbir şey silmez, kaç satırın etkileneceğini yazdırır}
        {--force : Onay istemeden çalıştır (üretimde --force olmadan silme yapılmaz)}';

    /**
     * @var string
     */
    protected $description = 'Saklama süresini aşan page_visit_logs / session_logs / activity_log kayıtlarını chunk\'lı şekilde budar.';

    /**
     * Silme sırasında tek seferde işlenecek satır sayısı.
     */
    private const CHUNK_SIZE = 1000;

    public function handle(): int
    {
        $tableOption = $this->option('table');
        $targets = $this->resolveTargets($tableOption);

        if ($targets === null) {
            $this->components->error("Geçersiz --table değeri: '{$tableOption}'. Beklenen: page_visits, sessions, activities.");

            return self::FAILURE;
        }

        $daysOverride = $this->option('days');
        if ($daysOverride !== null && (! is_numeric($daysOverride) || (int) $daysOverride < 1)) {
            $this->components->error('--days pozitif bir tam sayı olmalıdır.');

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $isForced = (bool) $this->option('force');

        // Her hedef için: kaç satır etkilenecek (silme öncesi sayım — hem
        // dry-run raporu hem de onay mesajı için kullanılır).
        $plan = [];
        $totalAffected = 0;

        foreach ($targets as $key => $target) {
            $days = $daysOverride !== null ? (int) $daysOverride : (int) config($target['config_key']);
            $cutoff = Carbon::now()->subDays($days);

            $count = $this->baseQuery($target, $cutoff)->count();

            $plan[$key] = [
                'target' => $target,
                'days' => $days,
                'cutoff' => $cutoff,
                'count' => $count,
            ];
            $totalAffected += $count;
        }

        if ($isDryRun) {
            $this->printPlan($plan, dryRun: true);
            $this->components->info("dry-run: hiçbir şey silinmedi (toplam {$totalAffected} satır etkilenecekti).");

            return self::SUCCESS;
        }

        if ($totalAffected === 0) {
            $this->printPlan($plan, dryRun: false);
            $this->components->info('Saklama süresini aşan kayıt bulunamadı, silinecek bir şey yok.');

            return self::SUCCESS;
        }

        if (! $isForced) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Üretimde silme işlemi onay ister: --no-interaction ile çalıştırırken --force gereklidir.');

                return self::FAILURE;
            }

            $this->printPlan($plan, dryRun: false);

            if (! $this->confirm("Toplam {$totalAffected} satır kalıcı olarak silinecek. Devam edilsin mi?")) {
                $this->components->warn('İşlem iptal edildi.');

                return self::SUCCESS;
            }
        }

        $summary = [];

        foreach ($plan as $key => $entry) {
            $target = $entry['target'];
            $started = microtime(true);

            $this->components->task("{$target['label']} budanıyor (> {$entry['days']} gün)", function () use ($target, $entry, &$summary, $key, $started) {
                $deleted = $this->pruneTarget($target, $entry['cutoff']);
                $elapsed = round(microtime(true) - $started, 2);

                $summary[$key] = [
                    'label' => $target['label'],
                    'deleted' => $deleted,
                    'elapsed' => $elapsed,
                ];

                return true;
            });
        }

        $this->newLine();
        $this->table(
            ['Tablo', 'Silinen Satır', 'Süre (sn)'],
            collect($summary)->map(fn ($row) => [$row['label'], $row['deleted'], $row['elapsed']])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{model: class-string, label: string, date_column: string, config_key: string}>|null
     */
    private function resolveTargets(?string $tableOption): ?array
    {
        $all = [
            'page_visits' => [
                'model' => PageVisitLog::class,
                'label' => 'page_visit_logs',
                // entered_at: ziyaretin BAŞLADIĞI an. Heartbeat ile updated_at
                // sürekli değiştiği için "kaç gündür var" ölçütü olarak
                // ziyaretin gerçek başlangıcı kullanılır.
                'date_column' => 'entered_at',
                'config_key' => 'syncra.log_retention.page_visits',
            ],
            'sessions' => [
                'model' => SessionLog::class,
                'label' => 'session_logs',
                'date_column' => 'created_at',
                'config_key' => 'syncra.log_retention.sessions',
            ],
            'activities' => [
                'model' => Activity::class,
                'label' => config('activitylog.table_name', 'activity_log'),
                'date_column' => 'created_at',
                'config_key' => 'syncra.log_retention.activities',
            ],
        ];

        if ($tableOption === null) {
            return $all;
        }

        if (! array_key_exists($tableOption, $all)) {
            return null;
        }

        return [$tableOption => $all[$tableOption]];
    }

    /**
     * @param  array{model: class-string, date_column: string}  $target
     */
    private function baseQuery(array $target, Carbon $cutoff): Builder
    {
        /** @var class-string<Model> $model */
        $model = $target['model'];

        return $model::query()->where($target['date_column'], '<', $cutoff);
    }

    /**
     * LIMIT'li döngüyle chunk'lı silme. chunkById yerine bilinçli olarak bu
     * yaklaşım seçildi: chunkById(imzası gereği) her turda ID'ye göre bir
     * sonraki sayfayı ister, ama satırlar aynı turda silindiği için "sonraki
     * sayfa" kayması riski taşır. Burada her iterasyon sorguyu SIFIRDAN
     * çalıştırıp kalan en eski satırları siler — kayma riski yok.
     *
     * @param  array{model: class-string, date_column: string}  $target
     */
    private function pruneTarget(array $target, Carbon $cutoff): int
    {
        $totalDeleted = 0;

        do {
            $deleted = $this->baseQuery($target, $cutoff)->limit(self::CHUNK_SIZE)->delete();
            $totalDeleted += $deleted;
        } while ($deleted === self::CHUNK_SIZE);

        return $totalDeleted;
    }

    /**
     * @param  array<string, array{target: array{label: string}, days: int, cutoff: Carbon, count: int}>  $plan
     */
    private function printPlan(array $plan, bool $dryRun): void
    {
        $rows = collect($plan)->map(fn ($entry) => [
            $entry['target']['label'],
            $entry['days'].' gün',
            $entry['cutoff']->toDateTimeString().' öncesi',
            $entry['count'],
        ])->all();

        $this->components->info($dryRun ? 'dry-run planı:' : 'Silme planı:');
        $this->table(['Tablo', 'Saklama Süresi', 'Kesim Tarihi', 'Etkilenecek Satır'], $rows);
    }
}
