<?php

/*
|--------------------------------------------------------------------------
| Messages d'erreur
|--------------------------------------------------------------------------
|
| Les phrases d'erreur propres à l'application (`errors.message` de l'enveloppe de
| réponse).
|
| Phase 14 / Volet D : toutes les phrases fixes restantes de `bootstrap/app.php` (le bloc
| `match` de statut + 5xx + repli de validation), les phrases `denyTransition()` des
| machines à états (QuoteStatusMachine/TicketStatusMachine), UserDeactivated et
| DealVersionConflictException ont été déplacées ici.
|
| Les paramètres `:from`/`:to`/`:allowed` sous `status_transition`/`quote_status`/
| `ticket_status` sont des NOMS DE MACHINE À ÉTATS (draft/sent/open/...) - des valeurs
| fixes, pas des données utilisateur, mais tout de même portées en paramètre hors de la
| phrase afin que la phrase elle-même puisse être traduite.
*/

return [
    'unauthenticated' => 'Vous devez vous connecter pour effectuer cette action.',
    'forbidden' => 'Vous n\'êtes pas autorisé à effectuer cette action.',
    'not_found' => 'Enregistrement introuvable.',
    'method_not_allowed' => "Cette méthode de requête n'est pas valide pour cette adresse.",
    'session_expired' => 'La vérification de session a échoué. Veuillez actualiser la page et réessayer.',
    'too_many_attempts' => 'Trop de tentatives. Veuillez réessayer plus tard.',
    'request_failed' => "La requête n'a pas pu être traitée.",
    'server_error' => "Une erreur serveur inattendue s'est produite.",
    'validation_failed' => 'Les données envoyées sont invalides.',

    'status_transition' => [
        'invalid' => "Cette transition de statut n'est pas autorisée : :from → :to.",
    ],

    'quote_status' => [
        'send_endpoint_required' => 'Un devis ne peut être envoyé que via POST /api/quotes/{quote}/send.',
        'terminal' => '« :from » est un statut terminal ; le statut de ce devis ne peut plus être modifié. Créez un nouveau devis si un changement est nécessaire.',
        'allowed_transitions' => 'Depuis « :from », seule une transition vers les statuts suivants est possible : :allowed.',
    ],

    'ticket_status' => [
        'terminal' => '« :from » est un statut terminal ; le statut de ce ticket ne peut plus être modifié.',
        'allowed_transitions' => 'Depuis « :from », seule une transition vers les statuts suivants est possible : :allowed.',
    ],

    'deal_version_conflict' => [
        'message' => 'Cette carte a été mise à jour par un autre utilisateur pendant que vous la déplaciez. '
            .'Son état actuel est affiché ci-dessous ; veuillez réessayer.',
    ],

    'user_deactivated' => [
        'message' => 'Votre compte a été désactivé. Votre session a été terminée.',
    ],

    // Phase 14 / Volet F — C2 Vues enregistrées (docs/PHASE-AUDIT.md §5.4) : `query_json`
    // est revalidé côté serveur par rapport à une liste blanche par module ; un champ
    // inconnu/non autorisé n'est jamais silencieusement ignoré, il est explicitement
    // rejeté. `:fields` sont les noms de champs rejetés (ex. « filter.raw_sql, sort ») -
    // des noms techniques fixes, pas des données utilisateur.
    'saved_view' => [
        'invalid_query' => 'La requête de la vue enregistrée contient des champ(s) non autorisé(s) : :fields.',
    ],
];
