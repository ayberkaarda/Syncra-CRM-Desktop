<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A hard-delete tombstone (`sync_deletions`, SYNCDESKTOP §4.2).
 *
 * Rows are written by SyncDeletionObserver and by the `conversation_user`
 * AFTER DELETE trigger, and read by SyncPullService through the query builder;
 * this model exists so `logs:prune` can treat the table like every other
 * retention target it already knows how to chunk.
 *
 * `sync_version` is not a sync column here - this table is not synced
 * (protocol §1.3) - it is the cursor position the tombstone occupies.
 */
class SyncDeletion extends Model
{
    protected $table = 'sync_deletions';

    /**
     * The table carries `deleted_at` (when the row died) but no
     * created_at/updated_at pair - a tombstone is written once and never
     * touched again.
     */
    public $timestamps = false;

    protected $fillable = ['table_name', 'row_key', 'sync_version', 'deleted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sync_version' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }
}
