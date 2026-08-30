<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * İstek başına dil çözümü — Faz 14 / İz D (docs/PHASE-INTL.md §1.4).
 *
 * ÖNCELİK SIRASI (§1.3'ün otorite kararı):
 *   1. `users.locale`     — kişisel tercih. OTORİTE budur.
 *   2. `Accept-Language`  — yalnız anonim/pre-login yanıtlar için (login hatası, şifre
 *                           sıfırlama talebi). Oturum varken tarayıcı başlığı KULLANILMAZ:
 *                           kullanıcı arayüz dilini bilinçli seçtiyse, tarayıcısının dili
 *                           onu ezmemeli.
 *   3. `syncra.i18n.default_locale` — uygulama varsayılanı (`tr`).
 *
 * ---------------------------------------------------------------------------
 * NEDEN `api` GRUBUNUN SONUNA EKLENİYOR (global `append`/`prepend` DEĞİL)
 * ---------------------------------------------------------------------------
 * Bu middleware `$request->user()`'ı okumak zorunda; kullanıcı ise oturum (Sanctum SPA
 * cookie modu) çözülmeden bilinemez. Global yığın, `api` grubundan ÖNCE çalışır — orada
 * `StartSession` henüz koşmamıştır ve `$request->user()` her zaman `null` döner, yani
 * kural 1 hiç devreye girmezdi.
 *
 * `api` grubunun EN SONUNA eklendiğinde `statefulApi()`'nin prepend ettiği
 * `EnsureFrontendRequestsAreStateful` çoktan çalışmış, oturum başlatılmıştır; bu noktada
 * `$request->user()` varsayılan (`web`) guard üzerinden oturumdaki kullanıcıyı çözer.
 * Rota middleware'i olan `auth:sanctum` bundan SONRA çalışır ve yalnızca ERİŞİMİ
 * doğrular — kimliği burada okumamıza engel değildir.
 *
 * BUNUN BİLİNÇLİ SONUCU: `auth:sanctum`'ın FIRLATTIĞI istisnalar (401) bu middleware
 * çalıştıktan sonra oluşur, yani onlar da doğru dilde render edilir. Buna karşılık rota
 * bulunamayan 404 gibi `api` grubuna hiç girmeyen yanıtlar uygulama varsayılanında kalır —
 * kabul edilebilir, çünkü orada dilini bildiğimiz bir muhatap yok.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);

        $response = $next($request);

        /*
         * Yanıtın hangi dilde üretildiğini AÇIKÇA bildirir: istemci önbellekleri ve ara
         * katmanlar aynı URL'nin dile göre farklı gövde döndürebileceğini bilmeli.
         *
         * BİLİNEN SINIR (ölçüldü): `Illuminate\Routing\Pipeline`, bir pipe'ın fırlattığı
         * istisnayı BİR DIŞTAKİ pipe'ın try/catch'inde yakalar; yani `$next($request)`
         * fırlatırsa bu metodun geri kalanı çalışmaz. Bu middleware kimlik/yetki
         * katmanlarının DIŞINDA durduğu için 401/403/404 gövdeleri yine başlığı alır, ama
         * SetLocale'den daha DIŞTA doğan yanıtlar (rota hiç eşleşmeyen 404, CORS preflight,
         * bakım modu) almaz. Zararsızdır: oralarda dilini bildiğimiz bir muhatap zaten yok
         * ve `Content-Language` bilgilendiricidir — gövdenin dilini `App::setLocale()`
         * belirler, bu başlık değil.
         */
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function resolve(Request $request): string
    {
        /** @var array<int, string> $supported */
        $supported = (array) config('syncra.i18n.supported_locales', ['tr']);
        $default = (string) config('syncra.i18n.default_locale', 'tr');

        $user = $request->user();
        if ($user !== null && in_array($user->locale, $supported, true)) {
            return (string) $user->locale;
        }

        $negotiated = $this->fromAcceptLanguage($request, $supported);
        if ($negotiated !== null) {
            return $negotiated;
        }

        return in_array($default, $supported, true) ? $default : (string) ($supported[0] ?? 'tr');
    }

    /**
     * `Accept-Language` pazarlığı.
     *
     * `getPreferredLanguage()` eşleşme bulamazsa listenin İLK öğesini döndürür (Symfony
     * sözleşmesi) — bu, "başlık hiç yoktu" ile "başlık vardı ama hiçbiri desteklenmiyor"u
     * ayırt edilemez kılardı. Bu yüzden başlığın varlığı ayrıca kontrol edilir ve
     * eşleşme yoksa `null` dönülerek karar bir sonraki basamağa (uygulama varsayılanı)
     * bırakılır.
     *
     * @param  array<int, string>  $supported
     */
    private function fromAcceptLanguage(Request $request, array $supported): ?string
    {
        if ($supported === [] || ! $request->hasHeader('Accept-Language')) {
            return null;
        }

        $preferred = $request->getPreferredLanguage($supported);

        if (! is_string($preferred) || ! in_array($preferred, $supported, true)) {
            return null;
        }

        // Symfony'nin "hiç eşleşme yoksa ilk öğe" davranışını, istemcinin gerçekten o dili
        // istediğini doğrulayarak eleriz.
        $accepted = array_map(
            static fn (string $language): string => strtolower(substr($language, 0, 2)),
            $request->getLanguages(),
        );

        return in_array(strtolower(substr($preferred, 0, 2)), $accepted, true) ? $preferred : null;
    }
}
