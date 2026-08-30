<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every API response uses one error envelope:
 *
 *   { "errors": { "message": "...", "code": "...", "fields": { ... } } }
 *
 * `fields` is only present for validation failures.
 */
$apiError = function (string $message, string $code, int $status, array $fields = [], array $headers = []) {
    $payload = ['message' => $message, 'code' => $code];

    if ($fields !== []) {
        $payload['fields'] = $fields;
    }

    return response()->json(['errors' => $payload], $status, $headers);
};

/**
 * Fallback code for HTTP statuses that have no dedicated contract entry.
 *
 * @var array<int, string>
 */
$statusCodes = [
    400 => 'BAD_REQUEST',
    401 => 'UNAUTHENTICATED',
    403 => 'FORBIDDEN',
    404 => 'NOT_FOUND',
    405 => 'METHOD_NOT_ALLOWED',
    409 => 'CONFLICT',
    419 => 'CSRF_TOKEN_MISMATCH',
    422 => 'VALIDATION_ERROR',
    429 => 'TOO_MANY_ATTEMPTS',
    503 => 'SERVICE_UNAVAILABLE',
];

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * Channel authorization endpoint: GET|POST /broadcasting/auth
     *
     * Registered explicitly instead of through withRouting(channels: ...),
     * because that helper falls back to Broadcast::routes() with the `web`
     * middleware group ONLY - no auth guard, no active check. What this route
     * decides is who may listen on a channel, so it gets the same gate as the
     * REST surface:
     *
     *   web          the session cookie stack. Sanctum SPA mode authenticates
     *                from the session, so the cookie is the credential here
     *                too. Broadcast::routes() already exempts this route from
     *                VerifyCsrfToken, which is what lets Echo POST to it.
     *   auth:sanctum anonymous callers get 401 UNAUTHENTICATED instead of the
     *                bare 403 a channel callback would produce; the SPA needs
     *                to tell "logged out" from "not allowed on this channel".
     *   active       an account deactivated mid-session cannot open NEW
     *                subscriptions. Sockets already open are torn down
     *                separately by UserDeactivated on private-user.{id}.
     *
     * DELIBERATELY ABSENT: `password.changed`. A user under a forced password
     * change still needs a live socket - that is precisely the session in
     * which UserDeactivated has to reach them, and the change-password screen
     * shows connection state. The trade-off is safe because no channel
     * callback grants data beyond identity plus the module permissions the
     * user already holds; the password gate protects the REST endpoints that
     * actually return records, and those stay behind `password.changed` in
     * routes/api.php.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth:sanctum', 'active']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Güvenlik başlıkları (Faz 13 / H1) — GLOBAL ve EN DIŞTA.
         *
         * prepend(), append() DEĞİL: bu middleware yığının en dışında durunca
         * içeride üretilen HER yanıt ona geri döner - rota bulunamayan 404,
         * HandleCors'un kısa devre yaptığı preflight, bakım modu, throttle 429
         * ve exception'dan render edilen 5xx dahil. append() edilseydi bu
         * yolların bir kısmı başlıksız kalırdı; "her yanıtta" kabul kriteri
         * (PHASE-AUDIT §6) beyaz listeyle karşılanamaz.
         *
         * TrustProxies'ten önce çalışması sorun değil: HSTS kararı $next()
         * DÖNDÜKTEN sonra, yani TrustProxies zaten isteği işaretledikten sonra
         * verilir (bkz. SecurityHeaders).
         */
        $middleware->prepend(SecurityHeaders::class);

        // Sanctum SPA cookie mode: requests whose Origin/Referer matches
        // config('sanctum.stateful') get the full session stack (cookies,
        // StartSession, CSRF, AuthenticateSession) on the `api` group.
        // No API tokens are used anywhere - User does not use HasApiTokens.
        $middleware->statefulApi();

        /*
         * Dil çözümü (Faz 14 / İz D) — `api` grubunun SONUNA.
         *
         * `prepend()`/global yığın DEĞİL: SetLocale `$request->user()`'ı okur ve oturum ancak
         * `statefulApi()`'nin prepend ettiği EnsureFrontendRequestsAreStateful çalıştıktan
         * sonra vardır; global yığın ise o grubun tamamından ÖNCE koşar ve orada kullanıcı
         * her zaman `null` olurdu. Grup içindeki NİHAİ sırayı ise aşağıdaki öncelik kaydı
         * belirler. Ayrıntılı gerekçe: SetLocale.php.
         *
         * SecurityHeaders'ın GLOBAL prepend'i (H1) bundan etkilenmez: o, yığının en
         * dışında kalmaya devam eder ve SetLocale'in ürettiği yanıtlar da ona geri döner.
         */
        $middleware->appendToGroup('api', SetLocale::class);

        /*
         * ...VE ÖNCELİK LİSTESİNDE KİMLİK DOĞRULAMANIN HEMEN ÖNÜNE (ölçülerek bulundu).
         *
         * Yalnız gruba eklemek YETMİYOR: `Router::gatherRouteMiddleware()` grup + rota
         * middleware'ini birleştirdikten sonra `SortedMiddleware` ile ÖNCELİK listesine göre
         * yeniden sıralar. `auth:sanctum` o listede olduğu için, listede OLMAYAN SetLocale'in
         * ÖNÜNE geçiyordu. Ölçülen sonuç: kimliği doğrulanmış isteklerde dil doğru, ama ANONİM
         * isteklerde `auth` istisnayı SetLocale hiç çalışmadan fırlatıyor ve 401 gövdesi
         * uygulama varsayılanında kalıyordu — yani `Accept-Language`in tek işe yaradığı yerde
         * (§1.3: pre-login yanıtlar) çalışmıyordu.
         *
         * ÇAPA `Illuminate\Auth\Middleware\Authenticate` DEĞİL, ONUN SÖZLEŞMESİ: Laravel 12'nin
         * varsayılan öncelik listesi somut sınıfı değil `Contracts\Auth\Middleware\
         * AuthenticatesRequests` arayüzünü taşır (listenin kendisi dökülerek doğrulandı).
         * Somut sınıfla çapalamak sessizce başarısız olur — `addToMiddlewarePriorityRelative`
         * bulamadığı çapada middleware'i listenin SONUNA ekler, ki bu hiçbir şeyi düzeltmez.
         *
         * Kimlik doğrulamanın ÖNÜNE koymak iki ihtiyacı birden karşılar: `Authenticate`
         * çalışmadan da `$request->user()` oturumdaki kullanıcıyı çözer (o middleware kimliği
         * OKUMAZ, ERİŞİMİ doğrular) — oturum, listenin başındaki
         * `EnsureFrontendRequestsAreStateful` sayesinde zaten açıktır; anonim istekte ise dil,
         * istisna fırlamadan ÖNCE ayarlanmış olur.
         */
        $middleware->prependToPriorityList(AuthenticatesRequests::class, SetLocale::class);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError, $statusCodes) {
        $isApi = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e): bool => $isApi($request));

        $exceptions->render(function (Throwable $e, Request $request) use ($apiError, $statusCodes, $isApi) {
            if (! $isApi($request)) {
                return null;
            }

            // Already carries a fully formed response (e.g. the deactivated
            // account payload, or a rate limiter response callback).
            if ($e instanceof HttpResponseException) {
                return null;
            }

            if ($e instanceof ValidationException) {
                $fields = $e->errors();

                return $apiError(
                    (string) (Arr::first(Arr::flatten($fields)) ?: __('errors.validation_failed')),
                    'VALIDATION_ERROR',
                    422,
                    $fields,
                );
            }

            /*
             * Faz 14 / İz D: sabit Türkçe cümleler `lang/{tr,en,de,fr}/errors.php`'ye
             * taşındı ve dil `SetLocale` middleware'inden gelir — kimlik/yetki/bulunamadı
             * üçlüsü, aşağıdaki `match` bloğu (405/419/429/genel) ve 5xx metni dahil TAMAMI.
             */
            if ($e instanceof AuthenticationException) {
                return $apiError(__('errors.unauthenticated'), 'UNAUTHENTICATED', 401);
            }

            if ($e instanceof AccessDeniedHttpException) {
                return $apiError(__('errors.forbidden'), 'FORBIDDEN', 403);
            }

            if ($e instanceof NotFoundHttpException) {
                return $apiError(__('errors.not_found'), 'NOT_FOUND', 404);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = $statusCodes[$status] ?? 'HTTP_ERROR';

                $message = match ($status) {
                    403 => __('errors.forbidden'),
                    404 => __('errors.not_found'),
                    405 => __('errors.method_not_allowed'),
                    419 => __('errors.session_expired'),
                    429 => __('errors.too_many_attempts'),
                    default => $e->getMessage() !== '' && $status < 500
                        ? $e->getMessage()
                        : __('errors.request_failed'),
                };

                // Preserves Retry-After (and the X-RateLimit-* headers) that the
                // throttle middleware attached to the exception.
                return $apiError($message, $code, $status, [], $e->getHeaders());
            }

            // Anything unexpected: never leak the message, stack trace, SQL or
            // file paths to the client when debug mode is off.
            $payload = [
                'message' => __('errors.server_error'),
                'code' => 'SERVER_ERROR',
            ];

            if (config('app.debug')) {
                $payload['debug'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];
            }

            return response()->json(['errors' => $payload], 500);
        });
    })->create();
