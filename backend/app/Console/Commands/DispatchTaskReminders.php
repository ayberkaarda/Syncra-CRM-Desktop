<?php

namespace App\Console\Commands;

use App\Events\TaskReminderDue;
use App\Models\Task;
use App\Support\MorphTargets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * `tasks:dispatch-reminders` — ROADMAP Faz 8: `reminder_at` alanı var ama
 * hiçbir şey tetiklemiyordu. `routes/console.php`'de her dakika zamanlanır
 * (`everyMinute()`), böylece hatırlatıcı gecikmesi dakika mertebesinde kalır.
 *
 * ---------------------------------------------------------------------------
 * TEKRAR GÖNDERİMİ ÖNLEME — TASARIM KARARI VE SINIRLARI
 * ---------------------------------------------------------------------------
 * Komut her dakika çalışır ve `reminder_at <= now()` koşulunu sağlayan HER
 * görevi tarar — bir görev tamamlanmadığı/iptal edilmediği/yeniden
 * atanmadığı sürece bu koşul SONSUZA KADAR doğru kalır. `reminder_at`'i
 * NULL'a çekerek "işlendi" işaretlemek veri kaybıdır (kullanıcı görevi
 * incelediğinde "ne zaman hatırlatma istemiştim" bilgisini kaybeder) ve
 * zaten migration'a dokunmak bu şeridin sahipliği dışında.
 *
 * Bunun yerine varsayılan cache store'da (CACHE_STORE — üretimde redis,
 * testte array) TTL'li bir "işlendi" işareti tutuluyor:
 *
 *   key   = "tasks:reminder-dispatched:{task_id}:{reminder_at_unix_ts}"
 *   value = true
 *   ttl   = 7 gün (self::DEDUP_TTL_SECONDS)
 *
 * Anahtara `reminder_at`'in KENDİSİ dahil edilmesi kasıtlı: kullanıcı görevi
 * güncelleyip hatırlatıcıyı yeni bir zamana taşırsa, yeni zaman DAMGASI
 * farklı bir anahtar üretir ve yeni hatırlatıcı normal şekilde tetiklenir —
 * eski işaret "kirli" kalmaz.
 *
 * SINIRLAR (bilinçli kabul edilen ödünler):
 *   1. Bu ADIM DIŞI bir çözümdür — sunucu tarafında kalıcı bir "gönderildi"
 *      sütunu YOKTUR. Cache store TAMAMEN temizlenirse (redis restart
 *      (persistence kapalıysa), `php artisan cache:clear`, ya da testte her
 *      PHPUnit sürecinin kendi `array` cache'i) işaretler kaybolur ve
 *      sıradaki çalıştırmada aynı hatırlatıcılar BİR KEZ DAHA dispatch
 *      edilir. Bu, e-posta değil yalnızca in-app/realtime bir sinyal olduğu
 *      için (bkz. TaskReminderDue dokümanı) düşük şiddetli bir başarısızlık
 *      modudur — kullanıcı en kötü ihtimalle aynı toast'ı iki kez görür.
 *   2. TTL (7 gün) normal işleyişte HİÇ devreye girmez çünkü zamanlayıcı her
 *      dakika çalışır ve işaret dispatch anında yazılır — TTL yalnızca
 *      anahtar kümesinin SÜRESİZ büyümesini engelleyen bir üst sınırdır.
 *      Zamanlayıcı 7 günden uzun süre DURURSA (sunucu kapalı, kuyruk/
 *      scheduler arızası), işaretin süresi dolar ve o görev bir kez daha
 *      hatırlatılabilir — bu, "asla iki kez gönderme" garantisi değil,
 *      "normal işleyişte iki kez gönderme" garantisidir.
 *   3. Yeni bir DB kolonu (ör. `reminder_dispatched_at`) bu sınırların
 *      ikisini de ortadan kaldırırdı (kalıcı + tam garanti), ama migration
 *      yazmak bu şeridin (A) sahipliği DIŞINDA — görev tanımı net.
 */
class DispatchTaskReminders extends Command
{
    /**
     * @var string
     */
    protected $signature = 'tasks:dispatch-reminders
        {--dry-run : Hiçbir event dispatch etmez, yalnızca ne gönderileceğini yazdırır}';

    /**
     * @var string
     */
    protected $description = 'Vadesi gelmiş görev hatırlatıcıları için TaskReminderDue event\'i dispatch eder (in-app realtime).';

    /**
     * Bkz. sınıf dokümanı "TEKRAR GÖNDERİMİ ÖNLEME" bölümü.
     */
    private const DEDUP_TTL_SECONDS = 60 * 60 * 24 * 7; // 7 gün

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $tasks = Task::query()
            ->whereNotNull('reminder_at')
            ->where('reminder_at', '<=', now())
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('assigned_to')
            ->with('taskable')
            ->get();

        if ($tasks->isEmpty()) {
            $this->components->info('Vadesi gelmiş hatırlatıcı bulunamadı.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            $key = $this->dedupKey($task);

            if (Cache::has($key)) {
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("[dry-run] Görev #{$task->id} \"{$task->title}\" için hatırlatıcı dispatch EDİLECEKTİ (assigned_to: {$task->assigned_to}).");
                $dispatched++;

                continue;
            }

            $shortType = MorphTargets::shortName($task->taskable_type);

            event(new TaskReminderDue((int) $task->assigned_to, [
                'task_id' => $task->id,
                'title' => $task->title,
                'due_at' => $task->due_at?->toIso8601String(),
                'priority' => $task->priority,
                'taskable_type' => $shortType,
                'taskable_id' => $task->taskable_id,
                'taskable_label' => MorphTargets::label($shortType, $task->taskable),
            ]));

            // Yalnızca gerçek (dry-run olmayan) dispatch'ten SONRA işaretle
            // — dry-run hiçbir kalıcı iz bırakmaz, bir sonraki gerçek
            // çalıştırma aynı adayları yeniden değerlendirir.
            Cache::put($key, true, self::DEDUP_TTL_SECONDS);
            $dispatched++;
        }

        if ($isDryRun) {
            $this->components->info("dry-run: {$dispatched} hatırlatıcı adayı bulundu (hiçbiri dispatch edilmedi), {$skipped} zaten işlenmişti.");

            return self::SUCCESS;
        }

        $this->components->info("{$dispatched} hatırlatıcı dispatch edildi, {$skipped} tekrar gönderim önlendi (zaten işlenmiş).");

        return self::SUCCESS;
    }

    /**
     * `reminder_at`'in kendisi anahtara dahildir — bkz. sınıf dokümanı.
     */
    private function dedupKey(Task $task): string
    {
        $reminderTimestamp = $task->reminder_at?->timestamp;

        return "tasks:reminder-dispatched:{$task->id}:{$reminderTimestamp}";
    }
}
