<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — `client_id` offline identity column (SYNCDESKTOP §4.2 (b)).
 *
 * A record created while offline exists on the device long before the server
 * assigns an id. The device therefore mints a UUIDv7 and sends it as
 * `client_id`; the server stores it and the UNIQUE index makes the create
 * idempotent for free - a replayed batch hits the index, the applier reports
 * `duplicate` and returns the already-assigned `server_id`. In-batch foreign
 * keys (`company_client_id`) resolve through the same column.
 *
 * NULL is the normal state: every row the web SPA has ever created has no
 * client_id, and MySQL/MariaDB UNIQUE indexes ignore NULLs, so an arbitrary
 * number of them coexist.
 *
 * DELIBERATELY ABSENT:
 *   - `notifications` - its primary key is ALREADY a CHAR(36) UUID that can
 *     never be null (protocol §6.1/D10). The client mirrors it directly as its
 *     own client_id; a second uuid column would be dead weight and a second
 *     source of truth.
 *   - `taggables`, `quote_items`, `custom_field_values` - embedded in an owner
 *     row's payload, never addressed on their own (protocol §1.4/§1.5).
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const TABLES = [
        'companies', 'contacts', 'leads', 'deals', 'tasks', 'activities',
        'tickets', 'quotes', 'conversations', 'messages', 'conversation_user',
        'tags',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->char('client_id', 36)->nullable();
                $blueprint->unique('client_id', 'uq_'.$table.'_client_id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique('uq_'.$table.'_client_id');
                $blueprint->dropColumn('client_id');
            });
        }
    }
};
