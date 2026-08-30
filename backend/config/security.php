<?php

/*
|--------------------------------------------------------------------------
| Güvenlik Başlıkları (Faz 13 — H1)
|--------------------------------------------------------------------------
|
| App\Http\Middleware\SecurityHeaders bu dosyadan okur; hiçbir değer
| middleware içinde hard-code edilmez. Sebep: bu başlıkların doğru değeri
| DAĞITIM ORTAMINA bağlıdır (HTTP/HTTPS, SPA'yı kimin servis ettiği, Reverb
| hostu) — kod değil konfigürasyon meselesidir.
|
| TASARIM KARARI — VARSAYILAN GÜVENLİ (fail-safe):
| Buradaki varsayılanlar EN KATI hâlleridir. Gevşetme yalnızca ilgili
| SECURITY_* ortam değişkeni bilinçle ayarlanarak yapılır; hiçbir gevşetme
| "unutulmuş varsayılan" olarak sızmaz.
|
| MİMARİ NOT — bu başlıklar NEYİ korur?
| Laravel bu projede SPA'yı SERVİS ETMEZ (geliştirmede Vite :5173, üretimde
| statik build). Laravel'in ürettiği yanıtlar pratikte üç sınıftır:
|
|   1) JSON (API'nin %99'u)  — bir DOKÜMAN değildir; alt kaynak yüklemez,
|      script çalıştırmaz. Burada CSP'nin script/style direktifleri ÖLÜ
|      HARFTİR. Gerçek değer taşıyanlar: `frame-ancestors` (her yanıt
|      çerçevelenebilir — clickjacking/cross-site leak), `X-Frame-Options`
|      (aynısının eski tarayıcı karşılığı) ve `X-Content-Type-Options`
|      (yanıtın HTML sanılıp çalıştırılmasını engeller).
|   2) İkili/dosya (PDF, ek indirme) — aynı şekilde doküman değildir;
|      `nosniff` burada EN YÜKSEK değeri taşır: kullanıcı yüklemesi,
|      DB'deki doğrulanmış MIME ile servis edilir ve tarayıcının içeriğe
|      bakıp HTML'e "terfi ettirmesi" bu başlıkla engellenir.
|   3) HTML (yalnızca `/` welcome sayfası, `/up` sağlık ucu ve JSON
|      beklemeyen bir isteğe dönen hata sayfaları) — CSP'nin script/style
|      direktiflerinin ANLAM KAZANDIĞI tek sınıf.
|
| Bu yüzden CSP tek bir dize değil, İKİ PROFİLDİR (`api` ve `html`);
| middleware yanıtın Content-Type'ına bakıp seçer. Tek bir "SPA CSP'si"
| yazmak, JSON yanıtlarına hiçbir şey yapmayan bir dize iliştirip
| "CSP var" demek olurdu.
|
*/

/*
 * Reverb WebSocket origin'i — CSP `connect-src` için.
 *
 * CSP kaynak ifadesi ŞEMA + HOST + PORT'un tamamını eşler: `'self'`
 * ws://localhost:8080'i KAPSAMAZ (farklı şema ve port). Bu yüzden açıkça
 * yazılır, aksi hâlde `html` profili altında realtime tamamen kırılır.
 *
 * DİKKAT: buradaki host, tarayıcının gerçekten bağlandığı hostla birebir
 * aynı olmalı — yani frontend'in VITE_REVERB_HOST/VITE_REVERB_PORT değeriyle.
 * `localhost` ile `127.0.0.1` CSP açısından FARKLI kaynaklardır.
 */
$reverbScheme = env('REVERB_SCHEME', 'http') === 'https' ? 'wss' : 'ws';
$reverbOrigin = $reverbScheme.'://'.env('REVERB_HOST', 'localhost').':'.env('REVERB_PORT', 8080);

