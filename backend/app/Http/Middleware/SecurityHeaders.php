<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tarayıcı taraflı güvenlik başlıkları — HER yanıta (Faz 13 / H1).
 *
 * Tüm değerler config/security.php'den okunur; burada politika DEĞİL, politikayı
 * yanıta uygulama KURALLARI vardır. Neyin nerede gerçekten koruduğu o dosyanın
 * başındaki mimari notta tartışılır — özeti: bu API'nin yanıtlarının çoğu bir
 * DOKÜMAN değildir, dolayısıyla gerçek değeri taşıyanlar `frame-ancestors` /
 * `X-Frame-Options` (çerçeveleme) ve `X-Content-Type-Options` (içerik tahmini)
 * başlıklarıdır; CSP'nin script/style direktifleri yalnız HTML yüzeyinde anlam
 * kazanır.
 *
 * GLOBAL ve EN DIŞTA kayıtlı (bootstrap/app.php -> prepend). Üç sonucu var:
 *   - Yanıtı üreten yol ne olursa olsun başlıklar eklenir: rota bulunamayan
 *     404, CORS preflight, bakım modu, throttle 429 ve exception'dan üretilen
 *     5xx dahil. (Laravel'in middleware pipeline'ı exception'ı yakalayıp
 *     RENDER EDİLMİŞ yanıtı dış katmanlara geri verir; bu yüzden hata yanıtları
 *     da buradan geçer.)
 *   - Kabul kriteri "her yanıtta" der; beyaz liste tutmak fail-open olurdu.
 *   - `secure()` okuması $next() SONRASINDA yapılır, böylece TrustProxies
 *     (kendisi de global) zaten çalışmış olur ve ters proxy arkasındaki
 *     X-Forwarded-Proto doğru değerlendirilir.
 *
 * Var olan bir başlığın ÜZERİNE YAZILMAZ: bir uç kendi politikasını bilerek
 * belirtmişse (bugünkü tek örnek AttachmentController'ın kendi `nosniff`'i —
 * aynı değeri koyar) o karar korunur. Aksi hâlde bu middleware, daha sıkı bir
 * uç politikasını sessizce gevşetebilirdi.
 */
class SecurityHeaders
{
    /**
     * Tarayıcının HTML OLMADAN doküman gibi GÖSTERDİĞİ içerik tipleri.
     *
     * Konfigürasyonda değil kodda: bu bir ortam tercihi değil, bir tarayıcı
     * davranışı gerçeğidir (bkz. config/security.php `csp.media` gerekçesi).
     * Ön ek olarak eşleşir; `image/` tüm raster tipleri kapsar.
     *
     * @var list<string>
     */
    private const MEDIA_TYPES = ['application/pdf', 'image/'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->put($response, 'X-Frame-Options', (string) config('security.frame_options', ''));
        $this->put($response, 'X-Content-Type-Options', (string) config('security.content_type_options', 'nosniff'));
        $this->put($response, 'Referrer-Policy', (string) config('security.referrer_policy', ''));
        $this->put($response, 'Permissions-Policy', (string) config('security.permissions_policy', ''));
        $this->put($response, 'Content-Security-Policy', $this->contentSecurityPolicy($response));
        $this->put($response, 'Strict-Transport-Security', $this->strictTransportSecurity($request));

        return $response;
    }

    /**
     * Yanıt tipine göre CSP profili.
     *
     * Content-Type güvenilir bir ayraçtır: Symfony, gönderilmeden önce
     * Response::prepare() içinde başlığı zaten kesinleştirir (belirtilmemişse
     * text/html'e düşer), bu yüzden burada okunduğunda set edilmiş olur. Tek
     * istisna gövdesiz yanıtlardır (204/304) — orada Content-Type kaldırılır ve
     * katı `api` profiline düşmek doğrudur: ortada yorumlanacak bir doküman yok.
     *
     * Sıralama fail-safe'tir: tanınmayan her tip KATI `api` profiline düşer,
     * gevşetme yalnız açıkça tanınan iki sınıfa (HTML dokümanı ve tarayıcının
     * gösterdiği medya) verilir.
     */
    private function contentSecurityPolicy(Response $response): string
    {
        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        $profile = match (true) {
            str_contains($contentType, 'text/html') => 'html',
            $this->isRenderedMedia($contentType) => 'media',
            default => 'api',
        };

        /** @var array<string, list<string>> $policy */
        $policy = (array) config("security.csp.{$profile}", []);

        $directives = [];

        foreach ($policy as $directive => $sources) {
            $sources = array_values(array_filter((array) $sources, static fn ($source) => (string) $source !== ''));

            // Kaynağı olmayan direktif atlanır: `frame-ancestors` gibi bir
            // direktifi DEĞERSİZ göndermek tarayıcıda tanımsız davranıştır;
            // `upgrade-insecure-requests` benzeri değersiz direktifler ise
            // config'de zaten boş liste olarak değil, ayrı bir anahtar olarak
            // durur (bugün yok).
            if ($sources === []) {
                continue;
            }

            $directives[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $directives);
    }

    private function isRenderedMedia(string $contentType): bool
    {
        foreach (self::MEDIA_TYPES as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * HSTS — YALNIZCA HTTPS istekte.
     *
     * Bu başlık düz HTTP üzerinde tarayıcı tarafından zaten yok sayılır, yani
     * koşulsuz göndermenin kazancı sıfırdır. Riski ise gerçektir: geliştirme
     * http://localhost:8000 üzerinde koşuyor ve HSTS bir HOST kaydıdır —
     * `localhost` bir kez kilitlenirse tarayıcı o hostun BÜTÜN portlarını
     * (5173 dahil) HTTPS'e zorlar ve kayıt yalnız chrome://net-internals'tan
     * elle temizlenir. Ortam değişkenine değil, isteğin gerçek şemasına
     * bakıyoruz: yanlış ayarlanmış bir bayrak bu hasarı veremesin.
     */
    private function strictTransportSecurity(Request $request): string
    {
        if (! $request->secure()) {
            return '';
        }

        $maxAge = (int) config('security.hsts.max_age', 0);

        if ($maxAge <= 0) {
            return '';
        }

        $value = 'max-age='.$maxAge;

        if (config('security.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        if (config('security.hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }

    /**
     * Boş değer = başlığı hiç gönderme (config'den kapatılabilsin diye).
     */
    private function put(Response $response, string $name, string $value): void
    {
        if ($value === '' || $response->headers->has($name)) {
            return;
        }

        $response->headers->set($name, $value);
    }
}
