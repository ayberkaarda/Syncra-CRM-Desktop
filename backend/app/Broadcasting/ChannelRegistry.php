<?php

namespace App\Broadcasting;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared vocabulary for routes/channels.php.
 *
 * These live in a class rather than at the top of the routes file on purpose:
 * Laravel `require`s (not `require_once`) the channel routes on every
 * application boot, and PHPUnit boots a fresh application per test inside one
 * PHP process - a file-scope `const` or `function` there would blow up on the
 * second test with a redeclaration error.
 */
final class ChannelRegistry
{
    /**
     * The ONLY record types accepted in `presence-record.{type}.{id}`.
     *
     * `{type}` is caller-supplied. Resolving it as "App\Models\{$type}" would
     * be class injection - any autoloadable class could be reached through a
     * websocket subscribe frame. This fixed map is the whitelist: a `{type}`
     * that is not a key here is refused before any query runs.
     *
     * Each entry pairs the model with the permission required to watch it, so
     * `presence-record` can never grant visibility a user does not already
     * have on the module itself.
     *
     * @var array<string, array{model: class-string<Model>, permission: string}>
     */
    public const RECORDS = [
        'deal' => ['model' => Deal::class, 'permission' => 'deals.view'],
        'ticket' => ['model' => Ticket::class, 'permission' => 'tickets.view'],
        'contact' => ['model' => Contact::class, 'permission' => 'contacts.view'],
        'company' => ['model' => Company::class, 'permission' => 'companies.view'],
        'lead' => ['model' => Lead::class, 'permission' => 'leads.view'],
    ];

    /**
     * BOARD channels: one channel per module-wide live view, gated on the
     * module's own `*.view` permission.
     *
     * Different question from RECORDS. `presence-record.deal.{id}` answers
     * "who is looking at THIS card" and is subscribed per record; a Kanban
     * board needs the opposite - one subscription that carries every card
     * movement on the board, so a page showing 50 deals opens one socket
     * channel instead of 50.
     *
     * @var array<string, string>
     */
    public const BOARDS = [
        'deals' => 'deals.view',
        // Phase 8: the support queue. TicketSlaWarning / TicketSlaBreached
        // publish here. An SLA about to burn is the team's shared problem,
        // not the assignee's private notification - and unassigned tickets
        // have no personal recipient at all - so this is a module channel
        // rather than private-user.{id}.
        'tickets' => 'tickets.view',
    ];

    /**
     * The permission required to listen on a board channel, or null when the
     * name is not a board.
     */
    public static function board(string $name): ?string
    {
        return self::BOARDS[$name] ?? null;
    }

    /**
     * Resolve a client-supplied record type, or null when it is not whitelisted.
     *
     * @return array{model: class-string<Model>, permission: string}|null
     */
    public static function record(string $type): ?array
    {
        // Deliberately a plain array lookup: no string concatenation, no
        // class_exists(), no case folding. What is not literally a key is not
        // a channel.
        return self::RECORDS[$type] ?? null;
    }

    /**
     * The member payload published to every OTHER subscriber of a presence
     * channel.
     *
     * Presence membership is readable by everyone inside the channel, so this
     * carries only what colleagues are meant to see. No `is_active`, no
     * `must_change_password`, no permission list - a presence roster is not an
     * account dump.
     *
     * @return array<string, mixed>
     */
    public static function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'department' => $user->department,
        ];
    }
}
