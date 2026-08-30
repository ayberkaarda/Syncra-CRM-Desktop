<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use Illuminate\Database\Eloquent\Builder;

/**
 * =============================================================================
 * Geçerlilik bitişi TÜRETİLMİŞTİR, kalıcı bir bayrak değildir
 * =============================================================================
 *
 * Karar, Faz 8'in SLA ihlali kararıyla (docs/SLA-DESIGN.md §1: "İhlal kalıcı
 * bayrak değil, türetilmiş değerdir") BİLİNÇLİ olarak aynıdır ve aynı üç
 * gerekçeye dayanır:
 *
 *  1. KALICI BAYRAK ARADA YALAN SÖYLER. Durumu `sent → expired` diye çeviren
 *     bir zamanlanmış görev (`quotes:scan-expired`) ne sıklıkta koşarsa
 *     koşsun, iki koşu ARASINDA süresi dolmuş bir teklif hâlâ "gönderildi"
 *     görünür. Kullanıcı ekranda geçerli sanıp müşteriye onaylatabilir.
 *     Türetilmiş değer okuma anında hesaplandığı için ASLA bayatlamaz.
 *  2. ZAMANLANMIŞ GÖREV BAĞIMLILIĞI. Kalıcı yaklaşım, doğruluğu
 *     `schedule:work` sürecinin ayakta olmasına bağlar. Windows/XAMPP
 *     ortamında bu süreç elle başlatılır (bkz. docs/PROGRESS.md "Zamanlanmış
 *     görevler"); durursa veriler sessizce yanlışlaşır. Türetilmiş değerin
 *     çalışan bir sürece ihtiyacı yoktur.
 *  3. GERİ ALINAMAZLIK. Durumu `expired` yapmak TERMİNAL bir yazmadır
 *     (QuoteStatusMachine). Bir tarih hatası yüzünden erken damgalanan teklif
 *     bir daha kabul edilemez hâle gelirdi; türetilmiş değerde `valid_until`
 *     düzeltilince her şey kendiliğinden düzelir.
 *
 * -----------------------------------------------------------------------------
 * PEKİ `status = 'expired'` NEDEN HÂLÂ VAR
 * -----------------------------------------------------------------------------
 * `quotes.status` enum'unda `expired` bir değer olarak durur ve demo veride
 * bir teklif bu durumdadır. Bu bir çelişki değil, iki farklı sorunun
 * cevabıdır:
 *
 *   - `is_expired` (türetilmiş): "bu teklifin geçerlilik tarihi geçti mi?"
 *   - `status = 'expired'` (kalıcı): "bu teklif, KULLANICI tarafından
 *     süresi dolmuş olarak KAPATILDI mı?" — takip listesinden düşürmek için
 *     verilen bilinçli, terminal bir karar.
 *
 * Yani otomatik bir yazan YOKTUR; tek yazan `PATCH /api/quotes/{quote}/status`
 * ucundan gelen kullanıcıdır. `is_expired` ise her iki durumu da kapsar:
 * kullanıcı kapatmışsa da, tarihi geçmiş bir `sent` teklifse de doğrudur.
 *
 * Bu sınıf o tanımın TEK yeridir — `is_expired` alanı, `filter[expired]`
 * filtresi ve ileride Faz 11 raporları aynı predicate'i paylaşır. Kopyalanan
 * ikinci bir tanım, listedeki sayı ile detaydaki rozetin çelişmesi demektir.
 */
class QuoteExpiry
{
    /**
     * Yalnızca `sent` teklifler tarih yüzünden "süresi dolmuş" sayılır:
     * bir taslak müşteriye hiç ulaşmamıştır (süresi dolamaz), kabul veya red
     * ise sonuçlanmıştır — sonuçlanmış bir teklifin geçerlilik tarihinin
     * geçmiş olması onu geçersiz kılmaz.
     */
    public function isExpired(Quote $quote): bool
    {
        if ($quote->status === 'expired') {
            return true;
        }

        if ($quote->status !== 'sent' || $quote->valid_until === null) {
            return false;
        }

        return $quote->valid_until->startOfDay()->isBefore(now()->startOfDay());
    }

    /**
     * Sorgu tarafındaki AYNI tanım. Ham SQL yok: parantezli bir `where`
     * grubu, dışarıdaki `filter[...]` koşullarına OR sızdırmaz.
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', 'expired')
                ->orWhere(function (Builder $query): void {
                    $query->where('status', 'sent')
                        ->whereNotNull('valid_until')
                        ->whereDate('valid_until', '<', now()->toDateString());
                });
        });
    }

    /**
     * `filter[expired]=0` — süresi DOLMAMIŞ teklifler. `scopeExpired`'in tam
     * tümleyeni olması için `whereNot` ile aynı grubun değili alınır; koşulu
     * elle ters çevirmek (`>=` yazmak) `valid_until IS NULL` olan kayıtları
     * sessizce dışarıda bırakırdı.
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->whereNot(function (Builder $query): void {
            $this->scopeExpired($query);
        });
    }
}
