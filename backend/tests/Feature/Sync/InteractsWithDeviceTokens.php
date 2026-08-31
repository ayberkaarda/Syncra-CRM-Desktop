<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Services\Auth\DeviceTokenService;

/**
 * Shared setup for the desktop sync suite.
 *
 * ==========================================================================
 * `actingAs()` IS FORBIDDEN IN THESE TESTS (protocol §3.3/K-A, §8.1)
 * ==========================================================================
 * `actingAs()` authenticates through the session guard, and Sanctum hands a
 * session user a TransientToken whose `can()` returns an unconditional `true`.
 * So a test written with `actingAs()` would sail through `ability:desktop` and
 * report GREEN for an ability check that never actually ran - proving the
 * opposite of what it claims.
 *
 * Every test here therefore mints a REAL personal access token with
 * `createToken()` and sends it with `withToken()`, which is the only way to
 * exercise the same code path a desktop client uses.
 *
 * The ONE deliberate exception is the test that asserts a cookie session is
 * REFUSED by `device.token`; there `actingAs()` is mandatory, because
 * reproducing the weakness is the whole point of that assertion.
 */
trait InteractsWithDeviceTokens
{
    /**
     * A user plus a bearer token carrying the `desktop` ability.
     *
     * @return array{0: User, 1: string}
     */
    protected function deviceUser(string $role = 'Admin', array $attributes = []): array
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        $token = $user->createToken('TEST-PC', [DeviceTokenService::ABILITY]);

        return [$user, $token->plainTextToken];
    }

    /**
     * A token WITHOUT the `desktop` ability - the negative case for
     * SYNCDESKTOP §9's first security criterion.
     *
     * @return array{0: User, 1: string}
     */
    protected function nonDeviceTokenUser(string $role = 'Admin'): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $token = $user->createToken('SOME-OTHER-CLIENT', ['other']);

        return [$user, $token->plainTextToken];
    }
}
