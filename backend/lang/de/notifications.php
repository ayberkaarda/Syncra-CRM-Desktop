<?php

/*
|--------------------------------------------------------------------------
| Benachrichtigungen
|--------------------------------------------------------------------------
|
| BENACHRICHTIGUNGSTEXTE - Schlüssel+Parameter-Vertrag (docs/PHASE-INTL.md 1.4).
|
| `notifications.data` speichert keinen gerenderten Text mehr, sondern die Schlüssel und
| Parameter dieser Datei; der Text wird beim LESEN, in der Sprache des LESERS, aufgelöst.
| Ändert ein Benutzer später seine Sprache, liest er auch vergangene Benachrichtigungen in
| der neuen Sprache.
|
| Phase 14 / Spur D: alle 11 Benachrichtigungstypen wurden auf den Schlüsselmodus
| umgestellt.
|
| PARAMETER-VERTRAG: Platzhalter, die mit `:` beginnen, sind die eigene Syntax von `__()`.
| Parameter, die auf `_at` enden, sind ISO-8601-Datumsangaben und werden beim Lesen in der
| Sprache des Lesers formatiert (siehe NotificationText::resolveParams()). Alle anderen
| Parameter sind BENUTZERDATEN (Name, Betrag, Zahl) und werden unverändert ausgegeben -
| bereits übersetzter Text wird NIEMALS in einen Parameter gelegt (das würde das
| Einfrier-Problem nur verschieben). Satzvarianten (bekannter/unbekannter Akteur, Gruppe/
| Direkt, mit/ohne Label, je Status...) leben deshalb als EIGENE Schlüssel, nicht als
| bedingte Zeichenkettenbildung.
*/

return [
    'deal_assigned' => [
        'title' => 'Ihnen wurde eine Verkaufschance zugewiesen',
        'body' => ':subject — :amount',
    ],
    'task_assigned' => [
        'title' => 'Ihnen wurde eine Aufgabe zugewiesen',
        'body' => ':title',
        'body_with_due' => ':title — fällig :due_at',
    ],
    'chat_mention' => [
        'title' => ':actor hat Sie erwähnt',
        'title_in_group' => ':actor hat Sie erwähnt (:conversation)',
        'title_unknown_actor' => 'Sie wurden erwähnt',
        'title_unknown_actor_in_group' => 'Sie wurden erwähnt (:conversation)',
        'body' => ':excerpt',
        'body_no_content' => 'Hat eine Datei geteilt.',
    ],
    'deal_lost' => [
        'title' => 'Verkaufschance verloren',
        'body' => ':subject — :amount',
    ],
    'deal_won' => [
        'title' => 'Verkaufschance gewonnen',
        'body' => ':subject — :amount',
    ],
    'deal_stage_changed' => [
        'title' => 'Phase der Verkaufschance geändert',
        'body' => ':deal_title — jetzt in Phase ":stage"',
    ],
    'lead_assigned' => [
        'title' => 'Ihnen wurde ein Lead zugewiesen',
        'body' => ':person',
        'body_with_company' => ':person — :company',
    ],
    'quote_status_changed' => [
        'title' => 'Angebotsstatus geändert',
        'body_draft' => ':quote_number — Entwurf',
        'body_sent' => ':quote_number — Gesendet',
        'body_accepted' => ':quote_number — Angenommen',
        'body_rejected' => ':quote_number — Abgelehnt',
        'body_expired' => ':quote_number — Abgelaufen',
        'body_default' => ':quote_number — :status',
    ],
    'task_reminder' => [
        'title' => 'Aufgabenerinnerung',
        'body' => ':title',
        'body_with_label' => ':title — :label',
    ],
    'ticket_assigned' => [
        'title' => 'Ihnen wurde ein Support-Ticket zugewiesen',
        'body' => ':ticket_number — :subject',
    ],
    'ticket_sla_breached' => [
        'title' => 'SLA verletzt',
        'body' => ':ticket_number — :subject (:minutes Min. überfällig)',
    ],
    'ticket_sla_warning' => [
        'title' => 'SLA-Zeit läuft ab',
        'body' => ':ticket_number — :subject (~:minutes Min. verbleibend)',
    ],
];