/*
 * `frame-ancestors` tek yerde tanımlanır ve HER İKİ profile de girer:
 * clickjacking koruması yanıtın JSON mu HTML mi olduğuna bağlı değildir.
 *
 * BİLİNEN ETKİ (raporlandı): `'none'` iken teklif PDF ÖNİZLEME iframe'i
 * (frontend QuoteDetailPage — `<iframe src=".../api/quotes/{id}/pdf">`)
 * yüklenmez; ekrandaki "PDF İndir / yeni sekmede aç" bağlantısı çalışmaya
 * devam eder. Kalıcı çözüm frontend tarafında PDF'i blob URL olarak almaktır
 * (bu iş kaleminin dosya sahipliği dışında). Ara çözüm gerekirse:
 * SECURITY_FRAME_ANCESTORS="'self' http://localhost:5173" — bu durumda
 * SECURITY_FRAME_OPTIONS de SAMEORIGIN'e çekilmeli ya da boşaltılmalı,
 * çünkü X-Frame-Options: DENY'in "izin verilen origin" karşılığı YOKTUR
 * (ALLOW-FROM ölü bir direktiftir).
 *
 * Gizli-iframe ile tetiklenen CSV/XLSX export ve lead şablon indirmeleri
 * ETKİLENMEZ: `Content-Disposition: attachment` yanıtı tarayıcıda bir
 * doküman olarak COMMIT EDİLMEZ, indirmeye dönüşür ve çerçeve atası
 * denetiminden geçmez.
 */
$frameAncestors = array_values(array_filter(explode(' ', trim((string) env('SECURITY_FRAME_ANCESTORS', "'none'")))));

