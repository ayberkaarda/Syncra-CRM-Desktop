<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desktop sync — the ONLY database triggers in this project (protocol §2.2/P2).
 *
 * `conversation_user` is the one sync-scope table whose entire mutation
 * surface bypasses Eloquent model events, so an observer physically cannot see
 * its writes:
 *   - ChatReadState.php:71,104,129,144  raw `DB::update()` (single atomic
 *     UPDATE with GREATEST()/correlated subquery - splitting it into
 *     read-modify-write would reintroduce the lost-update races its own
 *     docblock argues against);
 *   - ConversationService.php:256,394 / MessageService.php:292  query builder;
 *   - attach()/detach()/sync()  Laravel 12 fires NO model event for pivot
 *     writes (InteractsWithPivotTable goes straight to newPivotStatement()).
 *
 * Bumping the owner `conversations` row instead was rejected: `unread_count`,
 * `last_read_message_id` and `is_muted` are PER MEMBER, and putting one
 * member's read state into every member's delta both leaks and thrashes.
 *
 * Observers and triggers are NEVER combined on one table: the trigger's
 * `SET NEW.sync_version` would overwrite whatever the observer wrote, spending
 * two counter values per write and tearing holes in the version space.
 *
 * ------------------------------------------------------------------------
 * BEFORE UPDATE carries a NULL-safe no-op guard (protocol §2.4 / P4b)
 * ------------------------------------------------------------------------
 * Probe T7 measured that MariaDB fires BEFORE UPDATE even for a value-less
 * UPDATE (`SET x = x`), which would mint a version - and therefore a phantom
 * delta - for a write that changed nothing. `<=>` (NULL-safe equality) is
 * mandatory rather than `=`: `last_read_message_id` is nullable, and
 * `NULL = NULL` is NULL, so a plain comparison would read as "changed" on
 * every row that has never been read.
 *
 * `sync_version` itself is excluded from the guard on purpose, so a statement
 * that writes only that column (the backfill helper) passes through untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // BEFORE INSERT - a fresh membership row is a delta for every device
        // of that user (it is how "you were added to a conversation" arrives).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER conversation_user_sync_version_bi
            BEFORE INSERT ON conversation_user
            FOR EACH ROW
            BEGIN
                UPDATE sync_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1;
                SET NEW.sync_version = LAST_INSERT_ID();
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER conversation_user_sync_version_bu
            BEFORE UPDATE ON conversation_user
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.conversation_id           <=> NEW.conversation_id
                    AND OLD.user_id                   <=> NEW.user_id
                    AND OLD.last_read_message_id      <=> NEW.last_read_message_id
                    AND OLD.last_delivered_message_id <=> NEW.last_delivered_message_id
                    AND OLD.unread_count              <=> NEW.unread_count
                    AND OLD.joined_at                 <=> NEW.joined_at
                    AND OLD.is_muted                  <=> NEW.is_muted
                    AND OLD.client_id                 <=> NEW.client_id) THEN
                    UPDATE sync_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1;
                    SET NEW.sync_version = LAST_INSERT_ID();
                END IF;
            END
        SQL);

        // AFTER DELETE - the only possible tombstone path for this table.
        // detach() issues a query-builder DELETE, so nothing in PHP ever sees
        // it. row_key is the LOGICAL key: a member who leaves and rejoins gets
        // a brand new surrogate `id` that the client cannot correlate with the
        // row it already holds.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER conversation_user_sync_deletion_ad
            AFTER DELETE ON conversation_user
            FOR EACH ROW
            BEGIN
                UPDATE sync_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1;
                INSERT INTO sync_deletions (table_name, row_key, sync_version, deleted_at)
                VALUES ('conversation_user',
                        CONCAT(OLD.conversation_id, ':', OLD.user_id),
                        LAST_INSERT_ID(),
                        NOW());
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS conversation_user_sync_deletion_ad');
        DB::unprepared('DROP TRIGGER IF EXISTS conversation_user_sync_version_bu');
        DB::unprepared('DROP TRIGGER IF EXISTS conversation_user_sync_version_bi');
    }
};
