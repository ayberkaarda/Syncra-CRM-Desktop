<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 14 / İz E — teklifte DONMUŞ kur (docs/PHASE-INTL.md §2.3).
 *
 *   - `exchange_rate`      decimal(18,6) — gönderim (`sent`) anındaki kur,
 *                          1 birim `quotes.currency` = X TRY.
 *   - `exchange_rate_date` date          — o kurun TCMB yayın tarihi.
 *
 * NEDEN `sent` ANINDA: teklif, `sent` olduğu anda müşteriye giden ve artık
 * DEĞİŞMEYEN bir belgedir (Faz 9 `QUOTE_LOCKED` kilidi). PDF'te basılan
 * "1 USD = 41,25 TRY (25.08.2026)" satırı, müşterinin gördüğü belgenin
 * parçasıdır; sonradan güncel kurla yeniden hesaplanırsa aynı belge iki
 * farklı TL karşılığı gösterir.
 *
 * NULLABLE, ÇÜNKÜ: taslakta (`draft`) kur YOKTUR — henüz gönderilmemiş,
 * dolayısıyla donacak bir an da yoktur. Kur bulunamayan bir gönderimde de
 * null kalır (+ `warning` log, bkz. App\Services\Quotes\QuoteStatusMachine::
 * freezeExchangeRate): sahte bir kur yazmak, PDF'e uydurma bir rakam
 * bastırmak demektir.
 *
 * REVİZYON (`QTE-000007-R2`) DEVRALMAZ (§2.3): revizyon YENİ bir belgedir,
 * `draft` doğar ve kendi `sent` anında TAZE kur alır. Kopyalama
 * App\Services\Quotes\QuoteService::REVISION_COPIED_FIELDS beyaz listesiyle
 * yapıldığı için bu kolonlar zaten kopyalanmaz; QuoteService ayrıca açıkça
 * null yazar (niyetin okunur olması için).
 *
 * HESAP MOTORUNA DOKUNMAZ (§2.7): `subtotal`/`tax_amount`/`total` teklifin
 * KENDİ para biriminde hesaplanmaya devam eder; kur yalnızca raporlama ve
 * gösterim dönüşümü içindir, kalem/KDV matematiğine GİRMEZ.
 *
 * FLOAT YOK: exchange_rates.rate ile aynı decimal(18,6) hassasiyeti.
 *
 * BACKFILL YOK (bilinçli): bu fazdan önce gönderilmiş tekliflerin hepsi TRY
 * olsa bile, o belgelerde basılmış bir kur satırı YOKTU — geriye dönük bir
 * kur yazmak, müşterinin eline geçmiş PDF ile veritabanını çelişkiye
 * düşürür. Eski teklifler kursuz kalır; gösterim tarafı `null` gördüğünde
 * kur satırını hiç basmaz. (Fırsat tarafındaki backfill'in gerekçesi
 * FARKLIDIR: orada rapor toplaması sessizce sıfırlanırdı; burada yalnızca
 * bir belge dipnotu eksik kalır.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency');
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'exchange_rate_date']);
        });
    }
};
