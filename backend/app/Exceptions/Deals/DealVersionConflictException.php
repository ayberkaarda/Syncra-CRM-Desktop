<?php

namespace App\Exceptions\Deals;

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * =============================================================================
 * 409 DEAL_VERSION_CONFLICT — iyimser kilit çakışması
 * =============================================================================
 *
 * İki kullanıcı aynı kartı aynı anda sürüklediğinde ikincisi, artık var
 * olmayan bir dünyanın fotoğrafına göre karar vermiştir: gördüğü sütun, gördüğü
 * komşular ve gördüğü durum bayattır. O isteği uygulamak, birinci kullanıcının
 * hareketini SESSİZCE geri almak demektir — Kanban'da en sinir bozucu hata
 * türü, çünkü kimse bir şeyin kaybolduğunu fark etmez.
 *
 * Bu yüzden `deals.version` her taşımada bir artar ve istemci elindeki
 * `version` ile gelir. Uyuşmazsa istek REDDEDİLİR.
 *
 * -----------------------------------------------------------------------------
 * NEDEN HttpResponseException TÜREVİ
 * -----------------------------------------------------------------------------
 * Hata zarfı merkezîdir (`bootstrap/app.php` → `withExceptions`) ve o dosya
 * Faz 7'de iki paralel şeridin ORTAK dosyasıdır; oraya bir `if` daha eklemek
 * çakışma üretir. Merkezî handler'ın ilk kuralı zaten şu:
 *
 *     if ($e instanceof HttpResponseException) { return null; }
 *     // "bu istisna kendi yanıtını taşıyor, dokunma"
 *
 * Yani `HttpResponseException`'dan türemek, merkezî yapıya HİÇ DOKUNMADAN
 * doğru zarfı üretmenin resmî yoludur. Alternatif olan `render()` metodu
 * tanımlamak da çalışırdı, ancak o yol istisnanın önce Laravel'in genel
 * raporlama hattından geçmesini gerektirir; buradaki durum bir "sunucu hatası"
 * değil, beklenen ve sık görülen bir iş sonucudur — log'a düşmesinin bir değeri
 * yoktur. Taşınan hazır yanıt, raporlamayı da kısa devre yapar.
 *
 * -----------------------------------------------------------------------------
 * YANITTA KARTIN GÜNCEL HÂLİ NEDEN VAR
 * -----------------------------------------------------------------------------
 * Çakışan istemcinin ihtiyacı "hata" değil, DOĞRU DURUMDUR. Yanıt kartın taze
 * hâlini (`version` dahil) taşıdığı için SPA ek bir GET atmadan kartı yerine
 * oturtabilir ve kullanıcıya "bu kart X tarafından taşındı" diyebilir.
 * Yeniden fetch etmeye zorlamak, çakışmanın en olası anında (herkes aynı panoda
 * çalışırken) gereksiz bir istek dalgası üretirdi.
 *
 * Kart, panonun kendisiyle ve 200 yanıtıyla AYNI gösterimi kullanır
 * (App\Http\Resources\DealCardResource) — istemci çakışmayı ayrı bir kod yolu
 * olarak değil, "kartın güncel hâlini yerine oturt" olarak işler.
 *
 * Çakışan istemcinin en çok ihtiyaç duyduğu bilgi — kart artık HANGİ SÜTUNDA? —
 * kartın kendi `pipeline_stage_id` alanından okunur. Zarf o alanı AYRICA
 * tekrarlamaz: aynı bilginin iki yerde durması, biri güncellenip diğeri
 * unutulduğunda sessiz bir tutarsızlık üretir.
 *
 * -----------------------------------------------------------------------------
 * FAZ 14 / İZ D — `__()` NEDEN CONSTRUCTOR'DA (OKUMA ANINDA DEĞİL)
 * -----------------------------------------------------------------------------
 * Bu istisna, bildirimlerin aksine, HİÇBİR YERDE saklanmaz — kurulduğu anda
 * `response()->json()` gövdesine gömülür ve saniyeler içinde tüketilir. Bu yüzden
 * `NotificationText`teki "render ANINDA değil OKUMA anında çöz" disiplini burada
 * uygulanmaz: kurulma anı zaten tek ve gerçek okuma anıdır — `SetLocale`
 * middleware'i bu noktaya kadar isteğin locale'ini çoktan ayarlamıştır.
 */
class DealVersionConflictException extends HttpResponseException
{
    /**
     * @param  array<string, mixed>  $current  DealCardResource::resolve() çıktısı
     */
    public function __construct(
        public readonly array $current,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(response()->json([
            'errors' => [
                'message' => __('errors.deal_version_conflict.message'),
                'code' => 'DEAL_VERSION_CONFLICT',
            ],
            // Zarfın dışında, `errors` ile kardeş: `errors.fields` doğrulama
            // hatalarına ayrılmış bir sözleşmedir, kart verisi oraya sığmaz.
            'deal' => $this->current,
        ], Response::HTTP_CONFLICT));
    }
}
