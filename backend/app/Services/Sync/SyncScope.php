<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Sync\SyncableRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Decides WHICH tables and WHICH rows a caller may pull (SYNCDESKTOP §4.1).
 *
 * ------------------------------------------------------------------------
 * TABLE LEVEL: an unpermitted table has no KEY, not an empty array
 * ------------------------------------------------------------------------
 * Same principle as GlobalSearchService, and for the same reason: answering
 * `deals: {rows: []}` still tells the caller that a `deals` module exists and
 * that they are shut out of it. The key's total absence keeps the module
 * invisible in the API contract itself.
 *
 * ------------------------------------------------------------------------
 * ROW LEVEL: only where the web surface already scopes rows
 * ------------------------------------------------------------------------
 * The CRM policies are deliberately flat for reads (see ChecksRecordOwnership
 * and GlobalSearchService): module `.view` sees every record, ownership is not
 * consulted. This service inherits that decision unchanged rather than
 * inventing a stricter sync-only rule.
 *
 * FOUR tables are different, because their web surface is genuinely row
 * scoped, and shipping them wholesale would be a real leak:
 *   - notifications  belong to one user (NotificationController answers 404
 *                    for somebody else's uuid);
 *   - conversations / messages / conversation_user  are gated per record by
 *                    ConversationPolicy::participate - membership, not a
 *                    module permission;
 *   - saved_views    are private unless `is_shared`.
 */
class SyncScope
{
    /**
     * The tables this user may pull, with their manifest metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tablesFor(User $user): array
    {
        $visible = [];

        foreach (SyncableRegistry::tables() as $table => $definition) {
            $permission = $definition['permission'];

            if ($permission !== null && ! $user->can($permission)) {
                continue;
            }

            $visible[$table] = $definition;
        }

        return $visible;
    }

    public function allows(User $user, string $table): bool
    {
        return array_key_exists($table, $this->tablesFor($user));
    }

    /**
     * Row-level restriction for one table, applied on top of the delta window.
     */
    public function applyRowScope(Builder $query, string $table, User $user): void
    {
        $userId = (int) $user->getKey();

        match ($table) {
            'notifications' => $query
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $userId),

            // Membership, resolved through the pivot rather than a join, so the
            // delta query keeps its single-column keyset shape.
            'conversations' => $query->whereIn('id', $this->conversationIds($userId)),
            'messages', 'conversation_user' => $query->whereIn('conversation_id', $this->conversationIds($userId)),

            'saved_views' => $query->where(function ($q) use ($userId): void {
                $q->where('user_id', $userId)->orWhere('is_shared', true);
            }),

            // Public settings only. The private ones are an admin surface and
            // some of them (integration keys, thresholds) are exactly what a
            // stolen laptop should not carry.
            'settings' => $query->where('is_public', true),

            // SYNCDESKTOP §4.1 caps the FX mirror at the last seven days: the
            // desktop client needs current rates to render amounts, not the
            // full historical series the reports screen queries online.
            'exchange_rates' => $query->where('rate_date', '>=', now()->subDays(7)->toDateString()),

            default => null,
        };
    }

    /**
     * Column projection for one table, or null for "every column".
     *
     * @return array<int, string>|null
     */
    public function projectionFor(string $table): ?array
    {
        // `users` is the only projected table, and SYNCDESKTOP §4.1 states the
        // rule as "no other column" - a whitelist, because `users` also holds
        // `password`, `remember_token` and `must_change_password`, and a
        // blacklist would leak whatever column is added next.
        return $table === 'users' ? SyncableRegistry::USER_PROJECTION : null;
    }

    /**
     * @return array<int, int>
     */
    private function conversationIds(int $userId): array
    {
        /** @var array<int, int> $ids */
        $ids = DB::table('conversation_user')
            ->where('user_id', $userId)
            ->pluck('conversation_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $ids;
    }
}
