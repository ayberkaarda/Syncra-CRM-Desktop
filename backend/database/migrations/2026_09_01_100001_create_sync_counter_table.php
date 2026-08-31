<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — global monotonic delta counter (SYNCDESKTOP §4.2, protocol §2.4).
 *
 * ONE row, forever. Every write inside the sync scope advances it with
 * `UPDATE sync_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1`,
 * which is atomic and hands the new value back through LAST_INSERT_ID() on the
 * same connection.
 *
 * The global write mutex this creates is DELIBERATE (protocol K-B): commit
 * order == version order is the correctness precondition of the keyset pull
 * cursor. An AUTO_INCREMENT ticket table would not block, but it would let a
 * higher version commit first and make the lower one invisible to any client
 * that pulled in between - silent data loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_counter', function (Blueprint $table) {
            // TINYINT + a single allowed value. MariaDB 10.4 enforces CHECK
            // constraints, so `id` can only ever be 1 and the table cannot grow
            // a second, competing counter row.
            $table->tinyInteger('id')->primary();
            $table->unsignedBigInteger('value');
        });

        DB::statement('ALTER TABLE sync_counter ADD CONSTRAINT sync_counter_single_row CHECK (id = 1)');

        DB::table('sync_counter')->insert(['id' => 1, 'value' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_counter');
    }
};
