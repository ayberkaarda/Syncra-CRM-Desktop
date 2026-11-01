<?php

namespace App\Http\Controllers\Api\Me;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `GET /api/me/devices` and `DELETE /api/me/devices/{token}` —
 * the user's own device list (SYNCDESKTOP §4.3).
 *
 * OWNERSHIP IS THE WHOLE SECURITY MODEL HERE, and it is enforced by querying
 * through `$user->tokens()` rather than by fetching a token and comparing
 * afterwards. Somebody else's token id therefore produces 404, not 403 - the
 * same existence-hiding rule NotificationController applies to notification
 * uuids. There is no "admin revokes a device" surface: deactivating or
 * deleting a user already drops every one of their tokens
 * (UserService::toggleActive/delete).
 *
 * `is_current` lets the desktop app grey out its own row so a user cannot
 * accidentally sign the machine they are sitting at out of its own list.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $devices = $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            /*
             * Only device tokens. Nothing else mints tokens today, but the
             * filter keeps this list honest if something ever does - a device
             * manager that lists non-device credentials would invite the user
             * to revoke things they cannot re-create here.
             *
             * Filtered in PHP, not with whereJsonContains(): `abilities` is a
             * TEXT column that Sanctum casts to an array, and pushing the test
             * into SQL would make this list depend on the JSON support of
             * whichever engine the deployment runs. A user holds a handful of
             * tokens, so there is nothing to gain by it.
             */
            ->filter(fn (PersonalAccessToken $token): bool => $token->can(DeviceTokenService::ABILITY))
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->getKey(),
                'name' => (string) $token->name,
                'platform' => $token->device_platform,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'is_current' => $currentId !== null && $token->getKey() === $currentId,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $devices]);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|null $device */
        $device = $user->tokens()->whereKey($token)->first();

        abort_if($device === null, Response::HTTP_NOT_FOUND);

        $deleted = $user->tokens()->whereKey($device->getKey())->delete();

        // A concurrent revoke of the same row already removed it: same answer
        // as an unknown id, and no second audit row for one revocation.
        abort_if($deleted === 0, Response::HTTP_NOT_FOUND);

        $this->auditRevocation($request, $user, $device);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * A revoked device is a security event - "was this laptop signed out, and
     * by whom, from where?" is exactly what an incident review asks - but a
     * token row is not a LogsCrmActivity model and disappears on revoke, so no
     * audit row was ever written. The entry goes to the same `crm` log as the
     * model audit rows (LogsCrmActivity::AUDIT_LOG_NAME) with the user as the
     * subject, because the token row it described no longer exists. The token
     * itself is NEVER logged - only its id and the metadata the device list
     * already shows.
     */
    private function auditRevocation(Request $request, User $user, PersonalAccessToken $device): void
    {
        $current = $user->currentAccessToken();

        activity('crm')
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties([
                'token_id' => $device->getKey(),
                'name' => (string) $device->name,
                'platform' => $device->device_platform,
                'last_used_at' => $device->last_used_at?->toIso8601String(),
                'self_revoked' => $current instanceof PersonalAccessToken && $current->getKey() === $device->getKey(),
                'ip' => $request->ip(),
            ])
            ->log('devices.revoked');
    }
}
