<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // `broadcasting/auth` is here because Echo calls it from the SPA origin
    // (localhost:5173) exactly like any /api/* call - without it the browser
    // preflight fails and every private/presence subscription is refused.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    /*
     * CORS-1: the desktop webview's origins do not equal the SPA origin and
     * both must be listed, or every preflight from the Tauri app fails
     * silently (browser reports a generic network error, no server-side
     * clue). Measured origins:
     *   - `http://localhost:1420`  `tauri dev` (Vite dev server on that app)
     *   - `http://tauri.localhost` packaged/bundled build on Windows
     *
     * Configurable via DESKTOP_ORIGINS (comma-separated) so a different
     * platform's origin (e.g. Linux's `tauri://localhost`) can be added
     * without touching this file; the default below covers Windows dev +
     * packaged, which is what this repo ships today.
     *
     * NOT the same list as SANCTUM_STATEFUL_DOMAINS (config/sanctum.php) -
     * see the warning next to that setting. This is CORS only: it decides
     * whether the browser lets the response through, not whether Laravel
     * treats the request as a stateful (cookie) session.
     */
    'allowed_origins' => array_values(array_unique(array_merge(
        [env('FRONTEND_URL', 'http://localhost:5173')],
        array_filter(array_map(
            'trim',
            explode(',', (string) env('DESKTOP_ORIGINS', 'http://localhost:1420,http://tauri.localhost')),
        )),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
