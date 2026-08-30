<?php

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| TEXTES DE NOTIFICATION - contrat clé+paramètre (docs/PHASE-INTL.md 1.4).
|
| `notifications.data` ne stocke plus de texte rendu ; il stocke les clés et paramètres de
| ce fichier. Le texte est résolu au moment de la LECTURE, dans la langue du LECTEUR - un
| utilisateur qui change de langue plus tard relit donc ses anciennes notifications dans la
| nouvelle langue.
|
| Phase 14 / Volet D : les 11 types de notification ont été convertis en mode clé.
|
| CONTRAT DE PARAMÈTRE : les substituants commençant par `:` appartiennent à la syntaxe de
| `__()`. Les paramètres se terminant par `_at` sont des chaînes de date ISO-8601, formatées
| à la lecture dans la langue du lecteur (voir NotificationText::resolveParams()). Tout
| autre paramètre est une DONNÉE UTILISATEUR (nom, montant, nombre) et est imprimé tel
| quel - un texte déjà traduit n'est JAMAIS placé dans un paramètre (cela ne ferait que
| déplacer le gel de langue). Les variantes de phrase (acteur connu/inconnu, groupe/direct,
| avec/sans étiquette, par statut...) vivent donc en clés SÉPARÉES, pas en concaténation
| conditionnelle.
*/

return [
    'deal_assigned' => [
        'title' => 'Une opportunité vous a été attribuée',
        'body' => ':subject — :amount',
    ],
    'task_assigned' => [
        'title' => 'Une tâche vous a été attribuée',
        'body' => ':title',
        'body_with_due' => ':title — échéance :due_at',
    ],
    'chat_mention' => [
        'title' => ':actor vous a mentionné',
        'title_in_group' => ':actor vous a mentionné (:conversation)',
        'title_unknown_actor' => 'Vous avez été mentionné',
        'title_unknown_actor_in_group' => 'Vous avez été mentionné (:conversation)',
        'body' => ':excerpt',
        'body_no_content' => 'A partagé un fichier.',
    ],
    'deal_lost' => [
        'title' => 'Opportunité perdue',
        'body' => ':subject — :amount',
    ],
    'deal_won' => [
        'title' => 'Opportunité gagnée',
        'body' => ':subject — :amount',
    ],
    'deal_stage_changed' => [
        'title' => "Étape de l'opportunité modifiée",
        'body' => ':deal_title — désormais à l\'étape « :stage »',
    ],
    'lead_assigned' => [
        'title' => 'Un prospect vous a été attribué',
        'body' => ':person',
        'body_with_company' => ':person — :company',
    ],
    'quote_status_changed' => [
        'title' => 'Statut du devis modifié',
        'body_draft' => ':quote_number — Brouillon',
        'body_sent' => ':quote_number — Envoyé',
        'body_accepted' => ':quote_number — Accepté',
        'body_rejected' => ':quote_number — Refusé',
        'body_expired' => ':quote_number — Expiré',
        'body_default' => ':quote_number — :status',
    ],
    'task_reminder' => [
        'title' => 'Rappel de tâche',
        'body' => ':title',
        'body_with_label' => ':title — :label',
    ],
    'ticket_assigned' => [
        'title' => 'Un ticket de support vous a été attribué',
        'body' => ':ticket_number — :subject',
    ],
    'ticket_sla_breached' => [
        'title' => 'SLA dépassé',
        'body' => ':ticket_number — :subject (:minutes min de retard)',
    ],
    'ticket_sla_warning' => [
        'title' => 'Délai SLA presque écoulé',
        'body' => ':ticket_number — :subject (~:minutes min restantes)',
    ],
];
