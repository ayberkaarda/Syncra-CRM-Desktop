<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * CORS-1 — config/cors.php desktop origin regression lock.
 *
 * INT-1's end-to-end run against the real backend found `config/cors.php`
 * only ever allowed `FRONTEND_URL` (the Vite SPA origin). Preflight from the
 * Tauri webview - `http://localhost:1420` in `tauri dev`, `http://
 * tauri.localhost` in a packaged build - was rejected with no server-side
 * clue: the browser just reports `Access-Control-Allow-Origin` did not match
 * and blocks the response client-side.
 *
 * These tests hit an existing, unauthenticated-reachable API route with a
 * genuine CORS preflight (`OPTIONS` + `Access-Control-Request-Method`) and
 * assert on `Access-Control-Allow-Origin` directly - the same signal INT-1
 * captured with curl. `Illuminate\Http\Middleware\HandleCors` short-circuits
 * a matched preflight BEFORE routing/auth run (see
 * `Fruitcake\Cors\CorsService::handlePreflightRequest`), so no authenticated
 * user or `RefreshDatabase` is needed here - only the CORS layer is under
 * test.
 *
 * NOT covered here (deliberately out of scope for CORS-1): whether
 * `SANCTUM_STATEFUL_DOMAINS` includes the desktop origin. It must NOT - see
 * config/sanctum.php and .env.example (KARAR A12) - and this file asserts
 * that separately below so a future edit that "fixes" CORS by widening
 * SANCTUM_STATEFUL_DOMAINS instead is caught.
 */
class DesktopCorsTest extends TestCase
{
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/me', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
    }

    public function test_tauri_dev_origin_receives_a_matching_preflight_response(): void
    {
        $response = $this->preflight('http://localhost:1420');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:1420');
    }

    public function test_packaged_desktop_origin_receives_a_matching_preflight_response(): void
    {
        $response = $this->preflight('http://tauri.localhost');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://tauri.localhost');
    }

    public function test_web_spa_origin_still_works(): void
    {
        // Regression guard: adding the desktop origins must not push the
        // existing SPA origin out of the allow-list.
        $response = $this->preflight('http://localhost:5173');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_an_unrecognized_origin_gets_no_allow_origin_header(): void
    {
        // A CORS-relevant negative: some other, never-whitelisted site must
        // not receive Access-Control-Allow-Origin at all (browser blocks the
        // response client-side because there is nothing to match against).
        $response = $this->preflight('http://evil.example.com');

        $response->assertStatus(204);
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_desktop_origin_is_not_registered_as_a_sanctum_stateful_domain(): void
    {
        // KARAR A12 / config/sanctum.php: if either desktop origin ever
        // matched here, EnsureFrontendRequestsAreStateful would treat the
        // desktop app's bearer-token requests as session requests and
        // ValidateCsrfToken would engage, breaking every POST with 419
        // CSRF_TOKEN_MISMATCH. CORS-1 touches CORS only - this must hold
        // both before and after this change.
        $stateful = config('sanctum.stateful');

        $this->assertIsArray($stateful);
        $this->assertNotContains('localhost:1420', $stateful);
        $this->assertNotContains('tauri.localhost', $stateful);
        $this->assertNotContains('http://localhost:1420', $stateful);
        $this->assertNotContains('http://tauri.localhost', $stateful);
    }
}
