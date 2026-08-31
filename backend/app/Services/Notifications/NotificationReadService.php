<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The read/unread write path for `notifications`, shared by the REST endpoint
 * and the desktop sync push applier.
 *
 * ------------------------------------------------------------------------
 * WHY THIS EXISTS AT ALL
 * ------------------------------------------------------------------------
 * NotificationController's docblock argues - correctly - that a service layer
 * around DatabaseNotification's own helpers would have added nothing. Two
 * things changed that:
 *
 *  1. K7: the sync push path MUST go through the same code the HTTP path uses.
 *     `notification.read_all` cannot reimplement "mark this user's unread
 *     notifications as read"; it has to CALL it.
 *  2. Protocol §2.3 #2 / §2.5: the old one-shot
 *     `unreadNotifications()->update(['read_at' => now()])` gave every affected
 *     row the same (in fact: no) version. The pull cursor is a single scalar,
 *     so if `LIMIT` ever lands between two rows sharing a version the second is
 *     never returned again - K-C makes a distinct version per row mandatory.
 *
 * The chunked model loop below satisfies both: each `markAsRead()` is a real
 * Eloquent save, so SyncVersionObserver stamps each row individually.
 * Chunking keeps the memory profile flat for a user who let ten thousand
 * notifications pile up, and `chunkById` is safe here even though the loop
 * mutates rows: `read_at` is not the chunk key.
 */
class NotificationReadService
{
    private const CHUNK_SIZE = 200;

    /**
     * Mark every unread notification of this user as read.
     *
     * @return int rows actually marked
     */
    public function markAllRead(User $user): int
    {
        $marked = 0;

        $user->unreadNotifications()
            ->chunkById(self::CHUNK_SIZE, function ($notifications) use (&$marked): void {
                foreach ($notifications as $notification) {
                    /** @var DatabaseNotification $notification */
                    $notification->markAsRead();
                    $marked++;
                }
            });

        return $marked;
    }

    /**
     * Mark one notification as read. Idempotent: an already-read row is left
     * alone, which also keeps it out of the delta a second time.
     */
    public function markRead(DatabaseNotification $notification): DatabaseNotification
    {
        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $notification;
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }
}
