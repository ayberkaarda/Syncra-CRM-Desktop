<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — `session_logs.channel` (SYNCDESKTOP §4.3, protocol §3.5/D13).
 *
 * Device logins produce a session_logs row exactly like SPA logins do, but the
 * two are operationally different events: a desktop login has no session id,
 * mints a long-lived bearer token and is expected to happen once per machine,
 * whereas a `web` login happens every browser session. Without this column the
 * logs screen (and the security review that reads it) cannot tell them apart.
 *
 * DEFAULT 'web' so every existing row - and every future SPA login that never
 * sets the field - keeps its correct meaning without a data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_logs', function (Blueprint $table) {
            $table->string('channel', 16)->default('web')->after('event');
        });
    }

    public function down(): void
    {
        Schema::table('session_logs', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
