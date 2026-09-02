<?php

/*
|--------------------------------------------------------------------------
| Error Messages
|--------------------------------------------------------------------------
|
| The application's own error sentences (the response envelope's `errors.message`).
|
| Phase 14 / Track D: every remaining fixed sentence in `bootstrap/app.php` (the status
| `match` block + 5xx + validation fallback), the status-machine `denyTransition()`
| sentences (QuoteStatusMachine/TicketStatusMachine), UserDeactivated and
| DealVersionConflictException were moved here.
|
| The `:from`/`:to`/`:allowed` parameters under `status_transition`/`quote_status`/
| `ticket_status` are status-MACHINE NAMES (draft/sent/open/...) - fixed values, not user
| data, but still carried as parameters outside the sentence so the sentence itself can be
| translated.
*/

return [
    'unauthenticated' => 'You need to sign in to perform this action.',
    'forbidden' => 'You are not authorised to perform this action.',
    'not_found' => 'Record not found.',
    'method_not_allowed' => 'This request method is not valid for this address.',
    'session_expired' => 'Session verification failed. Please refresh the page and try again.',
    'too_many_attempts' => 'Too many attempts. Please try again later.',
    'request_failed' => 'The request could not be processed.',
    'server_error' => 'An unexpected server error occurred.',
    'validation_failed' => 'The submitted data is invalid.',

    'status_transition' => [
        'invalid' => 'This status transition is not allowed: :from → :to.',
    ],

    'quote_status' => [
        'send_endpoint_required' => 'A quote can only be sent via POST /api/quotes/{quote}/send.',
        'terminal' => '":from" is a terminal status; this quote\'s status can no longer be changed. Create a new quote if a change is needed.',
        'allowed_transitions' => 'From ":from" you can only move to: :allowed.',
    ],

    'ticket_status' => [
        'terminal' => '":from" is a terminal status; this ticket\'s status can no longer be changed.',
        'allowed_transitions' => 'From ":from" you can only move to: :allowed.',
    ],

    'deal_version_conflict' => [
        'message' => 'This card was updated by another user while you were dragging it. '
            .'Its current state is shown below; please retry your action.',
    ],

    'user_deactivated' => [
        'message' => 'Your account has been deactivated. Your session has been ended.',
    ],

    // Phase 14 / Track F — C2 Saved Views (docs/PHASE-AUDIT.md §5.4): `query_json` is
    // re-validated on the server against a per-module whitelist; an unknown/disallowed
    // field is never silently dropped, it is explicitly rejected. `:fields` are the
    // rejected field names (e.g. "filter.raw_sql, sort") - fixed technical names, not
    // user data.
    'saved_view' => [
        'invalid_query' => 'The saved view query contains disallowed field(s): :fields.',
    ],

    /*
     * Phase F1 - desktop sync layer (SYNCDESKTOP §4.3-§4.5).
     *
     * These sentences cover HTTP-level rejections only. Per-mutation outcomes
     * (`applied`/`conflict`/`rejected`/`duplicate`) travel as a machine
     * readable `code`; the client renders those from `desktop.errors.*` in its
     * own language, so the server writes no sentence for them.
     */
    'sync' => [
        'device_token_required' => 'This endpoint requires a desktop device token.',
        'protocol_version_mismatch' => 'The desktop application is out of date; synchronisation has been stopped.',
        'push_batch_too_large' => 'The submitted change batch exceeds the allowed limit.',
        'invalid_credentials' => 'E-mail or password is incorrect.',
        'user_inactive' => 'Your account has been deactivated. Please contact your system administrator.',
    ],
];
