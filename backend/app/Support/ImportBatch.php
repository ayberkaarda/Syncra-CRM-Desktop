<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Lead CSV içe aktarma işinin ilerleme/rapor durumu.
 *
 * ---------------------------------------------------------------------------
 * NEDEN REDIS, NEDEN YENİ BİR TABLO DEĞİL
 * ---------------------------------------------------------------------------
 * Bir import "batch"i geçicidir: kullanıcı 30 saniye sonra raporu okur, sonra
 * bir daha asla ihtiyaç duymaz. Bunun için migration'lı bir tablo açmak
 * (index'i, soft-delete'i, temizlik cron'uyla) kalıcı bir şema sorumluluğu
 * yaratırdı — hem de Faz 6/D bu şemaya sahip değil. Redis zaten
 * cache/queue store olarak kurulu (REDIS_CLIENT=predis); TTL'li bir anahtar
 * tam ihtiyacı karşılıyor: 24 saat sonra kendiliğinden silinir, hiçbir
 * temizlik job'u gerekmez.
 *
 * ---------------------------------------------------------------------------
 * ANAHTAR VE TTL
 * ---------------------------------------------------------------------------
 * `syncra:import:{uuid}` — `syncra:` öneki, ileride Redis'te başka amaçlarla
 * (cache, oturum, kuyruk) kullanılan anahtarlarla çakışmayı önler. TTL 24
 * saat: bir kullanıcının "dün yüklediğim dosya ne olmuştu" diye bakması makul
 * bir pencere, ama sonsuza kadar tutmak Redis'i büyük dosyalardan gelen hata
 * listeleriyle şişirir.
 *
 * ---------------------------------------------------------------------------
 * NEDEN HER SATIRDA DEĞİL, `flush()` İLE TOPLU YAZMA
 * ---------------------------------------------------------------------------
 * Sayaç metodları (`incrementCreated()` vb.) yalnızca bellekteki diziyi
 * değiştirir; Redis'e yazmaz. Çağıran taraf (LeadImportService) periyodik
 * olarak `flush()` çağırır. 50.000 satırlık bir dosyada her satırda bir
 * SETEX çağırmak 50.000 round-trip demektir — `flush()`'ı 50 satırda bir
 * çağırmak bu sayıyı ~%98 azaltır, ilerleme çubuğu için yeterince "canlı"
 * kalır.
 */
final class ImportBatch
{
    public const KEY_PREFIX = 'syncra:import:';

    /**
     * 24 saat — bkz. sınıf dokümantasyonu.
     */
    public const TTL_SECONDS = 60 * 60 * 24;

    /**
     * `errors` dizisinde tutulacak en fazla kayıt. 50.000 satırlık bozuk bir
     * dosya her satır için bir hata üretebilir; bunların hepsini Redis'e
     * yazmak tek bir anahtarı megabaytlarca JSON'a çevirir. İlk 100 hata
     * genelde "ne yanlış gidiyor" sorusunu yanıtlamaya yeter — kullanıcı
     * deseni görüp dosyasını düzeltir. Sınır aşılırsa `errors_truncated` true
     * olur, kullanıcı "daha fazla hata var, ilk 100'ü gösteriyoruz" bilgisini
     * alır.
     */
    public const MAX_ERRORS = 100;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $state
     */
    private function __construct(private array $state) {}

    public static function start(string $id, int $userId, int $totalRows): self
    {
        $now = now()->toIso8601String();

        $batch = new self([
            'id' => $id,
            'user_id' => $userId,
            'status' => self::STATUS_PENDING,
            'total_rows' => $totalRows,
            'processed' => 0,
            'created' => 0,
            'skipped' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
            'errors_truncated' => false,
            'started_at' => $now,
            'finished_at' => null,
        ]);

        $batch->flush();

        return $batch;
    }

    public static function find(string $id): ?self
    {
        $raw = Redis::get(self::KEY_PREFIX.$id);

        if ($raw === null) {
            return null;
        }

        $state = json_decode($raw, true);

        if (! is_array($state)) {
            return null;
        }

        return new self($state);
    }

    public function id(): string
    {
        return (string) $this->state['id'];
    }

    public function userId(): int
    {
        return (int) $this->state['user_id'];
    }

    /**
     * Yalnızca batch'i başlatan kullanıcı durumu okuyabilsin diye — controller
     * bunu `status()` ucunda çağırıp başarısızsa 403 döner.
     */
    public function belongsTo(int $userId): bool
    {
        return $this->userId() === $userId;
    }

    public function status(): string
    {
        return (string) $this->state['status'];
    }

    public function markProcessing(): void
    {
        $this->state['status'] = self::STATUS_PROCESSING;
        $this->flush();
    }

    public function markCompleted(): void
    {
        $this->state['status'] = self::STATUS_COMPLETED;
        $this->state['finished_at'] = now()->toIso8601String();
        $this->flush();
    }

    /**
     * Job'un `failed()` metodundan çağrılır. `$reason` verilirse rapora bir
     * hata satırı olarak eklenir (row: 0 — dosyaya değil, işin kendisine ait
     * bir hata olduğunu belirtir).
     */
    public function markFailed(?string $reason = null): void
    {
        $this->state['status'] = self::STATUS_FAILED;
        $this->state['finished_at'] = now()->toIso8601String();

        if ($reason !== null) {
            $this->addError(0, $reason, [], 'error');
        }

        $this->flush();
    }

    public function incrementProcessed(): void
    {
        $this->state['processed']++;
    }

    public function incrementCreated(): void
    {
        $this->state['created']++;
    }

    public function incrementSkipped(): void
    {
        $this->state['skipped']++;
    }

    public function incrementUpdated(): void
    {
        $this->state['updated']++;
    }

    public function incrementFailed(): void
    {
        $this->state['failed']++;
    }

    /**
     * @param  array<string, mixed>  $data  Satırın ham verisi (teşhis amaçlı).
     * @param  string  $level  'error' (satır başarısız oldu) | 'warning' (varsayılana
     *                         düşüldü) | 'skipped' (duplicate nedeniyle atlandı).
     *                         Toplamda 'errors' dizisinde birlikte tutulur; hangi
     *                         sayaca yansıdığı (`failed`/`skipped`/vs.) ayrı
     *                         increment* çağrısıyla belirlenir, bu yalnızca
     *                         satır bazlı rapor detayıdır.
     */
    public function addError(int $row, string $message, array $data = [], string $level = 'error'): void
    {
        if (count($this->state['errors']) >= self::MAX_ERRORS) {
            $this->state['errors_truncated'] = true;

            return;
        }

        $this->state['errors'][] = [
            'row' => $row,
            'message' => $message,
            'data' => $data,
            'level' => $level,
        ];
    }

    public function flush(): void
    {
        Redis::setex(self::KEY_PREFIX.$this->id(), self::TTL_SECONDS, json_encode($this->state));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->state;
    }
}
