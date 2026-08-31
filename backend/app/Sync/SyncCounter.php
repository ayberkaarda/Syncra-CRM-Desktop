<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * The global monotonic delta counter (SYNCDESKTOP §4.2, protocol §2.4).
 *
 * ONE statement, and it has to be this one:
 *
 *     UPDATE sync_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1
 *
 * `LAST_INSERT_ID(expr)` both stores `expr` and makes it readable back on the
 * SAME connection, so the increment and the read are a single atomic step with
 * no SELECT ... FOR UPDATE round trip. The identical statement is used by the
 * `conversation_user` triggers, so PHP writes and trigger writes draw from one
 * sequence.
 *
 * ------------------------------------------------------------------------
 * WHY THE SERIALISATION IS THE POINT, NOT THE COST (protocol K-B)
 * ------------------------------------------------------------------------
 * Every writer inside a transaction contends on this single row, and probes
 * C1/C2 measured two concurrent transactions ending in `1205 Lock wait
 * timeout`. That is accepted DELIBERATELY: the pull cursor is a keyset scan
 * over `sync_version`, so "commit order == version order" is a correctness
 * precondition, and only the row lock provides it. Probe E1's non-blocking
 * AUTO_INCREMENT ticket alternative loses exactly that: if writer B (v=2)
 * commits before writer A (v=1) and a client pulls in between, the client
 * moves its cursor to 2 and NEVER sees A's row.
 *
 * The contention is managed rather than removed - see
 * App\Services\Sync\SyncPushService for the bounded retry (1205/1213, three
 * attempts, 100/400/900 ms with jitter) that protocol §2.4 P4a specifies.
 *
 * ------------------------------------------------------------------------
 * DOES THIS BREAK Eloquent's `create()`? NO - measured (probes T2/T3/T9)
 * ------------------------------------------------------------------------
 * The obvious fear is that writing LAST_INSERT_ID() corrupts the value
 * `PDO::lastInsertId()` returns for the INSERT that follows. It does not: the
 * AUTO_INCREMENT assignment happens later and overwrites the session value, so
 * `Model::create()` still receives the real primary key.
 */
final class SyncCounter
{
    /**
     * Reserve and return the next version.
     */
    public static function next(): int
    {
        DB::update('UPDATE sync_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1');

        /** @var object{value: int|string} $row */
        $row = DB::selectOne('SELECT LAST_INSERT_ID() AS value');

        return (int) $row->value;
    }

    /**
     * Current value without advancing it. Read-only callers only (tests,
     * diagnostics) - never use this to derive a version.
     */
    public static function current(): int
    {
        return (int) (DB::table('sync_counter')->where('id', 1)->value('value') ?? 0);
    }
}
