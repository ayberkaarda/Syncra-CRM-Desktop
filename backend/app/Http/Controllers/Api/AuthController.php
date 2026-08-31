<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePreferencesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sanctum SPA (cookie session) authentication.
 *
 * The SPA calls GET /sanctum/csrf-cookie first, then posts here with the
 * X-XSRF-TOKEN header.
 *
 * UPDATED IN F1: API tokens DO exist now. User uses HasApiTokens, and the
 * desktop client obtains a personal access token from
 * App\Http\Controllers\Api\Auth\DeviceTokenController
 * (POST /api/auth/device, single ability `desktop`). This controller is still
 * cookie-only: none of its endpoints issue, read or revoke a token, and the
 * SPA's flow through them is unchanged.
 *
 * ONE EXCEPTION, and it is in the service rather than here: changePassword()
 * now deletes the caller's OTHER device tokens (AuthService::changePassword(),
 * protocol §3.6). A password change has to invalidate credentials on machines
 * the user is not holding.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * POST /api/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->login($request);

        return UserResource::make($user)->response()->setStatusCode(200);
    }

    /**
     * POST /api/logout
     */
    public function logout(Request $request): Response
    {
        $this->auth->logout($request);

        return response()->noContent();
    }

    /**
     * GET /api/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($user->loadMissing('roles'))->response();
    }

    /**
     * POST /api/password/change
     *
     * Exempt from the `password.changed` middleware for obvious reasons, but
     * still behind auth:sanctum + active + throttle:6,1 - the current_password
     * field is an in-session password oracle and must not be brute-forceable.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        // $request->user() (not a re-fetched model) so the guard's instance
        // carries the new hash - see AuthService::changePassword().
        $user = $this->auth->changePassword(
            $request->user(),
            (string) $request->input('password'),
            $request
        );

        return UserResource::make($user)->response()->setStatusCode(200);
    }

    /**
     * PATCH /api/me/preferences
     *
     * Kişisel arayüz tercihleri (`locale`, `preferred_currency`) — Faz 14 / İz D.
     *
     * NEDEN BURADA, `SettingController` ya da `UserController` DEĞİL: `/settings` uygulama
     * geneli ayardır ve `settings.manage` izni ister; `/users/{user}` yönetici işlemidir ve
     * `users.update` ister. Kendi dilini seçmek İZİN GEREKTİRMEZ — bu uç, kimlik ucuyla
     * (`/me`) aynı ailedendir: özne her zaman `$request->user()`'dır, gövdeden gelen bir
     * kullanıcı kimliği YOKTUR, dolayısıyla başkasının tercihini yazma yüzeyi de yoktur.
     *
     * Rota `password.changed` grubunun İÇİNDEDİR (muafiyet beyaz listesine EKLENMEDİ):
     * zorunlu şifre değişimi ekranında dil değiştirmek yine mümkündür — seçim localStorage'da
     * anında etkilidir, sunucuya yazma o adımın bitmesini bekler. Muafiyet listesi bilinçli
     * olarak dar tutulur (bkz. routes/api.php).
     *
     * Servis katmanı YOK (bilinçli): iş kuralı, doğrulanmış iki alanı yazmaktan ibarettir;
     * `AuthService`'e bir metod eklemek burada yalnızca dolaylılık üretirdi.
     */
    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill($request->validated())->save();

        return UserResource::make($user->loadMissing('roles'))->response();
    }

    /**
     * POST /api/password/forgot
     *
     * Closed loop: always 202, identical response for known and unknown
     * addresses so the endpoint cannot be used to enumerate accounts.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->recordPasswordResetRequest((string) $request->input('email'), $request);

        return response()->json([
            'message' => 'Talebiniz alındı. Sistem yöneticisi sizinle iletişime geçecek.',
        ], 202);
    }
}
