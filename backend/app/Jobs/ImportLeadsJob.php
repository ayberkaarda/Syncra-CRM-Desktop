<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Leads\LeadImportService;
use App\Support\ImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 500 satır ve üzeri CSV içe aktarmalarını arka planda işler (bkz.
 * LeadImportService::SYNC_ROW_THRESHOLD). İş mantığının tamamı
 * `LeadImportService::process()`'te yaşar — bu job yalnızca dosyayı açar,
 * servisi çağırır ve sonunda geçici dosyayı temizler.
 */
class ImportLeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Otomatik tekrar deneme YOK.
     *
     * GEREKÇE: Bir import ilk denemede kısmen tamamlanmış olabilir (ör. 300
     * lead oluşturulduktan sonra worker OOM'dan öldü). Laravel'in varsayılan
     * `tries` davranışıyla job'u tekrar denemek dosyayı BAŞTAN işler ve
     * duplicate_mode='create' seçiliyse önceki 300 satır bu kez İKİNCİ KEZ
     * oluşturulur — sessiz veri çoğaltma. duplicate_mode='skip' bile bunu
     * tam garanti etmez (bir satır kendi az önce oluşturduğu kopyayla eşleşip
     * atlanabilir de atlanmayabilir de, DuplicateDetector'ın skorlama
     * sırasına bağlı). Tek deneme + `failed()` ile batch'i 'failed' işaretlemek,
     * kullanıcıya "kısmen işlendi, tekrar dene" yerine net ve tekrarlanabilir
     * bir sonuç verir; batch raporu o ana kadar işlenen satırları zaten
     * gösterir.
     */
    public int $tries = 1;

    public function __construct(
        private readonly string $batchId,
        private readonly string $relativePath,
        private readonly string $duplicateMode,
        private readonly ?int $ownerId,
        private readonly int $actorUserId,
    ) {}

    public function handle(LeadImportService $service): void
    {
        $batch = ImportBatch::find($this->batchId);

        if ($batch === null) {
            // Batch anahtarı TTL'den önce silinmiş olamaz (job dispatch
            // edilir edilmez batch zaten Redis'te) ama savunmacı: yine de
            // dosyayı temizle, yarım kalmış bir geçici dosya storage/app/imports
            // altında birikmesin.
            $this->cleanup();

            return;
        }

        $actor = User::find($this->actorUserId);

        if ($actor === null) {
            $batch->markFailed('İçe aktarmayı başlatan kullanıcı artık mevcut değil.');
            $this->cleanup();

            return;
        }

        $absolutePath = Storage::disk('local')->path($this->relativePath);
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            $batch->markFailed('Yüklenen dosya okunamadı.');
            $this->cleanup();

            return;
        }

        try {
            $service->readHeader($handle);
            $service->process($batch, $handle, $this->duplicateMode, $this->ownerId, $actor);
        } finally {
            fclose($handle);
        }

        $this->cleanup();
    }

    public function failed(?Throwable $exception): void
    {
        $batch = ImportBatch::find($this->batchId);

        $batch?->markFailed($exception?->getMessage() ?? 'İçe aktarma sırasında beklenmeyen bir hata oluştu.');

        $this->cleanup();
    }

    private function cleanup(): void
    {
        Storage::disk('local')->delete($this->relativePath);
    }
}
