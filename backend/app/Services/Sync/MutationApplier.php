<?php

namespace App\Services\Sync;

use App\Http\Requests\Activities\StoreActivityRequest;
use App\Http\Requests\Activities\UpdateActivityRequest;
use App\Http\Requests\Chat\StoreMessageRequest;
use App\Http\Requests\Chat\UpdateMessageRequest;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Http\Requests\Deals\AssignDealRequest;
use App\Http\Requests\Deals\MoveDealRequest;
use App\Http\Requests\Deals\StoreDealRequest;
use App\Http\Requests\Deals\UpdateDealRequest;
use App\Http\Requests\Leads\AssignLeadRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Requests\Quotes\StatusQuoteRequest;
use App\Http\Requests\Quotes\StoreQuoteRequest;
use App\Http\Requests\Quotes\UpdateQuoteRequest;
use App\Http\Requests\Tasks\AssignTaskRequest;
use App\Http\Requests\Tasks\CompleteTaskRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\StatusTicketRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Activities\ActivityService;
use App\Services\Chat\MessageService;
use App\Services\Companies\CompanyService;
use App\Services\Contacts\ContactService;
use App\Services\Deals\DealMoveService;
use App\Services\Deals\DealService;
use App\Services\Leads\LeadService;
use App\Services\Notifications\NotificationReadService;
use App\Services\Quotes\QuoteService;
use App\Services\Tasks\TaskService;
use App\Services\Tickets\TicketService;
use App\Sync\Mutation;
use App\Sync\MutationResult;
use App\Sync\SyncableRegistry;
use App\Sync\SyncCounter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies ONE mutation (SYNCDESKTOP §4.4).
 *
 * ==========================================================================
 * K7 IS THE WHOLE DESIGN: NO BUSINESS LOGIC LIVES HERE
 * ==========================================================================
 * Every branch below does the same four things the matching HTTP controller
 * does, in the same order, by calling the SAME classes:
 *
 *   Form Request rules  ->  Policy  ->  existing Service  ->  response shape
 *
 * Nothing is reimplemented. `deals.version` optimistic locking, QUOTE_LOCKED,
 * the ticket and quote status machines, SLA stamping, the horizontal ownership
 * boundary, `ticket_number`/`quote_number` generation, duplicate detection -
 * all of it is reached through the production path. A second implementation
 * would drift within one phase, and every drift is a rule the desktop client
 * can break that the web client cannot.
 *
 * ==========================================================================
 * WHY REAL FormRequest OBJECTS AND NOT `Validator::make($payload, $rules)`
 * ==========================================================================
 * Rules are only part of what a FormRequest decides. StoreDealRequest and its
 * siblings use `prepareForValidation()` to PIN `owner_id` to the caller when
 * they lack `*.assign` (ForcesRecordOwnerOnCreate), and CompleteTaskRequest
 * adds a `withValidator()` rule that reads the bound route model. Extracting
 * `rules()` alone would silently drop both - and the first of them is a
 * privilege boundary: a rep without `deals.assign` could create records in
 * somebody else's name straight from the sync endpoint.
 *
 * So the request is CONSTRUCTED and `validateResolved()` is called, which runs
 * prepareForValidation -> authorize -> rules -> withValidator -> passedValidation
 * exactly as an HTTP dispatch would.
 */
class MutationApplier
{
    /**
     * The `op=action` whitelist (SYNCDESKTOP §4.4).
     *
     * A whitelist, never a blacklist: an action absent from this list is
     * refused, so a capability added to the web later cannot become an
     * unreviewed offline capability by default.
     *
     * These entries are `entity.action` KEYS, not wire values. The wire
     * carries the two halves separately - `{"entity":"deal","action":"move"}`
     * (SYNCDESKTOP §4.4, protocol §4.3/P10) - and applyAction() joins them
     * before looking here. Naming the pair in one string keeps the list
     * readable; it is not a second spelling of the `action` field.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ACTIONS = [
        'deal.move', 'deal.assign',
        'task.complete', 'task.assign',
        'ticket.status', 'ticket.assign',
        'lead.assign',
        'quote.status',
        'conversation.read', 'conversation.delivered',
        'notification.read', 'notification.read_all',
    ];

    /**
     * Actions that exist on the web but are refused offline, with a code the
     * client renders as "do this online" rather than as a failure.
     *
     * These are not arbitrary: `lead.convert` writes across six tables in one
     * transaction and allocates ids the client cannot predict; `quote.send`
     * dispatches mail and renders a PDF; `quote.revise` mints a new numbered
     * document. Replaying any of them from a stale offline snapshot produces a
     * result nobody can reconcile.
     *
     * Same shape as ALLOWED_ACTIONS: `entity.action` keys, not wire values.
     *
     * @var array<int, string>
     */
    public const ONLINE_ONLY_ACTIONS = ['lead.convert', 'quote.send', 'quote.revise'];

