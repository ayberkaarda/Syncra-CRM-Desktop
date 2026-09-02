<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — hard-delete tombstones (SYNCDESKTOP §4.2, protocol §2.7).
 *
 * Only THREE tables ever land here: `tags`, `notifications` and
 * `conversation_user`. Everything else in the sync scope either uses soft
 * deletes (the row itself comes back through the delta with `deleted_at`
 * set and a fresh `sync_version`) or is embedded in an owner row's payload
 * (`taggables`, `quote_items`, `custom_field_values` - protocol §1.4/§1.5).
 *
 * `row_key` is a string, not a bigint: `notifications.id` is a UUID and
 * `conversation_user` is addressed by its logical key `conversation_id:user_id`
 * rather than its surrogate `id` (a member who leaves and rejoins gets a new
 * surrogate id, which the client cannot correlate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->string('row_key', 191);
            $table->unsignedBigInteger('sync_version');
            $table->timestamp('deleted_at');

            // The pull query is `WHERE table_name = ? AND sync_version > ?
            // ORDER BY sync_version` - exactly this composite.
            $table->index(['table_name', 'sync_version'], 'sync_deletions_table_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_deletions');
    }
};
