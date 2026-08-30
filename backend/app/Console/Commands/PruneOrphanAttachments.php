<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * `attachments:prune-orphans` — ÖNERİ.
 *
 * ÖNEMLİ: bu komut routes/console.php'deki zamanlayıcıya KAYITLI DEĞİL ve
 * hiçbir yerden otomatik çalışmaz — Faz 12 kapsamında yalnızca elle
 * çalıştırılabilecek bir araç olarak eklendi. Zamanlayıcıya bağlamak ayrı
 * bir karar/onay gerektirir (bkz. docs/ENGINEERING-RULES.md §6 — teknik lider kararı).
 *
 * GEREKÇE: Bir dosya `POST /api/attachments` ile yüklenip Attachment
 * satırı oluşturulduktan SONRA kullanıcı mesajı hiç göndermezse (sekmeyi
 * kapatır, "gönder"e basmadan vazgeçer, ağ hatası vb.) `attachable_id`
 * NULL kalır ve dosya diskte + tabloda süresiz birikir. `logs:prune`
 * (App\Console\Commands\PruneLogs) İLE AYNI DESEN izlenir: `--dry-run`,
 * `--force` (üretimde onaysız çalışmaz), chunk'lı silme.
 *
 * `logs:prune`'dan FARKI: satırlar soft-delete DEĞİL forceDelete ile
 * silinir VE diskteki dosya da kaldırılır — sahipsiz bir yükleme hiçbir
 * zaman gerçek bir kayıt olmadı, iz bırakmanın (soft delete) burada bir
 * denetim/rapor değeri yok; asıl amaç disk + tablo şişmesini önlemek.
 */
class PruneOrphanAttachments extends Command
{
    /**
     * @var string
     */
    protected $signature = 'attachments:prune-orphans
        {--hours= : config/chat.php->attachments.orphan_retention_hours değerini ezer}
        {--dry-run : Hiçbir şey silmez, kaç kaydın etkileneceğini yazdırır}
        {--force : Onay istemeden çalıştır (üretimde --force olmadan silme yapılmaz)}';

    /**
     * @var string
     */
    protected $description = 'Hiçbir mesaja bağlanmamış (attachable_id NULL) ve saklama süresini aşan attachments satırlarını + diskteki dosyalarını kalıcı olarak siler.';

    /**
     * Silme sırasında tek seferde işlenecek satır sayısı.
     */
    private const CHUNK_SIZE = 200;

    public function handle(): int
    {
        $hoursOption = $this->option('hours');

        if ($hoursOption !== null && (! is_numeric($hoursOption) || (int) $hoursOption < 0)) {
            $this->components->error('--hours negatif olmayan bir tam sayı olmalıdır.');

            return self::FAILURE;
        }

        $hours = $hoursOption !== null ? (int) $hoursOption : (int) config('chat.attachments.orphan_retention_hours');
        $cutoff = Carbon::now()->subHours($hours);

        $count = $this->baseQuery($cutoff)->count();
        $isDryRun = (bool) $this->option('dry-run');
        $isForced = (bool) $this->option('force');

        if ($isDryRun) {
            $this->components->info("dry-run: hiçbir şey silinmedi ({$count} sahipsiz ek etkilenecekti, > {$hours} saat, kesim: {$cutoff->toDateTimeString()}).");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->components->info('Saklama süresini aşan sahipsiz ek bulunamadı.');

            return self::SUCCESS;
        }

        if (! $isForced) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Üretimde silme işlemi onay ister: --no-interaction ile çalıştırırken --force gereklidir.');

                return self::FAILURE;
            }

            if (! $this->confirm("{$count} sahipsiz ek (disk dosyası dahil) kalıcı olarak silinecek. Devam edilsin mi?")) {
                $this->components->warn('İşlem iptal edildi.');

                return self::SUCCESS;
            }
        }

        $deleted = 0;

        $this->components->task('Sahipsiz ekler siliniyor', function () use ($cutoff, &$deleted) {
            do {
                $batch = $this->baseQuery($cutoff)->limit(self::CHUNK_SIZE)->get();

                foreach ($batch as $attachment) {
                    Storage::disk($attachment->disk)->delete($attachment->path);
                    $attachment->forceDelete();
                    $deleted++;
                }
            } while ($batch->count() === self::CHUNK_SIZE);

            return true;
        });

        $this->components->info("{$deleted} sahipsiz ek kalıcı olarak silindi.");

        return self::SUCCESS;
    }

    /**
     * @return Builder<Attachment>
     */
    private function baseQuery(Carbon $cutoff)
    {
        return Attachment::query()->unattached()->where('created_at', '<', $cutoff);
    }
}