    public function __construct(
        private readonly ConflictDetector $conflicts,
        private readonly NotificationReadService $notifications,
    ) {}

    /**
     * @param  array<string, int>  $clientIdMap  client_id => server_id, for rows created earlier in THIS batch
     */
    public function apply(Mutation $mutation, User $actor, array &$clientIdMap): MutationResult
    {
        try {
            return match ($mutation->op) {
                'create' => $this->applyCreate($mutation, $actor, $clientIdMap),
                'update' => $this->applyUpdate($mutation, $actor, $clientIdMap),
                'delete' => $this->applyDelete($mutation, $actor, $clientIdMap),
                'action' => $this->applyAction($mutation, $actor, $clientIdMap),
                default => MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'Unknown op: '.$mutation->op),
            };
        } catch (ValidationException $e) {
            return MutationResult::rejected(
                $mutation->seq,
                'INVALID_MUTATION',
                (string) (Arr::first(Arr::flatten($e->errors())) ?: $e->getMessage()),
            );
        } catch (AuthorizationException $e) {
            // The web surface answers 403 here. Policy denials in this project
            // are not only "no permission": DealPolicy::delete() refuses a
            // won/lost deal, TicketPolicy::delete() a resolved ticket,
            // LeadPolicy::update() a converted lead. All of them are terminal
            // for this mutation, which is why they are `rejected` and not
            // `conflict`.
            return MutationResult::rejected($mutation->seq, 'FORBIDDEN', $e->getMessage());
        } catch (HttpResponseException $e) {
            // The status machines and the optimistic lock signal through a
            // pre-built JSON response (QUOTE_LOCKED, INVALID_STATUS_TRANSITION,
            // DEAL_VERSION_CONFLICT). Their `code` is already the contract the
            // client expects - it is read back out rather than re-derived.
            return $this->fromHttpResponse($mutation, $e);
        }
    }

    // ---------------------------------------------------------------- create

    /**
     * @param  array<string, int>  $clientIdMap
     */
    private function applyCreate(Mutation $mutation, User $actor, array &$clientIdMap): MutationResult
    {
        $definition = SyncableRegistry::entity($mutation->entity);

        if ($definition === null || $definition['mode'] !== 'rw') {
            return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'Entity is not writable: '.$mutation->entity);
        }

        if ($mutation->clientId === null) {
            return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'create requires client_id');
        }

        $table = (string) SyncableRegistry::tableForEntity($mutation->entity);

        // The UNIQUE index on client_id makes replay detection free and exact:
        // if the row is already here, the earlier attempt succeeded and only
        // its response was lost.
        $existing = DB::table($table)->where('client_id', $mutation->clientId)->first(['id', 'sync_version']);

        if ($existing !== null) {
            $clientIdMap[$mutation->clientId] = (int) $existing->id;

            return MutationResult::duplicate($mutation->seq, (int) $existing->id, (int) $existing->sync_version);
        }

        $payload = $this->resolveReferences($mutation->payload, $clientIdMap);

        if ($payload === null) {
            return MutationResult::rejected($mutation->seq, 'UNRESOLVED_REFERENCE');
        }

        $model = match ($mutation->entity) {
            'company' => $this->create(Company::class, StoreCompanyRequest::class, $payload, $actor,
                fn (array $data) => app(CompanyService::class)->create($data)),
            'contact' => $this->create(Contact::class, StoreContactRequest::class, $payload, $actor,
                fn (array $data) => app(ContactService::class)->create($data)),
            'lead' => $this->create(Lead::class, StoreLeadRequest::class, $payload, $actor,
                fn (array $data) => app(LeadService::class)->create($data)),
            'deal' => $this->create(Deal::class, StoreDealRequest::class, $payload, $actor,
                fn (array $data) => app(DealService::class)->create($data)),
            'task' => $this->create(Task::class, StoreTaskRequest::class, $payload, $actor,
                fn (array $data) => app(TaskService::class)->create($data, (int) $actor->getKey())),
            'activity' => $this->create(Activity::class, StoreActivityRequest::class, $payload, $actor,
                fn (array $data) => app(ActivityService::class)->create($data, (int) $actor->getKey())),
            'ticket' => $this->create(Ticket::class, StoreTicketRequest::class, $payload, $actor,
                fn (array $data) => app(TicketService::class)->create($data, (int) $actor->getKey())),
            'quote' => $this->create(Quote::class, StoreQuoteRequest::class, $payload, $actor,
                fn (array $data) => app(QuoteService::class)->create($data, (int) $actor->getKey())),
            'message' => $this->createMessage($payload, $actor),
            default => null,
        };

        if ($model === null) {
            return MutationResult::rejected($mutation->seq, 'ONLINE_ONLY', 'This entity cannot be created offline: '.$mutation->entity);
        }

        $version = $this->stampClientId($model, $table, $mutation->clientId);

        $clientIdMap[$mutation->clientId] = (int) $model->getKey();

        return MutationResult::applied($mutation->seq, (int) $model->getKey(), $version);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<FormRequest>  $requestClass
     * @param  array<string, mixed>  $payload
     * @param  \Closure(array<string, mixed>): Model  $factory
     */
    private function create(string $modelClass, string $requestClass, array $payload, User $actor, \Closure $factory): Model
    {
        // Policy first, exactly like the controllers: an unauthorised caller
        // must not learn which fields would have failed validation.
        Gate::forUser($actor)->authorize('create', $modelClass);

        return $factory($this->validate($requestClass, $payload, $actor));
    }

    /**
     * Chat messages are the one create whose parent is a route parameter
     * rather than a payload field, so they cannot use the generic path.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createMessage(array $payload, User $actor): ?Model
    {
        $conversation = Conversation::query()->find($payload['conversation_id'] ?? null);

        if ($conversation === null) {
            return null;
        }

        Gate::forUser($actor)->authorize('participate', $conversation);

        return app(MessageService::class)->create(
            $conversation,
            $actor,
            $this->validate(StoreMessageRequest::class, $payload, $actor),
        );
    }

    // ---------------------------------------------------------------- update

    /**
     * @param  array<string, int>  $clientIdMap
     */
    private function applyUpdate(Mutation $mutation, User $actor, array &$clientIdMap): MutationResult
    {
        if ($mutation->serverId === null && $mutation->clientId === null) {
            return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'update requires server_id or client_id');
        }

        $model = $this->resolveTarget($mutation, $clientIdMap);

        if ($model === null) {
            return MutationResult::rejected($mutation->seq, $this->unresolvedCode($mutation));
        }

        /*
         * `changed_fields` is the contract, not a hint (SYNCDESKTOP §4.4):
         * only these keys may be written, and the client must have sent a
         * value for each. Anything else in `payload` is discarded - an offline
         * client holds a whole record, and letting it write the whole record
         * back would blindly overwrite every field somebody else edited while
         * it was offline. That is precisely the silent overwrite K6 forbids.
         */
        foreach ($mutation->changedFields as $field) {
            if (! array_key_exists($field, $mutation->payload)) {
                return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'changed_fields is not a subset of payload: '.$field);
            }
        }

        $conflicting = $this->conflicts->detect(
            $model,
            $mutation->entity,
            $mutation->changedFields,
            $mutation->baseSyncVersion,
            $mutation->occurredAt,
        );

        if ($conflicting !== []) {
            return MutationResult::conflict(
                $mutation->seq,
                'FIELD_CONFLICT',
                $conflicting,
                $this->serverRow($model),
                (int) $model->getAttribute('sync_version'),
            );
        }

        Gate::forUser($actor)->authorize('update', $model);

        $payload = $this->resolveReferences(
            array_intersect_key($mutation->payload, array_flip($mutation->changedFields)),
            $clientIdMap,
        );

        if ($payload === null) {
            return MutationResult::rejected($mutation->seq, 'UNRESOLVED_REFERENCE');
        }

        $requestClass = match ($mutation->entity) {
            'company' => UpdateCompanyRequest::class,
            'contact' => UpdateContactRequest::class,
            'lead' => UpdateLeadRequest::class,
            'deal' => UpdateDealRequest::class,
            'task' => UpdateTaskRequest::class,
            'activity' => UpdateActivityRequest::class,
            'ticket' => UpdateTicketRequest::class,
            'quote' => UpdateQuoteRequest::class,
            'message' => UpdateMessageRequest::class,
            default => null,
        };

        if ($requestClass === null) {
            return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'Entity is not updatable: '.$mutation->entity);
        }

        $data = $this->validate($requestClass, $payload, $actor, $model);

        $updated = match ($mutation->entity) {
            'company' => app(CompanyService::class)->update($model, $data),
            'contact' => app(ContactService::class)->update($model, $data),
            'lead' => app(LeadService::class)->update($model, $data),
            'deal' => app(DealService::class)->update($model, $data),
            'task' => app(TaskService::class)->update($model, $data),
            'activity' => app(ActivityService::class)->update($model, $data),
            'ticket' => app(TicketService::class)->update($model, $data),
            'quote' => app(QuoteService::class)->update($model, $data),
            'message' => app(MessageService::class)->update($model, $data),
        };

        return MutationResult::applied($mutation->seq, (int) $updated->getKey(), $this->currentVersion($updated));
    }

    // ---------------------------------------------------------------- delete

    /**
     * @param  array<string, int>  $clientIdMap
     */
    private function applyDelete(Mutation $mutation, User $actor, array &$clientIdMap): MutationResult
    {
        if ($mutation->serverId === null && $mutation->clientId === null) {
            return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'delete requires server_id or client_id');
        }

        $model = $this->resolveTarget($mutation, $clientIdMap);

        if ($model === null) {
            /*
             * Addressed by client_id and not found => the create has not landed
             * yet (a partial batch, a lost response). That is TRANSIENT, so it
             * must not be reported as done - the client re-sends once the
             * parent arrives.
             *
             * Addressed by server_id and not found => the row really is gone.
             * Deleting a deleted row is the outcome the client wanted, so it is
             * `duplicate`, not an error to surface.
             */
            return $mutation->serverId === null
                ? MutationResult::rejected($mutation->seq, 'UNRESOLVED_REFERENCE')
                : MutationResult::duplicate($mutation->seq, $mutation->serverId);
        }

        Gate::forUser($actor)->authorize('delete', $model);

        match ($mutation->entity) {
            'company' => app(CompanyService::class)->delete($model),
            'contact' => app(ContactService::class)->delete($model),
            'lead' => app(LeadService::class)->delete($model),
            'deal' => app(DealService::class)->delete($model),
            'task' => app(TaskService::class)->delete($model),
            'activity' => app(ActivityService::class)->delete($model),
            'ticket' => app(TicketService::class)->delete($model),
            'quote' => app(QuoteService::class)->delete($model),
            'message' => app(MessageService::class)->delete($model),
            'notification' => $model->delete(),
            default => throw new \RuntimeException('Entity is not deletable: '.$mutation->entity),
        };

        return MutationResult::applied(
            $mutation->seq,
            (int) $model->getKey(),
            (int) $model->getAttribute('sync_version'),
        );
    }

    // ---------------------------------------------------------------- action

    /**
     * @param  array<string, int>  $clientIdMap
     */
    private function applyAction(Mutation $mutation, User $actor, array &$clientIdMap): MutationResult
    {
        $action = (string) $mutation->action;

        /*
         * ONE DIALECT, AND THE BARE VERB IS IT.
         *
         * `entity` and `action` are two separate wire fields and `action`
         * holds the BARE verb: SYNCDESKTOP §4.4's own example is
         * `{"op":"action","entity":"deal","server_id":18342,"action":"move"}`
         * and protocol §4.3/P10's is `{"entity":"notification",
         * "action":"read_all","scope":"user"}`. The whitelists below are
         * written as `entity.action` pairs because that is what a permission
         * reviewer needs to read, so the two halves are joined HERE.
         *
         * An entity-qualified `action` is REFUSED rather than also accepted.
         * Being liberal in what we accept would mint a second spelling of the
         * same mutation, and a second spelling is drift with a delay fuse: a
         * client that gets a silent pass for `deal.move` keeps sending it, and
         * the day one side stops joining the halves the same way, the failure
         * lands on the user's offline queue instead of on this line.
         */
        if (str_contains($action, '.')) {
            return MutationResult::rejected(
                $mutation->seq,
                'INVALID_MUTATION',
                'action must be the bare verb, not entity-qualified: '.$action,
            );
        }

        $key = $mutation->entity.'.'.$action;

        if (in_array($key, self::ONLINE_ONLY_ACTIONS, true)) {
            return MutationResult::rejected($mutation->seq, 'ONLINE_ONLY');
        }

        if (! in_array($key, self::ALLOWED_ACTIONS, true)) {
            // The COMPOSITE key is reported: `deal` + `obliterate` and
            // `ticket` + `move` are different refusals, and a message naming
            // only the verb cannot tell them apart.
            return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'Action is not whitelisted: '.$key);
        }

        /*
         * `notification.read_all` is the ONE action with no subject row
         * (protocol §4.3/P10): it is user-scoped, carries neither `server_id`
         * nor `client_id`, and answers with how many rows it touched. Handled
         * before target resolution precisely because there is nothing to
         * resolve.
         */
        if ($key === 'notification.read_all') {
            if ($mutation->scope !== 'user') {
                return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'read_all requires scope=user');
            }

            return MutationResult::applied($mutation->seq, null, null, $this->notifications->markAllRead($actor));
        }

        if ($mutation->serverId === null && $mutation->clientId === null) {
            return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION', 'action requires server_id or client_id');
        }

        $model = $this->resolveTarget($mutation, $clientIdMap);

        if ($model === null) {
            return MutationResult::rejected($mutation->seq, $this->unresolvedCode($mutation));
        }

        return match ($key) {
            'deal.move' => $this->dealMove($mutation, $model, $actor),
            'deal.assign' => $this->simpleAction($mutation, $model, $actor, 'assign', AssignDealRequest::class,
                fn (array $data) => app(DealService::class)->assign($model, (int) $data['owner_id'])),
            'lead.assign' => $this->simpleAction($mutation, $model, $actor, 'assign', AssignLeadRequest::class,
                fn (array $data) => app(LeadService::class)->assign($model, (int) $data['owner_id'])),
            'task.complete' => $this->simpleAction($mutation, $model, $actor, 'complete', CompleteTaskRequest::class,
                fn (array $data) => app(TaskService::class)->complete($model, (bool) $data['completed'])),
            'task.assign' => $this->simpleAction($mutation, $model, $actor, 'assign', AssignTaskRequest::class,
                fn (array $data) => app(TaskService::class)->assign($model, (int) $data['assigned_to'])),
            'ticket.status' => $this->simpleAction($mutation, $model, $actor, 'update', StatusTicketRequest::class,
                fn (array $data) => app(TicketService::class)->changeStatus($model, (string) $data['status'])),
            'ticket.assign' => $this->simpleAction($mutation, $model, $actor, 'assign', AssignTicketRequest::class,
                fn (array $data) => app(TicketService::class)->assign($model, isset($data['assigned_to']) ? (int) $data['assigned_to'] : null)),
            'quote.status' => $this->simpleAction($mutation, $model, $actor, 'update', StatusQuoteRequest::class,
                fn (array $data) => app(QuoteService::class)->changeStatus($model, (string) $data['status'], isset($data['reason']) ? (string) $data['reason'] : null)),
            'conversation.read' => $this->chatCursor($mutation, $model, $actor, 'read'),
            'conversation.delivered' => $this->chatCursor($mutation, $model, $actor, 'delivered'),
            'notification.read' => $this->notificationRead($mutation, $model, $actor),
            default => MutationResult::rejected($mutation->seq, 'INVALID_MUTATION'),
        };
    }

    /**
     * @param  class-string<FormRequest>  $requestClass
     */
    private function simpleAction(Mutation $mutation, Model $model, User $actor, string $ability, string $requestClass, \Closure $run): MutationResult
    {
        Gate::forUser($actor)->authorize($ability, $model);

        $data = $this->validate($requestClass, $mutation->payload, $actor, $model);

        /** @var Model $result */
        $result = $run($data);

        return MutationResult::applied($mutation->seq, (int) $result->getKey(), $this->currentVersion($result));
    }

    /**
     * `deal.move` carries TWO counters and they mean different things
     * (protocol §4.3): `version` is the deal's own optimistic lock, checked by
     * DealMoveService and answered with 409 DEAL_VERSION_CONFLICT;
     * `base_sync_version` is the delta cursor. Neither substitutes for the
     * other, and the move goes through DealMoveService untouched so the row
     * lock, the fractional index and the `deal.moved` broadcast all behave
     * exactly as they do for the web.
     */
    private function dealMove(Mutation $mutation, Model $model, User $actor): MutationResult
    {
        Gate::forUser($actor)->authorize('move', $model);

        /** @var MoveDealRequest $request */
        $request = $this->makeRequest(MoveDealRequest::class, $mutation->payload, $actor, $model);

        $moved = app(DealMoveService::class)->move($model, $request->movePayload(), $actor);

        return MutationResult::applied($mutation->seq, (int) $moved->getKey(), $this->currentVersion($moved));
    }

    private function chatCursor(Mutation $mutation, Model $model, User $actor, string $kind): MutationResult
    {
        Gate::forUser($actor)->authorize('participate', $model);

        $messageId = isset($mutation->payload['message_id']) ? (int) $mutation->payload['message_id'] : null;

        $service = app(MessageService::class);

        $kind === 'read'
            ? $service->markRead($model, (int) $actor->getKey(), $messageId)
            : $service->markDelivered($model, (int) $actor->getKey(), $messageId);

        /*
         * The version lives on `conversation_user` and is written by a database
         * trigger, so PHP never holds it. Read back from the pivot rather than
         * from LAST_INSERT_ID(): protocol §6.3/K-D closed that option because
         * whether an UPDATE trigger leaves its value there was never measured,
         * and a wire contract may not rest on unmeasured engine behaviour.
         */
        $version = DB::table('conversation_user')
            ->where('conversation_id', $model->getKey())
            ->where('user_id', $actor->getKey())
            ->value('sync_version');

        return MutationResult::applied($mutation->seq, (int) $model->getKey(), (int) $version);
    }

    private function notificationRead(Mutation $mutation, Model $model, User $actor): MutationResult
    {
        /** @var DatabaseNotification $model */
        if ($model->notifiable_type !== $actor->getMorphClass() || (int) $model->notifiable_id !== (int) $actor->getKey()) {
            // Somebody else's notification. 404-equivalent: the client is told
            // the row is gone rather than that it exists and is forbidden -
            // the same existence-hiding rule NotificationController applies.
            return MutationResult::rejected($mutation->seq, 'RECORD_DELETED');
        }

        $this->notifications->markRead($model);

        return MutationResult::applied($mutation->seq, null, $this->currentVersion($model));
    }

    // ---------------------------------------------------------------- shared

    /**
     * Resolve the row a mutation targets.
     *
     * ------------------------------------------------------------------
     * WHY `client_id` IS A FIRST-CLASS ADDRESS, NOT A FALLBACK
     * ------------------------------------------------------------------
     * An `action` is its own level in the client's topological order
     * (SYNCDESKTOP §5.4: actions sort after their entity's create), so
     * "create a task, then complete it" legitimately arrives as TWO mutations
     * in ONE batch - and when the second is applied the server id exists only
     * in this batch's running map, never in the request. Requiring `server_id`
     * would make that sequence impossible to express offline, which is the
     * whole point of the outbox.
     *
     * Two sources, in order: the ids assigned earlier in THIS batch, then the
     * UNIQUE `client_id` column (the create may have landed in an earlier,
     * partially delivered batch). Exactly the two the FK resolver uses - one
     * mechanism, not two.
     *
     * `notifications` is addressed differently, and that is the absence of a
     * special case rather than one: its primary key is ALREADY a
     * server-minted UUID, so `client_id` IS that key and no second column
     * exists (protocol §6.1/D10).
     *
     * @param  array<string, int>  $clientIdMap
     */
    private function resolveTarget(Mutation $mutation, array &$clientIdMap): ?Model
    {
        $definition = SyncableRegistry::entity($mutation->entity);

        if ($definition === null || $definition['model'] === null) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $table = (string) SyncableRegistry::tableForEntity($mutation->entity);

        if (! in_array($table, SyncableRegistry::clientIdTables(), true)) {
            $key = $mutation->clientId ?? $mutation->serverId;

            return $key === null ? null : $modelClass::query()->find($key);
        }

        $id = $mutation->serverId;

        if ($id === null && $mutation->clientId !== null) {
            $id = $clientIdMap[$mutation->clientId]
                ?? DB::table($table)->where('client_id', $mutation->clientId)->value('id');
        }

        if ($id === null) {
            return null;
        }

        return $modelClass::query()->find($id);
    }

    /**
     * Why a target could not be resolved. The two answers are NOT
     * interchangeable:
     *
     *   server_id given, row missing -> RECORD_DELETED. Terminal: the record is
     *       gone and re-sending the same bytes can never succeed.
     *   client_id only, unresolved   -> UNRESOLVED_REFERENCE. Transient: the
     *       create is still queued behind it, and the client retries.
     *
     * Reporting the first as the second strands the mutation in the outbox
     * forever; the second as the first throws away work the user did offline.
     */
    private function unresolvedCode(Mutation $mutation): string
    {
        return $mutation->serverId === null ? 'UNRESOLVED_REFERENCE' : 'RECORD_DELETED';
    }

    /**
     * Turn `*_client_id` references into server ids (SYNCDESKTOP §4.4).
     *
     * A record created offline may point at another record created offline in
     * the SAME batch, which has no server id until the applier reaches it. So
     * two sources are consulted, in order: the running map of ids assigned
     * earlier in this batch, then the UNIQUE `client_id` column (the row may
     * have been created by an earlier, partially delivered batch).
     *
     * Returning null - rather than dropping the reference - is deliberate: a
     * contact silently saved without its company is worse than a rejected
     * mutation the client can retry once the parent lands.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $clientIdMap
     * @return array<string, mixed>|null null means UNRESOLVED_REFERENCE
     */
    private function resolveReferences(array $payload, array $clientIdMap): ?array
    {
        foreach ($payload as $key => $value) {
            if (! str_ends_with($key, '_client_id')) {
                continue;
            }

            unset($payload[$key]);

            if ($value === null) {
                continue;
            }

            $entity = substr($key, 0, -strlen('_client_id'));
            $table = SyncableRegistry::tableForEntity($entity);

            if ($table === null) {
                return null;
            }

            $serverId = $clientIdMap[(string) $value]
                ?? DB::table($table)->where('client_id', $value)->value('id');

            if ($serverId === null) {
                return null;
            }

            $payload[$entity.'_id'] = (int) $serverId;
        }

        return $payload;
    }

    /**
     * Write `client_id` onto a freshly created row.
     *
     * A raw statement, not `$model->save()`: saving would move `updated_at`
     * (the bootstrap window filter) and write a second audit row for a record
     * that was just created. The version is advanced in the SAME statement so
     * the row the client gets back is the row the delta will report.
     */
    private function stampClientId(Model $model, string $table, string $clientId): int
    {
        $version = SyncCounter::next();

        DB::table($table)
            ->where($model->getKeyName(), $model->getKey())
            ->update(['client_id' => $clientId, 'sync_version' => $version]);

        $model->setAttribute('client_id', $clientId);
        $model->setAttribute('sync_version', $version);
        $model->syncOriginal();

        return $version;
    }

    /**
     * @param  class-string<FormRequest>  $requestClass
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validate(string $requestClass, array $payload, User $actor, ?Model $routeModel = null): array
    {
        $request = $this->makeRequest($requestClass, $payload, $actor, $routeModel);

        return $request->validated();
    }

    /**
     * Build and run a FormRequest outside the HTTP kernel.
     *
     * `validateResolved()` is the exact entry point Laravel's controller
     * dispatcher uses, so prepareForValidation(), authorize(), rules(),
     * withValidator() and passedValidation() all run in their normal order.
     *
     * Three collaborators have to be supplied by hand:
     *   - the container, so the validation factory resolves;
     *   - the redirector, because FormRequest::failedValidation() asks it for a
     *     redirect URL while building the exception - without it the failure
     *     path throws the WRONG exception and the mutation would surface as a
     *     server error instead of INVALID_MUTATION;
     *   - the user resolver, because ForcesRecordOwnerOnCreate pins `owner_id`
     *     to `$this->user()` and would otherwise see an anonymous caller.
     *
     * The route resolver is only needed by CompleteTaskRequest, which reads the
     * bound task to refuse completing a cancelled one.
     *
     * @param  class-string<FormRequest>  $requestClass
     * @param  array<string, mixed>  $payload
     */
    private function makeRequest(string $requestClass, array $payload, User $actor, ?Model $routeModel = null): FormRequest
    {
        /** @var FormRequest $request */
        $request = $requestClass::create('/api/sync/push', 'POST', $payload);

        $request->setContainer(app());
        $request->setRedirector(app(Redirector::class));
        $request->setUserResolver(fn () => $actor);

        if ($routeModel !== null) {
            $route = new Route(['POST'], '/api/sync/push', []);
            $route->bind($request);
            $route->setParameter($this->routeParameterName($routeModel), $routeModel);

            $request->setRouteResolver(fn () => $route);
        }

        $request->validateResolved();

        return $request;
    }

    private function routeParameterName(Model $model): string
    {
        return Str::camel(class_basename($model));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The version the row carries RIGHT NOW.
     *
     * Read with a query-builder SELECT rather than `$model->refresh()`, and
     * that is not a style choice: several services return models carrying
     * relations that are not real Eloquent relations - CompanyRepository sets
     * a synthetic `primaryContact` with setRelation() (documented at
     * CompanyRepository:138) - and refresh() re-loads every loaded relation by
     * name, so it throws RelationNotFoundException on exactly those models.
     * The applier must never depend on how a service chose to hydrate its
     * return value.
     */
    private function currentVersion(Model $model): int
    {
        return (int) DB::table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->value('sync_version');
    }

    /**
     * The server's current row, so the client can render a real diff in the
     * Conflict Inbox instead of asking the user to guess what changed.
     *
     * Read through the query builder: a conflicted row may be soft deleted,
     * and every model here hides those behind a global scope.
     *
     * @return array<string, mixed>
     */
    private function serverRow(Model $model): array
    {
        $row = DB::table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->first();

        return $row === null ? [] : (array) $row;
    }

    private function fromHttpResponse(Mutation $mutation, HttpResponseException $e): MutationResult
    {
        $decoded = json_decode((string) $e->getResponse()->getContent(), true);
        $code = is_array($decoded) ? ($decoded['errors']['code'] ?? null) : null;
        $message = is_array($decoded) ? ($decoded['errors']['message'] ?? null) : null;
        /*
         * The conflict payloads carry the fresh record beside `errors`, under
         * their own key - DealVersionConflictException uses `deal` so that
         * `errors.fields` stays reserved for validation. Read defensively:
         * a code without a row is still a valid, useful result.
         */
        $serverRow = is_array($decoded)
            ? ($decoded['deal'] ?? $decoded['quote'] ?? $decoded['ticket'] ?? $decoded['data'] ?? null)
            : null;

        return MutationResult::rejected(
            $mutation->seq,
            is_string($code) ? $code : 'INVALID_MUTATION',
            is_string($message) ? $message : null,
            is_array($serverRow) ? $serverRow : null,
        );
    }
}
