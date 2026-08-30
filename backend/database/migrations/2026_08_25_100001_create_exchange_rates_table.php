<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 14 / İz E — çoklu para birimi kur tablosu (docs/PHASE-INTL.md §2.3).
 *
 * Her satır BİR para biriminin BİR güne ait TCMB döviz alış kurudur
 * (`rate = ForexBuying / Unit`, 1 birim yabancı para için TRY karşılığı —
 * gerekçe App\Services\Exchange\TcmbRateFetcher dokümanında). `source`
 * ayrımı otomatik (tcmb) çekmeyi elle (manual, Ayarlar'dan) girilen
 * düzeltmeden ayırt eder; `entered_by` yalnız manual satırlarda dolu olur.
 *
 * KARAR — TRY için satır TUTULMAZ (örtük, rate=1.000000):
 *   TCMB today.xml TRY/TRY çapraz kuru yayınlamaz (TRY her zaman kendi
 *   karşılığıdır) — bu yüzden çekme hattının TRY için üreteceği "gerçek"
 *   bir veri yoktur; sahte bir 1.000000 satırı GÜNLÜK olarak yazmak yalnız
 *   depolama israfı ve idempotency karmaşıklığı (365 anlamsız satır/yıl)
 *   ekler, hiçbir sorguyu kolaylaştırmaz. `ExchangeRateService::latest()`
 *   TRY için DB'ye hiç gitmeden `null` döner; çağıran taraf TRY'yi temel
 *   para birimi olarak (rate=1) ele alır — `ExchangeRateService::
 *   isBaseCurrency()` bu kısayolu standartlaştırır. Alternatif (her gün
 *   TRY için de satır yazmak) değerlendirildi ve reddedildi: `unique
 *   (currency, rate_date)` kısıtı ve bayatlık sorgusu TRY için anlamsız
 *   hale gelirdi (TRY hiçbir zaman "bayat" olamaz, temel birim budur).
 *
 * `unique(currency, rate_date)`: aynı gün + aynı para birimi için tek
 * satır garantisi — `exchange:fetch-tcmb` komutunun idempotency'si
 * (aynı günde birden fazla tetiklenme, hafta sonu/tatil "yeniden yayın"
 * senaryosu) doğrudan bu kısıta dayanır; komut upsert ile çalışır, kısıt
 * ihlali/yinelenen satır oluşmaz.
 *
 * `rate` decimal(18,6): TCMB günlük bültende 4 hane yayınlar; 6 hane
 * güvenli bir tampon (yuvarlama zincirlerinde hassasiyet kaybını önler).
 * FLOAT KULLANILMAZ — bkz. docs/QUOTE-FINANCIALS.md disiplini (bu fazda
 * bcmath ile aynı prensip kur hesaplarına da uygulanır).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->decimal('rate', 18, 6);
            // TCMB birimi: USD/EUR/GBP için 1, ör. JPY eklenirse 100 olur —
            // bkz. TcmbRateFetcher (bölme GENEL yazılır, sabit 1 varsayılmaz).
            $table->smallInteger('unit')->default(1);
            $table->date('rate_date');
            $table->enum('source', ['tcmb', 'manual']);
            // Yalnız source='manual' satırlarında dolu; otomatik TCMB
            // çekmesinde kullanıcı yoktur (zamanlanmış görev/konsol).
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['currency', 'rate_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
