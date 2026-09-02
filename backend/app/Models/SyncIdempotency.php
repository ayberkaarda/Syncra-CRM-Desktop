<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The push replay ledger (`sync_idempotency`, SYNCDESKTOP §4.2).
 *
 * Written and read by SyncPushService through the query builder; this model
 * exists so `logs:prune` can chunk-delete it with the same machinery it uses
 * for the log tables (7-day retention).
 *
 * The primary key is the client's `idempotency_key` - a UUID, not an
 * auto-increment - so `$incrementing` and `$keyType` both have to be declared;
 * without them Eloquent would cast the key to int and match row 0.
 */
class SyncIdempotency extends Model
{
    protected $table = 'sync_idempotency';

    protected $primaryKey = 'idempotency_key';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * `created_at` only - a ledger entry is never updated. Left as a plain
     * column rather than a managed timestamp pair so Eloquent does not try to
     * write a non-existent `updated_at`.
     */
    public $timestamps = false;

    protected $fillable = ['idempotency_key', 'user_id', 'result_json', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
