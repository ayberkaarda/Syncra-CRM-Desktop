<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeviceTokenRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\DeviceTokenService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/auth/device` — the desktop client's credential exchange
 * (SYNCDESKTOP §4.3).
 *
 * Public, exactly like POST /api/login: it is the endpoint that CREATES a
 * credential, so requiring one would be circular. It is not unprotected -
 * DeviceTokenService counts every failure against the same keyed lockout the
 * web login uses (protocol §3.5).
 *
 * Thin controller: request validation + service delegation + response shape.
 */
class DeviceTokenController extends Controller
{
    public function __construct(private readonly DeviceTokenService $devices) {}

    public function store(DeviceTokenRequest $request): JsonResponse
    {
        $result = $this->devices->issue($request->validated(), $request);

        return response()->json([
            // The plain-text token exists exactly once, in this response. It is
            // never readable again - `personal_access_tokens.token` holds a
            // sha256 hash - so the client stores it in the OS keychain (K9) on
            // receipt or loses it.
            'token' => $result['token']->plainTextToken,
            'token_id' => $result['token']->accessToken->getKey(),
            // The same payload GET /api/me returns, so the desktop app has a
            // hydrated session the moment login succeeds and does not need a
            // second round trip before it can render.
            'user' => UserResource::make($result['user'])->resolve($request),
            'must_change_password' => (bool) $result['user']->must_change_password,
            'abilities' => [DeviceTokenService::ABILITY],
        ], 200);
    }
}
