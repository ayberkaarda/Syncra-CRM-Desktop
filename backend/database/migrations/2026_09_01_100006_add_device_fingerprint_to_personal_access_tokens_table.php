<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — device identity on Sanctum tokens (protocol §3.5 / K-E).
 *
 * SYNCDESKTOP §4.3 requires "one token per device": re-authenticating from the
 * same machine deletes the previous token. That needs a stable, comparable
 * device key. Sanctum's own `name` column is NOT it - it is `text`, not
 * unique, and it holds the human-readable device name the user sees in
 * GET /api/me/devices. Overloading a display field with key semantics was
 * rejected (K-E); a dedicated indexed CHAR(64) (sha256 hex) is added instead.
 *
 * Nullable, because every token minted by anything other than
 * POST /api/auth/device legitimately has no device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->char('device_fingerprint', 64)->nullable()->after('abilities');
            $table->index('device_fingerprint', 'idx_pat_device_fingerprint');

            /*
             * `platform` is part of the GET /api/me/devices contract
             * (SYNCDESKTOP §4.3) and there is nowhere else it could live: the
             * token row is the only per-device record the server keeps, and
             * `session_logs` is an append-only audit trail that says what a
             * device did once, not what it IS. Without it the device list
             * cannot tell a Windows machine from a Linux one, which is exactly
             * the cue a user needs to recognise a device they should revoke.
             */
            $table->string('device_platform', 16)->nullable()->after('device_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('idx_pat_device_fingerprint');
            $table->dropColumn(['device_fingerprint', 'device_platform']);
        });
    }
};
