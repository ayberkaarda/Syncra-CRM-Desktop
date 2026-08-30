<?php

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| NOTIFICATION TEXT - key+parameter contract (docs/PHASE-INTL.md 1.4).
|
| `notifications.data` no longer stores rendered text; it stores this file's keys and
| parameters. Text is resolved at READ time, in the READER's language, so a user who
| changes their language later reads past notifications in the new language too.
|
| Phase 14 / Track D: all 11 notification types were converted to key mode.
|
| PARAMETER CONTRACT: placeholders starting with `:` are `__()`'s own syntax. Parameters
| ending in `_at` are ISO-8601 date strings, formatted at read time in the reader's
| language (see NotificationText::resolveParams()). Every other parameter is USER DATA
| (name, amount, number) and is printed as-is - pre-translated text is NEVER placed into a
| parameter (that would just move the language-freezing bug). Sentence variants (known/
| unknown actor, group/direct, with/without label, per status...) therefore live as
| SEPARATE keys, not conditional string-building.
*/

return [
    'deal_assigned' => [
        'title' => 'A deal was assigned to you',
        'body' => ':subject — :amount',
    ],
    'task_assigned' => [
        'title' => 'A task was assigned to you',
        'body' => ':title',
        'body_with_due' => ':title — due :due_at',
    ],
    'chat_mention' => [
        'title' => ':actor mentioned you',
        'title_in_group' => ':actor mentioned you (:conversation)',
        'title_unknown_actor' => 'You were mentioned',
        'title_unknown_actor_in_group' => 'You were mentioned (:conversation)',
        'body' => ':excerpt',
        'body_no_content' => 'Shared a file.',
    ],
    'deal_lost' => [
        'title' => 'Deal lost',
        'body' => ':subject — :amount',
    ],
    'deal_won' => [
        'title' => 'Deal won',
        'body' => ':subject — :amount',
    ],
    'deal_stage_changed' => [
        'title' => 'Deal stage changed',
        'body' => ':deal_title — now in the ":stage" stage',
    ],
    'lead_assigned' => [
        'title' => 'A lead was assigned to you',
        'body' => ':person',
        'body_with_company' => ':person — :company',
    ],
    'quote_status_changed' => [
        'title' => 'Quote status changed',
        'body_draft' => ':quote_number — Draft',
        'body_sent' => ':quote_number — Sent',
        'body_accepted' => ':quote_number — Accepted',
        'body_rejected' => ':quote_number — Rejected',
        'body_expired' => ':quote_number — Expired',
        'body_default' => ':quote_number — :status',
    ],
    'task_reminder' => [
        'title' => 'Task reminder',
        'body' => ':title',
        'body_with_label' => ':title — :label',
    ],
    'ticket_assigned' => [
        'title' => 'A support ticket was assigned to you',
        'body' => ':ticket_number — :subject',
    ],
    'ticket_sla_breached' => [
        'title' => 'SLA breached',
        'body' => ':ticket_number — :subject (:minutes min overdue)',
    ],
    'ticket_sla_warning' => [
        'title' => 'SLA time running out',
        'body' => ':ticket_number — :subject (~:minutes min left)',
    ],
];
