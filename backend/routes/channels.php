<?php

use App\Broadcasting\ChannelRegistry;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels — the channel dictionary
|--------------------------------------------------------------------------
|
| Every realtime feature in this project subscribes through one of the seven
| channels below. Names here are written WITHOUT the `private-` / `presence-`
| prefix: Laravel Echo adds it, and the authorization callback is looked up by
| the bare name.
|
|   private-user.{id}              -> personal channel (session revoke, toasts)
|   presence-online                -> global "who is online" roster
|   presence-record.{type}.{id}    -> "who is looking at this record"
|   private-conversation.{id}      -> chat room                      (Phase 12)
|   private-logs                   -> live log stream                (Phase 5)
|   private-dashboard              -> live dashboard counters        (Phase 11)
|   private-deals                  -> Kanban board card moves        (Phase 7)
|
| ----------------------------------------------------------------------------
| SECURITY MODEL
| ----------------------------------------------------------------------------
| A channel callback is the ONLY thing standing between a logged-in user and
| every frame published on that channel. Three rules are applied throughout:
|
| 1. Never turn user input into a class name. `presence-record.{type}` takes a
|    caller-supplied `{type}`; resolving it as "App\Models\{$type}" would be a
|    class-injection hole. ChannelRegistry::record() is a fixed whitelist -
|    anything that is not literally a key of it is refused before a query runs.
|
| 2. Existence is information. Returning "authorized" for a record id that does
|    not exist leaks the id space: a subscriber could enumerate which deals
|    exist purely from auth responses. Every record channel therefore verifies
|    the row actually exists and refuses otherwise (IDOR leak).
|
| 3. Deactivated accounts get nothing. `EnsureUserIsActive` guards the HTTP
|    surface, but a websocket connection outlives the request that opened it,
|    so every callback re-checks `is_active` at authorization time as well.
|
| 4. A forced password change closes every channel, the personal one included.
|    Data channels refuse such a user through $mayReceiveData below; the
|    personal channel refuses them too, because it is not identity-only: it
|    carries CRM notifications, task reminders (title + related record label)
|    and chat unread counters. That is the socket side of the line
|    routes/api.php draws between the REST whitelist and the data endpoints.
|    UserDeactivated is lost for that short window, which is acceptable:
|    EnsureUserIsActive still refuses every request synchronously.
|
| The `/broadcasting/auth` route itself is registered in bootstrap/app.php with
| ['web', 'auth:sanctum', 'active'] - see the note there.
|
| The whitelist and the presence payload live in App\Broadcasting\
| ChannelRegistry rather than at the top of this file: Laravel `require`s (not
| `require_once`) this file on every application boot, and PHPUnit boots a
| fresh application per test in one process - a file-scope const or function
| here would fail with a redeclaration error on the second test.
*/

/*
 * Shared precondition of every data channel: an active account that does not
 * owe a forced password change. A closure in a local variable, not a function,
 * for the redeclaration reason above.
 */
$mayReceiveData = static fn (User $user): bool => $user->is_active && ! $user->must_change_password;

/*
 * private-user.{id} — the user's own channel.
 *
 * App\Events\UserDeactivated broadcasts on PrivateChannel('user.'.$userId),
 * which resolves to exactly this callback. Strict identity only: no admin
 * override, because an admin listening on someone else's personal channel
 * would receive their private notifications.
 *
 * Refused while a forced password change is owed: besides UserDeactivated this
 * channel carries CrmNotification, TaskReminderDue and ChatUnread payloads -
 * record titles and labels the temporary password must not unlock.
 */
Broadcast::channel('user.{id}', function (User $user, string $id): bool {
    return $user->id === (int) $id && ! $user->must_change_password;
});

/*
 * presence-online — global roster.
 *
 * Returning an array authorizes AND publishes that array as the member info
 * every other subscriber sees. Returning null refuses. Deactivated accounts
 * are refused so a session that is being torn down cannot keep advertising
 * itself as online.
 */
Broadcast::channel('online', function (User $user) use ($mayReceiveData): ?array {
    if (! $mayReceiveData($user)) {
        return null;
    }

    return ChannelRegistry::payload($user);
});

/*
 * presence-record.{type}.{id} — collaborative editing indicator.
 *
 * Order matters: whitelist first (rejects class injection with no query),
 * permission second (cheap, spatie keeps the permission map in cache),
 * existence last (the only branch that touches the database).
 */
Broadcast::channel('record.{type}.{id}', function (User $user, string $type, string $id) use ($mayReceiveData): ?array {
    if (! $mayReceiveData($user)) {
        return null;
    }

    $channel = ChannelRegistry::record($type);

    if ($channel === null) {
        return null;
    }

    if (! $user->can($channel['permission'])) {
        return null;
    }

    // whereKey()->exists() - never hydrate the model, we only need the answer.
    if (! $channel['model']::query()->whereKey((int) $id)->exists()) {
        return null;
    }

    return ChannelRegistry::payload($user);
});

/*
 * private-conversation.{id} — chat (Phase 12).
 *
 * Membership is the conversation_user pivot, re-checked on every
 * authorization: a user removed from a conversation loses the channel on
 * their next subscribe. `chat.use` gates the feature as a whole.
 */
Broadcast::channel('conversation.{id}', function (User $user, string $id) use ($mayReceiveData): bool {
    if (! $mayReceiveData($user) || ! $user->can('chat.use')) {
        return false;
    }

    return Conversation::query()
        ->whereKey((int) $id)
        ->whereHas('users', fn ($query) => $query->whereKey($user->id))
        ->exists();
});

/*
 * private-logs — live activity/session log stream (Phase 5).
 */
Broadcast::channel('logs', function (User $user) use ($mayReceiveData): bool {
    return $mayReceiveData($user) && $user->can('logs.view');
});

/*
 * private-dashboard — live dashboard counters (Phase 11).
 */
Broadcast::channel('dashboard', function (User $user) use ($mayReceiveData): bool {
    return $mayReceiveData($user) && $user->can('dashboard.view');
});

/*
 * private-deals — Kanban board, one channel for the whole board (Phase 7).
 *
 * App\Events\DealMoved publishes `deal.moved` here whenever a card changes
 * stage or order. Gated on `deals.view` and NOT on `deals.move`: everyone who
 * may look at the board must see it move, even if they may not drag anything
 * themselves. The permission is read from ChannelRegistry::board() rather than
 * written inline, so the board dictionary has exactly one home.
 */
Broadcast::channel('deals', function (User $user) use ($mayReceiveData): bool {
    $permission = ChannelRegistry::board('deals');

    return $permission !== null && $mayReceiveData($user) && $user->can($permission);
});

/*
 * private-tickets — the support queue, one channel for the whole module
 * (Phase 8).
 *
 * App\Events\TicketSlaWarning and App\Events\TicketSlaBreached publish
 * `ticket.sla.warning` / `ticket.sla.breached` here whenever the scanner
 * (`tickets:scan-sla`, every five minutes) crosses a threshold. Gated on
 * `tickets.view`: whoever may not read a ticket may not hear its SLA burn
 * either. Same shape as `private-deals` above - the permission is read from
 * ChannelRegistry::board() rather than written inline, so the board
 * dictionary keeps exactly one home.
 */
Broadcast::channel('tickets', function (User $user) use ($mayReceiveData): bool {
    $permission = ChannelRegistry::board('tickets');

    return $permission !== null && $mayReceiveData($user) && $user->can($permission);
});
