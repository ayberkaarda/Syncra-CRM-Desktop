<?php

/*
|--------------------------------------------------------------------------
| PDF du devis — libellés statiques (Phase 14 / Piste D + Piste E)
|--------------------------------------------------------------------------
|
| Justification complète : voir lang/tr/pdf.php.
|
*/

return [

    'quote_label' => 'Devis',
    'title_label' => 'Titre',
    'date_label' => 'Date',
    'validity_label' => 'Validité',
    'status_label' => 'Statut',

    'customer_info' => 'Informations client',
    'phone_label' => 'Tél.',
    'email_label' => 'E-mail',

    'items_section' => 'Articles du devis',
    'col_no' => '#',
    'col_description' => 'Description',
    'col_quantity' => 'Quantité',
    'col_unit_price' => 'Prix unitaire',
    'col_discount' => 'Remise %',
    'col_tax' => 'TVA %',
    'col_amount' => 'Montant',
    'unit_piece' => 'unité(s)',

    'subtotal' => 'Sous-total',
    'discount' => 'Remise',
    'tax' => 'TVA',
    'grand_total' => 'TOTAL GÉNÉRAL',

    'notes_section' => 'Notes',
    'terms_section' => 'Conditions générales',

    'page_indicator' => 'Page :page / :total',

    'exchange_rate_line' => '1 :currency = :rate :base (:date)',

    'status' => [
        'draft' => 'Brouillon',
        'sent' => 'Envoyé',
        'accepted' => 'Accepté',
        'rejected' => 'Refusé',
        'expired' => 'Expiré',
    ],

];
