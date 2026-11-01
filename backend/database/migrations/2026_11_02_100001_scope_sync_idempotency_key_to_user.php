<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — the idempotency ledger's key becomes `(user_id, idempotency_key)`.
 *
 * The ledger was READ per user (SyncPushService::replayOf filters on both
 * columns) but its PRIMARY KEY was `idempotency_key` alone. So a key one user
 * had already recorded made the INSERT for a second user fail; that failure is
 * swallowed by design (SyncPushService::remember), which left the second user
 * with no ledger row at all - their retries were never recognised as replays,
 * and whether a user's mutation is protected depended on keys chosen by other
 * accounts. A client-chosen value must only ever be unique within its owner.
 *
 * SAFE ON A POPULATED TABLE: every existing row already has a distinct
 * `idempotency_key`, so it is also distinct as a `(user_id, idempotency_key)`
 * pair and no row is lost or rewritten. The swap is ONE `ALTER TABLE`
 * statement, so there is no moment where the table has no primary key, and
 * the `user_id` foreign key keeps its own index (created with the table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_idempotency')) {
            return;
        }

        DB::statement('ALTER TABLE `sync_idempotency` DROP PRIMARY KEY, ADD PRIMARY KEY (`user_id`, `idempotency_key`)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('sync_idempotency')) {
            return;
        }

        // Under the per-user key two accounts may legitimately hold the same
        // key; the global key cannot. The ledger is a 7-day retry cache, not
        // business data, so the extra copies are dropped (oldest row kept)
        // rather than letting the rollback fail half way.
        DB::statement(
            'DELETE newer FROM `sync_idempotency` AS newer '
            .'JOIN `sync_idempotency` AS older '
            .'ON older.`idempotency_key` = newer.`idempotency_key` '
            .'AND (older.`created_at` < newer.`created_at` '
            .'OR (older.`created_at` = newer.`created_at` AND older.`user_id` < newer.`user_id`))'
        );

        DB::statement('ALTER TABLE `sync_idempotency` DROP PRIMARY KEY, ADD PRIMARY KEY (`idempotency_key`)');
    }
};
