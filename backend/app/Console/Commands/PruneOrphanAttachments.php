<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * `attachments:prune-orphans` — ZAMANLANMIŞ, GERİ DÖNÜŞSÜZ SİLME.
 *
 * ZAMANLAMA: routes/console.php (D4) bu komutu
 * `Schedule::command('attachments:prune-orphans --force')->dailyAt('03:47')`
 * ile HER GÜN 03:47'de çalıştırır. Eşik `config('chat.attachments.
 * orphan_retention_hours')` (varsayılan 24 saat) — zamanlayıcı `--hours`
 * vermez. Elle çalıştırma da mümkündür ama komut "yalnızca elle çalışan bir
 * araç" DEĞİLDİR; bu docblock daha önce öyle olduğunu iddia ediyordu ve o
 * iddia bir veri kaybı hatasını gizledi (bkz. baseQuery()).
 *
 * NEYİ SİLER: yalnızca hiçbir yerden referans verilmeyen ekler —
 *   - `attachments.attachable_type/attachable_id` NULL (lead/contact zaman
 *     çizelgesine bağlı DEĞİL), VE
 *   - hiçbir `messages.attachment_id` bu satıra işaret ETMİYOR (sohbette
 *     gönderilmiş DEĞİL; soft-delete edilmiş mesajlar dahil), VE
 *   - `created_at` saklama eşiğinden eski.
 * Silme `forceDelete()` + `Storage::delete()`'tir: satır ve diskteki dosya
 * kalıcı olarak gider, geri alma yolu yoktur.
 *
 * NEYİ SİLMEZ: bir mesaja bağlı ekler, bir lead/contact'a bağlı ekler,
 * saklama eşiğinden yeni ekler. Şemada `attachments.id`'ye işaret eden TEK
 * yabancı anahtar `messages.attachment_id`'dir (2026_08_23_200006); ikinci ve
 * son bağlılık yolu polimorfik `attachable_*` kolonlarıdır. baseQuery() bu
 * iki yolu da dışlar; üçüncü bir yol eklenirse baseQuery de güncellenmelidir.
 *
 * GEREKÇE: Bir dosya `POST /api/attachments` ile yüklenip Attachment satırı
 * oluşturulduktan SONRA kullanıcı mesajı hiç göndermezse (sekmeyi kapatır,
 * "gönder"e basmadan vazgeçer, ağ hatası vb.) hiçbir referans oluşmaz ve
 * dosya diskte + tabloda süresiz birikir. `logs:prune`
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
    protected $description = 'Hiçbir mesaja VE hiçbir kayda bağlanmamış, saklama süresini aşan attachments satırlarını + diskteki dosyalarını kalıcı olarak siler.';

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
     * Silmeye aday satırlar. Bu sorgu diskteki dosyayı da yok ettiği için
     * bir eke işaret eden HER yol burada dışlanmak zorundadır:
     *
     *  1. `unattached()` → polimorfik `attachable_*` (lead/contact ekleri).
     *  2. `whereNotExists(messages)` → sohbet ekleri.
     *     `MessageService::create()` bir eki mesaja bağlarken YALNIZCA
     *     `messages.attachment_id` yazar, `attachable_*`'ı hiç doldurmaz;
     *     yani (1) tek başına sohbet eklerini KORUMAZ ve bu komut daha önce
     *     sohbete gönderilen her eki 24 saat sonra siliyordu.
     *
     * NEDEN `whereNotExists`, `whereDoesntHave` DEĞİL: (a) Attachment
     * üzerinde bir `messages` ilişkisi yok — `whereDoesntHave` yalnızca onu
     * eklemek için var olacak bir ilişki gerektirirdi, üstelik derlediği SQL
     * de aynı `where not exists`; (b) bu komut tüm `attachments` tablosunu
     * tarar, alt sorgu `messages_attachment_id_foreign` indeksi üzerinden
     * aday satır başına tek bir indeks aramasıdır (InnoDB yabancı anahtar
     * için bu indeksi kendiliğinden oluşturur), yani ekstra bir sıralama ya
     * da geçici tablo maliyeti yoktur.
     *
     * `messages.deleted_at` KASITLI olarak filtrelenmez: soft-delete edilmiş
     * bir mesaj geri alınabilir, eki hâlâ ona aittir.
     *
     * @return Builder<Attachment>
     */
    private function baseQuery(Carbon $cutoff)
    {
        return Attachment::query()
            ->unattached()
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.attachment_id', 'attachments.id');
            })
            ->where('created_at', '<', $cutoff);
    }
}
