<?php

/*
|--------------------------------------------------------------------------
| Fehlermeldungen
|--------------------------------------------------------------------------
|
| Die eigenen Fehlersätze der Anwendung (`errors.message` im Antwort-Umschlag).
|
| Phase 14 / Spur D: alle verbliebenen festen Sätze in `bootstrap/app.php` (der Status-
| `match`-Block + 5xx + Validierungs-Fallback), die `denyTransition()`-Sätze der
| Status-Maschinen (QuoteStatusMachine/TicketStatusMachine), UserDeactivated und
| DealVersionConflictException wurden hierher verschoben.
|
| Die Parameter `:from`/`:to`/`:allowed` unter `status_transition`/`quote_status`/
| `ticket_status` sind Status-MASCHINENNAMEN (draft/sent/open/...) - feste Werte, keine
| Benutzerdaten, aber dennoch als Parameter außerhalb des Satzes geführt, damit der Satz
| selbst übersetzt werden kann.
*/

return [
    'unauthenticated' => 'Für diese Aktion müssen Sie angemeldet sein.',
    'forbidden' => 'Sie sind für diese Aktion nicht berechtigt.',
    'not_found' => 'Datensatz nicht gefunden.',
    'method_not_allowed' => 'Diese Anfragemethode ist für diese Adresse ungültig.',
    'session_expired' => 'Die Sitzungsprüfung ist fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
    'too_many_attempts' => 'Zu viele Versuche. Bitte versuchen Sie es später erneut.',
    'request_failed' => 'Die Anfrage konnte nicht verarbeitet werden.',
    'server_error' => 'Es ist ein unerwarteter Serverfehler aufgetreten.',
    'validation_failed' => 'Die gesendeten Daten sind ungültig.',

    'status_transition' => [
        'invalid' => 'Dieser Statuswechsel ist nicht erlaubt: :from → :to.',
    ],

    'quote_status' => [
        'send_endpoint_required' => 'Ein Angebot kann nur über POST /api/quotes/{quote}/send gesendet werden.',
        'terminal' => '":from" ist ein finaler Status; der Status dieses Angebots kann nicht mehr geändert werden. Erstellen Sie bei Bedarf ein neues Angebot.',
        'allowed_transitions' => 'Von ":from" ist nur ein Wechsel zu folgenden Status möglich: :allowed.',
    ],

    'ticket_status' => [
        'terminal' => '":from" ist ein finaler Status; der Status dieses Tickets kann nicht mehr geändert werden.',
        'allowed_transitions' => 'Von ":from" ist nur ein Wechsel zu folgenden Status möglich: :allowed.',
    ],

    'deal_version_conflict' => [
        'message' => 'Diese Karte wurde von einem anderen Benutzer aktualisiert, während Sie sie verschoben haben. '
            .'Der aktuelle Stand wird unten angezeigt; bitte versuchen Sie es erneut.',
    ],

    'user_deactivated' => [
        'message' => 'Ihr Konto wurde deaktiviert. Ihre Sitzung wurde beendet.',
    ],

    // Phase 14 / Spur F — C2 Gespeicherte Ansichten (docs/PHASE-AUDIT.md §5.4):
    // `query_json` wird serverseitig gegen eine modulweise Positivliste erneut validiert;
    // ein unbekanntes/unzulässiges Feld wird nie stillschweigend verworfen, sondern
    // ausdrücklich abgelehnt. `:fields` sind die abgelehnten Feldnamen (z. B.
    // "filter.raw_sql, sort") - feste technische Namen, keine Benutzerdaten.
    'saved_view' => [
        'invalid_query' => 'Die Abfrage der gespeicherten Ansicht enthält nicht zulässige Feld(er): :fields.',
    ],
];
