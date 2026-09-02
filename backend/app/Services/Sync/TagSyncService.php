<?php

namespace App\Services\Sync;

use App\Sync\SyncVersionBumper;
use Illuminate\Database\Eloquent\Model;

/**
 * The single place `->tags()->sync()` may be called from (protocol §1.4/P1).
 *
 * `taggables` has no surrogate key and no `sync_version` of its own: it is not
 * a pull table, its rows travel inside the owner's `tags: [ids]` payload, and
 * it writes no tombstones. Losing a tag therefore has to be visible as the
 * OWNER's array getting shorter - which only works if the owner's version
 * moves.
 *
 * It does not move by itself. Laravel 12's pivot API is completely silent:
 * `attach()`/`detach()`/`sync()` go straight to `newPivotStatement()->insert()`
 * and `$query->delete()`, no model event of any kind exists (there is no
 * `pivotAttached` event in the framework), and `touchIfTouching()` is inert
 * because no model in this project declares `$touches`. So a tag-only edit
 * leaves the owner clean, leaves `updated_at` untouched, and would never reach
 * a desktop client.
 *
 * Seven call sites route through here (CompanyRepository:206,
 * ContactRepository:176, DealRepository:241, LeadRepository:163,
 * TicketRepository:128, ProductService:51 and :77), plus
 * LeadConversionService::moveTaggables(), which bumps BOTH sides because the
 * pivot rows move from the lead to the contact.
 *
 * Static by design: protocol §1.4 specifies the wrapper as
 * `TagSyncService::apply(...)`, and the seven callers are repositories and
 * services that are resolved from the container in production but constructed
 * directly in several existing tests. Threading a new constructor dependency
 * through all of them would change those call sites for no benefit - this
 * class holds no state and has no substitutable behaviour.
 */
final class TagSyncService
{
    /**
     * Replace the owner's tags and guarantee the change reaches the delta.
     *
     * @param  array<int, int|string>  $tagIds
     */
    public static function apply(Model $owner, array $tagIds): void
    {
        /** @phpstan-ignore-next-line every caller passes a model with a tags() relation. */
        $owner->tags()->sync($tagIds);

        SyncVersionBumper::bump($owner);
    }

    /**
     * Bump an owner whose pivot rows were moved or removed by a statement this
     * service did not issue (LeadConversionService::moveTaggables()).
     */
    public static function bumpOwner(Model $owner): void
    {
        SyncVersionBumper::bump($owner);
    }
}
