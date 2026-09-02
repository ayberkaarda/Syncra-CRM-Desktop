<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — push idempotency ledger (SYNCDESKTOP §4.2).
 *
 * The client retries a push batch whenever a response is lost, and the
 * partial-response contract (protocol §4.3 P10b) makes re-sending the NORMAL
 * case rather than an exceptional one. Replaying a mutation must therefore be
 * free: the stored `result_json` is returned verbatim as `status: "duplicate"`.
 *
 * `user_id` is part of the row (and indexed) so a leaked key from one account
 * can never replay a result into another; `created_at` is indexed for
 * `logs:prune` (7 days, SYNCDESKTOP §4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_idempotency', function (Blueprint $table) {
            $table->char('idempotency_key', 36)->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('result_json');
            $table->timestamp('created_at');

            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_idempotency');
    }
};