return [

    /*
    |--------------------------------------------------------------------------
    | X-Frame-Options
    |--------------------------------------------------------------------------
    |
    | CSP `frame-ancestors`'ın eski tarayıcı karşılığı. Modern tarayıcılar
    | ikisi birden geldiğinde CSP'yi dikkate alıp bunu YOK SAYAR; burada
    | yalnızca `frame-ancestors` desteklemeyen istemciler için durur.
    | Boş bırakılırsa başlık hiç gönderilmez.
    |
    */
    'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),

    /*
    |--------------------------------------------------------------------------
    | X-Content-Type-Options
    |--------------------------------------------------------------------------
    |
    | Bu projedeki en yüksek gerçek değerli başlık: kullanıcı tarafından
    | yüklenen dosyalar (chat ekleri) ve PDF, tarayıcının içerik tahminiyle
    | HTML'e terfi edip uygulama origin'inde çalışamaz. Kapatılabilir bir
    | değeri yok — sabit.
    |
    */
    'content_type_options' => 'nosniff',

    /*
    |--------------------------------------------------------------------------
    | Referrer-Policy
    |--------------------------------------------------------------------------
    |
    | Seçim: `strict-origin-when-cross-origin`.
    |
    | Neden `no-referrer` DEĞİL: Sanctum'un SPA (cookie) modu bir isteğin
    | "stateful" olup olmadığına Origin/Referer başlığına bakarak karar verir
    | (EnsureFrontendRequestsAreStateful). Referrer'ı tamamen kapatmak, ileride
    | SPA Laravel tarafından servis edilirse (aynı origin) oturum tespitinde
    | kırılgan bir bağımlılığı zayıflatır. Kazancı ise bu kapalı devre kurulumda
    | ~sıfırdır: dışarı referrer sızdırılacak üçüncü taraf yok.
    |
    | Neden bu değer YETERLİ: origin'ler arası isteklerde YALNIZCA origin
    | gönderilir — `/api/quotes/17/pdf` gibi kayıt kimliği taşıyan yollar
    | dışarı sızmaz; HTTPS -> HTTP düşüşünde hiçbir şey gönderilmez.
    |
    */
    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    /*
    |--------------------------------------------------------------------------
    | Permissions-Policy
    |--------------------------------------------------------------------------
    |
    | JSON yanıtlarında değeri YOKTUR (doküman değil). Yine de gönderiliyor:
    | maliyeti tek satır, ve HTML yüzeyinde (welcome/hata sayfası, ileride
    | Laravel'in servis edeceği bir SPA build'i) uygulamanın hiç kullanmadığı
    | cihaz yeteneklerini kapatır — bir HTML enjeksiyonu gerçekleşirse
    | kamera/mikrofon/konum o dokümandan istenemez.
    |
    | Liste "kullanmadığımız her şey kapalı" mantığındadır; `fullscreen` gibi
    | varsayılanı zaten `self` olan yetenekler listeye alınmaz. Boş bırakılırsa
    | başlık gönderilmez.
    |
    */
    'permissions_policy' => env(
        'SECURITY_PERMISSIONS_POLICY',
        'accelerometer=(), autoplay=(), camera=(), display-capture=(), '.
        'encrypted-media=(), geolocation=(), gyroscope=(), magnetometer=(), '.
        'microphone=(), midi=(), payment=(), usb=(), xr-spatial-tracking=()'
    ),

    /*
    |--------------------------------------------------------------------------
    | Strict-Transport-Security (HSTS)
    |--------------------------------------------------------------------------
    |
    | Middleware bu başlığı YALNIZCA $request->secure() iken gönderir
    | (gerekçe orada). Buradaki değerler o durumda kullanılacak parametrelerdir.
    |
    | `preload` bilinçle KAPALI: preload listesi tarayıcılara gömülü PUBLIC bir
    | listedir, geri alınması aylar sürer ve kapalı devre/tek makine bir kurulum
    | için hiçbir kazancı yoktur.
    |
    */
    'hsts' => [
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000), // 1 yıl
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | Direktif => kaynak listesi. Boş liste, değersiz direktif demektir
    | (ör. `upgrade-insecure-requests`). Middleware bunu tek dizeye derler.
    |
    */
    'csp' => [

        /*
         * `api` — JSON, PDF ve dosya yanıtları (yani neredeyse her yanıt).
         *
         * `default-src 'none'` bu sınıfta BEDAVA'dır: bu yanıtlar zaten hiçbir
         * alt kaynak yüklemez. Değeri savunma derinliğidir — bir uç bir gün
         * yanlışlıkla HTML üretirse (veya bir istemci yanıtı doküman olarak
         * yorumlarsa) o dokümanda hiçbir script/stil/görsel çalışmaz.
         *
         * `sandbox` direktifi BİLİNÇLE YOK: değersiz `sandbox` indirmeleri de
         * (allow-downloads kapalı) engeller — CSV/XLSX export ve ek indirme
         * hattını kırardı.
         */
        'api' => [
            'default-src' => ["'none'"],
            // Enjekte edilmiş bir <base> ile göreli URL'lerin kaçırılmasını
            // engeller; API yanıtının base'e ihtiyacı yoktur.
            'base-uri' => ["'none'"],
            // Bu yanıtlardan gönderilecek hiçbir form yok.
            'form-action' => ["'none'"],
            'frame-ancestors' => $frameAncestors,
        ],

        /*
         * `media` — tarayıcının DOKÜMAN OLARAK GÖSTERDİĞİ ama HTML olmayan
         * yanıtlar: PDF ve raster görseller (bkz. SecurityHeaders'daki tip
         * listesi).
         *
         * NEDEN AYRI BİR PROFİL — somut tarayıcı davranışı:
         * Chrome bir PDF'i (veya doğrudan açılan bir görseli) gösterirken
         * içeriği SENTETİK bir HTML sayfasına gömer (<embed>) ve o sayfaya
         * INLINE stil enjekte eder. O sayfa, PDF yanıtının CSP'siyle yönetilir;
         * dolayısıyla `default-src 'none'` görüntüleyiciyi kırar (boş/bozuk
         * sayfa). Bu, teklif PDF'i "yeni sekmede aç" akışını ve `?inline=1`
         * ek önizlemesini doğrudan bozardı.
         *
         * Bu yüzden burada FETCH direktifi HİÇ YOKTUR — yalnız gezinme/
         * çerçeveleme muhafızları durur. Güvenlik kaybı pratikte sıfır: PDF
         * sunucuda tamamı escape'li Blade'den (A5.5 SAFE) üretilir, sayfa
         * seviyesinde JS çalıştırmaz, ve asıl koruma olan `nosniff` ile
         * `frame-ancestors` yerinde kalır.
         */
        'media' => [
            'base-uri' => ["'none'"],
            'form-action' => ["'none'"],
            'frame-ancestors' => $frameAncestors,
        ],

        /*
         * `html` — Content-Type'ı text/html olan yanıtlar.
         *
         * Bugün bu yalnızca `/` welcome sayfası, `/up` ve JSON beklemeyen
         * isteklere dönen hata sayfalarıdır; ÜRÜNÜN parçası değildirler.
         * Politika yine de eksiksiz yazıldı, çünkü tek gerçekçi genişleme
         * yönü budur: üretimde statik SPA build'inin Laravel tarafından
         * servis edilmesi. O gün politikayı sıfırdan yazmak yerine burada
         * hazır ve gerekçeli durur.
         *
         * BİLİNEN YAN ETKİ: Laravel'in kendi welcome/hata sayfaları inline
         * <style> bloğu kullanır; `style-src 'self'` bunları engeller, o
         * sayfalar STİLSİZ görünür. Kabul edildi — bu sayfalar ürün yüzeyi
         * değildir ve `'unsafe-inline'`ı bir geliştirici sayfası için açmak
         * ters takas olurdu.
         */
        'html' => [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => $frameAncestors,
            'form-action' => ["'self'"],

            // `'unsafe-eval'` YOK: ne Vite build çıktısı ne Recharts ne de
            // Tailwind v4 runtime'da eval/new Function gerektirir.
            'script-src' => ["'self'"],

            /*
             * STİL — CSP'de İKİ AYRI YÜZEY:
             *
             *   style-src-elem : <style> blokları ve <link rel=stylesheet>.
             *                    Vite build'i Tailwind v4'ü GERÇEK bir .css
             *                    dosyasına derler; `'self'` yeter.
             *   style-src-attr : inline `style="..."` ÖZNİTELİĞİ. Recharts
             *                    (ve genel olarak React `style={{...}}`)
             *                    öznitelikleri runtime'da ÜRETİR; değerleri
             *                    dinamik olduğu için hash/nonce mümkün
             *                    değildir — burada `'unsafe-inline'`
             *                    kaçınılmazdır.
             *
             * `style-src` ikisini birden kapsayan geri düşüş değeridir; bunu
             * `'self'` bırakıp SADECE öznitelik yüzeyini gevşetiyoruz, yani
             * `'unsafe-inline'` <style> bloklarına ASLA uygulanmaz.
             *
             * Risk değerlendirmesi: inline style ÖZNİTELİĞİ modern
             * tarayıcılarda JS çalıştıramaz (expression() öldü, `javascript:`
             * CSS'te geçmez); geriye kalan tehdit CSS ile veri sızdırmadır ve
             * kapalı devrede `default-src 'self'` zaten dış hedefi kapatır.
             */
            'style-src' => ["'self'"],
            'style-src-attr' => ["'unsafe-inline'"],

            // Fontlar self-host (@fontsource/poppins) — hiçbir dış CDN AÇILMAZ.
            'font-src' => ["'self'"],

            // data: — ikonlar/inline SVG; blob: — ek önizlemesi ve indirme
            // için üretilen nesne URL'leri.
            'img-src' => ["'self'", 'data:', 'blob:'],

            // 'self' Reverb'i KAPSAMAZ (farklı şema+port) — bkz. yukarısı.
            'connect-src' => ["'self'", $reverbOrigin],

            // Teklif PDF önizlemesi ve gizli export iframe'leri aynı origin'den
            // yüklenir; dış çerçeve yok.
            'frame-src' => ["'self'"],

            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
        ],

    ],

];
