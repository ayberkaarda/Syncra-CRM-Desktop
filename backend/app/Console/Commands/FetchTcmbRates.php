<?php

namespace App\Console\Commands;

use App\Services\Exchange\ExchangeRateService;
use App\Services\Exchange\TcmbRateFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 * `exchange:fetch-tcmb` — TCMB günlük döviz kuru çekme (Faz 14 / İz E)
 * =============================================================================
 *
 * `routes/console.php`'de HER GÜN 16:00'da zamanlanır (TCMB ~15:30 civarı
 * yayınlar, 16:00 buna güvenli bir pay bırakır). Güvenli ayrıştırma ve
 * giden çağrı sertleştirmesi (H7) `App\Services\Exchange\TcmbRateFetcher`
 * dokümanındadır — bu komut yalnızca sonucu yorumlar ve loglar.
 *
 * -----------------------------------------------------------------------------
 * HAFTA SONU / RESMİ TATİL DAVRANIŞI — KRİTİK KARAR (PHASE-INTL §2.1)
 * -----------------------------------------------------------------------------
 * TCMB hafta sonu ve resmi tatillerde (ve günün ~15:30'undan önce) yeni
 * bülten YAYINLAMAZ; `today.xml` o günlerde SON YAYINLANAN günün verisini
 * döndürmeye devam eder. Bu senaryoda:
 *
 *   1. `TcmbRateFetcher::fetch()` YİNE BAŞARILI döner (HTTP 200, geçerli
 *      XML) — ama `rateDate` bugünün tarihi DEĞİL, son yayınlanan tarihtir.
 *   2. `ExchangeRateService::applyFetch()` bu tarih için satırlar ZATEN
 *      VARSA (önceki çalıştırmadan) `unique(currency, rate_date)` sayesinde
 *      hiçbir şey YAZMAZ — `written=0`, `unchanged=N`.
 *   3. Komut bunu `written=0` görünce "bugün için yeni TCMB verisi yok"
 *      diye YORUMLAR ve bunu `info` seviyesinde loglar — BU BİR HATA
 *      DEĞİLDİR, exit code her zaman SUCCESS'tir. Son bilinen kur (önceki
 *      satır) DEĞİŞMEDEN kalır; bkz. tests/Feature/Exchange/
 *      FetchTcmbRatesTest::hafta sonu senaryosu.
 *
 * Bunu HATA sayan bir uygulama, Faz 9'daki "KDV'yi indirim öncesi matrahtan
 * hesaplama" sınıfı bir sessiz-doğru-görünen-ama-yanlış davranış olurdu —
 * burada tam tersi: doğru davranışı YANLIŞLIKLA hataya çevirmemek esastır.
 *
 * -----------------------------------------------------------------------------
 * TOPLAM BAŞARISIZLIK (TCMB'ye hiç ulaşılamıyor / XML reddedildi)
 * -----------------------------------------------------------------------------
 * `TcmbRateFetcher::fetch()` `success=false` döndürürse: komut BAŞARISIZLIĞI
 * `warning` seviyesinde loglar ama yine SUCCESS exit code döner — "son
 * bilinen kuru kullan, kırılma" kararı (PHASE-INTL §2.1/§2.5). Zamanlanmış
 * görevin başarısız sayılması, Laravel'in scheduler hata bildirim/izleme
 * mekanizmasını (varsa) gereksiz yere tetikler; asıl kullanıcı etkisi
 * yoktur çünkü uygulama zaten son kurla çalışmaya devam eder.
 */
class FetchTcmbRates extends Command
{
    /**
     * @var string
     */
    protected $signature = 'exchange:fetch-tcmb
        {--dry-run : Hiçbir şey kaydetmez, TCMB\'den ne çekileceğini yazdırır}';

    /**
     * @var string
     */
    protected $description = 'TCMB today.xml\'den USD/EUR/GBP döviz alış kurlarını çeker ve exchange_rates tablosuna idempotent şekilde yazar.';

    public function __construct(
        protected TcmbRateFetcher $fetcher,
        protected ExchangeRateService $rates,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->fetcher->fetch();

        if (! $result->success) {
            $message = "TCMB kur çekme başarısız: {$result->errorMessage} — son bilinen kurlar korunuyor (kırılma yok).";
            $this->components->warn($message);
            Log::warning('exchange:fetch-tcmb başarısız, son kur korundu.', [
                'error' => $result->errorMessage,
            ]);

            return self::SUCCESS;
        }

        $rateDateLabel = $result->rateDate->format('d.m.Y');

        if ($this->option('dry-run')) {
            $this->components->info("dry-run: TCMB {$rateDateLabel} tarihli veri döndürdü, hiçbir şey kaydedilmedi.");

            foreach ($result->rates as $currency => $data) {
                $this->line("  {$currency}: rate={$data['rate']} (unit={$data['unit']})");
            }

            return self::SUCCESS;
        }

        $summary = $this->rates->applyFetch($result);

        if ($summary->written === 0) {
            // Bkz. sınıf dokümanındaki "HAFTA SONU / RESMİ TATİL" bölümü —
            // bu YOL BİR HATA DEĞİLDİR, bilinçli olarak `info` seviyesinde.
            $this->components->info(
                "TCMB {$rateDateLabel}: {$summary->unchanged} kur zaten güncel, yeni veri yok (hafta sonu/tatil sonrası tekrar yayın olabilir) — son kur korunuyor."
            );
            Log::info('exchange:fetch-tcmb: bugün için yeni TCMB verisi yok, son kur korundu.', [
                'rate_date' => $result->rateDate->toDateString(),
                'currencies' => $summary->currencies,
            ]);

            return self::SUCCESS;
        }

        $this->components->info(
            "TCMB {$rateDateLabel}: {$summary->written} kur kaydedildi/güncellendi, {$summary->unchanged} zaten güncel."
        );
        Log::info('exchange:fetch-tcmb: kurlar güncellendi.', [
            'rate_date' => $result->rateDate->toDateString(),
            'written' => $summary->written,
            'unchanged' => $summary->unchanged,
            'currencies' => $summary->currencies,
        ]);

        return self::SUCCESS;
    }
}
