<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 14 / İz E — fırsatta DONMUŞ temel-para-birimi tutarı
 * (docs/PHASE-INTL.md §2.3 + §2.4).
 *
 * ÜÇ KOLON, TEK KAVRAM: "bu fırsat KAPANDIĞI AN kaç TRY ediyordu?"
 *   - `base_amount`  decimal(15,2) — kapanış anındaki TRY karşılığı.
 *   - `base_rate`    decimal(18,6) — kullanılan kur (1 birim yabancı para = X TRY).
 *   - `base_rate_date` date        — o kurun TCMB yayın tarihi (TRY'de kapanış günü).
 * Üçü BİRLİKTE yazılır, birlikte temizlenir; `base_amount` dolu ama
 * `base_rate` boş bir satır ASLA oluşmaz — rakamın nereden geldiği
 * açıklanamaz olurdu (PHASE-INTL §2.4 "rapor hangi kuru kullandığını
 * gösterir" kuralının satır düzeyindeki karşılığı).
 *
 * NEDEN DONDURULUYOR (§2.4, bilinçli muhasebe kararı): gerçekleşmiş gelir
 * sabittir. Ocak'ta kazanılmış bir fırsatı her gün güncel kurla yeniden
 * değerlemek, geçen çeyreğin gelirini her gün değiştirir ("geçen ay neden
 * değişti?" — Faz 9 KDV sınıfı sessiz hata). Rapor toplaması bu yüzden
 * `SUM(base_amount)` ile tek sorguda, dönüşümsüz ve KARARLI çalışır.
 *
 * NULLABLE, ÇÜNKÜ: açık (`open`) fırsatların donmuş bir değeri YOKTUR —
 * onların bugünkü değeri güncel kurla, rapor anında hesaplanır. Ayrıca
 * kapanış anında hiçbir kur satırı bulunamayan (ve son bilinen kuru da
 * olmayan) yabancı para birimli bir fırsat, SESSİZCE 0 yazmak yerine null
 * bırakılır + `warning` loglanır (bkz. App\Services\Deals\DealMoveService::
 * freezeBaseAmount) — sıfır, "geliri yok" demektir ve raporu sessizce
 * yanıltır; null "bilinmiyor" demektir ve rapor bunu
 * `rate_info.unconverted_closed_count` ile GÖRÜNÜR kılar.
 *
 * FLOAT YOK: `base_amount` deals.amount ile aynı decimal(15,2), `base_rate`
 * exchange_rates.rate ile aynı decimal(18,6) — docs/QUOTE-FINANCIALS.md
 * disiplini (dönüşüm bcmath ile yapılır, PHP float'ı hesaba hiç girmez).
 *
 * -----------------------------------------------------------------------------
 * GERİYE DÖNÜK DOLDURMA (backfill) — NEDEN BU GÖÇÜN İÇİNDE
 * -----------------------------------------------------------------------------
 * Bu göçten önce kapanmış TÜM fırsatların `base_amount`'ı null olurdu ve
 * `SUM(base_amount)` tabanlı raporlar bir anda "0 gelir" gösterirdi — mevcut
 * demo/üretim verisi için sessiz ve tam bir veri kaybı görüntüsü. Bu yüzden
 * göç, ZATEN KAPANMIŞ ve para birimi TEMEL PARA BİRİMİ (TRY) olan satırları
 * doldurur: TRY için kur tanımı gereği 1.000000'dır, dolayısıyla bu bir
 * TAHMİN DEĞİL, kesin bir kimliktir (`base_amount = amount`). Bu fazdan önce
 * sistemde yabancı para birimli hiçbir fırsat kapanmış olamaz (kur altyapısı
 * bu fazda doğdu), ama yine de savunma amaçlı `currency = 'TRY'` koşulu
 * konur: yabancı para birimli eski bir satır varsa null kalır ve rapor onu
 * "çevrilemedi" olarak GÖSTERİR — uydurma bir kurla doldurmaz.
 *
 * `base_rate_date` = `DATE(closed_at)`; `closed_at` boşsa (veri tutarsızlığı)
 * satır atlanır — donmuş kur tarihi olmayan bir donmuş tutar yazmayız.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->decimal('base_amount', 15, 2)->nullable()->after('currency');
            $table->decimal('base_rate', 18, 6)->nullable()->after('base_amount');
            $table->date('base_rate_date')->nullable()->after('base_rate');
        });

        // Yalnızca kapanmış + TRY + closed_at dolu satırlar. Ham SQL yerine
        // query builder; `whereNull('base_amount')` idempotency sağlar
        // (göç iki kez çalışsa bile dolu satırı ezmez).
        DB::table('deals')
            ->whereIn('status', ['won', 'lost'])
            ->where('currency', 'TRY')
            ->whereNotNull('closed_at')
            ->whereNull('base_amount')
            ->update([
                'base_amount' => DB::raw('amount'),
                'base_rate' => '1.000000',
                'base_rate_date' => DB::raw('DATE(closed_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['base_amount', 'base_rate', 'base_rate_date']);
        });
    }
};
